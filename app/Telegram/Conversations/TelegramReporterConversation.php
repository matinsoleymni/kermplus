<?php

namespace App\Telegram\Conversations;

use App\Models\User;
use App\Models\WhitelistedTarget;
use App\Services\FeatureLimitService;
use App\Services\WhitelistService;
use App\Telegram\Keyboards\TelegramReportReasonKeyboard;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class TelegramReporterConversation extends Conversation
{
    protected function getLocalUser(Nutgram $bot): ?User
    {
        $tgUser = $bot->callbackQuery()?->from ?? $bot->user();
        if (!$tgUser) return null;

        return User::where('telegram_id', $tgUser->id)->first();
    }

    public function start(Nutgram $bot)
    {
        $local = $this->getLocalUser($bot);
        if (!$local) {
            $bot->sendMessage('⛔️ حساب شما پیدا نشد. ابتدا /start را ارسال کنید.');
            $this->end();
            return;
        }

        if ($local->isSuspended()) {
            $bot->sendMessage('⛔️ حساب شما موقتا معلق شده است.');
            $this->end();
            return;
        }

        $local->last_active_at = now();
        $local->save();

        $limit = app(FeatureLimitService::class)->checkReporterLimit($local);
        if ($limit) {
            $this->respondWithLimit($bot, $limit);
            $this->end();
            return;
        }

        $targetType = $this->determineTargetType($bot->callbackQuery()?->data);
        $bot->setUserData('tg_reporter_target_type', $targetType);

        $prompt = match ($targetType) {
            'channel' => '📢 لطفا لینک یا یوزرنیم کانال رو بفرست (مثال: https://t.me/example یا example).',
            'post' => '📮 لینک پست تلگرام رو بفرست (مثال: https://t.me/example/123).',
            default => '👤 لطفا یوزرنیم تلگرام را وارد کنید (بدون @):',
        };

        $bot->sendMessage($prompt);
        $this->next('awaitTargetInput');
    }

    public function awaitTargetInput(Nutgram $bot)
    {
        $input = trim((string)$bot->message()?->text);
        if ($input === '') {
            $bot->sendMessage('⛔️ لطفا مقدار معتبری وارد کنید.');
            return;
        }

        $targetType = $bot->getUserData('tg_reporter_target_type') ?? 'account';
        $normalized = $this->normalizeTelegramTarget($input, $targetType);

        if ($normalized === null) {
            $bot->sendMessage('⛔️ مقدار وارد شده نامعتبر است. دوباره امتحان کن.');
            return;
        }

        $username = $normalized['username'];
        $bot->setUserData('tg_reporter_username', $username);
        $bot->setUserData('tg_reporter_target', $normalized);

        $whitelist = app(WhitelistService::class);
        if ($whitelist->isWhitelisted($username, WhitelistedTarget::TYPE_TELEGRAM)) {
            $bot->sendMessage($whitelist->getBlockMessage($username, WhitelistedTarget::TYPE_TELEGRAM));
            $this->end();
            return;
        }

        if (!empty($normalized['link'])) {
            $this->sendTelegramPreview($bot, $normalized);
        }

        $label = $normalized['label'] ?? "👤 یوزرنیم: @{$username}";

        // Show confirmation
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('✅ تایید', callback_data: 'start_tg_report'),
                InlineKeyboardButton::make('❌ لغو', callback_data: 'cancel_tg_report')
            );

        $bot->sendMessage("✅ **ثبت شد!**\n\n{$label}\n\nآیا میخواهید شروع کنیم؟", reply_markup: $keyboard);
        $this->next('processReporterStart');
    }

    public function processReporterStart(Nutgram $bot)
    {
        $data = $bot->callbackQuery()?->data;

        if ($data === 'cancel_tg_report') {
            $bot->sendMessage('❌ لغو شد.');
            $this->end();
            return;
        }

        if ($data === 'start_tg_report') {
            $username = $bot->getUserData('tg_reporter_username');
            $local = $this->getLocalUser($bot);

            if (!$local) {
                $bot->sendMessage('⛔️ حساب شما پیدا نشد. ابتدا /start را ارسال کنید.');
                $this->end();
                return;
            }

            $limiter = app(FeatureLimitService::class);
            $limit = $limiter->checkReporterLimit($local);
            if ($limit) {
                $this->respondWithLimit($bot, $limit);
                $this->end();
                return;
            }

            $bot->answerCallbackQuery();
            $this->askTelegramReason($bot);
            return;
        }
    }

    private function askTelegramReason(Nutgram $bot): void
    {
        $this->promptTelegramReason($bot);
        $this->next('processTelegramReason');
    }

    public function processTelegramReason(Nutgram $bot): void
    {
        $data = $bot->callbackQuery()?->data;
        $reasons = $this->telegramReasons();

        if (!$data || !isset($reasons[$data])) {
            $bot->answerCallbackQuery(text: '⛔️ گزینه نامعتبر است. از دکمه‌ها استفاده کن.');
            $this->promptTelegramReason($bot);
            return;
        }

        $target = $bot->getUserData('tg_reporter_target') ?? [];
        $username = $target['username'] ?? $bot->getUserData('tg_reporter_username');
        if (!$username) {
            $bot->answerCallbackQuery(text: '⛔️ ابتدا یوزرنیم را وارد کنید.');
            $this->end();
            return;
        }
        $local = $this->getLocalUser($bot);

        if (!$local) {
            $bot->answerCallbackQuery(text: '⛔️ حساب شما پیدا نشد.');
            $this->end();
            return;
        }

        $limiter = app(FeatureLimitService::class);
        $limit = $limiter->checkReporterLimit($local);
        if ($limit) {
            $this->respondWithLimit($bot, $limit);
            $this->end();
            return;
        }

        $limiter->recordReporterUsage($local);
        $bot->answerCallbackQuery(text: '✅ دلیل ثبت شد.');
        $this->runTelegramReport(
            $bot,
            $username,
            $reasons[$data],
            $target['label'] ?? null,
            $target['link'] ?? null
        );
    }

    private function runTelegramReport(Nutgram $bot, string $username, string $reason, ?string $targetLabel = null, ?string $previewLink = null)
    {
        $messageId = null;
        $totalSteps = 10; // Simulated steps

        $label = $targetLabel ?: "👤 یوزرنیم: @{$username}";
        $previewLine = $previewLink ? "\n🔗 لینک: {$previewLink}" : '';

        // Send initial message
        $msg = $bot->sendMessage("⏳ **درحال بررسی...**\n\n{$label}\n🗣 دلیل: {$reason}{$previewLine}\n📊 پیشرفت: 0%");
        $messageId = $msg->message_id;

        // Simulate progress
        for ($i = 1; $i <= $totalSteps; $i++) {
            sleep(2); // 2-second intervals
            $percent = ($i / $totalSteps) * 100;
            $percent = (int)$percent;
            $progressBar = $this->getProgressBar($percent);

            $updateMsg = "⏳ **درحال بررسی...**\n\n";
            $updateMsg .= "{$label}\n";
            $updateMsg .= "🗣 دلیل: {$reason}\n";
            $updateMsg .= $previewLine ? "{$previewLine}\n" : '';
            $updateMsg .= "📊 پیشرفت: {$percent}%\n";
            $updateMsg .= "{$progressBar}";

            try {
                $bot->editMessageText(
                    chat_id: $bot->user()->id,
                    message_id: $messageId,
                    text: $updateMsg
                );
            } catch (\Exception $e) {
                // Continue on error
            }
        }

        // Final message
        $finalMsg = "✅ **انجام شد!**\n\n";
        $finalMsg .= "{$label}\n";
        $finalMsg .= "🗣 دلیل: {$reason}\n";
        $finalMsg .= $previewLine ? "{$previewLine}\n" : '';
        $finalMsg .= "📊 پیشرفت: 100%\n";
        $finalMsg .= "[████████████████████] 100%\n\n";
        $finalMsg .= "✨ گزارش بررسی تکمیل شد.";

        $bot->editMessageText(
            chat_id: $bot->user()->id,
            message_id: $messageId,
            text: $finalMsg
        );

        $this->end();
    }

    private function getProgressBar(int $percent): string
    {
        $filled = (int)($percent / 5);
        $empty = 20 - $filled;
        $bar = '[' . str_repeat('█', $filled) . str_repeat('░', $empty) . ']';
        return $bar . ' ' . $percent . '%';
    }

    private function determineTargetType(?string $callbackData): string
    {
        return match ($callbackData) {
            'telegram_report_channel' => 'channel',
            'telegram_report_post' => 'post',
            default => 'account',
        };
    }

    private function normalizeTelegramTarget(string $input, string $targetType): ?array
    {
        if ($targetType === 'post') {
            $post = $this->normalizeTelegramPost($input);
            if (!$post) {
                return null;
            }

            $link = $this->buildTelegramLink($post['username'], $post['message_id']);

            return [
                'type' => 'post',
                'username' => $post['username'],
                'message_id' => $post['message_id'],
                'link' => $link,
                'label' => "📮 پست: @{$post['username']} / {$post['message_id']}",
            ];
        }

        $username = $this->normalizeTelegramUsername($input);
        if (!$username) {
            return null;
        }

        $link = $this->buildTelegramLink($username);
        $label = $targetType === 'channel'
            ? "📢 کانال: @{$username}"
            : "👤 یوزرنیم: @{$username}";

        return [
            'type' => $targetType,
            'username' => $username,
            'message_id' => null,
            'link' => $link,
            'label' => $label,
        ];
    }

    private function normalizeTelegramUsername(string $input): ?string
    {
        $username = preg_replace('#^https?://t\\.me/#i', '', trim($input));
        $username = ltrim($username ?? '', '@/');
        $username = strtok($username, '/');

        if (!$username || strlen($username) < 3 || strlen($username) > 32) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9_]+$/', $username) ? $username : null;
    }

    private function normalizeTelegramPost(string $input): ?array
    {
        $clean = preg_replace('#^https?://t\\.me/#i', '', trim($input));
        $clean = ltrim($clean ?? '', '@/');

        if (!preg_match('#^([A-Za-z0-9_]{3,32})/(\\d{1,12})#', $clean, $matches)) {
            return null;
        }

        return [
            'username' => $matches[1],
            'message_id' => (int)$matches[2],
        ];
    }

    private function buildTelegramLink(string $username, ?int $messageId = null): string
    {
        $base = 'https://t.me/' . ltrim($username, '@/');
        return $messageId ? "{$base}/{$messageId}" : $base;
    }

    private function sendTelegramPreview(Nutgram $bot, array $target): void
    {
        $link = $target['link'] ?? null;
        if (!$link) return;

        $preview = $this->buildTelegramPreview($bot, $target);

        try {
            $bot->sendMessage(
                $preview ?? ("🔍 پیش‌نمایش هدف:\n{$link}"),
                disable_web_page_preview: false
            );
        } catch (\Throwable $e) {
            // ignore preview errors to avoid blocking the flow
        }
    }

    private function buildTelegramPreview(Nutgram $bot, array $target): ?string
    {
        $link = $target['link'] ?? '';
        $username = $target['username'] ?? '';
        $type = $target['type'] ?? 'account';

        // Try to fetch chat info when possible
        $chat = null;
        $memberCount = null;

        if ($type === 'channel' && $username) {
            $memberCount = $this->fetchChannelMemberCount($bot, $username);
        }

        if ($type !== 'post' && $username) {
            try {
                $chat = $bot->getChat(chat_id: '@' . $username);
            } catch (\Throwable $e) {
                $chat = null;
            }
        }

        return match ($type) {
            'channel' => $this->formatChannelPreview($chat, $username, $memberCount),
            'post' => $this->formatMessagePreview($chat, $username, $target['message_id'] ?? null, $link),
            default => $this->formatAccountPreview($chat, $username),
        };
    }

    private function formatAccountPreview($chat, string $username): string
    {
        $name = trim(($chat->first_name ?? '') . ' ' . ($chat->last_name ?? '')) ?: '—';
        $bio = $chat->bio ?? '—';
        $id = $chat->id ?? '—';
        $isPrivate = 'yes'; // user accounts are private conversations

        return "🎗 KermPlus | Account Found\n".
            "━━━━━━━━━━━━━━━\n".
            "👤 name: {$name}\n".
            "🆔 id : {$id}\n".
            "📜 username : @{$username}\n".
            "🧾 bio: {$bio}\n\n".
            "📅 created: —\n".
            "🔒 is private?: {$isPrivate}\n".
            "━━━━━━━━━━━━━━━\n\n".
            "🗣 دلیل ریپورت را انتخاب کنید:\n";
    }

    private function formatChannelPreview($chat, string $username, ?int $memberCount = null): string
    {
        $title = $chat->title ?? '@' . $username;
        $description = $chat->description ?? '—';
        $visibility = ($chat && ($chat->type === 'channel')) ? 'public' : 'unknown';
        $members = $memberCount ?? $chat->subscriber_count ?? $chat->member_count ?? '—';

        return "🎗 KermPlus | Channel Found\n".
            "━━━━━━━━━━━━━━━\n".
            "📢 title: {$title}\n".
            "🆔 username: @{$username}\n".
            "🧾 description: {$description}\n\n".
            "👥 subscribers: {$members}\n".
            "━━━━━━━━━━━━━━━\n\n".
            "🗣 دلیل ریپورت را انتخاب کنید:\n";
    }

    private function formatMessagePreview($chat, string $username, ?int $messageId, string $link): string
    {
        $content = '—';
        $views = '—';
        $sentAt = '—';

        return "🎗 KermPlus | Message Found\n".
            "━━━━━━━━━━━━━━━\n".
            "📢 source: @{$username}\n".
            "🆔 message id: {$messageId}\n".
            "📝 content: {$content}\n".
            "🖇️ link : {$link}\n\n".
            "👀 views: {$views}\n".
            "📅 sent at: {$sentAt}\n".
            "━━━━━━━━━━━━━━━\n\n".
            "🗣 دلیل ریپورت را انتخاب کنید:\n";
    }

    private function respondWithLimit(Nutgram $bot, string $message): void
    {
        if ($bot->callbackQuery()) {
            $bot->answerCallbackQuery(text: $message, show_alert: true);
            return;
        }

        $bot->sendMessage($message);
    }

    private function telegramReasons(): array
    {
        return [
            'telegram_reason_child_abuse' => 'کودک آزاری',
            'telegram_reason_violence' => 'خشونت',
            'telegram_reason_illegal_goods' => 'کالا و خدمات غیرقانونی',
            'telegram_reason_illegal_adult' => 'محتوای بزرگسالان غیرقانونی',
            'telegram_reason_personal_data' => 'داده ‌های شخصی',
            'telegram_reason_fraud' => 'کلاهبرداری',
            'telegram_reason_copyright' => 'کپی رایت',
            'telegram_reason_spam' => 'اسپم',
            'telegram_reason_other' => 'غیرقانونی نیست ، اما باید حذف شود',
        ];
    }

    private function promptTelegramReason(Nutgram $bot): void
    {
        $bot->sendMessage(
            '🗣 دلیل ریپورت رو انتخاب کن :',
            reply_markup: TelegramReportReasonKeyboard::make()
        );
    }

    private function fetchChannelMemberCount(Nutgram $bot, string $username): ?int
    {
        try {
            return $bot->getChatMemberCount(chat_id: '@' . $username);
        } catch (\Throwable $e) {
            return null;
        }
    }
}

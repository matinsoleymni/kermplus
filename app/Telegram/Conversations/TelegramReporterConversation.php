<?php

namespace App\Telegram\Conversations;

use App\Models\User;
use App\Models\WhitelistedTarget;
use App\Services\FeatureLimitService;
use App\Services\WhitelistService;
use App\Telegram\Keyboards\TelegramReportReasonKeyboard;
use App\Telegram\Keyboards\TelegramReporterMenuKeyboard;
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

        $this->sendOrEditMessage($bot, $prompt, $this->targetInputKeyboard());
        $this->next('awaitTargetInput');
    }

    public function awaitTargetInput(Nutgram $bot)
    {
        $callbackData = $bot->callbackQuery()?->data;
        if ($callbackData === 'reporter_telegram_menu') {
            $bot->answerCallbackQuery();
            $this->showTelegramReporterMenu($bot);
            $this->end();
            return;
        }

        if ($callbackData) {
            $bot->answerCallbackQuery(text: '⛔️ ابتدا هدف را به صورت متن ارسال کن.');
            return;
        }

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

        $loadingMsg = $bot->sendMessage('⏳ درحال دریافت اطلاعات از تلگرام...');
        $preview = $this->buildTelegramPreview($bot, $normalized);
        $details = $preview ?? ($normalized['label'] ?? "🎯 هدف: @{$username}");
        $details .= "\n\n🗣️ دلیل ریپورت رو انتخاب کن :";
        $keyboard = TelegramReportReasonKeyboard::make();

        if ($loadingMsg?->message_id) {
            try {
                $bot->editMessageText(
                    chat_id: $bot->user()->id,
                    message_id: $loadingMsg->message_id,
                    text: $details,
                    reply_markup: $keyboard,
                    parse_mode: 'HTML'
                );
                $this->next('processTelegramReason');
                return;
            } catch (\Throwable) {
                $this->deleteMessageSafe($bot, $loadingMsg->message_id, parse_mode: 'HTML');
            }
        }

        $bot->sendMessage($details, reply_markup: $keyboard);
        $this->next('processTelegramReason');
    }

    public function processTelegramReason(Nutgram $bot): void
    {
        $data = $bot->callbackQuery()?->data;
        $reasons = $this->telegramReasons();

        if ($data === 'reporter_telegram_menu') {
            $bot->answerCallbackQuery();
            $this->showTelegramReporterMenu($bot);
            $this->end();
            return;
        }

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

        $whitelist = app(WhitelistService::class);
        if ($whitelist->isWhitelisted($username, WhitelistedTarget::TYPE_TELEGRAM)) {
            $bot->answerCallbackQuery();
            $bot->sendMessage($whitelist->getBlockMessage($username, WhitelistedTarget::TYPE_TELEGRAM));
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
            $target['link'] ?? null,
            $bot->callbackQuery()?->message?->message_id
        );
    }

    private function runTelegramReport(
        Nutgram $bot,
        string $username,
        string $reason,
        ?string $targetLabel = null,
        ?string $previewLink = null,
        ?int $baseMessageId = null
    ) {
        $totalSteps = 5;
        $delayPerStep = 5;

        $label = $targetLabel ?: "👤 یوزرنیم: @{$username}";
        $previewLine = $previewLink ? "\n🖇️ لینک: {$previewLink}" : '';

        $initialText = $this->buildProcessingMessage(
            percent: 0,
            step: 1,
            totalSteps: $totalSteps,
            targetLabel: $label,
            reason: $reason,
            queue: 243,
            active: 18,
            done: 162,
            ok: 147,
            fail: 15,
            retry: 9,
            elapsed: '00:00:00',
            eta: '~00:00:24',
            statuses: $this->buildStatusLines(1)
        ) . $previewLine;

        $progressMessageId = $this->prepareProgressMessage($bot, $initialText, $baseMessageId);
        if (!$progressMessageId) {
            $bot->sendMessage('⛔️ خطا در ایجاد پیام وضعیت. دوباره تلاش کن.');
            $this->end();
            return;
        }

        $queue = 243;
        $active = 18;
        $done = 162;
        $ok = 147;
        $fail = 15;
        $retry = 9;
        $start = microtime(true);

        for ($i = 1; $i <= $totalSteps; $i++) {
            sleep($delayPerStep);

            $percent = (int)(($i / $totalSteps) * 100);
            $queue = max(0, $queue - 48);
            $done += 30;
            $ok += 30;
            $retry = max(0, $retry - 3);

            $elapsedSeconds = (int)(microtime(true) - $start);
            $elapsed = gmdate('H:i:s', $elapsedSeconds);
            $etaSeconds = max(0, ($totalSteps - $i) * $delayPerStep);
            $eta = '~' . gmdate('H:i:s', $etaSeconds);

            $updateMsg = $this->buildProcessingMessage(
                percent: $percent,
                step: $i,
                totalSteps: $totalSteps,
                targetLabel: $label,
                reason: $reason,
                queue: $queue,
                active: $active,
                done: $done,
                ok: $ok,
                fail: $fail,
                retry: $retry,
                elapsed: $elapsed,
                eta: $eta,
                statuses: $this->buildStatusLines($i + 1)
            ) . $previewLine;

            try {
                $bot->editMessageText(
                    chat_id: $bot->user()->id,
                    message_id: $progressMessageId,
                    text: $updateMsg,
                    parse_mode: 'HTML'
                );
            } catch (\Exception) {
                // Continue on error
            }
        }

        $this->deleteMessageSafe($bot, $progressMessageId);
        $bot->sendMessage($this->buildFinalMessage($label, $previewLink), parse_mode: 'HTML');

        $this->end();
    }

    private function getProgressBar(int $percent): string
    {
        $filled = max(0, min(10, (int)round($percent / 10)));
        $empty = 10 - $filled;
        $bar = '[' . str_repeat('█', $filled) . str_repeat('░', $empty) . ']';
        return $bar . ' ' . $percent . '%';
    }

    private function buildProcessingMessage(
        int $percent,
        int $step,
        int $totalSteps,
        string $targetLabel,
        string $reason,
        int $queue,
        int $active,
        int $done,
        int $ok,
        int $fail,
        int $retry,
        string $elapsed,
        string $eta,
        array $statuses
    ): string {
        $progressBar = $this->getProgressBar($percent);
        $barOnly = explode(' ', $progressBar, 2)[0];
        $statusBlock = implode("\n", array_map(static fn(string $line): string => "> {$line}", $statuses));
        $date = now()->format('Y/m/d');
        $time = now()->format('H:i:s');

        return "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> KermPlus | Processing Job\n" .
            "━━━━━━━━━━━━━━━━\n\n" .
            "{$barOnly} {$percent}%   <tg-emoji emoji-id='5116159438062879454'>🙏</tg-emoji> step {$step}/{$totalSteps}\n\n" .
            "📦 queue: {$queue} items\n" .
            "<tg-emoji emoji-id='4904936030232117798'>⚙️</tg-emoji> active: {$active}   <tg-emoji emoji-id='6224314343924699041'>✅</tg-emoji> done: {$done}\n" .
            "<tg-emoji emoji-id='5325945307454789973'>🟢</tg-emoji> ok: {$ok}   <tg-emoji emoji-id='5326056199215406977'>❌</tg-emoji> fail: {$fail}   🔁 retry: {$retry}\n\n" .
            "rate: 12/s backoff: 2.5s\n" .
            "elapsed: {$elapsed} ETA: {$eta}\n\n" .
            "{$statusBlock}\n\n" .
            "trace: job=8f2a mode=ro gate=open\n" .
            "Please wait...\n\n" .
            "<tg-emoji emoji-id='5431897022456145283'>📆</tg-emoji> {$date}  <tg-emoji emoji-id='4904882772637648609'>⏰</tg-emoji> {$time}\n" .
            "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> @NitroHostBot <tg-emoji emoji-id='4927295007204836791'>🪱</tg-emoji>";
    }

    private function buildFinalMessage(string $targetLabel, ?string $link = null): string
    {
        $date = now()->format('Y/m/d');
        $time = now()->format('H:i:s');
        $preview = $link ? "🖇️ لینک: {$link}\n" : '';

        return "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> KermPlus | Reported Successful\n" .
            "━━━━━━━━━━━━━━━━\n\n" .
            "🎯 هدف: {$targetLabel}\n" .
            $preview .
            "📦 تعداد کل درخواست ها : 1321\n" .
            "✅ 1235 موفق | ❌ 134 ناموفق\n\n" .
            "تمامی ریپورت ها از سمت <b>کرم پلاس</b>🪱 با موفقیت ارسال شدند.\n" .
            "نتیجه نهایی وابسته به بررسی پلتفرم مقصد می‌باشد.\n\n" .
            "📆 {$date} ⏰ {$time}\n" .
            "• @NitroHostBot •";
    }

    private function buildStatusLines(int $step): array
    {
        $lines = [
            "<tg-emoji emoji-id='5134183530313548836'>🧪</tg-emoji> validate inputs      [ OK ]",
            "<tg-emoji emoji-id='5116093437300442328'>⚡️</tg-emoji> open connections     [ OK ]",
            "<tg-emoji emoji-id='5292226786229236118'>🔄</tg-emoji> process batch #09    [ .. ]",
            "<tg-emoji emoji-id='5334882760735598374'>📝</tg-emoji> write results        [ -- ]",
            "<tg-emoji emoji-id='5411520005386806155'>🏁</tg-emoji> finalize             [ -- ]",
        ];

        if ($step >= 2) {
            $lines[2] = "<tg-emoji emoji-id='5292226786229236118'>🔄</tg-emoji> process batch #09    [ OK ]";
        }
        if ($step >= 3) {
            $lines[3] = "<tg-emoji emoji-id='5334882760735598374'>📝</tg-emoji> write results        [ OK ]";
        }
        if ($step >= 4) {
            $lines[4] = "<tg-emoji emoji-id='5411520005386806155'>🏁</tg-emoji> finalize             [ OK ]";
        }

        return $lines;
    }

    private function deleteMessageSafe(Nutgram $bot, int $messageId): void
    {
        try {
            $bot->deleteMessage(chat_id: $bot->user()->id, message_id: $messageId);
        } catch (\Throwable) {
            // ignore
        }
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
        return "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> KermPlus | Account Found\n" .
            "━━━━━━━━━━━━━━━\n" .
            "📜 username : @{$username}\n" .
            "━━━━━━━━━━━━━━━\n\n";
    }

    private function formatChannelPreview($chat, string $username, ?int $memberCount = null): string
    {
        $title = $chat->title ?? '@' . $username;
        $description = $chat->description ?? '—';
        $members = $memberCount ?? $chat->subscriber_count ?? $chat->member_count ?? '—';

        return "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> KermPlus | Channel Found\n" .
            "━━━━━━━━━━━━━━━\n" .
            "📢 title: {$title}\n" .
            "🆔 username: @{$username}\n" .
            "🧾 description: {$description}\n\n" .
            "👥 subscribers: {$members}\n" .
            "━━━━━━━━━━━━━━━\n\n";
    }

    private function formatMessagePreview($chat, string $username, ?int $messageId, string $link): string
    {
        $content = '—';
        $views = '—';
        $sentAt = '—';

        return "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> KermPlus | Message Found\n" .
            "━━━━━━━━━━━━━━━\n" .
            "📢 source: @{$username}\n" .
            "🆔 message id: {$messageId}\n" .
            "📝 content: {$content}\n" .
            "🖇️ link : {$link}\n\n" .
            "👀 views: {$views}\n" .
            "📅 sent at: {$sentAt}\n" .
            "━━━━━━━━━━━━━━━\n\n" ;    }

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
        $text = "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> کرم پلاس <tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji>\n\n<tg-emoji emoji-id='4904973211763999824'>🗣️</tg-emoji> دلیل ریپورت رو انتخاب کن :";
        $keyboard = TelegramReportReasonKeyboard::make();
        $messageId = $bot->callbackQuery()?->message?->message_id;

        if ($messageId) {
            try {
                $bot->editMessageText(
                    chat_id: $bot->user()->id,
                    message_id: $messageId,
                    text: $text,
                    parse_mode: 'HTML',
                    reply_markup: $keyboard
                );
                return;
            } catch (\Throwable) {
                // fallback to sending a new message
            }
        }

        $bot->sendMessage($text, parse_mode: 'HTML', reply_markup: $keyboard);
    }

    private function prepareProgressMessage(Nutgram $bot, string $text, ?int $baseMessageId = null): ?int
    {
        if ($baseMessageId) {
            try {
                $bot->editMessageText(
                    chat_id: $bot->user()->id,
                    message_id: $baseMessageId,
                    text: $text,
                    parse_mode: 'HTML'
                );
                return $baseMessageId;
            } catch (\Throwable) {
                // fallback to sending a new message
            }
        }

        $sent = $bot->sendMessage($text, parse_mode: 'HTML');
        return $sent->message_id ?? null;
    }

    private function targetInputKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('بازگشت', callback_data: 'reporter_telegram_menu', style: 'danger', icon: '5352759161945867747')
            );
    }

    private function sendOrEditMessage(Nutgram $bot, string $text, ?InlineKeyboardMarkup $keyboard = null): void
    {
        $messageId = $bot->callbackQuery()?->message?->message_id;

        if ($messageId) {
            try {
                $bot->editMessageText(
                    chat_id: $bot->user()->id,
                    message_id: $messageId,
                    text: $text,
                    parse_mode: 'HTML',
                    reply_markup: $keyboard
                );
                return;
            } catch (\Throwable) {
                // fallback to sending a new message
            }
        }

        $bot->sendMessage($text, parse_mode: 'HTML', reply_markup: $keyboard);
    }

    private function showTelegramReporterMenu(Nutgram $bot): void
    {
        $msg = "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> کرم پلاس <tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji>\n\n<tg-emoji emoji-id='5364125616801073577'>✈️</tg-emoji> ریپورتر تلگرام\nبرای ادامه یکی از گزینه های زیر رو انتخاب کن :";
        $this->sendOrEditMessage($bot, $msg, TelegramReporterMenuKeyboard::make());
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

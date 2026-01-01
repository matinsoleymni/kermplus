<?php

namespace App\Telegram\Conversations;

use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use App\Models\User;
use App\Models\UsageRecord;
use App\Services\ChannelReactionService;
use App\Services\FeatureLimitService;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class ReactionManagerConversation extends Conversation
{
    protected ?string $testLink = null;

    protected function getLocalUserByTelegram(Nutgram $bot): ?User
    {
        $tgUser = $bot->callbackQuery()?->from ?? $bot->user();
        if (!$tgUser) {
            return null;
        }

        return User::where('telegram_id', $tgUser->id)->first();
    }

    protected function ensureAdmin(Nutgram $bot): ?User
    {
        $local = $this->getLocalUserByTelegram($bot);
        if (!$local || !$local->isAdmin()) {
            $bot->sendMessage('⛔️ دسترسی ندارید. این بخش فقط برای ادمین‌هاست.');
            $this->end();
            return null;
        }

        return $local;
    }

    public function start(Nutgram $bot)
    {
        if (!$this->ensureAdmin($bot)) {
            return;
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('📡 وضعیت سرویس', callback_data: 'reaction_status'),
                InlineKeyboardButton::make('➕ افزودن کانال‌ها', callback_data: 'reaction_add_channels')
            )
            ->addRow(
                InlineKeyboardButton::make('⚡️ تست ری‌اکشن', callback_data: 'reaction_test'),
                InlineKeyboardButton::make('📈 آمار استفاده', callback_data: 'reaction_usage_stats')
            )
            ->addRow(
                InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'admin_panel')
            );

        $bot->sendMessage('💬 مدیریت ری‌اکشن‌ها — یک گزینه انتخاب کنید:', reply_markup: $keyboard);
        $this->next('handleMenu');
    }

    public function handleMenu(Nutgram $bot)
    {
        if (!$this->ensureAdmin($bot)) {
            return;
        }

        $data = $bot->callbackQuery()?->data;
        switch ($data) {
            case 'reaction_status':
                $this->showStatus($bot);
                $this->start($bot);
                return;
            case 'reaction_add_channels':
                $bot->sendMessage("📥 لینک کانال‌ها را ارسال کن (هر خط یک لینک یا @username):");
                $this->next('receiveChannels');
                return;
            case 'reaction_test':
                $bot->sendMessage('🔗 لینک پست کانال را بفرست (مثال: https://t.me/channel/123):');
                $this->next('receiveTestLink');
                return;
            case 'reaction_usage_stats':
                $this->showUsageStats($bot);
                $this->start($bot);
                return;
            case 'admin_panel':
                AdminPanelConversation::begin($bot);
                $this->end();
                return;
            default:
                $bot->sendMessage('❌ گزینه نامعتبر.');
                $this->start($bot);
        }
    }

    public function receiveChannels(Nutgram $bot)
    {
        if (!$this->ensureAdmin($bot)) {
            return;
        }

        $text = trim((string)$bot->message()?->text);
        $parts = array_filter(array_map('trim', preg_split('/[\r\n\s]+/', $text)));
        $links = [];
        foreach ($parts as $raw) {
            $normalized = $this->normalizeChannelLink($raw);
            if ($normalized !== null) {
                $links[] = $normalized;
            }
        }

        if (empty($links)) {
            $bot->sendMessage('❌ لینکی تشخیص داده نشد. دوباره ارسال کنید:');
            $this->next('receiveChannels');
            return;
        }

        $service = app(ChannelReactionService::class);
        $result = $service->addChannels($links);
        if (isset($result['error'])) {
            $msg = "⚠️ {$result['error']}";
            if (isset($result['status'])) {
                $msg .= "\nکد: {$result['status']}";
            }
            if (!empty($result['body'])) {
                $body = is_array($result['body']) ? json_encode($result['body']) : (string)$result['body'];
                $msg .= "\nجزییات: {$body}";
            }
            $bot->sendMessage($msg);
        } else {
            $registered = $result['registered'] ?? count($links);
            $bot->sendMessage("✅ {$registered} کانال ثبت شد.");
        }

        $this->start($bot);
    }

    public function receiveTestLink(Nutgram $bot)
    {
        if (!$this->ensureAdmin($bot)) {
            return;
        }

        $link = trim((string)$bot->message()?->text);
        if (!$this->isValidPostLink($link)) {
            $bot->sendMessage('❌ لینک نامعتبر است. نمونه: https://t.me/channel/123');
            $this->next('receiveTestLink');
            return;
        }

        $this->testLink = $link;

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('🎲 انتخاب خودکار', callback_data: 'reaction_test_skip_emoji'),
                InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'admin_panel')
            );
        $bot->sendMessage('😈 ایموجی ری‌اکشن را بفرست (یا «انتخاب خودکار» را بزن):', reply_markup: $keyboard);
        $this->next('receiveTestEmoji');
    }

    public function receiveTestEmoji(Nutgram $bot)
    {
        $admin = $this->ensureAdmin($bot);
        if (!$admin) {
            return;
        }

        if (!$this->testLink) {
            $bot->sendMessage('⛔️ لینک تست در دسترس نیست. دوباره تلاش کنید.');
            $this->start($bot);
            return;
        }

        $emoji = null;
        $data = $bot->callbackQuery()?->data;

        if ($data === 'admin_panel') {
            AdminPanelConversation::begin($bot);
            $this->end();
            return;
        }

        if ($data === 'reaction_test_skip_emoji') {
            $bot->answerCallbackQuery(text: 'با اولین ری‌اکشن مجاز ادامه دادیم.');
        } else {
            $emojiText = trim((string)$bot->message()?->text);
            if ($emojiText === '') {
                $bot->sendMessage('❌ ایموجی نامعتبر است. دوباره ارسال کنید یا «انتخاب خودکار» را بزنید.');
                $this->next('receiveTestEmoji');
                return;
            }
            $emoji = $emojiText;
        }

        $service = app(ChannelReactionService::class);
        $result = $service->sendReaction($admin, $this->testLink, $emoji ?: null);

        if (isset($result['error'])) {
            $msg = "⚠️ {$result['error']}";
            if (isset($result['status'])) {
                $msg .= "\nکد: {$result['status']}";
            }
            if (!empty($result['body'])) {
                $body = is_array($result['body']) ? json_encode($result['body']) : (string)$result['body'];
                $msg .= "\nجزییات: {$body}";
            }
            $bot->sendMessage($msg);
            $this->start($bot);
            return;
        }

        $sent = (int)($result['sent'] ?? 0);
        $usedReaction = $result['used_reaction'] ?? ($emoji ?: '—');
        $available = $result['available_reactions'] ?? [];
        $errors = $result['errors'] ?? [];

        $message = "✅ تست ری‌اکشن انجام شد.\n";
        $message .= "🔗 لینک: {$this->testLink}\n";
        $message .= "😈 ری‌اکشن استفاده‌شده: {$usedReaction}\n";
        $message .= "📤 تعداد ارسال‌شده: {$sent}\n";

        if (!empty($available)) {
            $message .= "👌 ری‌اکشن‌های مجاز: " . implode(' ', $available) . "\n";
        }

        if (!empty($errors)) {
            $message .= "⚠️ خطاها:\n- " . implode("\n- ", $errors);
        }

        $bot->sendMessage($message);
        $this->start($bot);
    }

    protected function showStatus(Nutgram $bot): void
    {
        $url = (string) config('services.channel_reaction.url');
        $token = (string) config('services.channel_reaction.token');
        $limitPerDay = 5; // defined in FeatureLimitService::checkNegativeReactionLimit

        $msg = "📡 وضعیت سرویس ری‌اکشن:\n";
        $msg .= "• URL: " . ($url ?: 'تعریف نشده') . "\n";
        $msg .= "• Token: " . ($token ? '✅ تنظیم شده' : '⚠️ تنظیم نشده') . "\n";
        $msg .= "• محدودیت روزانه هر کاربر: {$limitPerDay} ری‌اکشن منفی\n";
        $msg .= "• Endpoint ثبت کانال: /channels\n";
        $msg .= "• Endpoint ارسال ری‌اکشن: /reactions\n";

        $bot->sendMessage($msg);
    }

    protected function showUsageStats(Nutgram $bot): void
    {
        $today = UsageRecord::query()
            ->where('type', FeatureLimitService::TYPE_NEGATIVE_REACTION)
            ->whereDate('created_at', now()->toDateString())
            ->sum('count');

        $last7 = UsageRecord::query()
            ->where('type', FeatureLimitService::TYPE_NEGATIVE_REACTION)
            ->where('created_at', '>=', now()->subDays(7))
            ->sum('count');

        $all = UsageRecord::query()
            ->where('type', FeatureLimitService::TYPE_NEGATIVE_REACTION)
            ->sum('count');

        $topUsers = UsageRecord::query()
            ->with('user')
            ->where('type', FeatureLimitService::TYPE_NEGATIVE_REACTION)
            ->whereDate('created_at', now()->toDateString())
            ->selectRaw('user_id, SUM(count) as total')
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->limit(5)
            ->get();

        $msg = "📈 آمار استفاده از ری‌اکشن:\n";
        $msg .= "• امروز: {$today}\n";
        $msg .= "• ۷ روز اخیر: {$last7}\n";
        $msg .= "• کل: {$all}\n";

        if ($topUsers->isNotEmpty()) {
            $msg .= "\n🥇 بیشترین استفاده‌کننده‌های امروز:\n";
            foreach ($topUsers as $index => $row) {
                $name = $row->user?->name ?? ('User #' . $row->user_id);
                $msg .= ($index + 1) . ". {$name} — {$row->total}\n";
            }
        }

        $bot->sendMessage($msg);
    }

    protected function normalizeChannelLink(string $link): ?string
    {
        $link = trim($link);
        if ($link === '') {
            return null;
        }

        if (str_starts_with($link, '@')) {
            return 'https://t.me/' . ltrim($link, '@');
        }

        if (preg_match('#^(https?://)?(t\.me|telegram\.me)/[^/]+$#', $link)) {
            if (!str_starts_with($link, 'http')) {
                return 'https://' . $link;
            }
            return $link;
        }

        return null;
    }

    protected function isValidPostLink(?string $link): bool
    {
        if (!$link) {
            return false;
        }

        return (bool)preg_match('#^(https?://)?(t\.me|telegram\.me)/[^/]+/\\d+#', $link);
    }
}

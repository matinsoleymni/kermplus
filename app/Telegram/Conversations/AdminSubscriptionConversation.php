<?php

namespace App\Telegram\Conversations;

use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Telegram\Support\CallbackQueryResponder;
use Carbon\Carbon;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class AdminSubscriptionConversation extends Conversation
{
    protected function getLocalUserByTelegram(Nutgram $bot): ?User
    {
        $tgUser = $bot->callbackQuery()?->from ?? $bot->user();
        if (!$tgUser) {
            return null;
        }

        return User::where('telegram_id', $tgUser->id)->first();
    }

    public function start(Nutgram $bot)
    {
        $local = $this->getLocalUserByTelegram($bot);
        if (!$local || !$local->isAdmin()) {
            $bot->sendMessage('⛔️ دسترسی ندارید. این بخش فقط برای ادمین‌هاست.');
            $this->end();
            return;
        }

        $this->showSearchPrompt($bot);
    }

    public function handleInput(Nutgram $bot)
    {
        $local = $this->getLocalUserByTelegram($bot);
        if (!$local || !$local->isAdmin()) {
            $bot->sendMessage('⛔️ دسترسی ندارید.');
            $this->end();
            return;
        }

        $data = $bot->callbackQuery()?->data;
        CallbackQueryResponder::ack($bot);

        if ($data === 'admin_panel') {
            AdminPanelConversation::begin($bot);
            $this->end();
            return;
        }

        if ($data === 'admin_user_search') {
            $this->showSearchPrompt($bot);
            return;
        }

        if ($data && preg_match('/^admin_user_pick:(\d+)$/', $data, $m)) {
            $user = User::find((int) $m[1]);
            if (!$user) {
                $bot->sendMessage('کاربر یافت نشد.');
                $this->showSearchPrompt($bot);
                return;
            }

            $this->showUserDetails($bot, $user);
            return;
        }

        if ($data && preg_match('/^admin_user_action:(toggle_ban|set_pro|set_plus|remove_sub|refresh):(\d+)$/', $data, $m)) {
            $action = $m[1];
            $user = User::find((int) $m[2]);
            if (!$user) {
                $bot->sendMessage('کاربر یافت نشد.');
                $this->showSearchPrompt($bot);
                return;
            }

            $service = app(SubscriptionService::class);
            $result = $this->performAction($action, $user, $local->id, $service);
            if (!empty($result['notify_message'])) {
                $notified = $this->notifyUser($bot, $user, $result['notify_message']);
                $result['admin_message'] .= $notified
                    ? "\n📩 کاربر مطلع شد."
                    : "\n⚠️ اطلاع‌رسانی به کاربر انجام نشد (تلگرام کاربر در دسترس نبود).";
            }

            if (!empty($result['admin_message'])) {
                $bot->sendMessage($result['admin_message']);
            }
            $this->showUserDetails($bot, $user->fresh() ?? $user);
            return;
        }

        $query = $this->normalizeSearchInput((string) $bot->message()?->text);
        if ($query === '') {
            $this->showSearchPrompt($bot, '🔎 لطفا شناسه کاربر، آیدی تلگرام، یوزرنیم، نام یا ایمیل را ارسال کنید.');
            return;
        }

        $users = $this->searchUsers($query);
        if ($users->isEmpty()) {
            $userByTelegramUsername = $this->resolveUserByTelegramUsername($bot, $query);
            if ($userByTelegramUsername) {
                $users = collect([$userByTelegramUsername]);
            }
        }

        if ($users->isEmpty()) {
            $this->showSearchPrompt($bot, '❌ کاربری با این عبارت پیدا نشد.');
            return;
        }

        if ($users->count() === 1) {
            $this->showUserDetails($bot, $users->first());
            return;
        }

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($users as $user) {
            $keyboard->addRow(
                InlineKeyboardButton::make($this->userLabel($user), callback_data: "admin_user_pick:{$user->id}", style: 'danger')
            );
        }
        $keyboard->addRow(InlineKeyboardButton::make('🔎 جستجوی جدید', callback_data: 'admin_user_search', style: 'danger'));
        $keyboard->addRow(InlineKeyboardButton::make('بازگشت', callback_data: 'admin_panel', style: 'danger', icon_custom_emoji_id: '5352759161945867747'));

        $bot->sendMessage("👥 {$users->count()} نتیجه پیدا شد. کاربر مورد نظر را انتخاب کنید:", reply_markup: $keyboard);
        $this->next('handleInput');
    }

    protected function showSearchPrompt(Nutgram $bot, ?string $prepend = null): void
    {
        $text = "🔎 جستجوی کاربر\n\n";
        if ($prepend) {
            $text .= $prepend . "\n\n";
        }
        $text .= "شناسه کاربر، آیدی تلگرام، یوزرنیم، نام یا ایمیل را ارسال کنید:";

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('بازگشت', callback_data: 'admin_panel', style: 'danger', icon_custom_emoji_id: '5352759161945867747'));

        $bot->sendMessage($text, reply_markup: $keyboard);
        $this->next('handleInput');
    }

    protected function showUserDetails(Nutgram $bot, User $user): void
    {
        $subscriptionService = app(SubscriptionService::class);
        $activeSub = $subscriptionService->getActiveSubscription($user);

        $paymentsQuery = SubscriptionPayment::query()->where('user_id', $user->id);
        $paidCount = (clone $paymentsQuery)->where('status', 'paid')->count();
        $pendingCount = (clone $paymentsQuery)->whereIn('status', ['pending', 'pre_checkout', 'waiting'])->count();
        $lastPayment = (clone $paymentsQuery)->latest()->first();

        $createdAt = $user->created_at?->format('Y-m-d H:i') ?? '—';
        $lastActive = $user->last_active_at?->format('Y-m-d H:i') ?? '—';
        $banStatus = $user->suspended ? '🚫 بن‌شده' : '✅ فعال';

        $text = "👤 اطلاعات کاربر\n\n";
        $text .= "🆔 ID: {$user->id}\n";
        $text .= "📱 Telegram ID: " . ($user->telegram_id ?: '—') . "\n";
        $text .= "🔖 Username: " . ($user->telegram_username ? '@' . $user->telegram_username : '—') . "\n";
        $text .= "🏷 نام: " . ($user->name ?: '—') . "\n";
        $text .= "🛡 نقش: {$user->role}\n";
        $text .= "📌 وضعیت: {$banStatus}\n";
        $text .= "🗓 ثبت‌نام: {$createdAt}\n";
        $text .= "⏱ آخرین فعالیت: {$lastActive}\n";
        $text .= "🎁 معرفی‌کننده: " . ($user->referred_by ?: '—') . "\n";
        $text .= "📨 SMS رایگان استفاده‌شده: " . ($user->free_sms_used ? 'بله' : 'خیر') . "\n";
        $text .= "📧 Email رایگان استفاده‌شده: " . ($user->free_email_used ? 'بله' : 'خیر') . "\n\n";

        if ($activeSub) {
            $expiresAt = $activeSub->expires_at?->format('Y-m-d H:i') ?? 'نامحدود';
            $remaining = $activeSub->expires_at ? "{$activeSub->getRemainingDays()} روز" : 'نامحدود';
            $text .= "💳 اشتراک فعال: {$activeSub->plan->name}\n";
            $text .= "📅 شروع: " . ($activeSub->started_at?->format('Y-m-d H:i') ?? '—') . "\n";
            $text .= "⌛️ پایان: {$expiresAt}\n";
            $text .= "⏳ باقی‌مانده: {$remaining}\n\n";
        } else {
            $text .= "💳 اشتراک فعال: ندارد\n\n";
        }

        $text .= "💰 پرداخت‌ها: Paid={$paidCount} | Pending={$pendingCount}\n";
        if ($lastPayment) {
            $text .= "🧾 آخرین پرداخت: "
                . number_format((float) $lastPayment->price_amount, 2)
                . ' '
                . strtoupper((string) $lastPayment->price_currency)
                . " ({$lastPayment->status})\n";
        }

        $banButton = $user->suspended ? '✅ رفع بن' : '🚫 بن';
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make($banButton, callback_data: "admin_user_action:toggle_ban:{$user->id}", style: 'danger'),
                InlineKeyboardButton::make('🔄 رفرش', callback_data: "admin_user_action:refresh:{$user->id}", style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('🎫 اعمال Pro', callback_data: "admin_user_action:set_pro:{$user->id}", style: 'danger'),
                InlineKeyboardButton::make("🪱 اعمال Plus", callback_data: "admin_user_action:set_plus:{$user->id}", style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('🗑 حذف اشتراک', callback_data: "admin_user_action:remove_sub:{$user->id}", style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('🔎 جستجوی جدید', callback_data: 'admin_user_search', style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('بازگشت', callback_data: 'admin_panel', style: 'danger', icon_custom_emoji_id: '5352759161945867747')
            );

        $bot->sendMessage($text, reply_markup: $keyboard);
        $this->next('handleInput');
    }

    /**
     * @return \Illuminate\Support\Collection<int,User>
     */
    protected function searchUsers(string $query)
    {
        $needle = $this->normalizeSearchInput($query);
        if ($needle === '') {
            return collect();
        }

        $normalizedUsername = $this->extractUsernameFromSearch($needle);
        $isNumeric = is_numeric($needle);

        return User::query()
            ->where(function ($q) use ($needle, $normalizedUsername, $isNumeric): void {
                if ($isNumeric) {
                    $q->orWhere('id', (int) $needle)
                        ->orWhere('telegram_id', (int) $needle);
                }

                $q->orWhere('name', 'like', '%' . $needle . '%')
                    ->orWhere('email', 'like', '%' . $needle . '%')
                    ->orWhere('referral_code', 'like', '%' . $needle . '%')
                    ->orWhere('telegram_username', 'like', '%' . $needle . '%');

                if ($normalizedUsername && $normalizedUsername !== $needle) {
                    $q->orWhere('name', 'like', '%' . $normalizedUsername . '%')
                        ->orWhere('email', 'like', '%' . $normalizedUsername . '%')
                        ->orWhere('referral_code', 'like', '%' . $normalizedUsername . '%')
                        ->orWhere('telegram_username', 'like', '%' . $normalizedUsername . '%');
                }
            })
            ->orderByDesc('id')
            ->limit(10)
            ->get();
    }

    protected function extractUsernameFromSearch(string $value): ?string
    {
        $username = $this->normalizeSearchInput($value);
        if ($username === '') {
            return null;
        }

        $username = preg_replace('#^https?://t\.me/#i', '', $username) ?? $username;
        $username = preg_replace('#^t\.me/#i', '', $username) ?? $username;
        $username = ltrim($username, '@/');
        $username = (string) strtok($username, '/?');
        $username = trim($username);

        if ($username === '' || !preg_match('/^[A-Za-z0-9_]{3,32}$/', $username)) {
            return null;
        }

        return mb_strtolower($username);
    }

    protected function normalizeSearchInput(string $value): string
    {
        // Remove invisible RTL/LTR direction markers and similar formatting chars.
        $value = preg_replace('/[\x{200C}\x{200D}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $value) ?? $value;

        return trim($value);
    }

    protected function resolveUserByTelegramUsername(Nutgram $bot, string $value): ?User
    {
        $username = $this->extractUsernameFromSearch($value);
        if (!$username) {
            return null;
        }

        try {
            $chat = $bot->getChat(chat_id: '@' . $username);
        } catch (\Throwable) {
            return null;
        }

        $chatId = $chat->id ?? null;
        if (!is_numeric($chatId)) {
            return null;
        }

        $telegramId = (int) $chatId;
        if ($telegramId <= 0) {
            return null;
        }

        return User::where('telegram_id', $telegramId)->first();
    }

    protected function userLabel(User $user): string
    {
        $name = trim((string) $user->name);
        if ($name === '') {
            $name = 'بدون‌نام';
        }

        if (mb_strlen($name) > 20) {
            $name = mb_substr($name, 0, 20) . '…';
        }

        return "{$name} | ID:{$user->id} | TG:" . ($user->telegram_id ?: '-');
    }

    /**
     * @return array{admin_message:string,notify_message:?string}
     */
    protected function performAction(string $action, User $user, int $adminId, SubscriptionService $service): array
    {
        return match ($action) {
            'toggle_ban' => $this->toggleBan($user),
            'set_pro' => $this->setUserPlan($user, 'pro', $adminId, $service),
            'set_plus' => $this->setUserPlan($user, 'plus', $adminId, $service),
            'remove_sub' => $this->removeSubscription($user, $adminId),
            'refresh' => [
                'admin_message' => '',
                'notify_message' => null,
            ],
            default => [
                'admin_message' => '✅ اطلاعات کاربر بروزرسانی شد.',
                'notify_message' => null,
            ],
        };
    }

    /**
     * @return array{admin_message:string,notify_message:string}
     */
    protected function toggleBan(User $user): array
    {
        $user->suspended = !$user->suspended;
        $user->save();

        if ($user->suspended) {
            return [
                'admin_message' => "✅ کاربر {$user->name} بن شد.",
                'notify_message' => "⛔️ حساب شما در ربات توسط ادمین بن شد.\nبرای پیگیری با پشتیبانی @kermsup پیام بدید.",
            ];
        }

        return [
            'admin_message' => "✅ بن کاربر {$user->name} برداشته شد.",
            // 'notify_message' => "✅ محدودیت حساب شما در ربات برداشته شد و دوباره می‌تونید استفاده کنید.",
        ];
    }

    /**
     * @return array{admin_message:string,notify_message:?string}
     */
    protected function setUserPlan(User $user, string $planName, int $adminId, SubscriptionService $service): array
    {
        $plan = SubscriptionPlan::query()
            ->whereRaw('LOWER(name) = ?', [strtolower($planName)])
            ->first();

        if (!$plan) {
            return [
                'admin_message' => "⛔️ پلن {$planName} در دیتابیس پیدا نشد.",
                'notify_message' => null,
            ];
        }

        $cancelled = $this->cancelActiveSubscriptions($user, $adminId);
        $service->createSubscription($user, $plan, null, $adminId);

        $msg = "✅ پلن {$plan->name} برای کاربر {$user->name} اعمال شد.";
        if ($cancelled > 0) {
            $msg .= "\n{$cancelled} اشتراک فعال قبلی غیرفعال شد.";
        }

        return [
            'admin_message' => $msg,
            'notify_message' => null,
        ];
    }

    /**
     * @return array{admin_message:string,notify_message:?string}
     */
    protected function removeSubscription(User $user, int $adminId): array
    {
        $cancelled = $this->cancelActiveSubscriptions($user, $adminId);
        if ($cancelled < 1) {
            return [
                'admin_message' => "ℹ️ کاربر {$user->name} اشتراک فعال ندارد.",
                'notify_message' => null,
            ];
        }

        return [
            'admin_message' => "✅ {$cancelled} اشتراک فعال کاربر {$user->name} حذف شد.",
            // 'notify_message' => '⛔️ اشتراک فعال شما توسط ادمین حذف شد.',
        ];
    }

    protected function cancelActiveSubscriptions(User $user, int $adminId): int
    {
        $subs = $user->subscriptions()
            ->where('is_active', true)
            ->where(function ($q): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now());
            })
            ->get();

        $count = 0;
        foreach ($subs as $sub) {
            if ($sub->cancel($adminId)) {
                $count++;
            }
        }

        return $count;
    }

    protected function notifyUser(Nutgram $bot, User $user, string $message): bool
    {
        if (!$user->telegram_id) {
            return false;
        }

        try {
            $bot->sendMessage($message, chat_id: (int) $user->telegram_id);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}

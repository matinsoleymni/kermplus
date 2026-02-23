<?php

namespace App\Telegram\Handlers;

use App\Models\User;
use App\Services\ReferralService;
use App\Services\SubscriptionService;
use App\Telegram\Keyboards\UserProfileKeyboard;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;

class UserProfileHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $tgUser = $bot->user();
        $local = $tgUser ? User::where('telegram_id', $tgUser->id)->first() : null;

        if (!$local) {
            $bot->sendMessage('❌ کاربر پیدا نشد.');
            return;
        }

        $service = app(SubscriptionService::class);
        $referralService = app(ReferralService::class);
        $subscription = $service->getActiveSubscription($local);
        $local = $referralService->ensureUserHasCode($local);
        $referralCount = $referralService->totalReferrals($local);
        $botUsername = ltrim((string) env('TELEGRAM_BOT_USERNAME', ''), '@');
        $link = $botUsername !== ''
            ? "https://t.me/{$botUsername}?start={$local->referral_code}"
            : 'لینک دعوت موجود نیست';

        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $name = $escape((string) $local->name);
        $username = $tgUser?->username ?? 'None';
        $username = $escape((string) $username);
        $membership = $subscription?->plan->name ?? 'Free';
        $membership = $escape((string) $membership);
        $link = $escape((string) $link);
        if (!$subscription) {
            $remaining = '0 Days';
        } elseif ($subscription->expires_at === null) {
            $remaining = 'Unlimited';
        } else {
            $remaining = number_format($subscription->getRemainingDays()) . ' Days';
        }
        $remaining = $escape($remaining);

        $msg = implode("\n", [
            '╭═ ✦ USER INFO ✦ ═╮',
            "│ <tg-emoji emoji-id=\"4904848288345228262\">👤</tg-emoji> Name : {$name}",
            "│ <tg-emoji emoji-id=\"5082413149873767213\">💙</tg-emoji> Username : {$username}",
            "│ <tg-emoji emoji-id=\"4915791289489818259\">✅</tg-emoji> ID : {$tgUser->id}",
            "│ <tg-emoji emoji-id=\"4913497231492908158\">👤</tg-emoji> Invites : " . number_format($referralCount),
            '│',
            "│ <tg-emoji emoji-id=\"5084974483685507801\">💜</tg-emoji> Membership : {$membership}",
            "│ <tg-emoji emoji-id=\"4904882772637648609\">⏰</tg-emoji> Remaining : {$remaining}",
            '│',
            '│ <tg-emoji emoji-id="4916086774649848789">🔗</tg-emoji> Invite Link :',
            "│ {$link}",
            '╰═ ✦ ✦ ✦ ═╯',
        ]);

        try {
            if ($bot->callbackQuery()?->message) {
                $bot->editMessageText($msg, reply_markup: UserProfileKeyboard::make($subscription !== null), parse_mode: 'HTML');
                return;
            }
        } catch (TelegramException $e) {
            if (!str_contains($e->getMessage(), 'message is not modified')) {
                throw $e;
            }

            $bot->answerCallbackQuery(text: 'اطلاعات حساب شما به‌روز است.');
            return;
        }

        $bot->sendMessage($msg, reply_markup: UserProfileKeyboard::make($subscription !== null), parse_mode: 'HTML');
    }
}

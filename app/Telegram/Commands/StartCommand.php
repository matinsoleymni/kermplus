<?php

namespace App\Telegram\Commands;

use App\Models\User;
use App\Services\ReferralNotificationService;
use App\Services\ReferralService;
use App\Services\SponsorJoinService;
use App\Services\SubscriptionService;
use App\Telegram\Keyboards\MainMenuKeyboard;
use Illuminate\Support\Str;
use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;

class StartCommand extends Command
{
    protected string $command = 'start';

    protected ?string $description = 'شروع ربات و نمایش منوی اصلی';

    public function handle(Nutgram $bot): void
    {
        // Always reset any pending conversation/step to avoid users getting stuck.
        $bot->setUserData('conversation', null);
        $bot->setUserData('step', null);

        $tgUser = $bot->user();
        if (!$tgUser) {
            $bot->sendMessage('⛔️ خطا در دریافت اطلاعات تلگرام.');
            return;
        }

        $messageText = $bot->message()?->text ?? '';
        $incomingReferralCode = $this->extractReferralCode($messageText);
        if ($incomingReferralCode !== null) {
            $bot->setUserData('pending_referral_code', $incomingReferralCode);
        }

        $pendingReferralCode = trim((string) ($bot->getUserData('pending_referral_code') ?? ''));
        $effectiveReferralCode = $incomingReferralCode ?? ($pendingReferralCode !== '' ? $pendingReferralCode : null);
        $telegramUsername = $this->normalizeTelegramUsername($tgUser->username ?? null);
        $displayName = trim($tgUser->first_name . ' ' . ($tgUser->last_name ?? ''));
        if ($displayName === '') {
            $displayName = 'Telegram User';
        }

        $referralService = app(ReferralService::class);
        $local = User::where('telegram_id', $tgUser->id)->first();
        if (!$local) {
            $email = $telegramUsername
                ? ($telegramUsername . '@telegram.local')
                : ('tg_' . $tgUser->id . '@telegram.local');

            $local = User::create([
                'telegram_id' => $tgUser->id,
                'telegram_username' => $telegramUsername,
                'name' => $displayName,
                'email' => $email,
                'password' => bcrypt(Str::random(32)),
                'referral_code' => $referralService->generateUniqueCode(),
                'referred_by' => null,
            ]);
        }

        if (!$local->referral_code) {
            $local->referral_code = $referralService->generateUniqueCode();
        }

        $local->name = $displayName;
        $local->telegram_username = $telegramUsername;
        $local->last_active_at = now();
        $local->save();

        if ($local->isSuspended()) {
            $bot->sendMessage('⛔️ حساب شما موقتا معلق شده است. برای رفع مشکل به @kermsup پیام بدید.');
            return;
        }

        if (!app(SponsorJoinService::class)->enforce($bot)) {
            return;
        }

        $this->assignReferralAfterSponsorJoin($bot, $local, $effectiveReferralCode);

        $hasActiveSubscription = app(SubscriptionService::class)->hasActiveSubscription($local);

        $bot->sendMessage(
            "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> <b>کرم پلاس</b> <tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji>\n\nاگه کسی اذیتت کرده ...\nبا ربات ما توهم می‌تونی حسابی اذیتش کنی :)\n\n<tg-emoji emoji-id='4927295007204836791'>🪱</tg-emoji> برای شروع یکی از گزینه‌های زیر رو انتخاب کن:",
            parse_mode: 'HTML',
            reply_markup: MainMenuKeyboard::make($hasActiveSubscription)
        );
    }

    private function assignReferralAfterSponsorJoin(Nutgram $bot, User $local, ?string $referralCode): void
    {
        if ($local->referred_by) {
            $bot->setUserData('pending_referral_code', null);
            return;
        }

        $referralCode = trim((string) $referralCode);
        if ($referralCode === '') {
            return;
        }

        $referrer = User::where('referral_code', $referralCode)->first();
        if (!$referrer || $referrer->id === $local->id) {
            $bot->setUserData('pending_referral_code', null);
            return;
        }

        $local->referred_by = $referrer->id;
        $local->save();
        $bot->setUserData('pending_referral_code', null);

        app(ReferralNotificationService::class)->notifyReferrerAboutInvite($bot, $referrer, $local);
    }

    private function extractReferralCode(string $messageText): ?string
    {
        $text = trim($messageText);
        if ($text === '' || !str_starts_with($text, '/start')) {
            return null;
        }

        // Formats we accept:
        // /start CODE
        // /start@BotName CODE
        // /start ref=CODE
        // /start ?ref=CODE
        $payload = (string) preg_replace('/^\/start(?:@\w+)?/u', '', $text, 1);
        $payload = trim($payload);
        if ($payload === '') {
            return null;
        }

        $payload = ltrim($payload, '?');
        $payload = urldecode($payload);

        if (str_starts_with($payload, 'ref=')) {
            $payload = substr($payload, 4);
        }

        $payload = trim($payload);

        if ($payload === '') {
            return null;
        }

        if (!preg_match('/^[A-Za-z0-9_-]{4,32}$/', $payload)) {
            return null;
        }

        return Str::upper($payload);
    }

    private function normalizeTelegramUsername(?string $username): ?string
    {
        $username = trim((string) $username);
        if ($username === '') {
            return null;
        }

        $username = ltrim($username, '@');
        if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $username)) {
            return null;
        }

        return mb_strtolower($username);
    }
}

<?php

namespace App\Telegram\Middleware;

use App\Models\User;
use App\Services\ReferralNotificationService;
use App\Services\SponsorJoinService;
use SergiX44\Nutgram\Nutgram;

class EnsureSponsorJoinMiddleware
{
    public function __construct(private SponsorJoinService $sponsorJoinService)
    {
    }

    public function __invoke(Nutgram $bot, $next): void
    {
        if ($this->shouldSkip($bot)) {
            $next($bot);
            return;
        }

        if (!$this->sponsorJoinService->enforce($bot)) {
            return;
        }

        $this->applyPendingReferralIfPossible($bot);
        $next($bot);
    }

    private function shouldSkip(Nutgram $bot): bool
    {
        $text = trim((string) ($bot->message()?->text ?? ''));

        return $text !== '' && str_starts_with($text, '/start');
    }

    private function applyPendingReferralIfPossible(Nutgram $bot): void
    {
        $tgUserId = $bot->user()?->id;
        if (!$tgUserId) {
            return;
        }

        $local = User::query()->where('telegram_id', $tgUserId)->first();
        if (!$local || $local->referred_by) {
            return;
        }

        $pendingCode = trim((string) ($bot->getUserData('pending_referral_code') ?? ''));
        if ($pendingCode === '') {
            return;
        }

        $referrer = User::query()->where('referral_code', $pendingCode)->first();
        if (!$referrer || $referrer->id === $local->id) {
            $bot->setUserData('pending_referral_code', null);
            return;
        }

        $local->referred_by = $referrer->id;
        $local->save();
        $bot->setUserData('pending_referral_code', null);

        app(ReferralNotificationService::class)->notifyReferrerAboutInvite($bot, $referrer, $local);
    }
}

<?php

namespace App\Telegram\Handlers;

use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\ReferralService;
use App\Services\SubscriptionService;
use App\Telegram\Keyboards\ReferralKeyboard;
use Illuminate\Support\Facades\DB;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class ReferralHandler
{
    private const REFERRAL_REWARD_DURATION_DAYS = 30;

    public function __invoke(Nutgram $bot): void
    {
        $tgUser = $bot->user();
        $local = $tgUser ? User::where('telegram_id', $tgUser->id)->first() : null;

        if (!$local) {
            $bot->sendMessage('❌ کاربر پیدا نشد.');
            return;
        }

        $data = $bot->callbackQuery()?->data ?? '';
        if ($data === 'referral_claim') {
            $this->claimReward($bot, $local);
            return;
        }

        $this->showReferralDashboard($bot, $local);
    }

    private function showReferralDashboard(Nutgram $bot, User $local): void
    {
        $referralService = app(ReferralService::class);
        $local = $referralService->ensureUserHasCode($local);

        $referralLink = $this->buildReferralLink($bot, $local->referral_code);
        $points = $referralService->totalReferrals($local);
        $threshold = $referralService->rewardThreshold();
        $availableRewards = $referralService->availableRewardCycles($local);
        $claimHint = $availableRewards > 0
            ? "\n\n❁ {$availableRewards} اشتراک هدیه آماده دریافت داری؛ از دکمه «دریافت اشتراک هدیه» استفاده کن."
            : '';
        $shareText = "🪱 کرم پلاس 🪱\n\nبا ربات زیر میتونی هرکی اذیتت کرده رو حسابی اذیتش کنی و ازش انتقام بگیری 👀\n\n• امکان پروندن پیج اینستای شخص\n• ریست کردن گوشی شخص\n• ارسال هزاران مزاحم تلفنی برای شخص\nو کلییی قابلیت خفن دیگه 😙\n\n{$referralLink}";

        $promoMessage = "🪱 کرم پلاس 🪱\n\n";
        $promoMessage .= "با ربات زیر میتونی هرکی اذیتت کرده رو حسابی اذیتش کنی و ازش انتقام بگیری 👀\n\n";
        $promoMessage .= "• امکان پروندن پیج اینستای شخص\n";
        $promoMessage .= "• ریست کردن گوشی شخص\n";
        $promoMessage .= "• ارسال هزاران مزاحم تلفنی برای شخص\n";
        $promoMessage .= "و کلییی قابلیت خفن دیگه 😙\n\n";
        $promoMessage .= "• {$referralLink} •\n\n";
        $promoMessage .= "کلی قراره به دردت بخوره ؛)";

        if ($this->shouldSendPromo($bot)) {
            $this->sendPromoMessage($bot, $promoMessage);
        }

        $msg = "❁ لینک اختصاصیت ساخته شد 🥰\n\n";
        $msg .= "{$referralLink}\n\n";
        $msg .= "• با دعوت هر یک نفر از دوستات میتونی 1 امتیاز دریافت کنی 😍\n";
        $msg .= "• میتونی با دعوت {$threshold} نفر از دوستات اشتراک پلاس رباتمون رو به صورت رایگان دریافت کنی 😉\n\n";
        $msg .= "❁ تا الان {$points} امتیاز داری ❁\n\n";
        $msg .= "برای این که دوستات رو به ربات دعوت کنی میتونی از لینک اختصاصیت استفاده کنی😗❕\n\n";
        $msg .= "🎈 همین الان ربات کرم‌پلاس رو با دوستانتون به‌اشتراک بگذارید تا امتیاز دریافت کنید!";
        $msg .= $claimHint;

        $this->replyWithEditPreferred(
            $bot,
            $msg,
            ReferralKeyboard::make($referralLink, $shareText, $availableRewards > 0)
        );
    }

    private function shouldSendPromo(Nutgram $bot): bool
    {
        $currentText = (string) ($bot->callbackQuery()?->message?->text ?? '');
        if ($currentText !== '' && str_contains($currentText, '❁ تا الان')) {
            return false;
        }

        return true;
    }

    private function sendPromoMessage(Nutgram $bot, string $promoMessage): void
    {
        $imagePath = public_path('images/bot-thumb.png');
        if (is_readable($imagePath)) {
            $bot->sendPhoto(
                photo: InputFile::make($imagePath, 'bot-thumb.png'),
                caption: $promoMessage
            );

            return;
        }

        $bot->sendMessage($promoMessage);
    }

    private function claimReward(Nutgram $bot, User $local): void
    {
        if ($bot->callbackQuery()) {
            $bot->answerCallbackQuery();
        }

        $referralService = app(ReferralService::class);
        $subscriptionService = app(SubscriptionService::class);

        $result = DB::transaction(function () use ($local, $referralService, $subscriptionService) {
            $lockedUser = User::query()->whereKey($local->id)->lockForUpdate()->first();
            if (!$lockedUser) {
                return ['ok' => false, 'message' => '❌ کاربر پیدا نشد.'];
            }

            if ($referralService->availableRewardCycles($lockedUser) < 1) {
                return ['ok' => false, 'message' => '⛔️ هنوز پاداش آماده دریافت ندارید.'];
            }

            if ($subscriptionService->hasActiveSubscription($lockedUser)) {
                return ['ok' => false, 'message' => '⛔️ برای دریافت هدیه رفرال، ابتدا باید اشتراک فعلی شما منقضی شود.'];
            }

            $rewardPlan = $this->resolveRewardPlan();
            if (!$rewardPlan) {
                return ['ok' => false, 'message' => '⛔️ پلن هدیه برای رفرال تنظیم نشده است.'];
            }

            $subscriptionService->createSubscription(
                $lockedUser,
                $rewardPlan,
                self::REFERRAL_REWARD_DURATION_DAYS,
                $lockedUser->id
            );
            $referralService->consumeOneRewardCycle($lockedUser);

            return ['ok' => true, 'message' => "✅ اشتراک هدیه «{$rewardPlan->name}» با موفقیت برای شما فعال شد."];
        });

        $bot->sendMessage($result['message']);
        $this->showReferralDashboard($bot, $local->fresh() ?? $local);
    }

    private function buildReferralLink(Nutgram $bot, string $referralCode): string
    {
        $botUsername = $bot->getMe()?->username ?? env('TELEGRAM_BOT_USERNAME');
        if ($botUsername) {
            $botUsername = ltrim($botUsername, '@');
            return "https://t.me/{$botUsername}?start={$referralCode}";
        }

        return "/start {$referralCode}";
    }

    private function resolveRewardPlan(): ?SubscriptionPlan
    {
        $planId = config('services.referral.reward_plan_id');
        if ($planId) {
            return SubscriptionPlan::query()
                ->where('is_active', true)
                ->find((int) $planId);
        }

        return SubscriptionPlan::query()
            ->where('is_active', true)
            ->orderBy('price')
            ->orderByDesc('duration_days')
            ->first();
    }

    private function replyWithEditPreferred(Nutgram $bot, string $text, InlineKeyboardMarkup $keyboard): void
    {
        try {
            if ($bot->callbackQuery()?->message) {
                $bot->editMessageText($text, reply_markup: $keyboard);
                return;
            }
        } catch (TelegramException $e) {
            if (!str_contains($e->getMessage(), 'message is not modified')) {
                throw $e;
            }

            // Prevent Telegram 400 from breaking the whole update.
            $bot->answerCallbackQuery(text: 'اطلاعات همین الان هم به‌روز است.');
            return;
        }

        $bot->sendMessage($text, reply_markup: $keyboard);
    }
}

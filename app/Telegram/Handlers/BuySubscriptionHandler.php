<?php

namespace App\Telegram\Handlers;

use App\Models\SubscriptionPlan;
use Illuminate\Support\Collection;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class BuySubscriptionHandler
{
    public function __invoke(Nutgram $bot): void
    {
        /** @var Collection<int, SubscriptionPlan> $plans */
        $plans = SubscriptionPlan::query()
            ->where('is_active', true)
            ->orderByRaw('LOWER(name)')
            ->get()
            ->sortBy(function (SubscriptionPlan $plan): int {
                return match (mb_strtolower(trim((string) $plan->name))) {
                    'pro' => 0,
                    'plus' => 1,
                    default => 2,
                };
            })
            ->values();
        $data = $bot->callbackQuery()?->data ?? '';

        if ($plans->isEmpty()) {
            $bot->sendMessage('❌ هیچ پلن موجود نیست.');
            return;
        }

        if ($bot->callbackQuery()) {
            $bot->answerCallbackQuery();
        }

        if ($data === 'buy_sub_crypto') {
            $this->showPlansForMethod($bot, $plans, 'crypto');
            return;
        }

        if ($data === 'buy_sub_star') {
            $this->showPlansForMethod($bot, $plans, 'star');
            return;
        }

        if ($data === 'buy_sub_referral') {
            $this->showPlansForMethod($bot, $plans, 'referral');
            return;
        }

        $msg = "<tg-emoji emoji-id=\"4929619512224909015\">🪱</tg-emoji> به بخش ارتقای ربات کرم پلاس خوش اومدی\n\n";
        $msg .= "<tg-emoji emoji-id=\"4927295007204836791\">🪱</tg-emoji> ربات جذاب کرم پلاس تو دو نسخه پلاس<tg-emoji emoji-id=\"5433758796289685818\">👑</tg-emoji> و پرو<tg-emoji emoji-id=\"6244241334320762892\">💎</tg-emoji> عرضه میشه که میتونید ویژگی های هر نسخه رو این پایین بخونید.\n\n";
        $msg .= "<tg-emoji emoji-id=\"5433758796289685818\">👑</tg-emoji><a href='https://t.me/kermpluslearn/18'>ویژگی های نسخه پلاس </a><tg-emoji emoji-id=\"5433758796289685818\">👑</tg-emoji>\n";
        $msg .= "<tg-emoji emoji-id=\"6244241334320762892\">💎</tg-emoji><a href='https://t.me/kermpluslearn/19'>ویژگی های نسخه پرو </a><tg-emoji emoji-id=\"6244241334320762892\">💎</tg-emoji>\n\n";
        $msg .= "<tg-emoji emoji-id=\"5116648080787112958\">💰</tg-emoji> روش پرداخت: میتونی هر نسخه ای رو که خواستی با تومان ، ارز دیجیتال ( ton تون ، trx ترون ) یا حتی استارز تلگرام بخری\n\n";
        $msg .= "<tg-emoji emoji-id=\"4927295007204836791\">🪱</tg-emoji> روش مورد نظرتون رو برای ارتقا انتخاب کنید <tg-emoji emoji-id=\"5231102735817918643\">👇</tg-emoji>";

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('پرداخت کریپتویی (ارز ترون یا تون)', callback_data: 'buy_sub_crypto', style: 'danger', icon_custom_emoji_id: '5361656830944624968'))
            ->addRow(InlineKeyboardButton::make('پرداخت با استارز (واحد پول تلگرام)', callback_data: 'buy_sub_star', style: 'danger', icon_custom_emoji_id: '5958376256788502078'))
            // ->addRow(InlineKeyboardButton::make('پرداخت با زیر مجموعه', callback_data: 'buy_sub_referral', style: 'danger', icon_custom_emoji_id: '4913497231492908158'))
            ->addRow(InlineKeyboardButton::make('پرداخت تومانی (با کمی معطلی)', url: 'https://t.me/kermsup', style: 'danger', icon_custom_emoji_id: '5472250091332993630'))
            ->addRow(InlineKeyboardButton::make('بازگشت', callback_data: 'main_menu', style: 'danger', icon_custom_emoji_id: '5352759161945867747'));

        $bot->editMessageText($msg, reply_markup: $keyboard, parse_mode: 'HTML', disable_web_page_preview: true);
    }

    /**
     * @param iterable<int,\App\Models\SubscriptionPlan> $plans
     */
    private function showPlansForMethod(Nutgram $bot, iterable $plans, string $method): void
    {
        $msg = $this->methodSectionIntro($method) . "\n\n";
        $chunks = [];
        foreach ($plans as $plan) {
            $durationText = ($plan->duration_days ?? 0) > 0 ? "{$plan->duration_days} روز" : 'نامحدود';
            $chunks[] = $this->planSectionTitle($plan) . "\n"
                . $this->planValueLineByMethod($plan, $method) . "\n"
                . "⏱ مدت: {$durationText}";
        }

        $msg .= implode("\n\n", $chunks);

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($plans as $plan) {
            $callback = match ($method) {
                'crypto' => "pay_crypto_{$plan->id}",
                'star' => "pay_star_{$plan->id}",
                default => 'user_referral',
            };
            $keyboard->addRow(
                InlineKeyboardButton::make(
                    $this->planButtonLabel($plan),
                    callback_data: $callback,
                    style: 'danger',
                    icon_custom_emoji_id: $this->planButtonIcon($plan)
                )
            );
        }

        $keyboard->addRow(InlineKeyboardButton::make('بازگشت', callback_data: 'buy_subscription', style: 'danger', icon_custom_emoji_id: '5352759161945867747'));

        $bot->editMessageText($msg, reply_markup: $keyboard, parse_mode: 'HTML');
    }

    private function methodSectionIntro(string $method): string
    {
        $msg = "<tg-emoji emoji-id=\"4929619512224909015\">🪱</tg-emoji> نسخه پرو ربات کرم پلاس <tg-emoji emoji-id=\"4929619512224909015\">🪱</tg-emoji>\n\n";
        $msg .= "<tg-emoji emoji-id=\"5116093437300442328\">⚡️</tg-emoji> با پرداخت این مبلغ، شما به صورت کامل به برخی از قابلیت های ربات به طور دائمی دسترسی خواهید داشت و تمامی درخواست های شما با سرعت چندین برابری انجام خواهد شد.\n\n";

        return $msg . match ($method) {
            'crypto' => "<tg-emoji emoji-id=\"4927295007204836791\">🪱</tg-emoji> برای ارتقا به نسخه پلاس لطفا رمز ارز مدنظر خودتون رو انتخاب کنید <tg-emoji emoji-id=\"5231102735817918643\">👇</tg-emoji>",
            'star' => "<tg-emoji emoji-id=\"4927295007204836791\">🪱</tg-emoji> برای ارتقا به نسخه پلاس لطفا مقدار استارز مدنظر خودتون رو انتخاب کنید <tg-emoji emoji-id=\"5231102735817918643\">👇</tg-emoji>",
            default => "<tg-emoji emoji-id=\"4927295007204836791\">🪱</tg-emoji> برای ارتقا به نسخه پلاس لطفا مقدار امتیاز مدنظر خودتون رو انتخاب کنید <tg-emoji emoji-id=\"5231102735817918643\">👇</tg-emoji>",
        };
    }

    private function planValueLineByMethod(SubscriptionPlan $plan, string $method): string
    {
        if ($method === 'star') {
            return "⭐️ قیمت استاری: " . number_format((float) $plan->starsPrice(), 0) . " استار";
        }

        if ($method === 'referral') {
            return "🎯 امتیاز مورد نیاز: " . number_format((float) config('services.referral.reward_threshold', 20), 0) . " امتیاز";
        }

        return "💰 قیمت ارزی: " . number_format($plan->usdPrice(), 2, '.', '') . "$";
    }

    private function planButtonLabel(SubscriptionPlan $plan): string
    {
        $planName = mb_strtolower(trim((string) $plan->name));

        return match ($planName) {
            'pro' => 'ارتقا به نسخه پرو',
            'plus' => 'ارتقا به نسخه پلاس',
            default => "ارتقا به نسخه {$plan->name}",
        };
    }

    private function planButtonIcon(SubscriptionPlan $plan): ?string
    {
        $planName = mb_strtolower(trim((string) $plan->name));

        return match ($planName) {
            'pro' => '6244241334320762892',
            'plus' => '5433758796289685818',
            default => null,
        };
    }

    private function planSectionTitle(SubscriptionPlan $plan): string
    {
        $planName = mb_strtolower(trim((string) $plan->name));

        return match ($planName) {
            'pro' => "<tg-emoji emoji-id=\"6244241334320762892\">💎</tg-emoji> <b>اشتراک پرو</b> <tg-emoji emoji-id=\"6244241334320762892\">💎</tg-emoji>",
            'plus' => "<tg-emoji emoji-id=\"5433758796289685818\">👑</tg-emoji> <b>اشتراک پلاس</b> <tg-emoji emoji-id=\"5433758796289685818\">👑</tg-emoji>",
            default => "◾️ <b>{$plan->name}</b>",
        };
    }
}

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
            ->get();
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

        $msg = "❁ به بخش ارتقا به نسخه پلاس خوش اومدی 😉\n\n";
        $msg .= "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> قابلیت های جذاب نسخه پلاس ربات <b>کرم پلاس</b>:\n";
        $msg .= "- دسترسی کامل به تمامی قابلیت های ربات 😍\n";
        $msg .= "- انجام درخواست ها با سرعت چند برابری 😚\n";
        $msg .= "- دسترسی دائمی به تمامی آپدیت ها و قابلیت ها 🙃\n\n";
        $msg .= "💳 روش پرداخت: میتونی هر نسخه ای رو که خواستی با تومان، ارز دیجیتال (ton تون یا trx ترون) یا حتی استارز تلگرام بخری\n\n";
        $msg .= "📌 قیمت پلن‌ها:\n";
        foreach ($plans as $plan) {
            $msg .= "• {$plan->name}: {$this->formatUsd($plan->usdPrice())}$ | {$this->formatIrr($plan->irrPrice())} ریال | {$plan->starsPrice()} ⭐️\n";
        }
        $msg .= "\n";
        $msg .= "❁ روش مورد نظرتون رو برای ارتقا انتخاب کنید 👇";

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('پرداخت کریپتویی (ارز ترون یا تون)', callback_data: 'buy_sub_crypto', style: 'danger', icon: '5361656830944624968'))
            ->addRow(InlineKeyboardButton::make('پرداخت با استارز (واحد پول تلگرام)', callback_data: 'buy_sub_star', style: 'danger', icon: '5958376256788502078'))
            ->addRow(InlineKeyboardButton::make('پرداخت با زیر مجموعه', callback_data: 'user_referral', style: 'danger', icon: '4913497231492908158'))
            ->addRow(InlineKeyboardButton::make('پرداخت تومانی (با کمی معطلی)', url: 'https://t.me/kermsup', style: 'danger', icon: '5472250091332993630'))
            ->addRow(InlineKeyboardButton::make('بازگشت', callback_data: 'main_menu', style: 'danger', icon: '5352759161945867747'));

        $bot->editMessageText($msg, reply_markup: $keyboard, parse_mode: 'HTML');
    }

    /**
     * @param iterable<int,\App\Models\SubscriptionPlan> $plans
     */
    private function showPlansForMethod(Nutgram $bot, iterable $plans, string $method): void
    {
        $title = $method === 'crypto' ? '🪙 پرداخت کریپتویی (ترون یا تون)' : '⭐️ پرداخت با استارز تلگرام';
        $intro = $method === 'crypto'
            ? "روی پلن بزن تا فاکتور کریپتویی    ساخته بشه."
            : "روی پلن بزن تا پرداخت استار تلگرام برات باز بشه.";

        $msg = "{$title}\nپلن مورد نظرت رو انتخاب کن:\n\n";
        $chunks = [];
        foreach ($plans as $plan) {
            $usd = $this->formatUsd($plan->usdPrice());
            $stars = number_format((float) $plan->starsPrice(), 0);
            $irr = $this->formatIrr($plan->irrPrice());
            $durationText = ($plan->duration_days ?? 0) > 0 ? "{$plan->duration_days} روز" : 'نامحدود';

            $chunks[] = "◾️ **{$plan->name}**\n"
                . "   💰 {$usd}$ | {$irr} ریال | {$stars} استار\n"
                . "   ⏱ مدت: {$durationText}\n";
        }

        $msg .= implode("\n\n", $chunks) . "\n\n" . $intro;

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($plans as $plan) {
            $callback = $method === 'crypto'
                ? "pay_crypto_{$plan->id}"
                : "pay_star_{$plan->id}";
            $keyboard->addRow(InlineKeyboardButton::make("{$plan->name}", callback_data: $callback, style: 'danger'));
        }

        $keyboard->addRow(InlineKeyboardButton::make('بازگشت', callback_data: 'buy_subscription', style: 'danger', icon: '5352759161945867747'));

        $bot->editMessageText($msg, reply_markup: $keyboard, parse_mode: 'Markdown');
    }

    private function formatUsd(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function formatIrr(int $value): string
    {
        return number_format($value, 0, '.', ',');
    }
}

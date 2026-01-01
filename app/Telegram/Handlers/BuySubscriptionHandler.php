<?php

namespace App\Telegram\Handlers;

use App\Models\SubscriptionPlan;
use App\Services\TelegramStarService;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class BuySubscriptionHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $plans = SubscriptionPlan::where('is_active', true)->get();
        $starService = TelegramStarService::make();
        $data = $bot->callbackQuery()?->data ?? '';

        if ($plans->isEmpty()) {
            $bot->sendMessage('❌ هیچ پلن موجود نیست.');
            return;
        }

        if ($bot->callbackQuery()) {
            $bot->answerCallbackQuery();
        }

        if ($data === 'buy_sub_crypto') {
            $this->showPlansForMethod($bot, $plans, $starService, 'crypto');
            return;
        }

        if ($data === 'buy_sub_star') {
            $this->showPlansForMethod($bot, $plans, $starService, 'star');
            return;
        }

        $msg = "❁ به بخش ارتقا به نسخه پلاس خوش اومدی 😉\n\n";
        $msg .= "🎗 قابلیت های جذاب نسخه پلاس ربات کرم پلاس:\n";
        $msg .= "- دسترسی کامل به تمامی قابلیت های ربات 😍\n";
        $msg .= "- انجام درخواست ها با سرعت چند برابری 😚\n";
        $msg .= "- دسترسی دائمی به تمامی آپدیت ها و قابلیت ها 🙃\n\n";
        $msg .= "💳 روش پرداخت: میتونی هر نسخه ای رو که خواستی با تومان، ارز دیجیتال (ton تون یا trx ترون) یا حتی استارز تلگرام بخری\n\n";
        $msg .= "❁ روش مورد نظرتون رو برای ارتقا انتخاب کنید 👇";

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🪙 پرداخت کریپتویی (ارز ترون یا تون) 🪙', callback_data: 'buy_sub_crypto'))
            ->addRow(InlineKeyboardButton::make('⭐ پرداخت با استارز (واحد پول تلگرام) ⭐', callback_data: 'buy_sub_star'))
            ->addRow(InlineKeyboardButton::make('👥 پرداخت با زیر مجموعه 👥', callback_data: 'user_referral'))
            ->addRow(InlineKeyboardButton::make('💳 پرداخت تومانی (با کمی معطلی) 💳', url: 'https://t.me/kermsup'))
            ->addRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'main_menu'));

        $bot->editMessageText($msg, reply_markup: $keyboard);
    }

    /**
     * @param iterable<int,\App\Models\SubscriptionPlan> $plans
     */
    private function showPlansForMethod(Nutgram $bot, iterable $plans, TelegramStarService $starService, string $method): void
    {
        $title = $method === 'crypto' ? '🪙 پرداخت کریپتویی (ترون یا تون)' : '⭐️ پرداخت با استارز تلگرام';
        $intro = $method === 'crypto'
            ? "روی پلن بزن تا فاکتور کریپتویی    ساخته بشه."
            : "روی پلن بزن تا پرداخت استار تلگرام برات باز بشه.";

        $msg = "{$title}\nپلن مورد نظرت رو انتخاب کن:\n\n";
        $chunks = [];
        foreach ($plans as $plan) {
            $starCount = $starService->usdToStars((float) $plan->price);
            $usd = number_format((float) $plan->price, 2);
            $stars = number_format((float) $starCount, 0);
            $durationText = ($plan->duration_days ?? 0) > 0 ? "{$plan->duration_days} روز" : 'نامحدود';

            $chunks[] = "◾️ **{$plan->name}**\n"
                . "   💰 {$usd} دلار (~{$stars} استار)\n"
                . "   ⏱ مدت: {$durationText}\n";
        }

        $msg .= implode("\n\n", $chunks) . "\n\n" . $intro;

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($plans as $plan) {
            $callback = $method === 'crypto'
                ? "pay_crypto_{$plan->id}"
                : "pay_star_{$plan->id}";
            $keyboard->addRow(InlineKeyboardButton::make("{$plan->name}", callback_data: $callback));
        }

        $keyboard->addRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'buy_subscription'));

        $bot->editMessageText($msg, reply_markup: $keyboard, parse_mode: 'Markdown');
    }
}

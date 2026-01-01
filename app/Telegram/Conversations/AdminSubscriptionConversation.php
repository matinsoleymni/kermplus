<?php

namespace App\Telegram\Conversations;

use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Helpers\SubscriptionHelper;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class AdminSubscriptionConversation extends Conversation
{
    protected SubscriptionService $service;

    protected function getLocalUserByTelegram(Nutgram $bot): ?User
    {
        $tgUser = $bot->callbackQuery()?->from ?? $bot->user();
        if (!$tgUser) return null;
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

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('🔎 جستجوی آیدی تلگرام', callback_data: 'admin_sub_search')
            )
            ->addRow(
                InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'admin_panel')
            );

        $bot->sendMessage('🧾 مدیریت اشتراک‌ها — لطفا یک گزینه انتخاب کنید:', reply_markup: $keyboard);
        $this->next('handleMenu');
    }

    public function handleMenu(Nutgram $bot)
    {
        $data = $bot->callbackQuery()?->data;
        if ($data === 'admin_panel') {
            AdminPanelConversation::begin($bot);
            $this->end();
            return;
        }
        if ($data === 'admin_sub_search') {
            $bot->sendMessage('🔎 لطفا آیدی تلگرام کاربر را وارد کنید:');
            $this->next('searchByTelegramId');
            return;
        }

        $this->start($bot);
    }

    public function searchByTelegramId(Nutgram $bot)
    {
        $telegramId = trim($bot->message()?->text ?? '');
        if ($telegramId === '') {
            $bot->sendMessage('آیدی تلگرام نامعتبر است. بازگشت.');
            $this->start($bot);
            return;
        }

        $user = User::where('telegram_id', $telegramId)->first();
        if (!$user) {
            $bot->sendMessage('کاربر یافت نشد.');
            $this->start($bot);
            return;
        }

        $this->service = $this->service ?? app(SubscriptionService::class);
        $sub = $this->service->getActiveSubscription($user);
        if (!$sub) {
            $bot->sendMessage("کاربر {$user->name} اشتراک فعالی ندارد.");
            $this->start($bot);
            return;
        }

        $this->showSubscriptionDetail($bot, $sub);
    }

    protected function showSubscriptionDetail(Nutgram $bot, Subscription $sub)
    {
        $kb = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('🔄 تمدید', callback_data: "admin_sub_renew:{$sub->id}"),
                InlineKeyboardButton::make('❌ لغو', callback_data: "admin_sub_cancel:{$sub->id}")
            )
            ->addRow(
                InlineKeyboardButton::make('↩️ بازگشت', callback_data: 'admin_sub_back')
            );

        $text = "📋 اشتراک کاربر: {$sub->user->name}\nپلن: {$sub->plan->name}\nشروع: {$sub->started_at->format('Y-m-d H:i')}\nپایان: {$sub->expires_at->format('Y-m-d H:i')}\nروزهای باقی‌مانده: {$sub->getRemainingDays()}";

        $bot->sendMessage($text, reply_markup: $kb);
        $this->next('detailHandler');
    }

    public function detailHandler(Nutgram $bot)
    {
        $data = $bot->callbackQuery()?->data;
        if (!$data) {
            $this->start($bot);
            return;
        }

        if ($data === 'admin_sub_back') {
            $this->start($bot);
            return;
        }

        if (str_starts_with($data, 'admin_sub_renew:')) {
            $id = (int) explode(':', $data, 2)[1];
            $sub = Subscription::find($id);
            $tgAdmin = $bot->callbackQuery()?->from ?? $bot->user();
            $adminLocal = $tgAdmin ? User::where('telegram_id', $tgAdmin->id)->first() : null;
            $adminId = $adminLocal ? $adminLocal->id : null;
            if ($sub && $sub->renew($adminId)) {
                $bot->answerCallbackQuery(text: 'اشتراک تمدید شد.');
            } else {
                $bot->answerCallbackQuery(text: 'خطا در تمدید.');
            }
            $this->start($bot);
            return;
        }

        if (str_starts_with($data, 'admin_sub_cancel:')) {
            $id = (int) explode(':', $data, 2)[1];
            $sub = Subscription::find($id);
            $tgAdmin = $bot->callbackQuery()?->from ?? $bot->user();
            $adminLocal = $tgAdmin ? User::where('telegram_id', $tgAdmin->id)->first() : null;
            $adminId = $adminLocal ? $adminLocal->id : null;
            if ($sub && $sub->cancel($adminId)) {
                $bot->answerCallbackQuery(text: 'اشتراک لغو شد.');
            } else {
                $bot->answerCallbackQuery(text: 'خطا در لغو.');
            }
            $this->start($bot);
            return;
        }

        $this->start($bot);
    }
}

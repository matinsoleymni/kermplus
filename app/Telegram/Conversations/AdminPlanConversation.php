<?php

namespace App\Telegram\Conversations;

use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use App\Models\SubscriptionPlan;
use App\Models\User;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class AdminPlanConversation extends Conversation
{
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
                InlineKeyboardButton::make('📊 لیست پلن‌ها', callback_data: 'admin_plan_list'),
                InlineKeyboardButton::make('➕ ایجاد پلن', callback_data: 'admin_plan_create')
            )
            ->addRow(
                InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'admin_panel')
            );

        $bot->sendMessage('📦 مدیریت پلن‌ها — لطفا یک گزینه انتخاب کنید:', reply_markup: $keyboard);
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
        if ($data === 'admin_plan_list') {
            $this->showList($bot);
            return;
        }
        if ($data === 'admin_plan_create') {
            $bot->sendMessage('✏️ نام پلن را وارد کنید:');
            $this->next('createName');
            return;
        }
        $this->start($bot);
    }

    protected function showList(Nutgram $bot)
    {
        $plans = SubscriptionPlan::orderBy('price')->get();
        if ($plans->isEmpty()) {
            $bot->sendMessage('هیچ پلنی تعریف نشده است.');
            $this->start($bot);
            return;
        }

        foreach ($plans as $plan) {
            $kb = InlineKeyboardMarkup::make()
                ->addRow(
                    InlineKeyboardButton::make($plan->is_active ? '🔴 غیرفعال' : '🟢 فعال', callback_data: "admin_plan_toggle:{$plan->id}"),
                    InlineKeyboardButton::make('🗑 حذف', callback_data: "admin_plan_delete:{$plan->id}")
                )
                ->addRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'admin_plan_list'));

            $text = "🔹 {$plan->name}\nقیمت: " . number_format((float)$plan->price, 0) . " تومان\nمدت: {$plan->duration_days} روز\nویژگی‌ها: " . implode(',', $plan->getFeatures() ?? []);
            $bot->sendMessage($text, reply_markup: $kb);
        }
        $this->start($bot);
    }

    public function createName(Nutgram $bot)
    {
        $name = $bot->message()?->text;
        if (!$name) {
            $bot->sendMessage('نام نامعتبر است. بازگشت.');
            $this->start($bot);
            return;
        }
        $bot->setUserData('new_plan_name', $name);
        $bot->sendMessage('قیمت (تومان) را وارد کنید:');
        $this->next('createPrice');
    }

    public function createPrice(Nutgram $bot)
    {
        $price = $bot->message()?->text;
        if (!is_numeric($price)) {
            $bot->sendMessage('قیمت نامعتبر. بازگشت.');
            $this->start($bot);
            return;
        }
        $bot->setUserData('new_plan_price', (float) $price);
        $bot->sendMessage('مدت (روز) را وارد کنید:');
        $this->next('createDuration');
    }

    public function createDuration(Nutgram $bot)
    {
        $days = $bot->message()?->text;
        if (!is_numeric($days) || (int)$days < 1) {
            $bot->sendMessage('مدت نامعتبر. بازگشت.');
            $this->start($bot);
            return;
        }
        $bot->setUserData('new_plan_duration', (int)$days);
        $bot->sendMessage('✅ پلن جدید ایجاد شد.');
        $data = [
            'name' => $bot->getUserData('new_plan_name'),
            'price' => (float)$bot->getUserData('new_plan_price'),
            'duration_days' => (int)$bot->getUserData('new_plan_duration'),
            'max_sms_per_day' => 1000,
            'max_email_per_day' => 1000,
            'max_requests_per_day' => 10000,
            'is_active' => true,
        ];
        SubscriptionPlan::create($data);
        $this->start($bot);
    }


    public function handleCallback(Nutgram $bot)
    {
        $data = $bot->callbackQuery()?->data;
        if (!$data) {
            $this->start($bot);
            return;
        }

        if (str_starts_with($data, 'admin_plan_toggle:')) {
            $id = (int) explode(':', $data, 2)[1];
            $plan = SubscriptionPlan::find($id);
            if ($plan) {
                $plan->is_active = !$plan->is_active;
                $plan->save();
                $bot->answerCallbackQuery(text: 'وضعیت تغییر کرد.');
            }
            $this->start($bot);
            return;
        }

        if (str_starts_with($data, 'admin_plan_delete:')) {
            $id = (int) explode(':', $data, 2)[1];
            $plan = SubscriptionPlan::find($id);
            if ($plan) {
                $plan->delete();
                $bot->answerCallbackQuery(text: 'پلن حذف شد.');
            } else {
                $bot->answerCallbackQuery(text: 'پلن یافت نشد.');
            }
            $this->start($bot);
            return;
        }

        if (str_starts_with($data, 'admin_plan_show:')) {
            $id = (int) explode(':', $data, 2)[1];
            $plan = SubscriptionPlan::find($id);
            if ($plan) {
                $msg = "🔹 {$plan->name}\nقیمت: " . number_format((float)$plan->price, 0) . " تومان\nمدت: {$plan->duration_days} روز";
                $bot->sendMessage($msg);
            }
            $this->start($bot);
            return;
        }

        $this->start($bot);
    }
}

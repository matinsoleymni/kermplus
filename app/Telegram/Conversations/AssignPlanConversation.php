<?php

namespace App\Telegram\Conversations;

use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use Carbon\Carbon;
use App\Models\User;
use App\Models\SubscriptionPlan;
use App\Services\SubscriptionService;

class AssignPlanConversation extends Conversation
{
    protected string $action = 'set'; // set | remove

    public function start(Nutgram $bot)
    {
        $tgUser = $bot->callbackQuery()?->from ?? $bot->user();
        $local = \App\Models\User::where('telegram_id', $tgUser->id ?? null)->first();
        if (!$local || !$local->isAdmin()) {
            $bot->sendMessage('⛔️ دسترسی ندارید.');
            $this->end();
            return;
        }

        $now = Carbon::now();
        $msg = "🤖 حذف یا اضافه کردن اشتراک به کاربر\n\n";
        $msg .= "یکی از گزینه های زیر را انتخاب کن";

        $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
            ->addRow(\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('➕ افزودن اشتراک', callback_data: 'assign_plan_set', style: 'danger'))
            ->addRow(\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('🗑 حذف اشتراک', callback_data: 'assign_plan_remove', style: 'danger'))
            ->addRow(\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('⬅️ بازگشت به منوی ادمین', callback_data: 'admin_panel', style: 'danger', icon_custom_emoji_id: '5352759161945867747'));

        $bot->sendMessage($msg, reply_markup: $keyboard);
        $this->next('menu');
    }

    public function menu(Nutgram $bot)
    {
        $data = $bot->callbackQuery()?->data;
        if ($data === 'admin_panel') {
            AdminPanelConversation::begin($bot);
            $this->end();
            return;
        }

        if ($data === 'assign_plan_set') {
            $this->action = 'set';
            $bot->setUserData('assign_action', 'set');
            $bot->sendMessage('✏️ آیدی تلگرام کاربر را وارد کنید تا پلن برای او تعیین شود:');
            $this->next('pickUser');
            return;
        }

        if ($data === 'assign_plan_remove') {
            $this->action = 'remove';
            $bot->setUserData('assign_action', 'remove');
            $bot->sendMessage('✏️ آیدی تلگرام کاربر را وارد کنید تا پلن او حذف شود:');
            $this->next('pickUser');
            return;
        }

        $this->start($bot);
    }

    public function pickUser(Nutgram $bot)
    {
        $telegramId = trim($bot->message()?->text ?? '');
        $user = User::where('telegram_id', $telegramId)->first();
        if (!$user) {
            $bot->sendMessage('کاربر یافت نشد.');
            $this->end();
            return;
        }

        $bot->setUserData('assign_user_id', $user->id);

        $action = $this->action ?: ($bot->getUserData('assign_action') ?? 'set');

        if ($action === 'remove') {
            $this->removePlan($bot, $user);
            return;
        }

        $plans = SubscriptionPlan::where('is_active', true)->get();
        if ($plans->isEmpty()) {
            $bot->sendMessage('پلنی تعریف نشده است.');
            $this->end();
            return;
        }

        $text = "انتخاب پلن برای {$user->name}:\n";
        foreach ($plans as $p) {
            $text .= "• {$p->id} - {$p->name} (" . number_format((float)$p->price, 0) . ")\n";
        }
        $text .= "\nلطفا ID پلن را ارسال کنید:";
        $bot->sendMessage($text);
        $this->next('applyPlan');
    }

    public function applyPlan(Nutgram $bot)
    {
        $input = trim($bot->message()?->text ?? '');
        $userId = $bot->getUserData('assign_user_id');
        $user = User::find($userId);
        if (!$user) {
            $bot->sendMessage('کاربر معتبر نیست.');
            $this->end();
            return;
        }

        if (!is_numeric($input)) {
            $bot->sendMessage('ID پلن نامعتبر است.');
            $this->end();
            return;
        }

        $plan = SubscriptionPlan::find((int)$input);
        if (!$plan) {
            $bot->sendMessage('پلن یافت نشد.');
            $this->end();
            return;
        }

        $tgAdmin = $bot->callbackQuery()?->from ?? $bot->user();
        $adminLocal = $tgAdmin ? \App\Models\User::where('telegram_id', $tgAdmin->id)->first() : null;
        $adminId = $adminLocal ? $adminLocal->id : null;

        $service = app(SubscriptionService::class);
        $service->createSubscription($user, $plan, null, $adminId);
        $bot->sendMessage('✅ پلن برای کاربر اعمال شد.');
        $this->end();
    }

    protected function removePlan(Nutgram $bot, User $user): void
    {
        $sub = $user->subscriptions()->where('is_active', true)->latest()->first();
        if ($sub) {
            $sub->cancel();
            $bot->sendMessage('اشتراک کاربر لغو شد.');
        } else {
            $bot->sendMessage('کاربر اشتراک فعالی ندارد.');
        }
        $this->end();
    }
}

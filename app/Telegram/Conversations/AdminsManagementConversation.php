<?php

namespace App\Telegram\Conversations;

use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use App\Models\User;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class AdminsManagementConversation extends Conversation
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
            $bot->sendMessage('⛔️ دسترسی ندارید.');
            $this->end();
            return;
        }

        $admins = User::query()
            ->whereIn('role', ['admin', 'super_admin'])
            ->orderByDesc('role')
            ->orderBy('id')
            ->get(['id', 'name', 'telegram_id']);

        $list = $admins->map(function ($admin) {
            $name = $admin->name ?: 'بدون‌نام';
            $tg = $admin->telegram_id ?: '—';
            return "{$name} - {$tg}";
        })->implode("\n");

        $text = "🤖 admins of KermPlus 🍷\n\n";
        $text .= ($list !== '' ? $list : 'ادمینی ثبت نشده است.');

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('➕ افزودن ادمین', callback_data: 'admin_add'),
                InlineKeyboardButton::make('➖ حذف ادمین', callback_data: 'admin_remove')
            )
            ->addRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'admin_panel'));

        $bot->sendMessage($text, reply_markup: $keyboard);
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
        if ($data === 'admin_add') {
            $bot->sendMessage('✏️ آیدی تلگرام کاربر را وارد کنید تا ادمین شود:');
            $this->next('doAdd');
            return;
        }
        if ($data === 'admin_remove') {
            $bot->sendMessage('✏️ آیدی تلگرام کاربر را وارد کنید تا از ادمین‌ها حذف شود:');
            $this->next('doRemove');
            return;
        }
        $this->start($bot);
    }

    public function doAdd(Nutgram $bot)
    {
        $text = trim($bot->message()?->text ?? '');
        if (!$text) {
            $bot->sendMessage('ورودی نامعتبر. بازگشت.');
            $this->start($bot);
            return;
        }

        $user = User::where('telegram_id', $text)->first();
        if (!$user) {
            $bot->sendMessage('کاربر یافت نشد.');
            $this->start($bot);
            return;
        }

        $user->role = 'admin';
        $user->is_admin = true;
        $user->save();

        $bot->sendMessage("✅ {$user->name} اکنون ادمین شد.");
        $this->start($bot);
    }

    public function doRemove(Nutgram $bot)
    {
        $text = trim($bot->message()?->text ?? '');
        if (!$text) {
            $bot->sendMessage('ورودی نامعتبر. بازگشت.');
            $this->start($bot);
            return;
        }

        $user = User::where('telegram_id', $text)->first();
        if (!$user) {
            $bot->sendMessage('کاربر یافت نشد.');
            $this->start($bot);
            return;
        }

        $user->role = 'user';
        $user->is_admin = false;
        $user->save();

        $bot->sendMessage("✅ {$user->name} از ادمین‌ها حذف شد.");
        $this->start($bot);
    }
}

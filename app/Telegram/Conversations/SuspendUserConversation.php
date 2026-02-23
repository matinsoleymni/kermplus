<?php

namespace App\Telegram\Conversations;

use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use App\Models\User;

class SuspendUserConversation extends Conversation
{
    protected ?string $forcedMode = null; // "suspend" یا "unsuspend"

    protected function getLocalUserByTelegram(Nutgram $bot): ?User
    {
        $tgUser = $bot->callbackQuery()?->from ?? $bot->user();
        if (!$tgUser) return null;
        return User::where('telegram_id', $tgUser->id)->first();
    }

    public function start(Nutgram $bot, ?string $forcedMode = null)
    {
        // Accept mode from begin(..., data: ['suspend']) or from stored property.
        if ($forcedMode !== null) {
            $this->forcedMode = $forcedMode;
        }

        $local = $this->getLocalUserByTelegram($bot);
        if (!$local || !$local->isAdmin()) {
            $bot->sendMessage('⛔️ دسترسی ندارید.');
            $this->end();
            return;
        }

        if ($this->forcedMode) {
            $this->promptTarget($bot, $this->forcedMode === 'unsuspend');
            return;
        }

        $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
            ->addRow(
                \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('🚫 معلق کردن کاربر', callback_data: 'suspend_user', style: 'danger'),
                \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('✅ رفع معلقیت', callback_data: 'unsuspend_user', style: 'danger')
            )
            ->addRow(\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('بازگشت', callback_data: 'admin_panel', style: 'danger', icon: '5352759161945867747'));

        $bot->sendMessage('🔒 معلق/فعالسازی کاربر — انتخاب کنید:', reply_markup: $keyboard);
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
        if ($data === 'suspend_user') {
            $this->promptTarget($bot, false);
            return;
        }
        if ($data === 'unsuspend_user') {
            $this->promptTarget($bot, true);
            return;
        }
        $this->start($bot);
    }

    protected function promptTarget(Nutgram $bot, bool $isUnsuspend): void
    {
        $bot->setUserData('suspend_mode', $isUnsuspend ? 'unsuspend' : 'suspend');
        $msg = $isUnsuspend
            ? "⚜️ آیدی تلگرام یا شناسه کاربر (ID) را برای رفع معلقیت ارسال کنید."
            : "⛔ آیدی تلگرام یا شناسه کاربر (ID) را برای معلق کردن ارسال کنید.\n\n⚠️ بعد از بلاک، کاربر به ربات دسترسی ندارد.";
        $bot->sendMessage($msg);
        $this->next($isUnsuspend ? 'doUnsuspend' : 'doSuspend');
    }

    public function doSuspend(Nutgram $bot)
    {
        $text = trim($bot->message()?->text ?? '');
        $user = User::where('telegram_id', $text)->orWhere('id', $text)->first();
        if (!$user) {
            $bot->sendMessage('کاربر یافت نشد.');
            $this->start($bot);
            return;
        }
        $user->suspended = true;
        $user->save();
        $bot->sendMessage("✅ کاربر {$user->name} معلق شد.");
        $this->start($bot);
    }

    public function doUnsuspend(Nutgram $bot)
    {
        $text = trim($bot->message()?->text ?? '');
        $user = User::where('telegram_id', $text)->orWhere('id', $text)->first();
        if (!$user) {
            $bot->sendMessage('کاربر یافت نشد.');
            $this->start($bot);
            return;
        }
        $user->suspended = false;
        $user->save();
        $bot->sendMessage("✅ معلقیت کاربر {$user->name} برداشته شد.");
        $this->start($bot);
    }
}

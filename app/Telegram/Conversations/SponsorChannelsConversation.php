<?php

namespace App\Telegram\Conversations;

use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use App\Models\SponsorChannel;
use App\Models\User;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class SponsorChannelsConversation extends Conversation
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

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('📋 لیست اسپانسرها', callback_data: 'sponsor_list'),
                InlineKeyboardButton::make('➕ افزودن اسپانسر', callback_data: 'sponsor_add')
            )
            ->addRow(
                InlineKeyboardButton::make('➖ حذف اسپانسر', callback_data: 'sponsor_remove'),
                InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'admin_panel')
            );

        $bot->sendMessage('📣 مدیریت کانال‌های اسپانسر — یک گزینه انتخاب کنید:', reply_markup: $keyboard);
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
        if ($data === 'sponsor_list') {
            $this->showList($bot);
            return;
        }
        if ($data === 'sponsor_add') {
            $bot->sendMessage('✏️ عنوان کانال را وارد کنید:');
            $this->next('addTitle');
            return;
        }
        if ($data === 'sponsor_remove') {
            $bot->sendMessage('✏️ شناسه (ID) کانال را وارد کنید تا حذف شود:');
            $this->next('removeById');
            return;
        }
        $this->start($bot);
    }

    protected function showList(Nutgram $bot)
    {
        $list = SponsorChannel::orderByDesc('id')->get();
        if ($list->isEmpty()) {
            $bot->sendMessage('لیستی موجود نیست.');
            $this->start($bot);
            return;
        }
        $msg = "• 🤖 اسپانسر ها🍷 •\n\n";
        foreach ($list as $c) {
            $link = $c->username ? "https://t.me/{$c->username}" : ($c->link ?: '(بدون لینک)');
            $msg .= "- #{$c->id} | {$c->title} ({$link}) (" . ($c->is_active ? 'Force' : 'Optional') . ")\n";
        }
        $msg .= "\n📆 " . now()->format('Y/m/d') . " - ⏰ " . now()->format('H:i:s') . "\n\n";

        $bot->sendMessage($msg);
        $this->start($bot);
    }

    public function addTitle(Nutgram $bot)
    {
        $title = trim($bot->message()?->text ?? '');
        if (!$title) {
            $bot->sendMessage('عنوان نامعتبر.');
            $this->start($bot);
            return;
        }
        $bot->setUserData('new_sponsor_title', $title);
        $bot->sendMessage('نام کاربری کانال (بدون @) یا لینک را وارد کنید (یا /none):');
        $this->next('addLink');
    }

    public function addLink(Nutgram $bot)
    {
        $text = trim($bot->message()?->text ?? '');
        $title = $bot->getUserData('new_sponsor_title');
        $username = null;
        $link = null;
        if ($text && $text !== '/none') {
            if (str_starts_with($text, 'http')) {
                $link = $text;
            } else {
                $username = preg_replace('/^@/', '', $text);
            }
        }
        SponsorChannel::create([
            'title' => $title,
            'username' => $username,
            'link' => $link,
            'is_active' => true,
        ]);
        $bot->sendMessage('✅ اسپانسر اضافه شد.');
        $this->start($bot);
    }

    public function removeById(Nutgram $bot)
    {
        $id = trim($bot->message()?->text ?? '');
        if (!is_numeric($id)) {
            $bot->sendMessage('ID نامعتبر.');
            $this->start($bot);
            return;
        }
        $c = SponsorChannel::find((int)$id);
        if (!$c) {
            $bot->sendMessage('کانال یافت نشد.');
            $this->start($bot);
            return;
        }
        $c->delete();
        $bot->sendMessage('✅ کانال حذف شد.');
        $this->start($bot);
    }
}

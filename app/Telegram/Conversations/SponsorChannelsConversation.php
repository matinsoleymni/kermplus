<?php

namespace App\Telegram\Conversations;

use App\Services\SponsorJoinService;
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
                InlineKeyboardButton::make('📋 لیست اسپانسرها', callback_data: 'sponsor_list', style: 'danger'),
                InlineKeyboardButton::make('➕ افزودن اسپانسر', callback_data: 'sponsor_add', style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('➖ حذف اسپانسر', callback_data: 'sponsor_remove', style: 'danger'),
                InlineKeyboardButton::make('بازگشت', callback_data: 'admin_panel', style: 'danger', icon_custom_emoji_id: '5352759161945867747')
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
        $joinService = app(SponsorJoinService::class);

        if ($list->isEmpty()) {
            $bot->sendMessage('لیستی موجود نیست.');
            $this->start($bot);
            return;
        }
        $msg = "• 🤖 اسپانسر ها🍷 •\n\n";
        foreach ($list as $c) {
            $link = $c->username ?: ($c->link ?: '(بدون لینک)');
            $mode = $c->is_active ? 'Force' : 'Optional';
            $verify = $joinService->canVerifyChannel($c) ? 'Verifiable' : 'Invalid';
            $msg .= "- #{$c->id} | {$c->title} ({$link}) ({$mode} | {$verify})\n";
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
        $bot->sendMessage("نام کاربری کانال (بدون @)، لینک عمومی t.me/username یا chat_id مثل -100... را وارد کنید.\nبرای کانال خصوصی می‌تونی این‌طوری بدی:\n-1001234567890 https://t.me/+InviteCode");
        $this->next('addLink');
    }

    public function addLink(Nutgram $bot)
    {
        $text = trim($bot->message()?->text ?? '');
        $title = $bot->getUserData('new_sponsor_title');
        $joinService = app(SponsorJoinService::class);
        $normalized = $joinService->normalizeAdminChannelInput($text);

        if ($normalized === null) {
            $bot->sendMessage('❌ ورودی نامعتبره. فرمت‌های مجاز: username ، لینک عمومی t.me/username ، chat_id با فرمت -100... ، یا chat_id به‌همراه لینک دعوت.');
            $this->next('addLink');
            return;
        }

        SponsorChannel::create([
            'title' => $title,
            'username' => $normalized['username'],
            'link' => $normalized['link'],
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

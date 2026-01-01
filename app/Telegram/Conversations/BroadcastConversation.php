<?php

namespace App\Telegram\Conversations;

use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use Illuminate\Support\Facades\DB;
use App\Models\User;

class BroadcastConversation extends Conversation
{
    public string $target;

    protected function getLocalUserByTelegram(Nutgram $bot): ?User
    {
        $tgUser = $bot->callbackQuery()?->from ?? $bot->user();
        if (!$tgUser) {
            return null;
        }

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

        $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
            ->addRow(
                \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('🟢 کاربران فعال', callback_data: 'broadcast_active'),
                \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('⚪️ کاربران غیرفعال', callback_data: 'broadcast_inactive')
            )
            ->addRow(
                \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('👥 همه کاربران', callback_data: 'broadcast_all'),
                \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'admin_panel')
            );
        $bot->sendMessage('📢 به بخش پیام همگانی خوش آمدید! گروه هدف را انتخاب کنید:', reply_markup: $keyboard);
        $this->next('chooseTarget');
    }

    public function chooseTarget(Nutgram $bot)
    {
        $local = $this->getLocalUserByTelegram($bot);
        if (!$local || !$local->isAdmin()) {
            $bot->sendMessage('⛔️ دسترسی ندارید.');
            $this->end();
            return;
        }

        $data = $bot->callbackQuery()?->data;
        if ($data === 'admin_panel') {
            AdminPanelConversation::begin($bot);
            $this->end();
            return;
        }
        if ($data === 'broadcast_active') {
            $this->target = 'active';
        } elseif ($data === 'broadcast_inactive') {
            $this->target = 'inactive';
        } elseif ($data === 'broadcast_all') {
            $this->target = 'all';
        } else {
            $bot->sendMessage('❌ انتخاب نامعتبر.');
            $this->start($bot);
            return;
        }
        $bot->sendMessage('✏️ پیام خود را وارد کنید:');
        $this->next('sendBroadcast');
    }

    public function sendBroadcast(Nutgram $bot)
    {
        $local = $this->getLocalUserByTelegram($bot);
        if (!$local || !$local->isAdmin()) {
            $bot->sendMessage('⛔️ دسترسی ندارید.');
            $this->end();
            return;
        }

        if (!isset($this->target)) {
            $bot->sendMessage('❌ ابتدا گروه هدف را انتخاب کنید.');
            $this->start($bot);
            return;
        }

        $text = trim((string)$bot->message()?->text);
        if ($text === '') {
            $bot->sendMessage('❌ متن پیام خالی است. لطفا دوباره ارسال کنید:');
            $this->next('sendBroadcast');
            return;
        }

        $count = 0;
        if ($this->target === 'active') {
            $users = DB::table('users')->where('last_active_at', '>=', now()->subDay())->pluck('telegram_id');
        } elseif ($this->target === 'inactive') {
            $users = DB::table('users')
                ->where(function ($q) {
                    $q->where('last_active_at', '<', now()->subDay())
                        ->orWhereNull('last_active_at');
                })
                ->pluck('telegram_id');
        } else {
            $users = DB::table('users')->pluck('telegram_id');
        }
        foreach ($users as $tgId) {
            if (!$tgId) continue; // skip users without telegram_id
            try {
                $bot->sendMessage($text, chat_id: $tgId);
                $count++;
            } catch (\Throwable $e) {
                // Ignore failed sends
            }
        }
        $bot->sendMessage("📢 پیام به {$count} کاربر ارسال شد.");
        $this->end();
    }

    public function secondStep(Nutgram $bot)
    {
        $bot->sendMessage('Bye!');
        $this->end();
    }
}

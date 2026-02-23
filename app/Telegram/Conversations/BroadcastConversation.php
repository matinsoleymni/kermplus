<?php

namespace App\Telegram\Conversations;

use App\Telegram\Support\CallbackQueryResponder;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Message\MessageOriginChannel;
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

        CallbackQueryResponder::ack($bot);

        $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
            ->addRow(
                \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('🟢 کاربران فعال', callback_data: 'broadcast_active', style: 'danger'),
                \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('⚪️ کاربران غیرفعال', callback_data: 'broadcast_inactive', style: 'danger')
            )
            ->addRow(
                \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('👥 همه کاربران', callback_data: 'broadcast_all', style: 'danger'),
                \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('بازگشت', callback_data: 'admin_panel', style: 'danger', icon: '5352759161945867747')
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
        CallbackQueryResponder::ack($bot);
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
        $bot->sendMessage('📨 پیام موردنظر را ارسال کنید (متن، عکس، ویدیو، فایل، فوروارد و ...):');
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

        $message = $bot->message();
        if (!$message || !isset($message->message_id, $message->chat->id)) {
            $bot->sendMessage('❌ پیام معتبری دریافت نشد. لطفا دوباره ارسال کنید:');
            $this->next('sendBroadcast');
            return;
        }
        $sourceChatId = (int) $message->chat->id;
        $sourceMessageId = (int) $message->message_id;
        $forwardedFromChannel = $message->forward_origin instanceof MessageOriginChannel;

        $sent = 0;
        $failed = 0;
        $usersQuery = DB::table('users')
            ->whereNotNull('telegram_id')
            ->where('telegram_id', '>', 0)
            ->where(function ($q) {
                $q->whereNull('suspended')->orWhere('suspended', false);
            });

        if ($this->target === 'active') {
            $usersQuery->where('last_active_at', '>=', now()->subDay());
        } elseif ($this->target === 'inactive') {
            $usersQuery->where(function ($q) {
                $q->where('last_active_at', '<', now()->subDay())
                    ->orWhereNull('last_active_at');
            });
        }

        $users = $usersQuery->distinct()->pluck('telegram_id');
        if ($users->isEmpty()) {
            $bot->sendMessage('ℹ️ کاربری برای ارسال پیام پیدا نشد.');
            $this->end();
            return;
        }

        foreach ($users as $tgId) {
            if (!$tgId) {
                continue;
            }

            try {
                if ($forwardedFromChannel) {
                    $bot->forwardMessage(
                        chat_id: (int) $tgId,
                        from_chat_id: $sourceChatId,
                        message_id: $sourceMessageId,
                    );
                } else {
                    $bot->copyMessage(
                        chat_id: (int) $tgId,
                        from_chat_id: $sourceChatId,
                        message_id: $sourceMessageId,
                    );
                }
                $sent++;
                // کاهش احتمال خطای Flood control
                usleep(65000);
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        $bot->sendMessage("📢 پیام همگانی ارسال شد.\n✅ موفق: {$sent}\n❌ ناموفق: {$failed}");
        $this->end();
    }

    public function secondStep(Nutgram $bot)
    {
        $bot->sendMessage('Bye!');
        $this->end();
    }
}

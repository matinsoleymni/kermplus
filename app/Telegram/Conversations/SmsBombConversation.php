<?php

namespace App\Telegram\Conversations;

use App\Models\WhitelistedTarget;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Services\WhitelistService;
use App\Telegram\Concerns\SendsSmsProgress;
use App\Telegram\Keyboards\PlusRequiredKeyboard;

class SmsBombConversation extends Conversation
{
    use SendsSmsProgress;

    public string $sms_phone;
    public int $sms_count;
    protected array $botMessages = [];
    protected array $userMessages = [];

    public function start(Nutgram $bot)
    {
        $tgUser = $bot->user();
        if (!$tgUser) {
            $bot->sendMessage('⛔️ خطا در دریافت اطلاعات تلگرام.');
            $this->end();
            return;
        }

        $local = User::where('telegram_id', $tgUser->id)->first();
        if (!$local) {
            $bot->sendMessage('ℹ️ حساب شما در سیستم ثبت نشده است. لطفا در وبسایت ثبت‌نام کنید یا با @kermsup تماس بگیرید.');
            $this->end();
            return;
        }

        $local->last_active_at = now();
        $local->save();

        if ($local->isSuspended()) {
            $bot->sendMessage('⛔️ حساب شما موقتا معلق شده است.');
            $this->end();
            return;
        }

        $service = app(SubscriptionService::class);
        if (!$service->canSendSms($local)) {
            $msg = "❗️✨ این بخش نیازمند به نسخه پلاس رباتمونه 😚\n\n";
            $msg .= "برای ارتقای نسخه ربات به \"نسخه پلاس🎗\" از طریق دکمه های زیر اقدام کنید :";
            $bot->sendMessage($msg, reply_markup: PlusRequiredKeyboard::make('main_menu'));
            $this->end();
            return;
        }

        $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
            ->addRow(\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('🔙 بازگشت به منو', callback_data: 'main_menu'));
        $msg = $bot->sendMessage('📱 شماره موبایل هدف را وارد کنید:', reply_markup: $keyboard);
        $this->rememberBotMessage($msg);
        $this->next('askCount');
    }

    public function askCount(Nutgram $bot)
    {
        $this->rememberUserMessage($bot);
        $phone = $bot->message()?->text;
        if (!preg_match('/^09\d{9,13}$/', $phone)) {
            $bot->sendMessage('❌ شماره وارد شده صحیح نیست. لطفا دوباره وارد کنید:');
            $this->start($bot);
            return;
        }

        $whitelist = app(WhitelistService::class);
        if ($whitelist->isWhitelisted($phone, WhitelistedTarget::TYPE_PHONE)) {
            $bot->sendMessage($whitelist->getBlockMessage($phone, WhitelistedTarget::TYPE_PHONE));
            $this->end();
            return;
        }

        $this->sms_phone = $phone;
        $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
            ->addRow(\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('🔙 بازگشت به منو', callback_data: 'main_menu'));
        $msg = $bot->sendMessage('🔢 تعداد پیامک را وارد کن (عدد بین 1 تا 100):', reply_markup: $keyboard);
        $this->rememberBotMessage($msg);
        $this->next('finish');
    }

    public function finish(Nutgram $bot)
    {
        $this->rememberUserMessage($bot);
        $count = (int)($bot->message()?->text);
        $phone = $this->sms_phone;
        $totalMessages = $count;
        $tgUser = $bot->user();
        $local = User::where('telegram_id', $tgUser->id)->first();
        $service = app(SubscriptionService::class);
        $whitelist = app(WhitelistService::class);

        if ($whitelist->isWhitelisted($phone, WhitelistedTarget::TYPE_PHONE)) {
            $bot->sendMessage($whitelist->getBlockMessage($phone, WhitelistedTarget::TYPE_PHONE));
            $this->end();
            return;
        }

        if ($totalMessages > 0 && $totalMessages <= 100) {
            if (!$service->checkSmsDailyLimit($local, $totalMessages)) {
                $bot->sendMessage('⚠️ محدودیت روزانه SMS شما اجازه این تعداد درخواست را نمی‌دهد.');
                $this->end();
                return;
            }

            // record usage
            \App\Models\UsageRecord::create([
                'user_id' => $local->id,
                'type' => 'sms',
                'target' => $phone,
                'count' => $totalMessages,
            ]);

            // اگر از مجانی استفاده کرد، آن را علامت‌گذاری کن
            if (!$service->getActiveSubscription($local)) {
                $local->markFreeSmsAsUsed();
            }

            $this->deletePreviousMessages($bot);
            \App\Jobs\SendSmsBombJob::dispatch($phone, $totalMessages, 1, 0);
            $this->sendSmsProgressPreview($bot, $phone, $totalMessages);
            $this->end();
        } else {
            $bot->sendMessage('❌ ورودی‌ها معتبر نیستند. لطفا دوباره تلاش کن.');
            $this->next('finish');
        }
    }

    public function secondStep(Nutgram $bot)
    {
        $bot->sendMessage('Bye!');
        $this->end();
    }

    private function rememberBotMessage($message): void
    {
        if ($message && isset($message->message_id)) {
            $this->botMessages[] = $message->message_id;
        }
    }

    private function rememberUserMessage(Nutgram $bot): void
    {
        $msg = $bot->message();
        if ($msg && isset($msg->message_id)) {
            $this->userMessages[] = $msg->message_id;
        }
    }

    private function deletePreviousMessages(Nutgram $bot): void
    {
        $chatId = $bot->chatId();
        foreach (array_merge($this->botMessages, $this->userMessages) as $messageId) {
            try {
                $bot->deleteMessage(chat_id: $chatId, message_id: $messageId);
            } catch (\Throwable) {
                // ignore deletion errors
            }
        }
        $this->botMessages = [];
        $this->userMessages = [];
    }
}

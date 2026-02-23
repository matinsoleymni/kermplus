<?php

namespace App\Telegram\Conversations;

use App\Models\WhitelistedTarget;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Services\WhitelistService;
use App\Telegram\Concerns\SendsSmsProgress;

class SmsBombConversation extends Conversation
{
    use SendsSmsProgress;

    private const DEFAULT_SMS_COUNT = 100;
    private const SPEED_MAX_CALLBACK = 'sms_speed_max';
    private const SPEED_CUSTOM_CALLBACK = 'sms_speed_custom';

    public string $sms_phone;
    public ?int $startDelayMinutes = null;
    public int $batchSize = self::DEFAULT_SMS_COUNT;
    public int $totalBatches = 1;
    public int $intervalMinutes = 0;
    public bool $useCustomSpeed = false;
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
            $bot->sendMessage('ℹ️ حساب شما در سیستم ثبت نشده است. لطفا در وبسایت ثبت‌نام کنید یا به @kermsup پیام بدید.');
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

        $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
            ->addRow(\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('بازگشت به منو', callback_data: 'main_menu', style: 'danger', icon: '5352759161945867747'));
        $msg = $bot->sendMessage('📱 شماره موبایل هدف را وارد کنید:', reply_markup: $keyboard);
        $this->rememberBotMessage($msg);
        $this->next('askCount');
    }

    public function askCount(Nutgram $bot)
    {
        $this->rememberUserMessage($bot);
        $phone = trim((string) $bot->message()?->text);
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
        $this->startDelayMinutes = 0;
        $this->intervalMinutes = 0;
        $this->totalBatches = 1;
        $this->batchSize = self::DEFAULT_SMS_COUNT;
        $this->useCustomSpeed = false;

        $this->promptSpeedMode($bot);
    }

    public function askSpeedMode(Nutgram $bot): void
    {
        if ($bot->callbackQuery()) {
            $bot->answerCallbackQuery();
        }

        $callbackData = $bot->callbackQuery()?->data;
        $input = trim((string)($bot->message()?->text ?? ''));

        if ($callbackData === self::SPEED_MAX_CALLBACK || $input === 'حداکثر سرعت') {
            $this->useCustomSpeed = false;
            $this->startDelayMinutes = 0;
            $this->intervalMinutes = 0;
            $this->totalBatches = 1;
            $this->batchSize = self::DEFAULT_SMS_COUNT;
            $this->finish($bot);
            return;
        }

        if ($callbackData === self::SPEED_CUSTOM_CALLBACK || $input === 'سرعت دلخواه') {
            $this->useCustomSpeed = true;
            $this->startDelayMinutes = null;
            $this->intervalMinutes = 0;
            $this->totalBatches = 1;
            $this->batchSize = 1;
            $this->promptStartDelay($bot);
            return;
        }

        $bot->sendMessage('❌ لطفا یکی از گزینه‌های سرعت را انتخاب کن.');
        $this->next('askSpeedMode');
    }

    public function askStartDelay(Nutgram $bot): void
    {
        $this->rememberUserMessage($bot);
        $startDelay = (int)($bot->message()?->text ?? -1);
        if ($startDelay < 0 || $startDelay > 1440) {
            $bot->sendMessage('❌ زمان شروع باید بین 0 تا 1440 دقیقه باشد. دوباره بفرست:');
            $this->next('askStartDelay');
            return;
        }

        $this->startDelayMinutes = $startDelay;
        $this->promptIntervalMinutes($bot);
    }

    public function askIntervalMinutes(Nutgram $bot): void
    {
        $this->rememberUserMessage($bot);
        $intervalMinutes = (int)($bot->message()?->text ?? -1);
        if ($intervalMinutes < 0 || $intervalMinutes > 1440) {
            $bot->sendMessage('❌ فاصله باید بین 0 تا 1440 دقیقه باشد. دوباره تلاش کن:');
            $this->next('askIntervalMinutes');
            return;
        }

        $this->intervalMinutes = $intervalMinutes;
        $this->promptTotalBatches($bot);
    }

    public function askTotalBatches(Nutgram $bot): void
    {
        $this->rememberUserMessage($bot);
        $totalBatches = (int)($bot->message()?->text ?? 0);
        if ($totalBatches < 1 || $totalBatches > 20) {
            $bot->sendMessage('❌ تعداد نوبت باید بین 1 تا 20 باشد. دوباره تلاش کن:');
            $this->next('askTotalBatches');
            return;
        }

        $this->totalBatches = $totalBatches;
        $this->promptBatchSize($bot);
    }

    public function finish(Nutgram $bot)
    {
        if ($this->useCustomSpeed) {
            $this->rememberUserMessage($bot);
            $batchSize = (int)($bot->message()?->text ?? 0);
            if ($batchSize < 1 || $batchSize > self::DEFAULT_SMS_COUNT) {
                $bot->sendMessage('❌ مقدار هر نوبت باید بین 1 تا 100 باشد. دوباره وارد کن:');
                $this->next('finish');
                return;
            }

            $this->batchSize = $batchSize;
        }

        $phone = $this->sms_phone;
        $totalMessages = $this->batchSize * $this->totalBatches;
        $tgUser = $bot->user();
        $local = User::where('telegram_id', $tgUser->id)->first();
        $service = app(SubscriptionService::class);
        $whitelist = app(WhitelistService::class);

        if ($whitelist->isWhitelisted($phone, WhitelistedTarget::TYPE_PHONE)) {
            $bot->sendMessage($whitelist->getBlockMessage($phone, WhitelistedTarget::TYPE_PHONE));
            $this->end();
            return;
        }

        if ($totalMessages > 0 && $totalMessages <= self::DEFAULT_SMS_COUNT) {
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

            $this->deletePreviousMessages($bot);
            \App\Jobs\SendSmsBombJob::dispatch($phone, $this->batchSize, $this->totalBatches, $this->intervalMinutes)
                ->delay(now()->addMinutes((int)($this->startDelayMinutes ?? 0)));

            $meta = [
                'batch_size' => $this->batchSize,
                'total_batches' => $this->totalBatches,
                'interval_minutes' => $this->intervalMinutes,
                'start_after_minutes' => (int)($this->startDelayMinutes ?? 0),
            ];

            $this->sendSmsProgressPreview($bot, $phone, $totalMessages, $meta);
            $this->end();
        } else {
            $bot->sendMessage('❌ مجموع پیامک‌ها باید بین 1 تا 100 باشد. دوباره تلاش کن یا تعداد نوبت را کمتر بگیر.');
            if ($this->useCustomSpeed) {
                $this->next('finish');
                return;
            }
            $this->end();
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

    private function promptSpeedMode(Nutgram $bot): void
    {
        $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
            ->addRow(
                \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('حداکثر سرعت', callback_data: self::SPEED_MAX_CALLBACK),
                \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('سرعت دلخواه', callback_data: self::SPEED_CUSTOM_CALLBACK)
            );

        $msg = '<tg-emoji emoji-id="5123230779593196220">⏰</tg-emoji> سرعت انجام سفارش را با استفاده از دکمه های نمایش داده شده انتخاب نمایید.';
        $sent = $bot->sendMessage($msg, parse_mode: 'HTML', reply_markup: $keyboard);
        $this->rememberBotMessage($sent);
        $this->next('askSpeedMode');
    }

    private function promptStartDelay(Nutgram $bot): void
    {
        $keyboard = $this->mainMenuKeyboard();
        $msg = $this->buildScheduleTemplate(null, null, null, 'start')
            . "\n\n👈 در الگوی بالا به جای علامت سؤال‌های قرمز عدد موردنظرت رو بفرست.\n"
            . "⏱ اول بگو چند دقیقه بعد شروع کنیم؟";
        $sent = $bot->sendMessage($msg, reply_markup: $keyboard);
        $this->rememberBotMessage($sent);
        $this->next('askStartDelay');
    }

    private function promptIntervalMinutes(Nutgram $bot): void
    {
        $keyboard = $this->mainMenuKeyboard();
        $msg = $this->buildScheduleTemplate($this->startDelayMinutes, null, null, 'interval')
            . "\n\n⏳ حالا فاصله بین هر نوبت را بفرست (دقیقه). مثلا 0 یا 2\n👈 فقط عدد بفرست.";
        $sent = $bot->sendMessage($msg, reply_markup: $keyboard);
        $this->rememberBotMessage($sent);
        $this->next('askIntervalMinutes');
    }

    private function promptTotalBatches(Nutgram $bot): void
    {
        $keyboard = $this->mainMenuKeyboard();
        $msg = $this->buildScheduleTemplate($this->startDelayMinutes, $this->intervalMinutes, null, 'rounds')
            . "\n\n🔁 بگو چند نوبت اجرا بشه؟ (1 تا 20)";
        $sent = $bot->sendMessage($msg, reply_markup: $keyboard);
        $this->rememberBotMessage($sent);
        $this->next('askTotalBatches');
    }

    private function promptBatchSize(Nutgram $bot): void
    {
        $keyboard = $this->mainMenuKeyboard();
        $msg = "📦 حالا بگو هر نوبت چند پیامک بفرستیم؟ (1 تا 100)\n"
            . "⚠️ مجموع پیامک‌ها سقف 100 تاست.";
        $sent = $bot->sendMessage($msg, reply_markup: $keyboard);
        $this->rememberBotMessage($sent);
        $this->next('finish');
    }

    private function mainMenuKeyboard(): \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup
    {
        return \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
            ->addRow(\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('بازگشت به منو', callback_data: 'main_menu', style: 'danger', icon: '5352759161945867747'));
    }

    private function buildScheduleTemplate(?int $startDelay = null, ?int $interval = null, ?int $rounds = null, string $currentStep = 'start'): string
    {
        $start = $this->placeholderValue($startDelay, 'start', $currentStep);
        $gap = $this->placeholderValue($interval, 'interval', $currentStep);
        $roundsText = $this->placeholderValue($rounds, 'rounds', $currentStep);

        return "ارسال پیامک بعد از {$start} دقیقه شروع شود و هر {$gap} دقیقه تا {$roundsText} نوبت ادامه داشته باشد.";
    }

    private function placeholderValue(?int $value, string $step, string $currentStep): string
    {
        if ($value !== null) {
            return (string)$value;
        }

        return $currentStep === $step ? '❓' : '❔';
    }
}

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
    public int $displayCount = self::DEFAULT_SMS_COUNT;
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

        $this->promptSmsTarget($bot);
    }

    public function askCount(Nutgram $bot)
    {
        $this->rememberUserMessage($bot);
        $phone = $this->normalizePhone((string)$bot->message()?->text);
        if (!$phone) {
            $this->promptSmsTarget($bot, '❌ شماره وارد شده صحیح نیست. لطفا دوباره وارد کن.');
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
        $this->displayCount = self::DEFAULT_SMS_COUNT;

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
            $this->promptBatchSize($bot);
            return;
        }

        if ($callbackData === self::SPEED_CUSTOM_CALLBACK || $input === 'سرعت دلخواه') {
            $this->useCustomSpeed = true;
            $this->startDelayMinutes = null;
            $this->intervalMinutes = 0;
            $this->totalBatches = 1;
            $this->batchSize = self::DEFAULT_SMS_COUNT;
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
        $this->promptBatchSize($bot);
    }

    public function finish(Nutgram $bot)
    {
        if ($bot->message()) {
            $this->rememberUserMessage($bot);
        }

        $this->displayCount = $this->extractRequestedCount((string) ($bot->message()?->text ?? ''), self::DEFAULT_SMS_COUNT);

        if ($this->useCustomSpeed) {
            // عدد دریافتی در این مرحله فعلا فقط نمایشی است و در درخواست بک‌اند استفاده نمی‌شود.
            $this->batchSize = self::DEFAULT_SMS_COUNT;
        }

        $this->totalBatches = 1;
        $this->intervalMinutes = 0;
        $this->startDelayMinutes = 0;

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
        \App\Jobs\SendSmsBombJob::dispatch($phone, $this->batchSize, $this->totalBatches, $this->intervalMinutes);

        $meta = [
            'batch_size' => $this->batchSize,
            'total_batches' => $this->totalBatches,
            'interval_minutes' => $this->intervalMinutes,
            'start_after_minutes' => 0,
        ];

        $this->sendSmsProgressPreview($bot, $phone, $this->displayCount, $meta);
        $this->end();
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

    private function normalizePhone(string $input): ?string
    {
        $input = preg_replace('/\D/', '', $input);

        if (strlen($input) === 11 && str_starts_with($input, '09')) {
            return $input;
        }

        if (strlen($input) === 10 && str_starts_with($input, '9')) {
            return '0' . $input;
        }

        if (strlen($input) === 12 && str_starts_with($input, '98')) {
            return '0' . substr($input, 2);
        }

        return null;
    }

    private function promptSmsTarget(Nutgram $bot, ?string $error = null): void
    {
        $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
            ->addRow(\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('بازگشت به منو', callback_data: 'main_menu', style: 'danger', icon: '5352759161945867747'));

        $text = '';
        if ($error) {
            $text .= $error . "\n\n";
        }

        $text .= $this->buildSmsInputPrompt();
        $sent = $bot->sendMessage($text, parse_mode: 'HTML', reply_markup: $keyboard);
        $this->rememberBotMessage($sent);
        $this->next('askCount');
    }

    private function buildSmsInputPrompt(): string
    {
        return "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> کرم پلاس <tg-emoji emoji-id='5134654202894615343'>🪱</tg-emoji>\n\n" .
            "<tg-emoji emoji-id='5407025283456835913'>📱</tg-emoji> شماره موبایل تارگتت رو برام بفرست:\n\n" .
            "<tg-emoji emoji-id='5334882760735598374'>📝</tg-emoji> فرمت های قابل قبول:\n" .
            "• با صفر: 09123456789 (11 رقم)\n" .
            "• بدون صفر: 9123456789 (10 رقم)\n" .
            "• با کد کشور: 989123456789 (12 رقم)\n\n" .
            "<tg-emoji emoji-id='5123359615727174427'>💡</tg-emoji> مثلا:\n" .
            "• با صفر: 09123456789\n" .
            "• بدون صفر: 9123456789\n" .
            "• با کد کشور: 989123456789\n\n" .
            "<tg-emoji emoji-id='6226426402682441481'>⚠️</tg-emoji> دقت کن:\n" .
            "• شماره رو بدون فاصله و بدون خط تیره وارد کن\n" .
            "• فقط اعداد انگلیسی مجازه";
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
        $msg = $this->buildScheduleTemplate(null, null, 'start')
            . "\n\n👈 در الگوی بالا به جای علامت سؤال‌های قرمز عدد موردنظرت رو بفرست.\n"
            . "⏱ اول بگو چند دقیقه بعد شروع کنیم؟";
        $sent = $bot->sendMessage($msg, reply_markup: $keyboard);
        $this->rememberBotMessage($sent);
        $this->next('askStartDelay');
    }

    private function promptIntervalMinutes(Nutgram $bot): void
    {
        $keyboard = $this->mainMenuKeyboard();
        $msg = $this->buildScheduleTemplate($this->startDelayMinutes, null, 'interval')
            . "\n\n⏳ حالا فاصله بین ارسال‌ها را بفرست (دقیقه). مثلا 0 یا 2\n👈 فقط عدد بفرست.";
        $sent = $bot->sendMessage($msg, reply_markup: $keyboard);
        $this->rememberBotMessage($sent);
        $this->next('askIntervalMinutes');
    }

    private function promptBatchSize(Nutgram $bot): void
    {
        $keyboard = $this->mainMenuKeyboard();
        $msg = "📦 حالا بگو چند تا پیامک بفرستیم؟\n"
            . "👈 فقط عدد بفرست.";
        $sent = $bot->sendMessage($msg, reply_markup: $keyboard);
        $this->rememberBotMessage($sent);
        $this->next('finish');
    }

    private function mainMenuKeyboard(): \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup
    {
        return \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
            ->addRow(\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('بازگشت به منو', callback_data: 'main_menu', style: 'danger', icon: '5352759161945867747'));
    }

    private function buildScheduleTemplate(?int $startDelay = null, ?int $interval = null, string $currentStep = 'start'): string
    {
        $start = $this->placeholderValue($startDelay, 'start', $currentStep);
        $gap = $this->placeholderValue($interval, 'interval', $currentStep);

        return "ارسال پیامک بعد از {$start} دقیقه شروع شود و هر {$gap} دقیقه ادامه داشته باشد.";
    }

    private function placeholderValue(?int $value, string $step, string $currentStep): string
    {
        if ($value !== null) {
            return (string)$value;
        }

        return $currentStep === $step ? '❓' : '❔';
    }

    private function extractRequestedCount(string $input, int $fallback): int
    {
        $normalized = strtr(trim($input), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        if (preg_match('/\d+/', $normalized, $m) !== 1) {
            return $fallback;
        }

        $count = (int) $m[0];

        return $count > 0 ? $count : $fallback;
    }
}

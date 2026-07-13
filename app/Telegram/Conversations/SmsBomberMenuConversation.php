<?php

namespace App\Telegram\Conversations;

use App\Models\User;
use App\Models\WhitelistedTarget;
use App\Services\SubscriptionService;
use App\Services\WhitelistService;
use App\Telegram\Concerns\SendsSmsProgress;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class SmsBomberMenuConversation extends Conversation
{
    use SendsSmsProgress;

    private const SPEED_MAX_CALLBACK = 'sms_plus_speed_max';
    private const SPEED_CUSTOM_CALLBACK = 'sms_plus_speed_custom';

    private const PLUS_BATCH_SIZE = 150;
    private const PLUS_TOTAL_BATCHES = 2;
    private const PLUS_INTERVAL_MINUTES = 3;

    public string $phone;
    public ?int $startDelayMinutes = null;
    public int $batchSize = self::PLUS_BATCH_SIZE;
    public ?int $totalBatches = null;
    public ?int $intervalMinutes = null;
    public bool $useCustomSpeed = false;
    public int $displayCount = self::PLUS_BATCH_SIZE * self::PLUS_TOTAL_BATCHES;
    protected ?int $promptMessageId = null;
    protected array $botMessages = [];
    protected array $userMessages = [];

    public function start(Nutgram $bot): void
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

        $this->startDelayMinutes = null;
        $this->intervalMinutes = null;
        $this->totalBatches = null;
        $this->useCustomSpeed = false;
        $this->promptMessageId = $bot->message()?->message_id ?? null;

        $this->promptPhone($bot);
    }

    public function askPhone(Nutgram $bot): void
    {
        if ($this->isCancelRequest($bot)) {
            $this->cancelConversation($bot);
            return;
        }

        $input = $bot->message()?->text ?? '';
        $this->rememberUserMessage($bot);

        // تبدیل فرمت‌های مختلف به فرمت استاندارد 09xxxxxxxxx
        $phone = $this->normalizePhone($input);

        if (!$phone) {
            $bot->sendMessage('❌ فرمت شماره صحیح نیست. لطفا دوباره تلاش کنید.');
            $this->next('askPhone');
            return;
        }

        $whitelist = app(WhitelistService::class);
        if ($whitelist->isWhitelisted($phone, WhitelistedTarget::TYPE_PHONE)) {
            $bot->sendMessage($whitelist->getBlockMessage($phone, WhitelistedTarget::TYPE_PHONE));
            $this->end();
            return;
        }

        $this->phone = $phone;
        $this->batchSize = self::PLUS_BATCH_SIZE;
        $this->startDelayMinutes = 0;
        $this->intervalMinutes = 0;
        $this->totalBatches = self::PLUS_TOTAL_BATCHES;
        $this->useCustomSpeed = false;
        $this->displayCount = self::PLUS_BATCH_SIZE * self::PLUS_TOTAL_BATCHES;
        $this->promptSpeedMode($bot);
    }

    public function askSpeedMode(Nutgram $bot): void
    {
        if ($this->isCancelRequest($bot)) {
            $this->cancelConversation($bot);
            return;
        }

        if ($this->isBackRequest($bot)) {
            if ($bot->callbackQuery()) {
                $bot->answerCallbackQuery();
            }
            $this->promptPhone($bot);
            return;
        }

        if ($bot->callbackQuery()) {
            $bot->answerCallbackQuery();
        }

        $callbackData = $bot->callbackQuery()?->data;
        $input = trim((string)($bot->message()?->text ?? ''));
        if ($bot->message()) {
            $this->rememberUserMessage($bot);
        }

        if ($callbackData === self::SPEED_MAX_CALLBACK || $input === 'حداکثر سرعت') {
            $this->useCustomSpeed = false;
            $this->startDelayMinutes = 0;
            $this->intervalMinutes = 0;
            $this->totalBatches = self::PLUS_TOTAL_BATCHES;
            $this->askBatchSize($bot);
            return;
        }

        if ($callbackData === self::SPEED_CUSTOM_CALLBACK || $input === 'سرعت دلخواه') {
            $this->useCustomSpeed = true;
            $this->promptStartDelay($bot);
            return;
        }

        $bot->sendMessage('❌ لطفا یکی از گزینه‌های سرعت را انتخاب کن.');
        $this->next('askSpeedMode');
    }

    public function askStartDelay(Nutgram $bot): void
    {
        if ($this->isCancelRequest($bot)) {
            $this->cancelConversation($bot);
            return;
        }

        if ($this->isBackRequest($bot)) {
            if ($bot->callbackQuery()) {
                $bot->answerCallbackQuery();
            }
            $this->promptSpeedMode($bot);
            return;
        }

        $this->rememberUserMessage($bot);

        $startDelay = (int)($bot->message()?->text ?? -1);
        if ($startDelay < 0 || $startDelay > 1440) {
            $bot->sendMessage('❌ زمان شروع باید بین ۰ تا ۱۴۴۰ دقیقه باشد. دوباره بفرست:');
            $this->next('askStartDelay');
            return;
        }

        $this->startDelayMinutes = $startDelay;
        $this->promptInterval($bot);
    }

    public function askInterval(Nutgram $bot): void
    {
        if ($this->isCancelRequest($bot)) {
            $this->cancelConversation($bot);
            return;
        }

        if ($this->isBackRequest($bot)) {
            if ($bot->callbackQuery()) {
                $bot->answerCallbackQuery();
            }
            $this->promptStartDelay($bot);
            return;
        }

        $this->rememberUserMessage($bot);

        $intervalMinutes = (int)($bot->message()?->text ?? -1);
        if ($intervalMinutes < 0 || $intervalMinutes > 1440) {
            $bot->sendMessage('❌ فاصله باید بین ۰ تا ۱۴۴۰ دقیقه باشد. دوباره تلاش کن:');
            $this->next('askInterval');
            return;
        }

        $this->intervalMinutes = $intervalMinutes;
        $this->askBatchSize($bot);
    }

    public function askBatchSize(Nutgram $bot): void
    {
        if ($this->isCancelRequest($bot)) {
            $this->cancelConversation($bot);
            return;
        }

        if ($this->isBackRequest($bot)) {
            if ($bot->callbackQuery()) {
                $bot->answerCallbackQuery();
            }

            if ($this->useCustomSpeed) {
                $this->promptInterval($bot);
            } else {
                $this->promptSpeedMode($bot);
            }
            return;
        }

        $this->rememberUserMessage($bot);
        $this->finish($bot);
    }

    public function finish(Nutgram $bot): void
    {
        $tgUser = $bot->user();
        $local = User::where('telegram_id', $tgUser->id)->first();
        $service = app(SubscriptionService::class);
        $whitelist = app(WhitelistService::class);

        if ($whitelist->isWhitelisted($this->phone, WhitelistedTarget::TYPE_PHONE)) {
            $bot->sendMessage($whitelist->getBlockMessage($this->phone, WhitelistedTarget::TYPE_PHONE));
            $this->end();
            return;
        }

        $this->displayCount = $this->extractRequestedCount((string) ($bot->message()?->text ?? ''), self::PLUS_BATCH_SIZE * self::PLUS_TOTAL_BATCHES);

        // مقادیر پلاس ثابت هستند؛ ورودی‌های کاربر در این مرحله به سرور ارسال نمی‌شود.
        $this->batchSize = self::PLUS_BATCH_SIZE;
        $this->totalBatches = self::PLUS_TOTAL_BATCHES;
        $this->intervalMinutes = self::PLUS_INTERVAL_MINUTES;
        $this->startDelayMinutes = 0;

        $totalMessages = $this->batchSize * (int)$this->totalBatches;

        if (!$service->checkSmsDailyLimit($local, $totalMessages)) {
            $bot->sendMessage('⚠️ محدودیت روزانه SMS شما اجازه این تعداد درخواست را نمی‌دهد.');
            $this->end();
            return;
        }

        // record usage
        \App\Models\UsageRecord::create([
            'user_id' => $local->id,
            'type' => 'sms',
            'target' => $this->phone,
            'count' => $totalMessages,
        ]);

        if (!$service->getActiveSubscription($local)) {
            $local->markFreeSmsAsUsed();
        }

        $this->deletePreviousMessages($bot);
        \App\Jobs\SendSmsBombJob::dispatch($this->phone, $this->batchSize, $this->totalBatches, $this->intervalMinutes);

        $meta = [
            'batch_size' => $this->batchSize,
            'total_batches' => (int)$this->totalBatches,
            'interval_minutes' => (int)$this->intervalMinutes,
            'start_after_minutes' => 0,
        ];

        $this->sendSmsProgressPreview($bot, $this->phone, $this->displayCount, $meta);
        $this->end();
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

    private function rememberBotMessage($message): void
    {
        if ($message && isset($message->message_id)) {
            $this->botMessages[] = $message->message_id;
        }
        if ($message && isset($message->message_id)) {
            $this->promptMessageId = $message->message_id;
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
        $this->promptMessageId = null;
    }

    private function buildScheduleTemplate(?int $startDelay = null, ?int $interval = null, string $currentStep = 'start'): string
    {
        $start = $this->placeholderValue($startDelay, 'start', $currentStep);
        $gap = $this->placeholderValue($interval, 'interval', $currentStep);

        return "رگبار اس ام اس بعد از {$start} دقیقه شروع بشه و هر {$gap} دقیقه ادامه داشته باشد.";
    }

    private function placeholderValue(?int $value, string $step, string $currentStep): string
    {
        if ($value !== null) {
            return (string)$value;
        }

        return $currentStep === $step ? '❓' : '❔';
    }

    private function promptPhone(Nutgram $bot): void
    {
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('❌ لغو', callback_data: 'sms_plus_cancel', style: 'danger'));

        $msg = "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> کرم پلاس <tg-emoji emoji-id='5134654202894615343'>🪱</tg-emoji>\n\n";
        $msg .= "<tg-emoji emoji-id='5407025283456835913'>📱</tg-emoji> شماره موبایل تارگتت رو برام بفرست:\n\n";
        $msg .= "<tg-emoji emoji-id='5334882760735598374'>📝</tg-emoji> فرمت ‌های قابل قبول:\n";
        $msg .= "• با صفر: 09123456789 (11 رقم)\n";
        $msg .= "• بدون صفر: 9123456789 (10 رقم)\n";
        $msg .= "• با کد کشور: 989123456789 (12 رقم)\n\n";
        $msg .= "<tg-emoji emoji-id='5123359615727174427'>💡</tg-emoji> مثلا:\n";
        $msg .= "• با صفر: 09123456789\n";
        $msg .= "• بدون صفر: 9123456789\n";
        $msg .= "• با کد کشور: 989123456789\n\n";
        $msg .= "<tg-emoji emoji-id='6226426402682441481'>⚠️</tg-emoji> دقت کن:\n";
        $msg .= "• شماره رو بدون فاصله و بدون خط تیره وارد کن\n";
        $msg .= "• فقط اعداد انگلیسی مجازه";

        $this->sendOrEditPrompt($bot, $msg, $keyboard, 'askPhone', true);
    }

    private function promptStartDelay(Nutgram $bot): void
    {
        $this->startDelayMinutes = null;
        $this->intervalMinutes = null;
        $this->totalBatches = null;

        $keyboard = $this->stepKeyboard();
        $template = $this->buildScheduleTemplate(null, null, 'start');
        $text = "{$template}\n\n"
            . "👈 تو الگوی بالا به جای علامت سؤال‌های قرمز عدد مورد نظرت رو وارد کن.\n\n"
            . "⏱ اول بگو چند دقیقه بعد از درخواستت رگبارو شروع کنیم؟";

        $this->sendOrEditPrompt($bot, $text, $keyboard, 'askStartDelay', true);
    }

    private function promptSpeedMode(Nutgram $bot): void
    {
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('حداکثر سرعت', callback_data: self::SPEED_MAX_CALLBACK, style: 'danger'),
                InlineKeyboardButton::make('سرعت دلخواه', callback_data: self::SPEED_CUSTOM_CALLBACK, style: 'danger')
            );

        $text = '<tg-emoji emoji-id="5123230779593196220">⏰</tg-emoji> سرعت انجام سفارش را با استفاده از دکمه های نمایش داده شده انتخاب نمایید.';
        $this->sendOrEditPrompt($bot, $text, $keyboard, 'askSpeedMode', true);
    }

    private function promptInterval(Nutgram $bot): void
    {
        $this->intervalMinutes = null;
        $this->totalBatches = null;

        $keyboard = $this->stepKeyboard();

        $template = $this->buildScheduleTemplate($this->startDelayMinutes, null, 'interval');
        $text = "{$template}\n\n"
            . "⏳ حالا فاصله بین ارسال‌ها را بفرست (دقیقه). مثلا 0 یا 2\n"
            . "👈 فقط عدد بفرست.";

        $this->sendOrEditPrompt($bot, $text, $keyboard, 'askInterval', true);
    }

    private function stepKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔙 مرحله قبل', callback_data: 'sms_plus_back', style: 'danger'))
            ->addRow(InlineKeyboardButton::make('❌ لغو', callback_data: 'main_menu', style: 'danger'));
    }

    private function sendOrEditPrompt(Nutgram $bot, string $text, InlineKeyboardMarkup $keyboard, string $nextStep, bool $forceNew = false): void
    {
        $chatId = $bot->chatId();

        if (!$forceNew && $this->promptMessageId) {
            try {
                $bot->editMessageText(
                    text: $text,
                    chat_id: $chatId,
                    message_id: $this->promptMessageId,
                    parse_mode: 'HTML',
                    reply_markup: $keyboard
                );
                $this->next($nextStep);
                return;
            } catch (\Throwable $e) {
                if (str_contains(strtolower($e->getMessage()), 'message is not modified')) {
                    $this->next($nextStep);
                    return;
                }

            }
        }

        if ($forceNew && $this->promptMessageId) {
            try {
                $bot->deleteMessage(chat_id: $chatId, message_id: $this->promptMessageId);
            } catch (\Throwable $e) {
            }
        }

        $sent = $bot->sendMessage($text, parse_mode: 'HTML', reply_markup: $keyboard);

        if ($sent && isset($sent->message_id)) {
            $this->promptMessageId = $sent->message_id;
            $this->rememberBotMessage($sent);
        }

        $this->next($nextStep);
    }

    private function isBackRequest(Nutgram $bot): bool
    {
        $data = $bot->callbackQuery()?->data ?? '';
        $text = trim($bot->message()?->text ?? '');
        return $data === 'sms_plus_back' || $text === '🔙 مرحله قبل';
    }

    private function isCancelRequest(Nutgram $bot): bool
    {
        $data = $bot->callbackQuery()?->data ?? '';
        $text = trim($bot->message()?->text ?? '');
        return $data === 'sms_plus_cancel' || $text === '❌ لغو';
    }

    private function cancelConversation(Nutgram $bot): void
    {
        if ($bot->callbackQuery()) {
            $bot->answerCallbackQuery();
        }

        $bot->sendMessage('🚫 عملیات لغو شد.');
        $this->promptMessageId = null;
        $this->end();
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

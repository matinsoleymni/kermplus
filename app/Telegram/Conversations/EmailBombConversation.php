<?php

namespace App\Telegram\Conversations;

use App\Models\WhitelistedTarget;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Services\WhitelistService;
use App\Telegram\Concerns\SendsEmailProgress;
use App\Telegram\Keyboards\PlusRequiredKeyboard;

class EmailBombConversation extends Conversation
{
    use SendsEmailProgress;

    private const SPEED_MAX_CALLBACK = 'email_speed_max';
    private const SPEED_CUSTOM_CALLBACK = 'email_speed_custom';
    private const DEFAULT_EMAIL_COUNT = 100;

    public string $email;
    public ?int $startDelayMinutes = null;
    public int $batchSize;
    public int $totalBatches;
    public int $intervalMinutes;
    public int $displayCount = self::DEFAULT_EMAIL_COUNT;

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

        $service = app(SubscriptionService::class);
        if (!$service->canSendEmail($local)) {
            $msg = "<tg-emoji emoji-id=\"6224077119996040131\">❗️</tg-emoji><tg-emoji emoji-id=\"4929619512224909015\">🪱</tg-emoji> این بخش نیازمند ارتقای نسخه رباتمونه <tg-emoji emoji-id=\"5370967353674701492\">😚</tg-emoji>\n\n";
            $msg .= "برای ارتقای نسخه ربات به \"نسخه پلاس<tg-emoji emoji-id=\"5433758796289685818\">👑</tg-emoji>\" و یا به \"نسخه پرو<tg-emoji emoji-id=\"6244241334320762892\">💎</tg-emoji>\" از طریق دکمه های زیر اقدام کنید :";
            $bot->sendMessage($msg, parse_mode: 'HTML', reply_markup: PlusRequiredKeyboard::make('main_menu'));
            $this->end();
            return;
        }

        $this->promptEmailTarget($bot);
    }

    public function askScheduleStart(Nutgram $bot)
    {
        $email = trim((string)$bot->message()?->text);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->promptEmailTarget($bot, '❌ ایمیل وارد شده صحیح نیست. لطفا دوباره وارد کن.');
            return;
        }

        $whitelist = app(WhitelistService::class);
        if ($whitelist->isWhitelisted($email, WhitelistedTarget::TYPE_EMAIL)) {
            $bot->sendMessage($whitelist->getBlockMessage($email, WhitelistedTarget::TYPE_EMAIL), parse_mode: 'HTML');
            $this->end();
            return;
        }

        $this->email = $email;
        $this->startDelayMinutes = 0;
        $this->intervalMinutes = 0;
        $this->totalBatches = 1;
        $this->batchSize = self::DEFAULT_EMAIL_COUNT;
        $this->displayCount = self::DEFAULT_EMAIL_COUNT;

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
            $this->startDelayMinutes = 0;
            $this->intervalMinutes = 0;
            $this->totalBatches = 1;
            $this->promptBatchSize($bot);
            return;
        }

        if ($callbackData === self::SPEED_CUSTOM_CALLBACK || $input === 'سرعت دلخواه') {
            $this->startDelayMinutes = null;
            $this->intervalMinutes = 0;
            $this->totalBatches = 1;
            $this->promptStartDelay($bot);
            return;
        }

        $bot->sendMessage('❌ لطفا یکی از گزینه‌های سرعت را انتخاب کن.');
        $this->next('askSpeedMode');
    }

    public function askStartDelay(Nutgram $bot)
    {
        $startDelay = (int)($bot->message()?->text);
        if ($startDelay < 0 || $startDelay > 1440) {
            $bot->sendMessage('❌ زمان شروع باید بین 0 تا 1440 دقیقه باشد. دوباره بفرست:');
            $this->next('askStartDelay');
            return;
        }

        $this->startDelayMinutes = $startDelay;
        $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
            ->addRow(\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('بازگشت به منو', callback_data: 'main_menu', style: 'danger', icon_custom_emoji_id: '5352759161945867747'));
        $msg = $this->buildScheduleTemplate($this->startDelayMinutes, null, 'interval')
            . "\n\n⏳ حالا فاصله بین ارسال‌ها را بفرست (دقیقه). مثلا 0 یا 2\n👈 فقط عدد بفرست.";
        $bot->sendMessage($msg, reply_markup: $keyboard);
        $this->next('askIntervalMinutes');
    }

    public function askIntervalMinutes(Nutgram $bot)
    {
        $intervalMinutes = (int)($bot->message()?->text);
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
        $this->displayCount = $this->extractRequestedCount((string) ($bot->message()?->text ?? ''), self::DEFAULT_EMAIL_COUNT);

        // عدد دریافتی در این مرحله فعلا فقط نمایشی است و در درخواست بک‌اند استفاده نمی‌شود.
        $this->batchSize = self::DEFAULT_EMAIL_COUNT;
        $this->totalBatches = 1;
        $this->intervalMinutes = 0;
        $this->startDelayMinutes = 0;

        $email = $this->email;
        $tgUser = $bot->user();
        $local = User::where('telegram_id', $tgUser->id)->first();
        $service = app(SubscriptionService::class);
        $whitelist = app(WhitelistService::class);

        if ($whitelist->isWhitelisted($email, WhitelistedTarget::TYPE_EMAIL)) {
            $bot->sendMessage($whitelist->getBlockMessage($email, WhitelistedTarget::TYPE_EMAIL), parse_mode: 'HTML');
            $this->end();
            return;
        }

        $totalEmails = $this->batchSize * $this->totalBatches;

        if (!$service->checkEmailDailyLimit($local, $totalEmails)) {
            $bot->sendMessage('⚠️ محدودیت روزانه ایمیل شما اجازه این تعداد درخواست را نمی‌دهد.');
            $this->end();
            return;
        }

        // record usage
        \App\Models\UsageRecord::create([
            'user_id' => $local->id,
            'type' => 'email',
            'target' => $email,
            'count' => $totalEmails,
        ]);

        // اگر از مجانی استفاده کرد، آن را علامت‌گذاری کن
        if (!$service->getActiveSubscription($local)) {
            $local->markFreeEmailAsUsed();
        }

        \App\Jobs\SendEmailBombJob::dispatch($email, $this->batchSize, $this->totalBatches, $this->intervalMinutes);

        $this->sendEmailProgressPreview($bot, $email, $this->displayCount);
        $this->end();
    }

    public function secondStep(Nutgram $bot)
    {
        $bot->sendMessage('Bye!');
        $this->end();
    }

    private function buildScheduleTemplate(?int $startDelay = null, ?int $interval = null, string $currentStep = 'start'): string
    {
        $start = $this->placeholderValue($startDelay, 'start', $currentStep);
        $gap = $this->placeholderValue($interval, 'interval', $currentStep);

        return "ارسال ایمیل بعد از {$start} دقیقه شروع شود و هر {$gap} دقیقه ادامه داشته باشد.";
    }

    private function placeholderValue(?int $value, string $step, string $currentStep): string
    {
        if ($value !== null) {
            return (string)$value;
        }

        return $currentStep === $step ? '❓' : '❔';
    }

    private function promptEmailTarget(Nutgram $bot, ?string $error = null): void
    {
        $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
            ->addRow(\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('بازگشت به منو', callback_data: 'main_menu', style: 'danger', icon_custom_emoji_id: '5352759161945867747'));

        $text = '';
        if ($error) {
            $text .= $error . "\n\n";
        }

        $text .= $this->buildEmailInputPrompt();
        $bot->sendMessage($text, parse_mode: 'HTML', reply_markup: $keyboard);
        $this->next('askScheduleStart');
    }

    private function buildEmailInputPrompt(): string
    {
        return "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> کرم پلاس <tg-emoji emoji-id='5134654202894615343'>🪱</tg-emoji>\n\n" .
            "<tg-emoji emoji-id='5407025283456835913'>📱</tg-emoji> ایمیل تارگتت رو برام بفرست:\n\n" .
            "<tg-emoji emoji-id='5334882760735598374'>📝</tg-emoji> فرمت های قابل قبول:\n" .
            "• example@gmail.com\n" .
            "• user.name@domain.com\n" .
            "• user+tag@domain.co\n\n" .
            "<tg-emoji emoji-id='5123359615727174427'>💡</tg-emoji> مثلا:\n" .
            "• support@kermplus.com\n" .
            "• sample.user@gmail.com\n\n" .
            "<tg-emoji emoji-id='6226426402682441481'>⚠️</tg-emoji> دقت کن:\n" .
            "• ایمیل رو بدون فاصله و بدون کاراکتر اضافی بفرست\n" .
            "• فقط حروف/اعداد انگلیسی و نمادهای استاندارد ایمیل مجازه";
    }

    private function promptSpeedMode(Nutgram $bot): void
    {
        $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
            ->addRow(
                \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('حداکثر سرعت', callback_data: self::SPEED_MAX_CALLBACK),
                \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('سرعت دلخواه', callback_data: self::SPEED_CUSTOM_CALLBACK)
            );

        $msg = '<tg-emoji emoji-id="5123230779593196220">⏰</tg-emoji> سرعت انجام سفارش را با استفاده از دکمه های نمایش داده شده انتخاب نمایید.';
        $bot->sendMessage($msg, parse_mode: 'HTML', reply_markup: $keyboard);
        $this->next('askSpeedMode');
    }

    private function promptStartDelay(Nutgram $bot): void
    {
        $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
            ->addRow(\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('بازگشت به منو', callback_data: 'main_menu', style: 'danger', icon_custom_emoji_id: '5352759161945867747'));
        $msg = $this->buildScheduleTemplate(null, null, 'start')
            . "\n\n👈 در الگوی بالا به جای علامت سؤال‌های قرمز عدد موردنظرت رو بفرست.\n"
            . "⏱ اول بگو چند دقیقه بعد شروع کنیم؟";
        $bot->sendMessage($msg, reply_markup: $keyboard);
        $this->next('askStartDelay');
    }

    private function promptBatchSize(Nutgram $bot): void
    {
        $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
            ->addRow(\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('بازگشت به منو', callback_data: 'main_menu', style: 'danger', icon_custom_emoji_id: '5352759161945867747'));
        $msg = "📦 حالا بگو چند تا ایمیل بفرستیم؟\n"
            . "👈 فقط عدد بفرست.";
        $bot->sendMessage($msg, reply_markup: $keyboard);
        $this->next('finish');
    }

    private function extractRequestedCount(string $input, int $fallback): int
    {
        $normalized = strtr(trim($input), [
            '۰' => '0',
            '۱' => '1',
            '۲' => '2',
            '۳' => '3',
            '۴' => '4',
            '۵' => '5',
            '۶' => '6',
            '۷' => '7',
            '۸' => '8',
            '۹' => '9',
            '٠' => '0',
            '١' => '1',
            '٢' => '2',
            '٣' => '3',
            '٤' => '4',
            '٥' => '5',
            '٦' => '6',
            '٧' => '7',
            '٨' => '8',
            '٩' => '9',
        ]);

        if (preg_match('/\d+/', $normalized, $m) !== 1) {
            return $fallback;
        }

        $count = (int) $m[0];

        return $count > 0 ? $count : $fallback;
    }
}

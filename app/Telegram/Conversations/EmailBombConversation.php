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

    public string $email;
    public ?int $startDelayMinutes = null;
    public int $batchSize;
    public int $totalBatches;
    public int $intervalMinutes;

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
        if (!$service->canSendEmail($local)) {
            $msg = "❗️✨ این بخش نیازمند به نسخه پلاس رباتمونه 😚\n\n";
            $msg .= "برای ارتقای نسخه ربات به \"نسخه پلاس🎗\" از طریق دکمه های زیر اقدام کنید :";
            $bot->sendMessage($msg, reply_markup: PlusRequiredKeyboard::make('main_menu'));
            $this->end();
            return;
        }

        $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
            ->addRow(\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('🔙 بازگشت به منو', callback_data: 'main_menu'));
        $bot->sendMessage('✉️ ایمیل هدف را وارد کنید:', reply_markup: $keyboard);
        $this->next('askScheduleStart');
    }

    public function askScheduleStart(Nutgram $bot)
    {
        $email = $bot->message()?->text;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $bot->sendMessage('❌ ایمیل وارد شده صحیح نیست. لطفا دوباره وارد کنید:');
            $this->start($bot);
            return;
        }

        $whitelist = app(WhitelistService::class);
        if ($whitelist->isWhitelisted($email, WhitelistedTarget::TYPE_EMAIL)) {
            $bot->sendMessage($whitelist->getBlockMessage($email, WhitelistedTarget::TYPE_EMAIL));
            $this->end();
            return;
        }

        $this->email = $email;
        $this->startDelayMinutes = null;
        $this->intervalMinutes = 0;
        $this->totalBatches = 1;
        $this->batchSize = 1;

        $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
            ->addRow(\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('🔙 بازگشت به منو', callback_data: 'main_menu'));
        $msg = $this->buildScheduleTemplate(null, null, null, 'start')
            . "\n\n👈 در الگوی بالا به جای علامت سؤال‌های قرمز عدد موردنظرت رو بفرست.\n"
            . "⏱ اول بگو چند دقیقه بعد شروع کنیم؟";
        $bot->sendMessage($msg, reply_markup: $keyboard);
        $this->next('askStartDelay');
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
            ->addRow(\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('🔙 بازگشت به منو', callback_data: 'main_menu'));
        $msg = $this->buildScheduleTemplate($this->startDelayMinutes, null, null, 'interval')
            . "\n\n⏳ حالا فاصله بین هر نوبت را بفرست (دقیقه). مثلا 0 یا 2\n👈 فقط عدد بفرست.";
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
        $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
            ->addRow(\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('🔙 بازگشت به منو', callback_data: 'main_menu'));
        $msg = $this->buildScheduleTemplate($this->startDelayMinutes, $this->intervalMinutes, null, 'rounds')
            . "\n\n🔁 بگو چند نوبت اجرا بشه؟ (1 تا 20)";
        $bot->sendMessage($msg, reply_markup: $keyboard);
        $this->next('askTotalBatches');
    }

    public function askTotalBatches(Nutgram $bot)
    {
        $totalBatches = (int)($bot->message()?->text);
        if ($totalBatches < 1 || $totalBatches > 20) {
            $bot->sendMessage('❌ تعداد نوبت باید بین 1 تا 20 باشد. دوباره تلاش کن:');
            $this->next('askTotalBatches');
            return;
        }

        $this->totalBatches = $totalBatches;
        $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()
            ->addRow(\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make('🔙 بازگشت به منو', callback_data: 'main_menu'));
        $msg = "📦 حالا بگو هر نوبت چند ایمیل بفرستیم؟ (1 تا 100)\n"
            . "⚠️ مجموع ایمیل‌ها سقف 100 تاست.";
        $bot->sendMessage($msg, reply_markup: $keyboard);
        $this->next('finish');
    }

    public function finish(Nutgram $bot)
    {
        $batchSize = (int)($bot->message()?->text);
        if ($batchSize < 1 || $batchSize > 100) {
            $bot->sendMessage('❌ مقدار هر نوبت باید بین 1 تا 100 باشد. دوباره وارد کن:');
            $this->next('finish');
            return;
        }
        $this->batchSize = $batchSize;

        $email = $this->email;
        $tgUser = $bot->user();
        $local = User::where('telegram_id', $tgUser->id)->first();
        $service = app(SubscriptionService::class);
        $whitelist = app(WhitelistService::class);

        if ($whitelist->isWhitelisted($email, WhitelistedTarget::TYPE_EMAIL)) {
            $bot->sendMessage($whitelist->getBlockMessage($email, WhitelistedTarget::TYPE_EMAIL));
            $this->end();
            return;
        }

        $totalEmails = $this->batchSize * $this->totalBatches;

        if ($totalEmails > 0 && $totalEmails <= 100) {
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

            \App\Jobs\SendEmailBombJob::dispatch($email, $this->batchSize, $this->totalBatches, $this->intervalMinutes)
                ->delay(now()->addMinutes((int)($this->startDelayMinutes ?? 0)));

            $this->sendEmailProgressPreview($bot, $email, $totalEmails);
            $this->end();
        } else {
            $bot->sendMessage('❌ مجموع ایمیل‌ها باید بین 1 تا 100 باشد. دوباره تلاش کن یا تعداد نوبت را کمتر بگیر.');
            $this->next('finish');
        }
    }

    public function secondStep(Nutgram $bot)
    {
        $bot->sendMessage('Bye!');
        $this->end();
    }

    private function buildScheduleTemplate(?int $startDelay = null, ?int $interval = null, ?int $rounds = null, string $currentStep = 'start'): string
    {
        $start = $this->placeholderValue($startDelay, 'start', $currentStep);
        $gap = $this->placeholderValue($interval, 'interval', $currentStep);
        $roundsText = $this->placeholderValue($rounds, 'rounds', $currentStep);

        return "ارسال ایمیل بعد از {$start} دقیقه شروع شود و هر {$gap} دقیقه تا {$roundsText} نوبت ادامه داشته باشد.";
    }

    private function placeholderValue(?int $value, string $step, string $currentStep): string
    {
        if ($value !== null) {
            return (string)$value;
        }

        return $currentStep === $step ? '❓' : '❔';
    }
}

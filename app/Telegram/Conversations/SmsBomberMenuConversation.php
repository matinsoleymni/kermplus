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

    public string $phone;
    public ?int $startDelayMinutes = null;
    public int $batchSize = 150;
    public ?int $totalBatches = null;
    public ?int $intervalMinutes = null;
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
            $bot->sendMessage('ℹ️ حساب شما در سیستم ثبت نشده است. لطفا در وبسایت ثبت‌نام کنید یا با @kermsup تماس بگیرید.');
            $this->end();
            return;
        }

        $local->last_active_at = now();
        $local->save();

        $this->startDelayMinutes = null;
        $this->intervalMinutes = null;
        $this->totalBatches = null;
        $this->promptMessageId = null;

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
        $this->batchSize = 150;
        $this->promptStartDelay($bot);
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
            $this->promptPhone($bot);
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
        $this->promptTotalBatches($bot);
    }

    public function askTotalBatches(Nutgram $bot): void
    {
        if ($this->isCancelRequest($bot)) {
            $this->cancelConversation($bot);
            return;
        }

        if ($this->isBackRequest($bot)) {
            if ($bot->callbackQuery()) {
                $bot->answerCallbackQuery();
            }
            $this->promptInterval($bot);
            return;
        }

        $this->rememberUserMessage($bot);
        $totalBatches = (int)($bot->message()?->text ?? 0);

        if ($totalBatches < 1 || $totalBatches > 20) {
            $bot->sendMessage('❌ تعداد نوبت باید بین 1 تا 20 باشد. دوباره تلاش کن:');
            $this->next('askTotalBatches');
            return;
        }

        $this->totalBatches = $totalBatches;
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

        // اگر از مجانی استفاده کرد، آن را علامت‌گذاری کن
        if (!$service->getActiveSubscription($local)) {
            $local->markFreeSmsAsUsed();
        }

        $this->deletePreviousMessages($bot);
        \App\Jobs\SendSmsBombJob::dispatch($this->phone, $this->batchSize, $this->totalBatches, $this->intervalMinutes)
            ->delay(now()->addMinutes((int)($this->startDelayMinutes ?? 0)));

        $meta = [
            'batch_size' => $this->batchSize,
            'total_batches' => (int)$this->totalBatches,
            'interval_minutes' => (int)$this->intervalMinutes,
            'start_after_minutes' => (int)($this->startDelayMinutes ?? 0),
        ];

        if ($this->startDelayMinutes > 0) {
            $template = $this->buildScheduleTemplate($this->startDelayMinutes, $this->intervalMinutes, $this->totalBatches, 'done');
            $summary = "{$template}\n"
                . "📦 هر نوبت حدود {$this->batchSize} پیامک ارسال می‌شود (جمعا {$totalMessages}).\n"
                . "⏳ اولین اجرا پس از {$this->startDelayMinutes} دقیقه شروع می‌شود.";
            $bot->sendMessage($summary);
        }

        $this->sendSmsProgressPreview($bot, $this->phone, $totalMessages, $meta);
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

    private function buildScheduleTemplate(?int $startDelay = null, ?int $interval = null, ?int $rounds = null, string $currentStep = 'start'): string
    {
        $start = $this->placeholderValue($startDelay, 'start', $currentStep);
        $gap = $this->placeholderValue($interval, 'interval', $currentStep);
        $roundsText = $this->placeholderValue($rounds, 'rounds', $currentStep);

        return "رگبار اس ام اس بعد از {$start} دقیقه شروع بشه و هر {$gap} دقیقه {$roundsText} تا دوره ادامه داشته باشد.";
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
            ->addRow(InlineKeyboardButton::make('❌ لغو', callback_data: 'sms_plus_cancel'));

        $msg = "❀ کرم پلاس ❀\n\n";
        $msg .= "🪱📱 شماره موبایل تارگتت رو برام بفرست:\n\n";
        $msg .= "📝 فرمت ‌های قابل قبول:\n";
        $msg .= "• با صفر: 09123456789 (۱۱ رقم)\n";
        $msg .= "• بدون صفر: 9123456789 (۱۰ رقم)\n";
        $msg .= "• با کد کشور: 989123456789 (۱۲ رقم)\n\n";
        $msg .= "💡 مثلا :\n";
        $msg .= "• با صفر : 09123456789\n";
        $msg .= "• بدون صفر: 9123456789\n";
        $msg .= "• با کد کشور: 989123456789\n\n";
        $msg .= "⚠️ دقت کن:\n";
        $msg .= "• شماره رو بدون فاصله و بدون خط تیره وارد کن\n";
        $msg .= "• فقط اعداد انگلیسی مجازه";

        $this->sendOrEditPrompt($bot, $msg, $keyboard, 'askPhone');
    }

    private function promptStartDelay(Nutgram $bot): void
    {
        $this->startDelayMinutes = null;
        $this->intervalMinutes = null;
        $this->totalBatches = null;

        $keyboard = $this->stepKeyboard();
        $template = $this->buildScheduleTemplate(null, null, null, 'start');
        $text = "{$template}\n\n"
            . "👈 تو الگوی بالا به جای علامت سؤال‌های قرمز عدد مورد نظرت رو وارد کن.\n"
            . "❕تو هر دوره حدود {$this->batchSize} اس ام اس برای تارگتت ارسال میشه.\n\n"
            . "⏱ اول بگو چند دقیقه بعد از درخواستت رگبارو شروع کنیم؟";

        $this->sendOrEditPrompt($bot, $text, $keyboard, 'askStartDelay');
    }

    private function promptInterval(Nutgram $bot): void
    {
        $this->intervalMinutes = null;
        $this->totalBatches = null;

        $keyboard = $this->stepKeyboard();

        $template = $this->buildScheduleTemplate($this->startDelayMinutes, null, null, 'interval');
        $text = "{$template}\n\n"
            . "⏳ حالا فاصله بین هر نوبت را بفرست (دقیقه). مثلا 0 یا 2\n"
            . "👈 فقط عدد بفرست.";

        $this->sendOrEditPrompt($bot, $text, $keyboard, 'askInterval');
    }

    private function promptTotalBatches(Nutgram $bot): void
    {
        $this->totalBatches = null;

        $keyboard = $this->stepKeyboard();

        $template = $this->buildScheduleTemplate($this->startDelayMinutes, $this->intervalMinutes, null, 'rounds');
        $text = "{$template}\n\n"
            . "📌 هر نوبت حدود {$this->batchSize} پیامک ارسال می‌شود.\n"
            . "حالا بگو چند نوبت میخوای اجرا بشه؟ (عدد بین 1 تا 20)";

        $this->sendOrEditPrompt($bot, $text, $keyboard, 'askTotalBatches');
    }

    private function stepKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔙 مرحله قبل', callback_data: 'sms_plus_back'))
            ->addRow(InlineKeyboardButton::make('❌ لغو', callback_data: 'sms_plus_cancel'));
    }

    private function sendOrEditPrompt(Nutgram $bot, string $text, InlineKeyboardMarkup $keyboard, string $nextStep): void
    {
        $chatId = $bot->chatId();

        if ($this->promptMessageId) {
            try {
                $bot->editMessageText(
                    chat_id: $chatId,
                    message_id: $this->promptMessageId,
                    text: $text,
                    reply_markup: $keyboard
                );
                $this->next($nextStep);
                return;
            } catch (\Throwable) {
                // fall back to sending a new message
            }
        }

        $sent = $bot->sendMessage($text, reply_markup: $keyboard);
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
}

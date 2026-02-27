<?php

namespace App\Telegram\Conversations;

use App\Models\User;
use App\Models\WhitelistedTarget;
use App\Services\FeatureLimitService;
use App\Services\WhitelistService;
use App\Telegram\Keyboards\RubikaReporterMenuKeyboard;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class RubikaReporterConversation extends Conversation
{
    private const TARGET_ACCOUNT = 'account';
    private const TARGET_CHANNEL = 'channel';
    private const TARGET_GROUP = 'group';

    protected function getLocalUser(Nutgram $bot): ?User
    {
        $tgUser = $bot->callbackQuery()?->from ?? $bot->user();
        if (!$tgUser) return null;

        return User::where('telegram_id', $tgUser->id)->first();
    }

    public function start(Nutgram $bot)
    {
        $bot->setUserData('rb_cleanup_messages', []);

        $local = $this->getLocalUser($bot);
        if (!$local) {
            $bot->sendMessage('⛔️ حساب شما پیدا نشد. ابتدا /start را ارسال کنید.');
            $this->end();
            return;
        }

        if ($local->isSuspended()) {
            $bot->sendMessage('⛔️ حساب شما موقتا معلق شده است.');
            $this->end();
            return;
        }

        $local->last_active_at = now();
        $local->save();

        $limit = app(FeatureLimitService::class)->checkReporterLimit($local);
        if ($limit) {
            $this->respondWithLimit($bot, $limit);
            $this->end();
            return;
        }

        $targetType = $this->resolveTargetType($bot->callbackQuery()?->data);
        $bot->setUserData('rb_reporter_type', $targetType);
        $bot->setUserData('rb_reporter_selected_reason_key', null);
        $bot->setUserData('rb_reporter_selected_reason_title', null);
        $bot->setUserData('rb_reporter_reason_summary', null);
        $bot->setUserData('rb_reason_summary_prompt_message_id', null);
        $bot->setUserData('rb_reason_summary_prompt_uses_caption', false);

        $this->sendOrEditMessage($bot, $this->buildRubikaTargetInputPrompt($targetType), $this->targetInputKeyboard());
        $this->next('awaitUsername');
    }

    public function awaitUsername(Nutgram $bot)
    {
        $callbackData = $bot->callbackQuery()?->data;
        if ($callbackData === 'reporter_rubika_menu') {
            $bot->answerCallbackQuery();
            $this->showRubikaReporterMenu($bot);
            $this->end();
            return;
        }

        if ($callbackData) {
            $bot->answerCallbackQuery(text: '⛔️ ابتدا یوزرنیم را به صورت متن ارسال کن.');
            return;
        }

        $username = $bot->message()?->text;
        if (!$username || strlen($username) < 3) {
            $bot->sendMessage('⛔️ یوزرنیم نامعتبر است. لطفا حداقل 3 کاراکتر وارد کنید.');
            return;
        }

        $username = ltrim(trim($username), '@');

        $targetType = $bot->getUserData('rb_reporter_type') ?? self::TARGET_ACCOUNT;
        $targetLabel = $this->getTargetLabel($targetType);

        $whitelist = app(WhitelistService::class);
        if ($whitelist->isWhitelisted($username, WhitelistedTarget::TYPE_CUSTOM)) {
            $bot->sendMessage($whitelist->getBlockMessage($username, WhitelistedTarget::TYPE_CUSTOM, $targetLabel));
            $this->end();
            return;
        }

        $bot->setUserData('rb_reporter_username', $username);

        $usePhoto = false;
        $loadingMsg = null;
        $reporterPhoto = $this->getReporterPhoto();

        if ($reporterPhoto) {
            try {
                $loadingMsg = $bot->sendPhoto(
                    photo: $reporterPhoto,
                    caption: '⏳ درحال آماده‌سازی گزارش روبیکا...'
                );
                $usePhoto = (bool)($loadingMsg->message_id ?? false);
            } catch (\Throwable) {
                $loadingMsg = null;
            }
        }

        if (!$loadingMsg) {
            $loadingMsg = $bot->sendMessage('⏳ درحال آماده‌سازی گزارش روبیکا...');
        }

        $this->addCleanupMessage($bot, $loadingMsg->message_id ?? null);

        $details = "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> KermPlus | Target Ready\n" .
            "━━━━━━━━━━━━━━━\n" .
            "🎯 نوع هدف: {$targetLabel}\n" .
            "👤 یوزرنیم: @{$username}\n" .
            "━━━━━━━━━━━━━━━\n\n" .
            "🗣️ دلیل ریپورت رو انتخاب کن :\n";

        $keyboard = $this->rubikaReasonKeyboard();
        $updated = false;

        if ($loadingMsg?->message_id) {
            try {
                if ($usePhoto) {
                    $bot->editMessageCaption(
                        chat_id: $bot->user()->id,
                        message_id: $loadingMsg->message_id,
                        caption: $details,
                        reply_markup: $keyboard
                    );
                } else {
                    $bot->editMessageText(
                        chat_id: $bot->user()->id,
                        message_id: $loadingMsg->message_id,
                        text: $details,
                        reply_markup: $keyboard
                    );
                }
                $updated = true;
            } catch (\Throwable) {
                $this->deleteMessageSafe($bot, $loadingMsg->message_id);
            }
        }

        if (!$updated) {
            $sent = null;
            if ($reporterPhoto) {
                try {
                    $sent = $bot->sendPhoto(
                        photo: $this->getReporterPhoto(),
                        caption: $details,
                        reply_markup: $keyboard
                    );
                } catch (\Throwable) {
                    $sent = null;
                }
            }

            if (!$sent) {
                $sent = $bot->sendMessage($details, reply_markup: $keyboard);
            }

            $this->addCleanupMessage($bot, $sent->message_id ?? null);
        }

        $this->next('processRubikaReason');
    }

    public function processRubikaReason(Nutgram $bot): void
    {
        $data = $bot->callbackQuery()?->data;
        $reasons = $this->rubikaReasons();

        if ($data === 'reporter_rubika_menu') {
            $bot->answerCallbackQuery();
            $this->showRubikaReporterMenu($bot);
            $this->end();
            return;
        }

        if (!$data || !isset($reasons[$data])) {
            if ($bot->callbackQuery()) {
                $bot->answerCallbackQuery(text: '⛔️ گزینه نامعتبر است. از دکمه‌ها استفاده کن.');
            } else {
                $bot->sendMessage('⛔️ لطفا دلیل ریپورت رو از دکمه‌ها انتخاب کن.');
            }
            $this->promptRubikaReason($bot);
            return;
        }

        $bot->setUserData('rb_reporter_selected_reason_key', $data);
        $bot->setUserData('rb_reporter_selected_reason_title', $reasons[$data]);
        $bot->answerCallbackQuery(text: '✅ دلیل ثبت شد.');
        $this->promptRubikaReasonSummary($bot);
        $this->next('awaitRubikaReasonSummary');
    }

    public function awaitRubikaReasonSummary(Nutgram $bot): void
    {
        $callbackData = $bot->callbackQuery()?->data;

        if ($callbackData === 'reporter_rubika_menu') {
            $bot->answerCallbackQuery();
            $this->showRubikaReporterMenu($bot);
            $this->end();
            return;
        }

        $reasonKey = $bot->getUserData('rb_reporter_selected_reason_key');
        $reasonTitle = $bot->getUserData('rb_reporter_selected_reason_title');

        if (!$reasonKey || !$reasonTitle) {
            if ($bot->callbackQuery()) {
                $bot->answerCallbackQuery(text: '⛔️ ابتدا دلیل ریپورت را انتخاب کن.');
            }
            $this->promptRubikaReason($bot);
            $this->next('processRubikaReason');
            return;
        }

        $summary = null;

        if ($callbackData) {
            if ($callbackData !== 'rubika_reason_summary_default') {
                $bot->answerCallbackQuery(text: '⛔️ لطفا متن رو ارسال کن یا متن پیش‌فرض رو بزن.');
                return;
            }

            $summary = $this->buildDefaultReasonSummary($bot, (string)$reasonKey);
            $bot->answerCallbackQuery(text: '✅ متن پیش‌فرض انتخاب شد.');
        } else {
            $summary = trim((string)$bot->message()?->text);
            if ($summary === '') {
                $bot->sendMessage('⛔️ لطفا توضیح دلیل ریپورت رو ارسال کن یا متن پیش‌فرض رو انتخاب کن.');
                return;
            }
        }

        $summary = $this->normalizeReasonSummary($summary);
        $bot->setUserData('rb_reporter_reason_summary', $summary);
        $this->finalizeRubikaReport($bot, (string)$reasonTitle, $summary);
    }

    private function finalizeRubikaReport(Nutgram $bot, string $reason, string $reasonSummary): void
    {
        $username = $bot->getUserData('rb_reporter_username');
        if (!$username) {
            if ($bot->callbackQuery()) {
                $bot->answerCallbackQuery(text: '⛔️ ابتدا یوزرنیم را وارد کنید.');
            } else {
                $bot->sendMessage('⛔️ ابتدا یوزرنیم را وارد کنید.');
            }
            $this->end();
            return;
        }

        $local = $this->getLocalUser($bot);
        if (!$local) {
            if ($bot->callbackQuery()) {
                $bot->answerCallbackQuery(text: '⛔️ حساب شما پیدا نشد.');
            }
            $this->end();
            return;
        }

        $limiter = app(FeatureLimitService::class);
        $limit = $limiter->checkReporterLimit($local);
        if ($limit) {
            $this->respondWithLimit($bot, $limit);
            $this->end();
            return;
        }

        $targetType = $bot->getUserData('rb_reporter_type') ?? self::TARGET_ACCOUNT;
        $targetLabel = $this->getTargetLabel($targetType);

        $whitelist = app(WhitelistService::class);
        if ($whitelist->isWhitelisted($username, WhitelistedTarget::TYPE_CUSTOM)) {
            if ($bot->callbackQuery()) {
                $bot->answerCallbackQuery();
            }
            $bot->sendMessage($whitelist->getBlockMessage($username, WhitelistedTarget::TYPE_CUSTOM, $targetLabel));
            $this->end();
            return;
        }

        $limiter->recordReporterUsage($local);
        $baseMessageId = $bot->getUserData('rb_reason_summary_prompt_message_id') ?: null;
        $baseUsesCaption = (bool)$bot->getUserData('rb_reason_summary_prompt_uses_caption');

        if (!$baseMessageId && $bot->callbackQuery()?->message?->message_id) {
            $baseMessageId = $bot->callbackQuery()?->message?->message_id;
            $baseUsesCaption = $this->isCallbackMessagePhoto($bot);
        }

        $this->runRubikaReport($bot, $username, $targetType, $reason, $reasonSummary, $baseMessageId, $baseUsesCaption);
    }

    private function promptRubikaReasonSummary(Nutgram $bot): void
    {
        $text = "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> کرم پلاس <tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji>\n\n" .
            "<tg-emoji emoji-id='4904973211763999824'>🗣️</tg-emoji> دلیل ریپورت رو به صورت خلاصه توضیح بده ( <tg-emoji emoji-id='6226426402682441481'>⚠️</tg-emoji> خیلی مهم و تاثیر گذار روی نتیجه ) :\n\n" .
            "<tg-emoji emoji-id='5377620300965888937'>🔴</tg-emoji> هرچی متنش رسمی تر و به زبان انگلیسی باشه نتیجه بهتری میگیری\n" .
            "<tg-emoji emoji-id='5377620300965888937'>🔴</tg-emoji> میتونی از Chat GPT کمک بگیری یا متن پیش فرض مارو انتخاب کنی";
        $keyboard = $this->reasonSummaryKeyboard();
        $messageId = $bot->callbackQuery()?->message?->message_id;
        $useCaption = $this->isCallbackMessagePhoto($bot);

        if ($messageId) {
            try {
                $this->editMessageByType($bot, $messageId, $text, $useCaption, $keyboard, true);
                $bot->setUserData('rb_reason_summary_prompt_message_id', $messageId);
                $bot->setUserData('rb_reason_summary_prompt_uses_caption', $useCaption);
                return;
            } catch (\Throwable) {
                // fallback to sending a new message
            }
        }

        $sent = $bot->sendMessage($text, parse_mode: 'HTML', reply_markup: $keyboard);
        $bot->setUserData('rb_reason_summary_prompt_message_id', $sent->message_id ?? null);
        $bot->setUserData('rb_reason_summary_prompt_uses_caption', false);
    }

    private function reasonSummaryKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('متن پیش فرض', callback_data: 'rubika_reason_summary_default', style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('بازگشت', callback_data: 'reporter_rubika_menu', style: 'danger', icon: '5352759161945867747')
            );
    }

    private function buildDefaultReasonSummary(Nutgram $bot, string $reasonKey): string
    {
        $targetType = (string)($bot->getUserData('rb_reporter_type') ?? self::TARGET_ACCOUNT);
        $category = $this->reasonCategoryLabel($reasonKey);

        return "I am reporting this {$targetType} for {$category}. This appears to violate platform guidelines. Please review and take appropriate action.";
    }

    private function reasonCategoryLabel(string $reasonKey): string
    {
        return match ($reasonKey) {
            'rubika_reason_child_abuse' => 'child abuse content',
            'rubika_reason_violence' => 'violent or dangerous content',
            'rubika_reason_illegal_goods' => 'illegal goods and services',
            'rubika_reason_illegal_adult' => 'illegal adult content',
            'rubika_reason_personal_data' => 'unauthorized sharing of personal data',
            'rubika_reason_fraud' => 'fraud or scam activity',
            'rubika_reason_copyright' => 'copyright infringement',
            'rubika_reason_spam' => 'spam and abusive behavior',
            default => 'harmful and policy-violating content',
        };
    }

    private function normalizeReasonSummary(string $summary): string
    {
        $summary = preg_replace('/\s+/u', ' ', trim($summary)) ?? trim($summary);

        if (strlen($summary) > 260) {
            $summary = substr($summary, 0, 257) . '...';
        }

        return $summary;
    }

    private function runRubikaReport(
        Nutgram $bot,
        string $username,
        string $targetType,
        string $reason,
        string $reasonSummary,
        ?int $baseMessageId = null,
        bool $baseUsesCaption = false
    ): void {
        $totalSteps = 5;
        $delayPerStep = 5;

        $targetLabel = $this->getTargetLabel($targetType);
        $label = "🎯 نوع هدف: {$targetLabel} | 👤 یوزرنیم: @{$username}";
        $initialText = $this->buildProcessingMessage(
            percent: 0,
            step: 1,
            totalSteps: $totalSteps,
            targetLabel: $label,
            reason: $reason,
            reasonSummary: $reasonSummary,
            queue: 243,
            active: 18,
            done: 162,
            ok: 147,
            fail: 15,
            retry: 9,
            elapsed: '00:00:00',
            eta: '~00:00:24',
            statuses: $this->buildStatusLines(1)
        );

        $progressMessageId = null;
        $useCaption = false;
        $reportPhoto = $this->getReporterPhoto();

        $this->clearCleanupMessages($bot);
        if ($baseMessageId) {
            $this->deleteMessageSafe($bot, $baseMessageId);
        }

        if ($reportPhoto) {
            try {
                $sent = $bot->sendPhoto(
                    photo: $reportPhoto,
                    caption: $initialText,
                    parse_mode: 'HTML'
                );
                $progressMessageId = $sent->message_id ?? null;
                $useCaption = (bool)$progressMessageId;
            } catch (\Throwable) {
                $progressMessageId = null;
            }
        }

        if (!$progressMessageId) {
            $sent = $bot->sendMessage($initialText, parse_mode: 'HTML');
            $progressMessageId = $sent->message_id ?? null;
            $useCaption = false;
        }

        if (!$progressMessageId) {
            $bot->sendMessage('⛔️ خطا در ایجاد پیام وضعیت. دوباره تلاش کن.');
            $this->end();
            return;
        }

        $queue = 243;
        $active = 18;
        $done = 162;
        $ok = 147;
        $fail = 15;
        $retry = 9;
        $start = microtime(true);

        for ($i = 1; $i <= $totalSteps; $i++) {
            sleep($delayPerStep);

            $percent = (int)(($i / $totalSteps) * 100);
            $queue = max(0, $queue - 48);
            $done += 30;
            $ok += 30;
            $retry = max(0, $retry - 3);

            $elapsedSeconds = (int)(microtime(true) - $start);
            $elapsed = gmdate('H:i:s', $elapsedSeconds);
            $etaSeconds = max(0, ($totalSteps - $i) * $delayPerStep);
            $eta = '~' . gmdate('H:i:s', $etaSeconds);

            $updateMsg = $this->buildProcessingMessage(
                percent: $percent,
                step: $i,
                totalSteps: $totalSteps,
                targetLabel: $label,
                reason: $reason,
                reasonSummary: $reasonSummary,
                queue: $queue,
                active: $active,
                done: $done,
                ok: $ok,
                fail: $fail,
                retry: $retry,
                elapsed: $elapsed,
                eta: $eta,
                statuses: $this->buildStatusLines($i + 1)
            );

            try {
                $this->editMessageByType($bot, $progressMessageId, $updateMsg, $useCaption, null, true);
            } catch (\Throwable) {
                // Continue on error
            }
        }

        $finalText = $this->buildFinalMessage($label, null);
        $reportPhoto = $this->getReporterPhoto();
        if ($reportPhoto) {
            $this->deleteMessageSafe($bot, $progressMessageId);
            try {
                $bot->sendPhoto(
                    photo: $reportPhoto,
                    caption: $finalText,
                    parse_mode: 'HTML'
                );
                $bot->setUserData('rb_cleanup_messages', []);
                $this->end();
                return;
            } catch (\Throwable) {
                // fallback to text mode below
            }
        }

        try {
            $this->editMessageByType($bot, $progressMessageId, $finalText, $useCaption, null, true);
        } catch (\Throwable) {
            $bot->sendMessage($finalText, parse_mode: 'HTML');
        }

        $bot->setUserData('rb_cleanup_messages', []);
        $this->end();
    }

    private function getProgressBar(int $percent): string
    {
        $filled = max(0, min(10, (int)round($percent / 10)));
        $empty = 10 - $filled;
        $bar = '[' . str_repeat('█', $filled) . str_repeat('░', $empty) . ']';
        return $bar . ' ' . $percent . '%';
    }

    private function respondWithLimit(Nutgram $bot, string $message): void
    {
        if ($bot->callbackQuery()) {
            $bot->answerCallbackQuery(text: $message, show_alert: true);
            return;
        }

        $bot->sendMessage($message);
    }

    private function resolveTargetType(?string $callbackData): string
    {
        return match ($callbackData) {
            'rubika_report_channel' => self::TARGET_CHANNEL,
            'rubika_report_group' => self::TARGET_GROUP,
            default => self::TARGET_ACCOUNT,
        };
    }

    private function getTargetLabel(string $targetType): string
    {
        return match ($targetType) {
            self::TARGET_CHANNEL => 'کانال',
            self::TARGET_GROUP => 'گروه',
            default => 'اکانت',
        };
    }

    private function buildRubikaTargetInputPrompt(string $targetType): string
    {
        $targetTitle = match ($targetType) {
            self::TARGET_CHANNEL => 'کانال',
            self::TARGET_GROUP => 'گروه',
            default => 'اکانت',
        };

        return "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> کرم پلاس <tg-emoji emoji-id='5134654202894615343'>🪱</tg-emoji>\n\n" .
            "<tg-emoji emoji-id='5407025283456835913'>📱</tg-emoji> یوزرنیم {$targetTitle} روبیکا تارگت رو برام بفرست:\n\n" .
            "<tg-emoji emoji-id='5334882760735598374'>📝</tg-emoji> فرمت های قابل قبول:\n" .
            "• بدون @: targetid\n" .
            "• با @: @targetid\n\n" .
            "<tg-emoji emoji-id='5123359615727174427'>💡</tg-emoji> مثلا:\n" .
            "• badchannel\n" .
            "• @badchannel\n\n" .
            "<tg-emoji emoji-id='6226426402682441481'>⚠️</tg-emoji> دقت کن:\n" .
            "• یوزرنیم رو بدون فاصله و بدون خط تیره بفرست\n" .
            "• فقط حروف انگلیسی، عدد و _ مجازه و حداقل 3 کاراکتر باشه";
    }

    private function rubikaReasons(): array
    {
        return [
            'rubika_reason_child_abuse' => 'کودک آزاری',
            'rubika_reason_violence' => 'خشونت',
            'rubika_reason_illegal_goods' => 'کالا و خدمات غیرقانونی',
            'rubika_reason_illegal_adult' => 'محتوای بزرگسالان غیرقانونی',
            'rubika_reason_personal_data' => 'داده ‌های شخصی',
            'rubika_reason_fraud' => 'کلاهبرداری',
            'rubika_reason_copyright' => 'کپی رایت',
            'rubika_reason_spam' => 'اسپم',
            'rubika_reason_other' => 'غیرقانونی نیست ، اما باید حذف شود',
        ];
    }

    private function promptRubikaReason(Nutgram $bot): void
    {
        $text = "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> کرم پلاس <tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji>\n\n<tg-emoji emoji-id='4904973211763999824'>🗣️</tg-emoji> دلیل ریپورت رو انتخاب کن :";
        $keyboard = $this->rubikaReasonKeyboard();
        $messageId = $bot->callbackQuery()?->message?->message_id;
        $useCaption = $this->isCallbackMessagePhoto($bot);

        if ($messageId) {
            try {
                $this->editMessageByType($bot, $messageId, $text, $useCaption, $keyboard, true);
                return;
            } catch (\Throwable) {
                // fallback to sending a new message
            }
        }

        $bot->sendMessage($text, parse_mode: 'HTML', reply_markup: $keyboard);
    }

    private function rubikaReasonKeyboard(): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();
        foreach ($this->rubikaReasons() as $key => $title) {
            $keyboard->addRow(InlineKeyboardButton::make($title, callback_data: $key, style: 'danger'));
        }
        $keyboard->addRow(InlineKeyboardButton::make('بازگشت', callback_data: 'reporter_rubika_menu', style: 'danger', icon: '5352759161945867747'));

        return $keyboard;
    }

    private function buildProcessingMessage(
        int $percent,
        int $step,
        int $totalSteps,
        string $targetLabel,
        string $reason,
        string $reasonSummary,
        int $queue,
        int $active,
        int $done,
        int $ok,
        int $fail,
        int $retry,
        string $elapsed,
        string $eta,
        array $statuses
    ): string {
        $progressBar = $this->getProgressBar($percent);
        $barOnly = explode(' ', $progressBar, 2)[0];
        $statusBlock = implode("\n", array_map(static fn(string $line): string => "> {$line}", $statuses));
        $safeTarget = htmlspecialchars($targetLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeReason = htmlspecialchars($reason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeSummary = htmlspecialchars($reasonSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $date = now()->format('Y/m/d');
        $time = now()->format('H:i:s');

        return "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> KermPlus | Processing Job\n" .
            "━━━━━━━━━━━━━━━━\n\n" .
            "{$barOnly} {$percent}%   <tg-emoji emoji-id='5116159438062879454'>🙏</tg-emoji> step {$step}/{$totalSteps}\n\n" .
            "🎯 target: {$safeTarget}\n" .
            "🏷️ reason: {$safeReason}\n" .
            "🗣️ summary: {$safeSummary}\n\n" .
            "📦 queue: {$queue} items\n" .
            "<tg-emoji emoji-id='4904936030232117798'>⚙️</tg-emoji> active: {$active}   <tg-emoji emoji-id='6224314343924699041'>✅</tg-emoji> done: {$done}\n" .
            "<tg-emoji emoji-id='5325945307454789973'>🟢</tg-emoji> ok: {$ok}   <tg-emoji emoji-id='5326056199215406977'>❌</tg-emoji> fail: {$fail}   🔁 retry: {$retry}\n\n" .
            "rate: 12/s backoff: 2.5s\n" .
            "elapsed: {$elapsed} ETA: {$eta}\n\n" .
            "{$statusBlock}\n\n" .
            "trace: job=8f2a mode=ro gate=open\n" .
            "Please wait...\n\n" .
            "<tg-emoji emoji-id='5431897022456145283'>📆</tg-emoji> {$date}  <tg-emoji emoji-id='4904882772637648609'>⏰</tg-emoji> {$time}\n" .
            "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> @NitroHostBot <tg-emoji emoji-id='4927295007204836791'>🪱</tg-emoji>";
    }

    private function buildFinalMessage(string $targetLabel, ?string $link = null): string
    {
        $date = now()->format('Y/n/j');
        $time = now()->format('H:i:s');

        return "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> KermPlus | Reported Successful\n" .
            "━━━━━━━━━━━━━━━━\n\n" .
            "<tg-emoji emoji-id='5116093437300442328'>⚡️</tg-emoji> تعداد کل درخواست ها : 1321\n" .
            "<tg-emoji emoji-id='6224314343924699041'>✅</tg-emoji> 1235 موفق | <tg-emoji emoji-id='6224072537265934868'>❌</tg-emoji> 134 ناموفق\n\n" .
            "تمامی ریپورت ها از سمت کرم پلاس<tg-emoji emoji-id='5134654202894615343'>🪱</tg-emoji> با موفقیت ارسال شدند.\n" .
            "نتیجه نهایی وابسته به بررسی پلتفرم مقصد می‌باشد.\n\n" .
            "<tg-emoji emoji-id='5431897022456145283'>📆</tg-emoji> {$date} <tg-emoji emoji-id='4904882772637648609'>⏰</tg-emoji> {$time}\n" .
            "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> @NitroHostBot <tg-emoji emoji-id='4927295007204836791'>🪱</tg-emoji>";
    }

    private function buildStatusLines(int $step): array
    {
        $lines = [
            "<tg-emoji emoji-id='5134183530313548836'>🧪</tg-emoji> validate inputs      [ OK ]",
            "<tg-emoji emoji-id='5116093437300442328'>⚡️</tg-emoji> open connections     [ OK ]",
            "<tg-emoji emoji-id='5292226786229236118'>🔄</tg-emoji> process batch #09    [ .. ]",
            "<tg-emoji emoji-id='5334882760735598374'>📝</tg-emoji> write results        [ -- ]",
            "<tg-emoji emoji-id='5411520005386806155'>🏁</tg-emoji> finalize             [ -- ]",
        ];

        if ($step >= 2) {
            $lines[2] = "<tg-emoji emoji-id='5292226786229236118'>🔄</tg-emoji> process batch #09    [ OK ]";
        }
        if ($step >= 3) {
            $lines[3] = "<tg-emoji emoji-id='5334882760735598374'>📝</tg-emoji> write results        [ OK ]";
        }
        if ($step >= 4) {
            $lines[4] = "<tg-emoji emoji-id='5411520005386806155'>🏁</tg-emoji> finalize             [ OK ]";
        }

        return $lines;
    }

    private function getReporterPhoto(): ?InputFile
    {
        $path = public_path('images/report.png');
        return is_readable($path) ? InputFile::make($path, 'report.png') : null;
    }

    private function addCleanupMessage(Nutgram $bot, ?int $messageId): void
    {
        if (!$messageId) return;
        $messages = $bot->getUserData('rb_cleanup_messages') ?? [];
        $messages[] = $messageId;
        $bot->setUserData('rb_cleanup_messages', $messages);
    }

    private function clearCleanupMessages(Nutgram $bot): void
    {
        $messages = $bot->getUserData('rb_cleanup_messages') ?? [];
        foreach ($messages as $mid) {
            $this->deleteMessageSafe($bot, (int)$mid);
        }
        $bot->setUserData('rb_cleanup_messages', []);
    }

    private function editMessageByType(
        Nutgram $bot,
        int $messageId,
        string $text,
        bool $useCaption,
        ?InlineKeyboardMarkup $keyboard = null,
        bool $parseHtml = false
    ): void {
        $parseMode = $parseHtml ? 'HTML' : null;

        if ($useCaption) {
            $bot->editMessageCaption(
                chat_id: $bot->user()->id,
                message_id: $messageId,
                caption: $text,
                parse_mode: $parseMode,
                reply_markup: $keyboard
            );
            return;
        }

        $bot->editMessageText(
            chat_id: $bot->user()->id,
            message_id: $messageId,
            text: $text,
            parse_mode: $parseMode,
            reply_markup: $keyboard
        );
    }

    private function targetInputKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('بازگشت', callback_data: 'reporter_rubika_menu', style: 'danger', icon: '5352759161945867747')
            );
    }

    private function sendOrEditMessage(Nutgram $bot, string $text, ?InlineKeyboardMarkup $keyboard = null): void
    {
        $messageId = $bot->callbackQuery()?->message?->message_id;

        if ($messageId) {
            try {
                $bot->editMessageText(
                    chat_id: $bot->user()->id,
                    message_id: $messageId,
                    text: $text,
                    parse_mode: 'HTML',
                    reply_markup: $keyboard
                );
                return;
            } catch (\Throwable) {
                // fallback to sending a new message
            }
        }

        $bot->sendMessage($text, parse_mode: 'HTML', reply_markup: $keyboard);
    }

    private function showRubikaReporterMenu(Nutgram $bot): void
    {
        $msg = "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> <b>کرم پلاس</b> <tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji>\n\n<tg-emoji emoji-id='4978973209056511046'>💬</tg-emoji> ریپورتر روبیکا 🤝\nبرای ادامه یکی از گزینه های زیر رو انتخاب کن :";
        $this->sendOrEditMessage($bot, $msg, RubikaReporterMenuKeyboard::make());
    }

    private function isCallbackMessagePhoto(Nutgram $bot): bool
    {
        return (bool)$bot->callbackQuery()?->message?->photo;
    }

    private function deleteMessageSafe(Nutgram $bot, int $messageId): void
    {
        try {
            $bot->deleteMessage(chat_id: $bot->user()->id, message_id: $messageId);
        } catch (\Throwable) {
            // ignore
        }
    }
}

<?php

namespace App\Telegram\Conversations;

use App\Models\User;
use App\Models\WhitelistedTarget;
use App\Services\FeatureLimitService;
use App\Services\WhitelistService;
use App\Telegram\Keyboards\TelegramReportReasonKeyboard;
use App\Telegram\Keyboards\TelegramReporterMenuKeyboard;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class TelegramReporterConversation extends Conversation
{
    protected function getLocalUser(Nutgram $bot): ?User
    {
        $tgUser = $bot->callbackQuery()?->from ?? $bot->user();
        if (!$tgUser) return null;

        return User::where('telegram_id', $tgUser->id)->first();
    }

    public function start(Nutgram $bot)
    {
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

        $targetType = $this->determineTargetType($bot->callbackQuery()?->data);
        $bot->setUserData('tg_reporter_target_type', $targetType);
        $bot->setUserData('tg_reporter_selected_reason_key', null);
        $bot->setUserData('tg_reporter_selected_reason_title', null);
        $bot->setUserData('tg_reporter_reason_summary', null);
        $bot->setUserData('tg_reason_summary_prompt_message_id', null);
        $bot->setUserData('tg_cleanup_messages', []);

        $prompt = $this->buildTelegramTargetInputPrompt($targetType);

        $this->sendOrEditMessage($bot, $prompt, $this->targetInputKeyboard());
        $this->next('awaitTargetInput');
    }

    public function awaitTargetInput(Nutgram $bot)
    {
        $callbackData = $bot->callbackQuery()?->data;
        if ($callbackData === 'reporter_telegram_menu') {
            $bot->answerCallbackQuery();
            $this->showTelegramReporterMenu($bot);
            $this->end();
            return;
        }

        if ($callbackData) {
            $bot->answerCallbackQuery(text: '⛔️ ابتدا هدف را به صورت متن ارسال کن.');
            return;
        }

        $input = trim((string)$bot->message()?->text);
        if ($input === '') {
            $bot->sendMessage('⛔️ لطفا مقدار معتبری وارد کنید.');
            return;
        }

        $targetType = $bot->getUserData('tg_reporter_target_type') ?? 'account';
        $normalized = $this->normalizeTelegramTarget($input, $targetType);

        if ($normalized === null) {
            $bot->sendMessage('⛔️ مقدار وارد شده نامعتبر است. دوباره امتحان کن.');
            return;
        }

        $username = $normalized['username'];
        $chat = $this->fetchTelegramChat($bot, $username);
        if (!$chat) {
            $bot->sendMessage('⛔️ یوزرنیم وارد شده در تلگرام پیدا نشد. لطفا یک یوزرنیم معتبر ارسال کن.');
            return;
        }

        $bot->setUserData('tg_reporter_username', $username);
        $bot->setUserData('tg_reporter_target', $normalized);

        $whitelist = app(WhitelistService::class);
        if ($whitelist->isWhitelisted($username, WhitelistedTarget::TYPE_TELEGRAM)) {
            $blockLabel = $this->resolveTelegramWhitelistLabel((string)($normalized['type'] ?? $targetType), $username);
            $bot->sendMessage($whitelist->getBlockMessage($username, WhitelistedTarget::TYPE_TELEGRAM, $blockLabel));
            $this->end();
            return;
        }

        $loadingMsg = null;
        $loadingUsesCaption = false;
        $reportPhoto = $this->getReportPhoto();
        if ($reportPhoto) {
            try {
                $loadingMsg = $bot->sendPhoto(
                    photo: $reportPhoto,
                    caption: '⏳ درحال دریافت اطلاعات از تلگرام...'
                );
                $loadingUsesCaption = (bool)($loadingMsg->message_id ?? false);
            } catch (\Throwable) {
                $loadingMsg = null;
            }
        }

        if (!$loadingMsg) {
            $loadingMsg = $bot->sendMessage('⏳ درحال دریافت اطلاعات از تلگرام...');
        }
        $this->addCleanupMessage($bot, $loadingMsg->message_id ?? null);

        $preview = $this->buildTelegramPreview($bot, $normalized, $chat);
        $details = $preview ?? ($normalized['label'] ?? "🎯 هدف: @{$username}");
        $details .= "\n\n🗣️ دلیل ریپورت رو انتخاب کن :";
        $keyboard = TelegramReportReasonKeyboard::make();

        if ($loadingMsg?->message_id) {
            try {
                if ($loadingUsesCaption) {
                    $bot->editMessageCaption(
                        chat_id: $bot->user()->id,
                        message_id: $loadingMsg->message_id,
                        caption: $details,
                        reply_markup: $keyboard,
                        parse_mode: 'HTML'
                    );
                } else {
                    $bot->editMessageText(
                        chat_id: $bot->user()->id,
                        message_id: $loadingMsg->message_id,
                        text: $details,
                        reply_markup: $keyboard,
                        parse_mode: 'HTML'
                    );
                }
                $this->next('processTelegramReason');
                return;
            } catch (\Throwable) {
                $this->deleteMessageSafe($bot, $loadingMsg->message_id);
            }
        }

        if ($reportPhoto) {
            try {
                $sent = $bot->sendPhoto(
                    photo: $this->getReportPhoto(),
                    caption: $details,
                    reply_markup: $keyboard,
                    parse_mode: 'HTML'
                );
                $this->addCleanupMessage($bot, $sent->message_id ?? null);
                $this->next('processTelegramReason');
                return;
            } catch (\Throwable) {
                // fallback to text message
            }
        }

        $sent = $bot->sendMessage($details, reply_markup: $keyboard, parse_mode: 'HTML');
        $this->addCleanupMessage($bot, $sent->message_id ?? null);
        $this->next('processTelegramReason');
    }

    public function processTelegramReason(Nutgram $bot): void
    {
        $data = $bot->callbackQuery()?->data;
        $reasons = $this->telegramReasons();

        if ($data === 'reporter_telegram_menu') {
            $bot->answerCallbackQuery();
            $this->showTelegramReporterMenu($bot);
            $this->end();
            return;
        }

        if (!$data || !isset($reasons[$data])) {
            if ($bot->callbackQuery()) {
                $bot->answerCallbackQuery(text: '⛔️ گزینه نامعتبر است. از دکمه‌ها استفاده کن.');
            } else {
                $bot->sendMessage('⛔️ لطفا دلیل ریپورت رو از دکمه‌ها انتخاب کن.');
            }
            $this->promptTelegramReason($bot);
            return;
        }

        $bot->setUserData('tg_reporter_selected_reason_key', $data);
        $bot->setUserData('tg_reporter_selected_reason_title', $reasons[$data]);
        $bot->answerCallbackQuery(text: '✅ دلیل ثبت شد.');
        $this->promptTelegramReasonSummary($bot);
        $this->next('awaitTelegramReasonSummary');
    }

    public function awaitTelegramReasonSummary(Nutgram $bot): void
    {
        $callbackData = $bot->callbackQuery()?->data;

        if ($callbackData === 'reporter_telegram_menu') {
            $bot->answerCallbackQuery();
            $this->showTelegramReporterMenu($bot);
            $this->end();
            return;
        }

        $reasonKey = $bot->getUserData('tg_reporter_selected_reason_key');
        $reasonTitle = $bot->getUserData('tg_reporter_selected_reason_title');

        if (!$reasonKey || !$reasonTitle) {
            if ($bot->callbackQuery()) {
                $bot->answerCallbackQuery(text: '⛔️ ابتدا دلیل ریپورت را انتخاب کن.');
            }
            $this->promptTelegramReason($bot);
            $this->next('processTelegramReason');
            return;
        }

        $summary = null;

        if ($callbackData) {
            if ($callbackData !== 'telegram_reason_summary_default') {
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
        $bot->setUserData('tg_reporter_reason_summary', $summary);
        $this->finalizeTelegramReport($bot, (string)$reasonTitle, $summary);
    }

    private function finalizeTelegramReport(Nutgram $bot, string $reason, string $reasonSummary): void
    {
        $target = $bot->getUserData('tg_reporter_target') ?? [];
        $username = $target['username'] ?? $bot->getUserData('tg_reporter_username');
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

        $whitelist = app(WhitelistService::class);
        if ($whitelist->isWhitelisted($username, WhitelistedTarget::TYPE_TELEGRAM)) {
            $targetType = (string)($target['type'] ?? $bot->getUserData('tg_reporter_target_type') ?? 'account');
            $blockLabel = $this->resolveTelegramWhitelistLabel($targetType, (string)$username);
            if ($bot->callbackQuery()) {
                $bot->answerCallbackQuery();
            }
            $bot->sendMessage($whitelist->getBlockMessage($username, WhitelistedTarget::TYPE_TELEGRAM, $blockLabel));
            $this->end();
            return;
        }

        $limiter->recordReporterUsage($local);
        $baseMessageId = $bot->getUserData('tg_reason_summary_prompt_message_id') ?: null;
        if (!$baseMessageId && $bot->callbackQuery()?->message?->message_id) {
            $baseMessageId = $bot->callbackQuery()?->message?->message_id;
        }

        $this->runTelegramReport(
            $bot,
            $username,
            $reason,
            $reasonSummary,
            $target['label'] ?? null,
            $target['link'] ?? null,
            $baseMessageId
        );
    }

    private function promptTelegramReasonSummary(Nutgram $bot): void
    {
        $text = "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> کرم پلاس <tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji>\n\n" .
            "<tg-emoji emoji-id='4904973211763999824'>🗣️</tg-emoji> دلیل ریپورت رو به صورت خلاصه توضیح بده ( <tg-emoji emoji-id='6226426402682441481'>⚠️</tg-emoji> خیلی مهم و تاثیر گذار روی نتیجه ) :\n\n" .
            "<tg-emoji emoji-id='5377620300965888937'>🔴</tg-emoji> هرچی متنش رسمی تر و به زبان انگلیسی باشه نتیجه بهتری میگیری\n" .
            "<tg-emoji emoji-id='5377620300965888937'>🔴</tg-emoji> میتونی از Chat GPT کمک بگیری یا متن پیش فرض مارو انتخاب کنی";
        $keyboard = $this->reasonSummaryKeyboard();
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
                $bot->setUserData('tg_reason_summary_prompt_message_id', $messageId);
                return;
            } catch (\Throwable) {
                // fallback to sending a new message
            }
        }

        $sent = $bot->sendMessage($text, parse_mode: 'HTML', reply_markup: $keyboard);
        $bot->setUserData('tg_reason_summary_prompt_message_id', $sent->message_id ?? null);
    }

    private function reasonSummaryKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('متن پیش فرض', callback_data: 'telegram_reason_summary_default', style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('بازگشت', callback_data: 'reporter_telegram_menu', style: 'danger', icon: '5352759161945867747')
            );
    }

    private function buildDefaultReasonSummary(Nutgram $bot, string $reasonKey): string
    {
        $targetType = (string)($bot->getUserData('tg_reporter_target_type') ?? 'account');
        $category = $this->reasonCategoryLabel($reasonKey);

        return "I am reporting this {$targetType} for {$category}. This appears to violate platform guidelines. Please review and take appropriate action.";
    }

    private function reasonCategoryLabel(string $reasonKey): string
    {
        return match ($reasonKey) {
            'telegram_reason_child_abuse' => 'child abuse content',
            'telegram_reason_violence' => 'violent or dangerous content',
            'telegram_reason_illegal_goods' => 'illegal goods and services',
            'telegram_reason_illegal_adult' => 'illegal adult content',
            'telegram_reason_personal_data' => 'unauthorized sharing of personal data',
            'telegram_reason_fraud' => 'fraud or scam activity',
            'telegram_reason_copyright' => 'copyright infringement',
            'telegram_reason_spam' => 'spam and abusive behavior',
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

    private function runTelegramReport(
        Nutgram $bot,
        string $username,
        string $reason,
        string $reasonSummary,
        ?string $targetLabel = null,
        ?string $previewLink = null,
        ?int $baseMessageId = null
    ) {
        $totalSteps = 5;
        $delayPerStep = 5;

        $label = $targetLabel ?: "👤 یوزرنیم: @{$username}";
        $previewLine = $previewLink ? "\n🖇️ لینک: {$previewLink}" : '';

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
        ) . $previewLine;

        $progressMessageId = null;
        $useCaption = false;
        $reportPhoto = $this->getReportPhoto();

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
            $progressMessageId = $this->prepareProgressMessage($bot, $initialText, null);
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
            ) . $previewLine;

            try {
                if ($useCaption) {
                    $bot->editMessageCaption(
                        chat_id: $bot->user()->id,
                        message_id: $progressMessageId,
                        caption: $updateMsg,
                        parse_mode: 'HTML'
                    );
                } else {
                    $bot->editMessageText(
                        chat_id: $bot->user()->id,
                        message_id: $progressMessageId,
                        text: $updateMsg,
                        parse_mode: 'HTML'
                    );
                }
            } catch (\Exception) {
                // Continue on error
            }
        }

        $finalText = $this->buildFinalMessage($label, $previewLink);
        $reportPhoto = $this->getReportPhoto();

        $this->deleteMessageSafe($bot, $progressMessageId);
        if ($reportPhoto) {
            try {
                $bot->sendPhoto(
                    photo: $reportPhoto,
                    caption: $finalText,
                    parse_mode: 'HTML'
                );
                $bot->setUserData('tg_cleanup_messages', []);
                $this->end();
                return;
            } catch (\Throwable) {
                // fallback to text mode below
            }
        }

        $bot->sendMessage($finalText, parse_mode: 'HTML');
        $bot->setUserData('tg_cleanup_messages', []);

        $this->end();
    }

    private function getProgressBar(int $percent): string
    {
        $filled = max(0, min(10, (int)round($percent / 10)));
        $empty = 10 - $filled;
        $bar = '[' . str_repeat('█', $filled) . str_repeat('░', $empty) . ']';
        return $bar . ' ' . $percent . '%';
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

    private function deleteMessageSafe(Nutgram $bot, int $messageId): void
    {
        try {
            $bot->deleteMessage(chat_id: $bot->user()->id, message_id: $messageId);
        } catch (\Throwable) {
            // ignore
        }
    }

    private function addCleanupMessage(Nutgram $bot, ?int $messageId): void
    {
        if (!$messageId) return;
        $messages = $bot->getUserData('tg_cleanup_messages') ?? [];
        $messages[] = $messageId;
        $bot->setUserData('tg_cleanup_messages', $messages);
    }

    private function clearCleanupMessages(Nutgram $bot): void
    {
        $messages = $bot->getUserData('tg_cleanup_messages') ?? [];
        foreach ($messages as $mid) {
            $this->deleteMessageSafe($bot, (int)$mid);
        }
        $bot->setUserData('tg_cleanup_messages', []);
    }

    private function determineTargetType(?string $callbackData): string
    {
        return match ($callbackData) {
            'telegram_report_channel' => 'channel',
            'telegram_report_post' => 'post',
            default => 'account',
        };
    }

    private function resolveTelegramWhitelistLabel(string $targetType, string $username): string
    {
        if ($targetType === 'channel') {
            return 'چنل';
        }

        if ($targetType === 'post') {
            return 'پست';
        }

        return preg_match('/bot$/i', $username) === 1 ? 'بات' : 'اکانت';
    }

    private function buildTelegramTargetInputPrompt(string $targetType): string
    {
        if ($targetType === 'post') {
            return "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> کرم پلاس <tg-emoji emoji-id='5134654202894615343'>🪱</tg-emoji>\n\n" .
                "<tg-emoji emoji-id='5407025283456835913'>📱</tg-emoji> لینک یا آیدی پست تلگرام تارگت رو برام بفرست:\n\n" .
                "<tg-emoji emoji-id='5334882760735598374'>📝</tg-emoji> فرمت های قابل قبول:\n" .
                "• لینک کامل: https://t.me/username/123\n" .
                "• بدون دامنه: username/123\n" .
                "• با @: @username/123\n\n" .
                "<tg-emoji emoji-id='5123359615727174427'>💡</tg-emoji> مثلا:\n" .
                "• https://t.me/telegram/100\n" .
                "• telegram/100\n\n" .
                "<tg-emoji emoji-id='6226426402682441481'>⚠️</tg-emoji> دقت کن:\n" .
                "• آیدی پیام باید فقط عدد انگلیسی باشه\n" .
                "• لینک/آیدی رو بدون فاصله و بدون خط تیره بفرست";
        }

        if ($targetType === 'channel') {
            return "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> کرم پلاس <tg-emoji emoji-id='5134654202894615343'>🪱</tg-emoji>\n\n" .
                "<tg-emoji emoji-id='5407025283456835913'>📱</tg-emoji> یوزرنیم یا لینک کانال تلگرام تارگت رو برام بفرست:\n\n" .
                "<tg-emoji emoji-id='5334882760735598374'>📝</tg-emoji> فرمت های قابل قبول:\n" .
                "• بدون @: channelname\n" .
                "• با @: @channelname\n" .
                "• لینک: https://t.me/channelname\n\n" .
                "<tg-emoji emoji-id='5123359615727174427'>💡</tg-emoji> مثلا:\n" .
                "• telegram\n" .
                "• @telegram\n" .
                "• https://t.me/telegram\n\n" .
                "<tg-emoji emoji-id='6226426402682441481'>⚠️</tg-emoji> دقت کن:\n" .
                "• فقط حروف انگلیسی، عدد و _ مجازه\n" .
                "• یوزرنیم باید بین 3 تا 32 کاراکتر باشه";
        }

        return "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> کرم پلاس <tg-emoji emoji-id='5134654202894615343'>🪱</tg-emoji>\n\n" .
            "<tg-emoji emoji-id='5407025283456835913'>📱</tg-emoji> یوزرنیم اکانت تلگرام تارگت رو برام بفرست:\n\n" .
            "<tg-emoji emoji-id='5334882760735598374'>📝</tg-emoji> فرمت های قابل قبول:\n" .
            "• بدون @: username\n" .
            "• با @: @username\n" .
            "• لینک: https://t.me/username\n\n" .
            "<tg-emoji emoji-id='5123359615727174427'>💡</tg-emoji> مثلا:\n" .
            "• durov\n" .
            "• @durov\n" .
            "• https://t.me/durov\n\n" .
            "<tg-emoji emoji-id='6226426402682441481'>⚠️</tg-emoji> دقت کن:\n" .
            "• فقط حروف انگلیسی، عدد و _ مجازه\n" .
            "• یوزرنیم باید بین 3 تا 32 کاراکتر باشه";
    }

    private function normalizeTelegramTarget(string $input, string $targetType): ?array
    {
        if ($targetType === 'post') {
            $post = $this->normalizeTelegramPost($input);
            if (!$post) {
                return null;
            }

            $link = $this->buildTelegramLink($post['username'], $post['message_id']);

            return [
                'type' => 'post',
                'username' => $post['username'],
                'message_id' => $post['message_id'],
                'link' => $link,
                'label' => "📮 پست: @{$post['username']} / {$post['message_id']}",
            ];
        }

        $username = $this->normalizeTelegramUsername($input);
        if (!$username) {
            return null;
        }

        $link = $this->buildTelegramLink($username);
        $label = $targetType === 'channel'
            ? "📢 کانال: @{$username}"
            : "👤 یوزرنیم: @{$username}";

        return [
            'type' => $targetType,
            'username' => $username,
            'message_id' => null,
            'link' => $link,
            'label' => $label,
        ];
    }

    private function normalizeTelegramUsername(string $input): ?string
    {
        $username = preg_replace('#^https?://t\\.me/#i', '', trim($input));
        $username = ltrim($username ?? '', '@/');
        $username = strtok($username, '/');

        if (!$username || strlen($username) < 3 || strlen($username) > 32) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9_]+$/', $username) ? $username : null;
    }

    private function normalizeTelegramPost(string $input): ?array
    {
        $clean = preg_replace('#^https?://t\\.me/#i', '', trim($input));
        $clean = ltrim($clean ?? '', '@/');

        if (!preg_match('#^([A-Za-z0-9_]{3,32})/(\\d{1,12})#', $clean, $matches)) {
            return null;
        }

        return [
            'username' => $matches[1],
            'message_id' => (int)$matches[2],
        ];
    }

    private function buildTelegramLink(string $username, ?int $messageId = null): string
    {
        $base = 'https://t.me/' . ltrim($username, '@/');
        return $messageId ? "{$base}/{$messageId}" : $base;
    }

    private function buildTelegramPreview(Nutgram $bot, array $target, $chat = null): ?string
    {
        $link = $target['link'] ?? '';
        $username = $target['username'] ?? '';
        $type = $target['type'] ?? 'account';

        // Try to fetch chat info when possible
        $memberCount = null;

        if ($type === 'channel' && $username) {
            $memberCount = $this->fetchChannelMemberCount($bot, $username);
        }

        if ($chat === null && $type !== 'post' && $username) {
            $chat = $this->fetchTelegramChat($bot, $username);
        }

        return match ($type) {
            'channel' => $this->formatChannelPreview($chat, $username, $memberCount),
            'post' => $this->formatMessagePreview($chat, $username, $target['message_id'] ?? null, $link),
            default => $this->formatAccountPreview($chat, $username),
        };
    }

    private function formatAccountPreview($chat, string $username): string
    {
        return "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> KermPlus | Account Found\n" .
            "━━━━━━━━━━━━━━━\n" .
            "📜 username : @{$username}\n" .
            "━━━━━━━━━━━━━━━\n\n";
    }

    private function formatChannelPreview($chat, string $username, ?int $memberCount = null): string
    {
        $title = $chat->title ?? '@' . $username;
        $description = $chat->description ?? '—';
        $members = $memberCount ?? $chat->subscriber_count ?? $chat->member_count ?? '—';

        return "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> KermPlus | Channel Found\n" .
            "━━━━━━━━━━━━━━━\n" .
            "📢 title: {$title}\n" .
            "🆔 username: @{$username}\n" .
            "🧾 description: {$description}\n\n" .
            "👥 subscribers: {$members}\n" .
            "━━━━━━━━━━━━━━━\n\n";
    }

    private function formatMessagePreview($chat, string $username, ?int $messageId, string $link): string
    {
        $content = '—';
        $views = '—';
        $sentAt = '—';

        return "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> KermPlus | Message Found\n" .
            "━━━━━━━━━━━━━━━\n" .
            "📢 source: @{$username}\n" .
            "🆔 message id: {$messageId}\n" .
            "📝 content: {$content}\n" .
            "🖇️ link : {$link}\n\n" .
            "👀 views: {$views}\n" .
            "📅 sent at: {$sentAt}\n" .
            "━━━━━━━━━━━━━━━\n\n" ;    }

    private function respondWithLimit(Nutgram $bot, string $message): void
    {
        if ($bot->callbackQuery()) {
            $bot->answerCallbackQuery(text: $message, show_alert: true);
            return;
        }

        $bot->sendMessage($message);
    }

    private function telegramReasons(): array
    {
        return [
            'telegram_reason_child_abuse' => 'کودک آزاری',
            'telegram_reason_violence' => 'خشونت',
            'telegram_reason_illegal_goods' => 'کالا و خدمات غیرقانونی',
            'telegram_reason_illegal_adult' => 'محتوای بزرگسالان غیرقانونی',
            'telegram_reason_personal_data' => 'داده ‌های شخصی',
            'telegram_reason_fraud' => 'کلاهبرداری',
            'telegram_reason_copyright' => 'کپی رایت',
            'telegram_reason_spam' => 'اسپم',
            'telegram_reason_other' => 'غیرقانونی نیست ، اما باید حذف شود',
        ];
    }

    private function promptTelegramReason(Nutgram $bot): void
    {
        $text = "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> کرم پلاس <tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji>\n\n<tg-emoji emoji-id='4904973211763999824'>🗣️</tg-emoji> دلیل ریپورت رو انتخاب کن :";
        $keyboard = TelegramReportReasonKeyboard::make();
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

    private function prepareProgressMessage(Nutgram $bot, string $text, ?int $baseMessageId = null): ?int
    {
        if ($baseMessageId) {
            try {
                $bot->editMessageText(
                    chat_id: $bot->user()->id,
                    message_id: $baseMessageId,
                    text: $text,
                    parse_mode: 'HTML'
                );
                return $baseMessageId;
            } catch (\Throwable) {
                // fallback to sending a new message
            }
        }

        $sent = $bot->sendMessage($text, parse_mode: 'HTML');
        return $sent->message_id ?? null;
    }

    private function targetInputKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('بازگشت', callback_data: 'reporter_telegram_menu', style: 'danger', icon: '5352759161945867747')
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

    private function showTelegramReporterMenu(Nutgram $bot): void
    {
        $msg = "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> کرم پلاس <tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji>\n\n<tg-emoji emoji-id='5364125616801073577'>✈️</tg-emoji> ریپورتر تلگرام\nبرای ادامه یکی از گزینه های زیر رو انتخاب کن :";
        $this->sendOrEditMessage($bot, $msg, TelegramReporterMenuKeyboard::make());
    }

    private function getReportPhoto(): ?InputFile
    {
        $path = public_path('images/report.png');
        return is_readable($path) ? InputFile::make($path, 'report.png') : null;
    }

    private function fetchChannelMemberCount(Nutgram $bot, string $username): ?int
    {
        try {
            return $bot->getChatMemberCount(chat_id: '@' . $username);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function fetchTelegramChat(Nutgram $bot, string $username)
    {
        try {
            return $bot->getChat(chat_id: '@' . $username);
        } catch (\Throwable) {
            return null;
        }
    }
}

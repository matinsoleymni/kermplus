<?php

namespace App\Telegram\Conversations;

use App\Models\User;
use App\Models\WhitelistedTarget;
use App\Services\FeatureLimitService;
use App\Services\WhitelistService;
use App\Services\BoxApiService;
use App\Telegram\Keyboards\InstagramReportReasonKeyboard;
use App\Telegram\Keyboards\InstagramReporterMenuKeyboard;
use Illuminate\Support\Carbon;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class InstagramReporterConversation extends BaseReporterConversation
{
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
        $bot->setUserData('ig_reporter_target_type', $targetType);
        $bot->setUserData('ig_reporter_media', null);
        $bot->setUserData('ig_reporter_post_link', null);
        $bot->setUserData('ig_reporter_selected_reason_key', null);
        $bot->setUserData('ig_reporter_selected_reason_title', null);
        $bot->setUserData('ig_reporter_reason_summary', null);
        $bot->setUserData('ig_reason_summary_prompt_message_id', null);
        $bot->setUserData('ig_reason_summary_prompt_uses_caption', false);
        $bot->setUserData('ig_stage_message_id', null);
        $bot->setUserData('ig_stage_message_uses_caption', false);
        $bot->setUserData('ig_cleanup_messages', []);

        $promptText = $this->buildInstagramTargetInputPrompt($targetType);

        $this->sendOrEditMessage($bot, $promptText, $this->targetInputKeyboard());
        $this->next($targetType === 'post' ? 'awaitPostLink' : 'awaitUsername');
    }

    public function awaitUsername(Nutgram $bot)
    {
        $callbackData = $bot->callbackQuery()?->data;
        if ($callbackData === 'reporter_instagram_menu') {
            $bot->answerCallbackQuery();
            $this->showInstagramReporterMenu($bot);
            $this->end();
            return;
        }

        if ($callbackData) {
            $bot->answerCallbackQuery(text: '⛔️ ابتدا یوزرنیم را به صورت متن ارسال کن.');
            return;
        }

        $username = $bot->message()?->text;
        if (!$username || strlen($username) < 3) {
            $this->sendOrEditMessage($bot, '⛔️ یوزرنیم نامعتبر است. لطفا حداقل 3 کاراکتر وارد کنید.', $this->targetInputKeyboard());
            return;
        }

        $username = ltrim(trim($username), '@');

        $whitelist = app(WhitelistService::class);
        $instagramTypes = [WhitelistedTarget::TYPE_INSTAGRAM_EMAIL, WhitelistedTarget::TYPE_CUSTOM];
        if ($whitelist->isWhitelisted($username, $instagramTypes)) {
            $this->sendOrEditMessage($bot, $whitelist->getBlockMessage($username, $instagramTypes, 'پیج اینستاگرام'));
            $this->end();
            return;
        }

        $bot->setUserData('ig_reporter_username', $username);

        $loadingMessageId = $bot->getUserData('ig_stage_message_id') ?: null;
        $loadingUsesCaption = (bool)$bot->getUserData('ig_stage_message_uses_caption');

        if ($loadingMessageId) {
            try {
                $this->editMessageByType($bot, (int)$loadingMessageId, '<tg-emoji emoji-id="5116159438062879454">🙏</tg-emoji> درحال دریافت اطلاعات پروفایل...', $loadingUsesCaption, null, true);
            } catch (\Throwable) {
                $loadingMessageId = null;
                $loadingUsesCaption = false;
            }
        }

        $reportPhoto = $this->getReportPhoto();
        if (!$loadingMessageId && $reportPhoto) {
            try {
                $loadingMsg = $bot->sendPhoto(
                    photo: $reportPhoto,
                    caption: '<tg-emoji emoji-id="5116159438062879454">🙏</tg-emoji> درحال دریافت اطلاعات پروفایل...'
                );
                $loadingMessageId = $loadingMsg->message_id ?? null;
                $loadingUsesCaption = (bool)$loadingMessageId;
            } catch (\Throwable) {
                $loadingMessageId = null;
            }
        }

        if (!$loadingMessageId) {
            $loadingMsg = $bot->sendMessage('<tg-emoji emoji-id="5116159438062879454">🙏</tg-emoji> درحال دریافت اطلاعات پروفایل...');
            $loadingMessageId = $loadingMsg->message_id ?? null;
            $loadingUsesCaption = false;
        }
        $this->setStageMessage($bot, $loadingMessageId, $loadingUsesCaption);
        $this->addCleanupMessage($bot, $loadingMessageId);

        $profile = $this->fetchInstagramProfile($username);

        if ($profile === null) {
            $this->sendOrEditMessage($bot, '⚠️ متاسفانه نتوانستیم اطلاعات این حساب را دریافت کنیم. لطفا بعدا دوباره تلاش کنید یا یوزرنیم دیگری وارد کنید.');
            $this->end();
            return;
        }

        $bot->setUserData('ig_reporter_profile', $profile);

        $details = $this->buildProfileMessage($profile);
        $keyboard = InstagramReportReasonKeyboard::make();
        $photoUrl = $profile['profile_pic_url'] ?? null;


        if ($loadingMessageId) {
            try {
                $bot->deleteMessage($bot->chatId(), $loadingMessageId);
            } catch (\Throwable $e) {
            }
        }

        try {
            $sent = $bot->sendPhoto(
                photo: $photoUrl ?? 'آدرس_عکس_پیش_فرض',
                caption: $details,
                reply_markup: $keyboard,
                parse_mode: 'HTML'
            );

            $sentId = $sent->message_id;
            $this->setStageMessage($bot, $sentId, true);
            $this->addCleanupMessage($bot, $sentId);
            $this->next('processInstagramReason');
            return;
        } catch (\Throwable $e) {
            $sent = $bot->sendMessage($details, reply_markup: $keyboard, parse_mode: 'HTML');
            $this->setStageMessage($bot, $sent->message_id, false);
            $this->next('processInstagramReason');
        }

        $sent = $bot->sendMessage($details, reply_markup: $keyboard, parse_mode: 'HTML');
        $sentId = $sent->message_id ?? null;
        $this->setStageMessage($bot, $sentId, false);
        $this->addCleanupMessage($bot, $sentId);
        $this->next('processInstagramReason');
    }

    public function awaitPostLink(Nutgram $bot)
    {
        $callbackData = $bot->callbackQuery()?->data;
        if ($callbackData === 'reporter_instagram_menu') {
            $bot->answerCallbackQuery();
            $this->showInstagramReporterMenu($bot);
            $this->end();
            return;
        }

        if ($callbackData) {
            $bot->answerCallbackQuery(text: '⛔️ ابتدا لینک پست را به صورت متن ارسال کن.');
            return;
        }

        $link = trim((string)$bot->message()?->text);
        if ($link === '') {
            $this->sendOrEditMessage($bot, '⛔️ لینک نامعتبر است. دوباره تلاش کن.', $this->targetInputKeyboard());
            return;
        }

        $shortcode = $this->extractInstagramShortcode($link);
        if (!$shortcode) {
            $this->sendOrEditMessage($bot, '⛔️ لینک یا کد کوتاه معتبر نیست.', $this->targetInputKeyboard());
            return;
        }

        $bot->setUserData('ig_reporter_post_shortcode', $shortcode);
        $bot->setUserData('ig_reporter_post_link', $this->buildInstagramLink($shortcode));

        $loadingMessageId = $bot->getUserData('ig_stage_message_id') ?: null;
        $loadingUsesCaption = (bool)$bot->getUserData('ig_stage_message_uses_caption');

        if ($loadingMessageId) {
            try {
                $this->editMessageByType($bot, (int)$loadingMessageId, '⏳ درحال دریافت اطلاعات پست...', $loadingUsesCaption, null, true);
            } catch (\Throwable) {
                $loadingMessageId = null;
                $loadingUsesCaption = false;
            }
        }

        $reportPhoto = $this->getReportPhoto();
        if (!$loadingMessageId && $reportPhoto) {
            try {
                $loadingMsg = $bot->sendPhoto(
                    photo: $reportPhoto,
                    caption: '⏳ درحال دریافت اطلاعات پست...'
                );
                $loadingMessageId = $loadingMsg->message_id ?? null;
                $loadingUsesCaption = (bool)$loadingMessageId;
            } catch (\Throwable) {
                $loadingMessageId = null;
            }
        }

        if (!$loadingMessageId) {
            $loadingMsg = $bot->sendMessage('⏳ درحال دریافت اطلاعات پست...');
            $loadingMessageId = $loadingMsg->message_id ?? null;
            $loadingUsesCaption = false;
        }
        $this->setStageMessage($bot, $loadingMessageId, $loadingUsesCaption);
        $this->addCleanupMessage($bot, $loadingMessageId);

        $media = $this->fetchInstagramMedia($shortcode);

        if ($media === null) {
            $this->sendOrEditMessage($bot, '⚠️ نتونستیم اطلاعات این پست رو بگیریم. دوباره امتحان کن یا لینک دیگری بده.');
            $this->end();
            return;
        }

        $media = $this->normalizeInstagramMedia($media, $shortcode);

        $owner = data_get($media, 'owner.username');
        if ($owner) {
            $bot->setUserData('ig_reporter_username', $owner);
        }

        $bot->setUserData('ig_reporter_media', $media);

        $details = $this->buildMediaMessage($media, $this->buildInstagramLink($shortcode));
        $keyboard = InstagramReportReasonKeyboard::make();
        $thumb = $media['thumbnail_url'] ?? $media['media_url'] ?? null;

        if ($loadingMessageId) {
            try {
                $this->editMessageByType($bot, (int)$loadingMessageId, $details, $loadingUsesCaption, $keyboard, true);
                $this->setStageMessage($bot, (int)$loadingMessageId, $loadingUsesCaption);
                $this->next('processInstagramReason');
                return;
            } catch (\Throwable) {
                // continue with fallback sending
            }
        }

        try {
            if ($thumb) {
                $sent = $bot->sendPhoto(
                    photo: $thumb,
                    caption: $details,
                    reply_markup: $keyboard,
                );
                $sentId = $sent->message_id ?? null;
                $this->setStageMessage($bot, $sentId, true);
                $this->addCleanupMessage($bot, $sentId);
                $this->next('processInstagramReason');
                return;
            }
        } catch (\Throwable $e) {
            // If sending photo fails, fallback to text message
        }

        $sent = $bot->sendMessage($details, reply_markup: $keyboard, parse_mode: 'HTML');
        $sentId = $sent->message_id ?? null;
        $this->setStageMessage($bot, $sentId, false);
        $this->addCleanupMessage($bot, $sentId);
        $this->next('processInstagramReason');
    }

    public function processInstagramReason(Nutgram $bot): void
    {
        $data = $bot->callbackQuery()?->data;
        $reasons = $this->instagramReasons();

        if ($data === 'reporter_instagram_menu') {
            $bot->answerCallbackQuery();
            $this->showInstagramReporterMenu($bot);
            $this->end();
            return;
        }

        if (!$data || !isset($reasons[$data])) {
            if ($bot->callbackQuery()) {
                $bot->answerCallbackQuery(text: '⛔️ گزینه نامعتبر است.');
            }
            $this->promptInstagramReason($bot, '⛔️ لطفا دلیل ریپورت رو از دکمه‌ها انتخاب کن.');
            return;
        }

        $bot->setUserData('ig_reporter_selected_reason_key', $data);
        $bot->setUserData('ig_reporter_selected_reason_title', $reasons[$data]);

        $bot->answerCallbackQuery(text: '✅ دلیل ثبت شد.');
        $this->promptInstagramReasonSummary($bot);
        $this->next('awaitInstagramReasonSummary');
    }

    public function awaitInstagramReasonSummary(Nutgram $bot): void
    {
        $callbackData = $bot->callbackQuery()?->data;

        if ($callbackData === 'reporter_instagram_menu') {
            $bot->answerCallbackQuery();
            $this->showInstagramReporterMenu($bot);
            $this->end();
            return;
        }

        $reasonKey = $bot->getUserData('ig_reporter_selected_reason_key');
        $reasonTitle = $bot->getUserData('ig_reporter_selected_reason_title');

        if (!$reasonKey || !$reasonTitle) {
            if ($bot->callbackQuery()) {
                $bot->answerCallbackQuery(text: '⛔️ ابتدا دلیل ریپورت را انتخاب کن.');
            }
            $this->promptInstagramReason($bot, '⛔️ ابتدا دلیل ریپورت را انتخاب کن.');
            $this->next('processInstagramReason');
            return;
        }

        $summary = null;

        if ($callbackData) {
            if ($callbackData !== 'instagram_reason_summary_default') {
                $bot->answerCallbackQuery(text: '⛔️ لطفا متن رو ارسال کن یا متن پیش‌فرض رو بزن.');
                return;
            }

            $summary = $this->buildDefaultReasonSummary($bot, (string)$reasonKey);
            $bot->answerCallbackQuery(text: '✅ متن پیش‌فرض انتخاب شد.');
        } else {
            $summary = trim((string)$bot->message()?->text);
            if ($summary === '') {
                $this->promptInstagramReasonSummary($bot, '⛔️ لطفا توضیح دلیل ریپورت رو ارسال کن یا متن پیش‌فرض رو انتخاب کن.');
                return;
            }
        }

        $summary = $this->normalizeReasonSummary($summary);
        $bot->setUserData('ig_reporter_reason_summary', $summary);
        $this->finalizeInstagramReport($bot, (string)$reasonTitle, $summary);
    }

    private function finalizeInstagramReport(Nutgram $bot, string $reason, string $reasonSummary): void
    {
        $username = $bot->getUserData('ig_reporter_username');
        if (!$username) {
            if ($bot->callbackQuery()) {
                $bot->answerCallbackQuery(text: '⛔️ ابتدا یوزرنیم را وارد کنید.');
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
        $instagramTypes = [WhitelistedTarget::TYPE_INSTAGRAM_EMAIL, WhitelistedTarget::TYPE_CUSTOM];
        if ($whitelist->isWhitelisted($username, $instagramTypes)) {
            if ($bot->callbackQuery()) {
                $bot->answerCallbackQuery();
            }
            $this->sendOrEditMessage($bot, $whitelist->getBlockMessage($username, $instagramTypes, 'پیج اینستاگرام'));
            $this->end();
            return;
        }

        $limiter->recordReporterUsage($local);

        $baseMessageId = $bot->getUserData('ig_reason_summary_prompt_message_id') ?: null;
        $baseUsesCaption = (bool)$bot->getUserData('ig_reason_summary_prompt_uses_caption');

        if (!$baseMessageId && $bot->callbackQuery()?->message?->message_id) {
            $baseMessageId = $bot->callbackQuery()?->message?->message_id;
            $baseUsesCaption = $this->isCallbackMessagePhoto($bot);
        }

        $targetType = $bot->getUserData('ig_reporter_target_type') ?? 'page';

        if ($targetType === 'post') {
            $media = $bot->getUserData('ig_reporter_media');
            $link = $bot->getUserData('ig_reporter_post_link');

            if (!$media) {
                if ($bot->callbackQuery()) {
                    $bot->answerCallbackQuery(text: '⛔️ پست یافت نشد. دوباره تلاش کن.');
                }
                $this->sendOrEditMessage($bot, '⛔️ پست یافت نشد. دوباره تلاش کن.');
                $this->end();
                return;
            }

            $owner = data_get($media, 'owner.username', $username);
            $this->runInstagramReport($bot, $owner, $reason, $reasonSummary, 'post', $media, $link, $baseMessageId, $baseUsesCaption);
            return;
        }

        $this->runInstagramReport($bot, $username, $reason, $reasonSummary, 'page', null, null, $baseMessageId, $baseUsesCaption);
    }

    private function promptInstagramReasonSummary(Nutgram $bot, ?string $error = null): void
    {
        $text = '';
        if ($error) {
            $text .= $error . "\n\n";
        }

        $text .= "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> کرم پلاس <tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji>\n\n" .
            "<tg-emoji emoji-id='4904973211763999824'>🗣️</tg-emoji> دلیل ریپورت رو به صورت خلاصه توضیح بده ( <tg-emoji emoji-id='6226426402682441481'>⚠️</tg-emoji> خیلی مهم و تاثیر گذار روی نتیجه ) :\n\n" .
            "<tg-emoji emoji-id='5377620300965888937'>🔴</tg-emoji> هرچی متنش رسمی تر و به زبان انگلیسی باشه نتیجه بهتری میگیری\n" .
            "<tg-emoji emoji-id='5377620300965888937'>🔴</tg-emoji> میتونی از Chat GPT کمک بگیری یا متن پیش فرض مارو انتخاب کنی";
        $keyboard = $this->reasonSummaryKeyboard();
        $result = $this->sendOrEditMessage($bot, $text, $keyboard);
        $bot->setUserData('ig_reason_summary_prompt_message_id', $result['message_id']);
        $bot->setUserData('ig_reason_summary_prompt_uses_caption', $result['uses_caption']);
    }

    private function reasonSummaryKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('متن پیش فرض', callback_data: 'instagram_reason_summary_default', style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('بازگشت', callback_data: 'reporter_instagram_menu', style: 'danger', icon_custom_emoji_id: '5352759161945867747')
            );
    }

    private function buildDefaultReasonSummary(Nutgram $bot, string $reasonKey): string
    {
        $targetType = $bot->getUserData('ig_reporter_target_type') === 'post' ? 'post' : 'account';
        $category = $this->reasonCategoryLabel($reasonKey);

        return "I am reporting this {$targetType} for {$category}. This appears to violate Instagram Community Guidelines. Please review and take appropriate action.";
    }

    private function reasonCategoryLabel(string $reasonKey): string
    {
        return match ($reasonKey) {
            'instagram_reason_spam' => 'spam and inauthentic behavior',
            'instagram_reason_harassment' => 'harassment and abusive behavior',
            'instagram_reason_violence' => 'violent or dangerous content',
            'instagram_reason_illegal_sales' => 'illegal sale or promotion of prohibited goods',
            'instagram_reason_nudity' => 'nudity or sexual content',
            'instagram_reason_fraud' => 'fraud or scam activity',
            'instagram_reason_misinformation' => 'misleading or false information',
            default => 'harmful and inappropriate content',
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

    private function runInstagramReport(
        Nutgram $bot,
        string $username,
        string $reason,
        string $reasonSummary,
        string $targetType = 'page',
        ?array $media = null,
        ?string $link = null,
        ?int $baseMessageId = null,
        bool $baseUsesCaption = false
    ) {
        $totalSteps = 5;
        $delayPerStep = 5;

        $label = $targetType === 'post'
            ? "📮 پست: @{$username}"
            : "📸 یوزرنیم: @{$username}";
        $previewLine = $link ? "\n🖇️ لینک: " . htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';
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

        $progressMessageId = $baseMessageId;
        $useCaption = $baseUsesCaption;
        $reportPhoto = $this->getReportPhoto();

        $this->clearCleanupMessages($bot, $baseMessageId ? [(int)$baseMessageId] : []);

        if ($progressMessageId) {
            try {
                $this->editMessageByType($bot, (int)$progressMessageId, $initialText, $useCaption, null, true);
            } catch (\Throwable) {
                $this->deleteMessageSafe($bot, (int)$progressMessageId);
                $progressMessageId = null;
                $useCaption = false;
            }
        }

        if (!$progressMessageId && $reportPhoto) {
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
            $this->sendOrEditMessage($bot, '⛔️ خطا در ایجاد پیام وضعیت. دوباره تلاش کن.');
            $this->end();
            return;
        }
        $this->setStageMessage($bot, (int)$progressMessageId, $useCaption);

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
                $this->editMessageByType($bot, $progressMessageId, $updateMsg, $useCaption, null, true);
            } catch (\Throwable) {
                // Continue on error
            }
        }

        $finalText = $this->buildFinalMessage($label, $link);
        try {
            $this->editMessageByType($bot, $progressMessageId, $finalText, $useCaption, parseHtml: true);
            $this->setStageMessage($bot, (int)$progressMessageId, $useCaption);
        } catch (\Throwable) {
            $this->deleteMessageSafe($bot, (int)$progressMessageId);

            if ($reportPhoto) {
                try {
                    $sent = $bot->sendPhoto(
                        photo: $reportPhoto,
                        caption: $finalText,
                        parse_mode: 'HTML'
                    );
                    $this->setStageMessage($bot, $sent->message_id ?? null, true);
                    $bot->setUserData('ig_cleanup_messages', []);
                    $this->end();
                    return;
                } catch (\Throwable) {
                    // fallback to text message below
                }
            }

            $sent = $bot->sendMessage($finalText, parse_mode: 'HTML');
            $this->setStageMessage($bot, $sent->message_id ?? null, false);
        }

        $bot->setUserData('ig_cleanup_messages', []);
        $this->end();
    }

    private function fetchInstagramProfile(string $username): ?array
    {
        return app(BoxApiService::class)->getInstagramProfile($username);
    }

    private function buildProfileMessage(array $profile): string
    {
        $username = $profile['username'] ?? 'نامشخص';
        $fullName = $profile['full_name'] ?? '—';
        $bio = $profile['biography'] ?? '—';
        $followers = data_get($profile, 'edge_followed_by.count', 0);
        $following = data_get($profile, 'edge_follow.count', 0);
        $posts = data_get($profile, 'edge_owner_to_timeline_media.count', 0);
        $isPrivate = !empty($profile['is_private']) ? 'yes' : 'no';

        return "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> KermPlus | Profile Found\n" .
            "━━━━━━━━━━━━━━━\n" .
            "<tg-emoji emoji-id='4913497231492908158'>👤</tg-emoji> name: @{$username}\n" .
            "<tg-emoji emoji-id='5422683699130933153'>🪪</tg-emoji> username: {$fullName}\n" .
            "<tg-emoji emoji-id='5123359615727174427'>💡</tg-emoji> bio:  {$bio}\n" .
            "<tg-emoji emoji-id='5803420768826038185'>🔘</tg-emoji> followers: {$followers}  \n" .
            "<tg-emoji emoji-id='5803420768826038185'>🔘</tg-emoji> following: {$following}  \n" .
            "<tg-emoji emoji-id='5803420768826038185'>🔘</tg-emoji> posts: {$posts}  \n" .
            "<tg-emoji emoji-id='4904500559203009298'>🔒</tg-emoji> is private? : {$isPrivate}  \n" .
            "━━━━━━━━━━━━━━━\n\n" .
            "<tg-emoji emoji-id='4904973211763999824'>🗣️</tg-emoji> دلیل ریپورت رو انتخاب کن :";
    }

    private function instagramReasons(): array
    {
        return [
            'instagram_reason_spam' => 'اسپم',
            'instagram_reason_harassment' => 'مزاحمت و آزار',
            'instagram_reason_violence' => 'خشونت',
            'instagram_reason_illegal_sales' => 'فروش یا تبلیغ کالای غیر مجاز',
            'instagram_reason_nudity' => 'برهنگی یا فعالیت جنسی',
            'instagram_reason_fraud' => 'کلاهبرداری',
            'instagram_reason_misinformation' => 'اطلاعات غلط',
            'instagram_reason_dislike' => 'ازش خوشم نمیاد',
        ];
    }

    private function promptInstagramReason(Nutgram $bot, ?string $error = null): void
    {
        $text = '';
        if ($error) {
            $text .= $error . "\n\n";
        }

        $text .= "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> کرم پلاس <tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji>\n\n<tg-emoji emoji-id='4904973211763999824'>🗣️</tg-emoji> دلیل ریپورت رو انتخاب کن :";
        $this->sendOrEditMessage($bot, $text, InstagramReportReasonKeyboard::make());
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
                InlineKeyboardButton::make('بازگشت', callback_data: 'reporter_instagram_menu', style: 'danger', icon_custom_emoji_id: '5352759161945867747')
            );
    }

    private function sendOrEditMessage(Nutgram $bot, string $text, ?InlineKeyboardMarkup $keyboard = null): array
    {
        $callbackMessageId = $bot->callbackQuery()?->message?->message_id;
        $messageId = $callbackMessageId ?: ($bot->getUserData('ig_stage_message_id') ?: null);
        $useCaption = $callbackMessageId
            ? $this->isCallbackMessagePhoto($bot)
            : (bool)$bot->getUserData('ig_stage_message_uses_caption');

        if ($messageId) {
            try {
                $this->editMessageByType($bot, (int)$messageId, $text, $useCaption, $keyboard, true);
                $this->setStageMessage($bot, (int)$messageId, $useCaption);
                return [
                    'message_id' => (int)$messageId,
                    'uses_caption' => $useCaption,
                ];
            } catch (\Throwable) {
                // fallback to sending a new message
            }
        }

        $sent = $bot->sendMessage($text, parse_mode: 'HTML', reply_markup: $keyboard);
        $sentId = $sent->message_id ?? null;
        $this->setStageMessage($bot, $sentId, false);

        return [
            'message_id' => $sentId,
            'uses_caption' => false,
        ];
    }

    private function showInstagramReporterMenu(Nutgram $bot): void
    {
        $msg = "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> کرم پلاس <tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji>\n\n<tg-emoji emoji-id='5364310996179503764'>📸</tg-emoji> ریپورتر اینستاگرام\nبرای ادامه یکی از گزینه های زیر رو انتخاب کن :";
        $this->sendOrEditMessage($bot, $msg, InstagramReporterMenuKeyboard::make());
    }

    private function getReportPhoto(): ?InputFile
    {
        $path = public_path('images/report.png');
        return is_readable($path) ? InputFile::make($path, 'report.png') : null;
    }

    private function isCallbackMessagePhoto(Nutgram $bot): bool
    {
        return (bool)$bot->callbackQuery()?->message?->photo;
    }

    private function addCleanupMessage(Nutgram $bot, ?int $messageId): void
    {
        if (!$messageId) return;
        $messages = $bot->getUserData('ig_cleanup_messages') ?? [];
        $messages[] = $messageId;
        $bot->setUserData('ig_cleanup_messages', $messages);
    }

    private function clearCleanupMessages(Nutgram $bot, array $exceptMessageIds = []): void
    {
        $skip = [];
        foreach ($exceptMessageIds as $id) {
            if ($id) {
                $skip[(int)$id] = true;
            }
        }

        $messages = $bot->getUserData('ig_cleanup_messages') ?? [];
        foreach ($messages as $mid) {
            $mid = (int)$mid;
            if (isset($skip[$mid])) {
                continue;
            }

            $this->deleteMessageSafe($bot, $mid);
        }
        $bot->setUserData('ig_cleanup_messages', []);
    }

    private function setStageMessage(Nutgram $bot, ?int $messageId, bool $usesCaption = false): void
    {
        $bot->setUserData('ig_stage_message_id', $messageId);
        $bot->setUserData('ig_stage_message_uses_caption', $messageId ? $usesCaption : false);
    }

    private function determineTargetType(?string $callbackData): string
    {
        return $callbackData === 'instagram_report_post' ? 'post' : 'page';
    }

    private function buildInstagramTargetInputPrompt(string $targetType): string
    {
        if ($targetType === 'post') {
            return "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> کرم پلاس <tg-emoji emoji-id='5134654202894615343'>🪱</tg-emoji>\n\n" .
                "<tg-emoji emoji-id='5407025283456835913'>📱</tg-emoji> لینک یا شناسه پست اینستاگرام تارگت رو برام بفرست:\n\n" .
                "<tg-emoji emoji-id='5334882760735598374'>📝</tg-emoji> فرمت های قابل قبول:\n" .
                "• لینک پست: https://www.instagram.com/p/shortcode\n" .
                "• لینک ریل: https://www.instagram.com/reel/shortcode\n" .
                "• شناسه کوتاه: shortcode\n\n" .
                "<tg-emoji emoji-id='5123359615727174427'>💡</tg-emoji> مثلا:\n" .
                "• لینک: https://www.instagram.com/p/CxYz123AbCd/\n" .
                "• شناسه: CxYz123AbCd\n\n" .
                "<tg-emoji emoji-id='6226426402682441481'>⚠️</tg-emoji> دقت کن:\n" .
                "• لینک رو کامل و بدون فاصله بفرست\n" .
                "• اگر شناسه می‌فرستی فقط حروف انگلیسی، عدد، _ و - مجازه";
        }

        return "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> کرم پلاس <tg-emoji emoji-id='5134654202894615343'>🪱</tg-emoji>\n\n" .
            "<tg-emoji emoji-id='5407025283456835913'>📱</tg-emoji> یوزرنیم اکانت اینستاگرام تارگت رو برام بفرست:\n\n" .
            "<tg-emoji emoji-id='5334882760735598374'>📝</tg-emoji> فرمت های قابل قبول:\n" .
            "• بدون @: instagram\n" .
            "• با @: @instagram\n\n" .
            "<tg-emoji emoji-id='5123359615727174427'>💡</tg-emoji> مثلا:\n" .
            "• بدون @: netflix\n" .
            "• با @: @netflix\n\n" .
            "<tg-emoji emoji-id='6226426402682441481'>⚠️</tg-emoji> دقت کن:\n" .
            "• یوزرنیم رو بدون فاصله و کاراکتر اضافی بفرست\n" .
            "• فقط متن انگلیسی مجازه و حداقل 3 کاراکتر باید باشه";
    }

    private function extractInstagramShortcode(string $input): ?string
    {
        $normalized = trim($input);

        if (preg_match('/^[A-Za-z0-9_-]{5,20}$/', $normalized)) {
            return $normalized;
        }

        if (preg_match('#instagram\\.com/(p|reel|tv)/([A-Za-z0-9_-]{5,20})#i', $normalized, $matches)) {
            return $matches[2];
        }

        return null;
    }

    private function buildInstagramLink(string $shortcode): string
    {
        return "https://www.instagram.com/p/{$shortcode}/";
    }

    private function fetchInstagramMedia(string $shortcode): ?array
    {
        return app(BoxApiService::class)->getInstagramMediaInfo($shortcode);
    }

    private function buildMediaMessage(array $media, string $link): string
    {
        $owner = data_get($media, 'owner.username', data_get($media, 'user.username', '—'));
        $id = $media['id'] ?? data_get($media, 'pk', data_get($media, 'code', '—'));
        $caption = trim((string)($media['caption_text'] ?? $media['caption'] ?? '—'));
        $caption = $caption !== '' ? $caption : '—';
        $likes = $media['like_count'] ?? data_get($media, 'like_count', '—');
        $comments = $media['comment_count'] ?? data_get($media, 'comment_count', '—');
        $timestamp = $media['timestamp'] ?? $media['taken_at'] ?? null;

        if (is_numeric($timestamp)) {
            $sentAt = Carbon::createFromTimestamp((int)$timestamp)->format('Y/m/d');
        } else {
            $sentAt = $timestamp ? Carbon::parse($timestamp)->format('Y/m/d') : '—';
        }

        return "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> KermPlus | Post Found\n" .
            "━━━━━━━━━━━━━━━\n" .
            "👤 author: @{$owner}\n" .
            "🆔 post id: {$id}\n" .
            "📝 caption: {$caption}\n" .
            "🖇️ link : {$link}\n\n" .
            "❤️ likes: {$likes}\n" .
            "💬 comments: {$comments}\n" .
            "📅 published: {$sentAt}\n" .
            "━━━━━━━━━━━━━━━\n\n" .
            "🗣️ دلیل ریپورت رو انتخاب کن :\n";
    }

    private function normalizeInstagramMedia(array $raw, string $shortcode): array
    {
        $caption = $raw['caption'] ?? null;
        $captionText = is_array($caption) ? ($caption['text'] ?? null) : $caption;

        $owner = $raw['owner'] ?? $raw['user'] ?? [];
        $ownerUsername = $owner['username'] ?? ($owner['full_name'] ?? '—');

        $imageUrl = data_get($raw, 'image_versions2.candidates.0.url');
        $videoUrl = data_get($raw, 'video_versions.0.url');

        return array_merge($raw, [
            'id' => $raw['id'] ?? $raw['pk'] ?? $raw['code'] ?? $shortcode,
            'shortcode' => $raw['code'] ?? $shortcode,
            'owner' => [
                'username' => $ownerUsername,
                'id' => $owner['id'] ?? $owner['pk'] ?? null,
            ],
            'caption_text' => $captionText,
            'like_count' => $raw['like_count'] ?? data_get($raw, 'like_count'),
            'comment_count' => $raw['comment_count'] ?? data_get($raw, 'comments_count'),
            'view_count' => $raw['view_count'] ?? $raw['play_count'] ?? null,
            'timestamp' => $raw['timestamp'] ?? $raw['taken_at'] ?? null,
            'media_url' => $videoUrl ?? $imageUrl,
            'thumbnail_url' => $imageUrl ?? $videoUrl,
        ]);
    }
}

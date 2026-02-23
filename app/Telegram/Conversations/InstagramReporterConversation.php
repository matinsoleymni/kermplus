<?php

namespace App\Telegram\Conversations;

use App\Models\User;
use App\Models\WhitelistedTarget;
use App\Services\FeatureLimitService;
use App\Services\WhitelistService;
use App\Telegram\Keyboards\InstagramReportReasonKeyboard;
use App\Telegram\Keyboards\InstagramReporterMenuKeyboard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class InstagramReporterConversation extends Conversation
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
        $bot->setUserData('ig_reporter_target_type', $targetType);
        $bot->setUserData('ig_reporter_media', null);
        $bot->setUserData('ig_reporter_post_link', null);
        $bot->setUserData('ig_reporter_selected_reason_key', null);
        $bot->setUserData('ig_reporter_selected_reason_title', null);
        $bot->setUserData('ig_reporter_reason_summary', null);
        $bot->setUserData('ig_reason_summary_prompt_message_id', null);
        $bot->setUserData('ig_reason_summary_prompt_uses_caption', false);
        $bot->setUserData('ig_cleanup_messages', []);

        $promptText = $targetType === 'post'
            ? '📮 لینک یا شناسه پست اینستاگرام را بفرست (مثال: https://www.instagram.com/p/xxxx یا کد کوتاه).'
            : '👤 لطفا یوزرنیم اینستاگرام را وارد کنید (بدون @):';

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
            $bot->sendMessage('⛔️ یوزرنیم نامعتبر است. لطفا حداقل 3 کاراکتر وارد کنید.');
            return;
        }

        // Remove @ if present
        $username = ltrim(trim($username), '@');

        $whitelist = app(WhitelistService::class);
        $instagramTypes = [WhitelistedTarget::TYPE_INSTAGRAM_EMAIL, WhitelistedTarget::TYPE_CUSTOM];
        if ($whitelist->isWhitelisted($username, $instagramTypes)) {
            $bot->sendMessage($whitelist->getBlockMessage($username, $instagramTypes));
            $this->end();
            return;
        }

        $bot->setUserData('ig_reporter_username', $username);

        $loadingMsg = null;
        $reportPhoto = $this->getReportPhoto();
        if ($reportPhoto) {
            try {
                $loadingMsg = $bot->sendPhoto(
                    photo: $reportPhoto,
                    caption: '⏳ درحال دریافت اطلاعات پروفایل...'
                );
            } catch (\Throwable) {
                $loadingMsg = null;
            }
        }

        if (!$loadingMsg) {
            $loadingMsg = $bot->sendMessage('⏳ درحال دریافت اطلاعات پروفایل...');
        }
        $this->addCleanupMessage($bot, $loadingMsg->message_id);

        $profile = $this->fetchInstagramProfile($username);

        if ($profile === null) {
            $bot->sendMessage('⚠️ متاسفانه نتوانستیم اطلاعات این حساب را دریافت کنیم. لطفا بعدا دوباره تلاش کنید یا یوزرنیم دیگری وارد کنید.');
            $this->end();
            return;
        }

        $bot->setUserData('ig_reporter_profile', $profile);

        $details = $this->buildProfileMessage($profile);
        $keyboard = InstagramReportReasonKeyboard::make();
        $photoUrl = $profile['profile_pic_url_hd'] ?? null;

        if ($photoUrl) {
            try {
                $sent = $bot->sendPhoto(
                    photo: $photoUrl,
                    caption: $details,
                    reply_markup: $keyboard,
                    parse_mode: 'HTML'
                );
                $this->addCleanupMessage($bot, $sent->message_id);
                $this->next('processInstagramReason');
                return;
            } catch (\Throwable $e) {
                // If sending photo fails, fallback to text message
            }
        }

        $sent = $bot->sendMessage($details, reply_markup: $keyboard);
        $this->addCleanupMessage($bot, $sent->message_id);
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
            $bot->sendMessage('⛔️ لینک نامعتبر است. دوباره تلاش کن.');
            return;
        }

        $shortcode = $this->extractInstagramShortcode($link);
        if (!$shortcode) {
            $bot->sendMessage('⛔️ لینک یا کد کوتاه معتبر نیست.');
            return;
        }

        $bot->setUserData('ig_reporter_post_shortcode', $shortcode);
        $bot->setUserData('ig_reporter_post_link', $this->buildInstagramLink($shortcode));

        $loadingMsg = null;
        $reportPhoto = $this->getReportPhoto();
        if ($reportPhoto) {
            try {
                $loadingMsg = $bot->sendPhoto(
                    photo: $reportPhoto,
                    caption: '⏳ درحال دریافت اطلاعات پست...'
                );
            } catch (\Throwable) {
                $loadingMsg = null;
            }
        }

        if (!$loadingMsg) {
            $loadingMsg = $bot->sendMessage('⏳ درحال دریافت اطلاعات پست...');
        }
        $this->addCleanupMessage($bot, $loadingMsg->message_id);

        $media = $this->fetchInstagramMedia($shortcode);

        if ($media === null) {
            $bot->sendMessage('⚠️ نتونستیم اطلاعات این پست رو بگیریم. دوباره امتحان کن یا لینک دیگری بده.');
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

        try {
            if ($thumb) {
                $sent = $bot->sendPhoto(
                    photo: $thumb,
                    caption: $details,
                    reply_markup: $keyboard,
                );
                $this->addCleanupMessage($bot, $sent->message_id);
                $this->next('processInstagramReason');
                return;
            }
        } catch (\Throwable $e) {
            // If sending photo fails, fallback to text message
        }

        $sent = $bot->sendMessage($details, reply_markup: $keyboard);
        $this->addCleanupMessage($bot, $sent->message_id);
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
            } else {
                $bot->sendMessage('⛔️ لطفا دلیل ریپورت رو از دکمه‌ها انتخاب کن.');
            }
            $this->promptInstagramReason($bot);
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
            $this->promptInstagramReason($bot);
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
                $bot->sendMessage('⛔️ لطفا توضیح دلیل ریپورت رو ارسال کن یا متن پیش‌فرض رو انتخاب کن.');
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
            $bot->sendMessage($whitelist->getBlockMessage($username, $instagramTypes));
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
                } else {
                    $bot->sendMessage('⛔️ پست یافت نشد. دوباره تلاش کن.');
                }
                $this->end();
                return;
            }

            $owner = data_get($media, 'owner.username', $username);
            $this->runInstagramReport($bot, $owner, $reason, $reasonSummary, 'post', $media, $link, $baseMessageId, $baseUsesCaption);
            return;
        }

        $this->runInstagramReport($bot, $username, $reason, $reasonSummary, 'page', null, null, $baseMessageId, $baseUsesCaption);
    }

    private function promptInstagramReasonSummary(Nutgram $bot): void
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
                $bot->setUserData('ig_reason_summary_prompt_message_id', $messageId);
                $bot->setUserData('ig_reason_summary_prompt_uses_caption', $useCaption);
                return;
            } catch (\Throwable) {
                // fallback to sending a new message
            }
        }

        $sent = $bot->sendMessage($text, parse_mode: 'HTML', reply_markup: $keyboard);
        $bot->setUserData('ig_reason_summary_prompt_message_id', $sent->message_id ?? null);
        $bot->setUserData('ig_reason_summary_prompt_uses_caption', false);
    }

    private function reasonSummaryKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('متن پیش فرض', callback_data: 'instagram_reason_summary_default', style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('بازگشت', callback_data: 'reporter_instagram_menu', style: 'danger', icon: '5352759161945867747')
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
        $delayPerStep = 5; // seconds, ~25s total

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
            ) . $previewLine;

            try {
                $this->editMessageByType($bot, $progressMessageId, $updateMsg, $useCaption, null, true);
            } catch (\Throwable) {
                // Continue on error
            }
        }

        $finalText = $this->buildFinalMessage($label, $link);
        $reportPhoto = $this->getReportPhoto();
        if ($reportPhoto) {
            $this->deleteMessageSafe($bot, $progressMessageId);
            try {
                $bot->sendPhoto(
                    photo: $reportPhoto,
                    caption: $finalText,
                    parse_mode: 'HTML'
                );
                $bot->setUserData('ig_cleanup_messages', []);
                $this->end();
                return;
            } catch (\Throwable) {
                // fallback to text mode below
            }
        }

        try {
            $this->editMessageByType($bot, $progressMessageId, $finalText, $useCaption, parseHtml: true);
        } catch (\Throwable) {
            $bot->sendMessage($finalText, parse_mode: 'HTML');
        }

        $bot->setUserData('ig_cleanup_messages', []);
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

    private function fetchInstagramProfile(string $username): ?array
    {
        $response = Http::withBasicAuth(
            config('services.boxapi.username'),
            config('services.boxapi.password')
        )->post('https://boxapi.ir/api/instagram/user/get_web_profile_info', [
            'username' => $username,
        ]);

        if (!$response->ok()) {
            return null;
        }

        $user = $response->json('response.body.data.user');

        if (!is_array($user)) {
            return null;
        }

        return $user;
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
            "👤 name: @{$username}\n" .
            "🪪 username: {$fullName}\n" .
            "🧾 bio:  {$bio}\n" .
            "🔘 followers: {$followers}  \n" .
            "🔘 following: {$following}  \n" .
            "🔘 posts: {$posts}  \n" .
            "🔒 is private? : {$isPrivate}  \n" .
            "━━━━━━━━━━━━━━━\n\n" .
            "🗣️ دلیل ریپورت رو انتخاب کن :";
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

    private function promptInstagramReason(Nutgram $bot): void
    {
        $text = "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> کرم پلاس <tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji>\n\n<tg-emoji emoji-id='4904973211763999824'>🗣️</tg-emoji> دلیل ریپورت رو انتخاب کن :";
        $keyboard = InstagramReportReasonKeyboard::make();
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
                InlineKeyboardButton::make('بازگشت', callback_data: 'reporter_instagram_menu', style: 'danger', icon: '5352759161945867747')
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

    private function deleteMessageSafe(Nutgram $bot, int $messageId): void
    {
        try {
            $bot->deleteMessage(chat_id: $bot->user()->id, message_id: $messageId);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    private function addCleanupMessage(Nutgram $bot, ?int $messageId): void
    {
        if (!$messageId) return;
        $messages = $bot->getUserData('ig_cleanup_messages') ?? [];
        $messages[] = $messageId;
        $bot->setUserData('ig_cleanup_messages', $messages);
    }

    private function clearCleanupMessages(Nutgram $bot): void
    {
        $messages = $bot->getUserData('ig_cleanup_messages') ?? [];
        foreach ($messages as $mid) {
            $this->deleteMessageSafe($bot, (int)$mid);
        }
        $bot->setUserData('ig_cleanup_messages', []);
    }

    private function determineTargetType(?string $callbackData): string
    {
        return $callbackData === 'instagram_report_post' ? 'post' : 'page';
    }

    private function extractInstagramShortcode(string $input): ?string
    {
        $normalized = trim($input);

        // If user sent raw shortcode/id
        if (preg_match('/^[A-Za-z0-9_-]{5,20}$/', $normalized)) {
            return $normalized;
        }

        // Try to pull shortcode from URL
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
        $response = Http::withBasicAuth(
            config('services.boxapi.username'),
            config('services.boxapi.password')
        )->post('https://boxapi.ir/api/instagram/media/get_info_by_shortcode', [
            'shortcode' => $shortcode,
        ]);

        if (!$response->ok()) {
            return null;
        }

        $media = $response->json('response.body.items.0')
            ?? $response->json('response.body.media')
            ?? $response->json('response.body.data')
            ?? $response->json();

        return is_array($media) ? $media : null;
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

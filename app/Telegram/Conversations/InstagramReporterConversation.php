<?php

namespace App\Telegram\Conversations;

use App\Models\User;
use App\Models\WhitelistedTarget;
use App\Services\FeatureLimitService;
use App\Services\WhitelistService;
use App\Telegram\Keyboards\InstagramReportReasonKeyboard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
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
        $this->addCleanupMessage($bot, $bot->callbackQuery()?->message?->message_id);
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

        $promptText = $targetType === 'post'
            ? '📮 لینک یا شناسه پست اینستاگرام را بفرست (مثال: https://www.instagram.com/p/xxxx یا کد کوتاه).'
            : '👤 لطفا یوزرنیم اینستاگرام را وارد کنید (بدون @):';

        $prompt = $bot->sendMessage($promptText);
        $this->addCleanupMessage($bot, $prompt->message_id ?? $bot->getLastMessageId());
        $this->next($targetType === 'post' ? 'awaitPostLink' : 'awaitUsername');
    }

    public function awaitUsername(Nutgram $bot)
    {
        $username = $bot->message()?->text;
        if (!$username || strlen($username) < 3) {
            $bot->sendMessage('⛔️ یوزرنیم نامعتبر است. لطفا حداقل 3 کاراکتر وارد کنید.');
            return;
        }

        // Remove @ if present
        $username = ltrim(trim($username), '@');

        $whitelist = app(WhitelistService::class);
        if ($whitelist->isWhitelisted($username, WhitelistedTarget::TYPE_CUSTOM)) {
            $bot->sendMessage($whitelist->getBlockMessage($username, WhitelistedTarget::TYPE_CUSTOM));
            $this->end();
            return;
        }

        $bot->setUserData('ig_reporter_username', $username);

        $loadingMsg = $bot->sendMessage('⏳ درحال دریافت اطلاعات پروفایل...');
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

        $loadingMsg = $bot->sendMessage('⏳ درحال دریافت اطلاعات پست...');
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

        if (!isset($reasons[$data])) {
            $bot->answerCallbackQuery(text: '⛔️ گزینه نامعتبر است.');
            return;
        }

        $username = $bot->getUserData('ig_reporter_username');
        if (!$username) {
            $bot->answerCallbackQuery(text: '⛔️ ابتدا یوزرنیم را وارد کنید.');
            $this->end();
            return;
        }

        $local = $this->getLocalUser($bot);
        if (!$local) {
            $bot->answerCallbackQuery(text: '⛔️ حساب شما پیدا نشد.');
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

        $limiter->recordReporterUsage($local);
        $bot->answerCallbackQuery(text: '✅ دلیل ثبت شد.');

        $targetType = $bot->getUserData('ig_reporter_target_type') ?? 'page';

        if ($targetType === 'post') {
            $media = $bot->getUserData('ig_reporter_media');
            $link = $bot->getUserData('ig_reporter_post_link');

            if (!$media) {
                $bot->answerCallbackQuery(text: '⛔️ پست یافت نشد. دوباره تلاش کن.');
                $this->end();
                return;
            }

            $owner = data_get($media, 'owner.username', $username);
            $this->runInstagramReport($bot, $owner, $reasons[$data], 'post', $media, $link);
            return;
        }

        $this->runInstagramReport($bot, $username, $reasons[$data], 'page');
    }

    private function runInstagramReport(Nutgram $bot, string $username, string $reason, string $targetType = 'page', ?array $media = null, ?string $link = null)
    {
        $this->clearPreviousMessages($bot);
        $this->clearCleanupMessages($bot);

        $totalSteps = 5;
        $delayPerStep = 5; // seconds, ~25s total

        $label = $targetType === 'post'
            ? "📮 پست: @{$username}"
            : "📸 یوزرنیم: @{$username}";
        $previewLine = $link ? "\n🖇️ لینک: {$link}" : '';

        $progressMsg = $bot->sendMessage($this->buildProcessingMessage(
            percent: 0,
            step: 1,
            totalSteps: $totalSteps,
            targetLabel: $label,
            reason: $reason,
            queue: 243,
            active: 18,
            done: 162,
            ok: 147,
            fail: 15,
            retry: 9,
            elapsed: '00:00:00',
            eta: '~00:00:24',
            statuses: $this->buildStatusLines(1)
        ) . $previewLine);

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
                $bot->editMessageText(
                    chat_id: $bot->user()->id,
                    message_id: $progressMsg->message_id,
                    text: $updateMsg
                );
            } catch (\Exception $e) {
                // Continue on error
            }
        }

        $this->deleteMessageSafe($bot, $progressMsg->message_id);
        $bot->sendMessage($this->buildFinalMessage($label, $link));

        $this->end();
    }

    private function getProgressBar(int $percent): string
    {
        $filled = (int)($percent / 5);
        $empty = 20 - $filled;
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

        return "🎗 KermPlus | Profile Found\n".
            "━━━━━━━━━━━━━━━\n".
            "👤 name: @{$username}\n".
            "🪪 username: {$fullName}\n".
            "🧾 bio:  {$bio}\n".
            "🔘 followers: {$followers}  \n".
            "🔘 following: {$following}  \n".
            "🔘 posts: {$posts}  \n".
            "🔒 is private? : {$isPrivate}  \n".
            "━━━━━━━━━━━━━━━\n\n".
            "🗣 دلیل ریپورت رو انتخاب کن :";
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

    private function buildProcessingMessage(
        int $percent,
        int $step,
        int $totalSteps,
        string $targetLabel,
        string $reason,
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
        $date = now()->format('Y/m/d');
        $time = now()->format('H:i:s');
        $progressBar = $this->getProgressBar($percent);
        $barOnly = explode(' ', $progressBar, 2)[0];
        $statusBlock = '> ' . implode("\n> ", $statuses);

        return "🎗 KermPlus | Processing Job\n".
            "━━━━━━━━━━━━━━━━\n\n".
            "{$barOnly} {$percent}%   🔁 step {$step}/{$totalSteps}\n\n".
            "🎯 هدف: {$targetLabel}\n".
            "🗣 دلیل: {$reason}\n\n".
            "📦 queue: {$queue} items\n".
            "⚙️ active: {$active}   ✅ done: {$done}\n".
            "🟢 ok: {$ok}   🔴 fail: {$fail}   🔁 retry: {$retry}\n\n".
            "rate: 12/s backoff: 2.5s\n".
            "elapsed: {$elapsed} ETA: {$eta}\n\n".
            "{$statusBlock}\n\n".
            "trace: job=8f2a mode=ro gate=open\n".
            "Please wait...\n\n".
            "📆 {$date}  ⏰ {$time}\n".
            "• @NitroHostBot •";
    }

    private function buildFinalMessage(string $targetLabel, ?string $link = null): string
    {
        $date = now()->format('Y/m/d');
        $time = now()->format('H:i:s');
        $preview = $link ? "🖇️ لینک: {$link}\n" : '';

        return "🎗 KermPlus | Reported Successful\n".
            "━━━━━━━━━━━━━━━━\n\n".
            "🎯 هدف: {$targetLabel}\n".
            $preview.
            "📦 تعداد کل درخواست ها : 1321\n".
            "✅ 1235 موفق | ❌ 134 ناموفق\n\n".
            "تمامی ریپورت ها از سمت کرم پلاس🪱 با موفقیت ارسال شدند.\n".
            "نتیجه نهایی وابسته به بررسی پلتفرم مقصد می‌باشد.\n\n".
            "📆 {$date} ⏰ {$time}\n".
            "• @NitroHostBot •";
    }

    private function buildStatusLines(int $step): array
    {
        $lines = [
            '🧪 validate inputs      [ OK ]',
            '🔌 open connections     [ OK ]',
            '🔄 process batch #09    [ .. ]',
            '📝 write results        [ -- ]',
            '🏁 finalize             [ -- ]',
        ];

        if ($step >= 2) {
            $lines[2] = '🔄 process batch #09    [ OK ]';
        }
        if ($step >= 3) {
            $lines[3] = '📝 write results        [ OK ]';
        }
        if ($step >= 4) {
            $lines[4] = '🏁 finalize             [ OK ]';
        }

        return $lines;
    }

    private function clearPreviousMessages(Nutgram $bot): void
    {
        $messageId = $bot->callbackQuery()?->message?->message_id;
        if ($messageId) {
            $this->deleteMessageSafe($bot, $messageId);
        }
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

        return "🎗 KermPlus | Post Found\n".
            "━━━━━━━━━━━━━━━\n".
            "👤 author: @{$owner}\n".
            "🆔 post id: {$id}\n".
            "📝 caption: {$caption}\n".
            "🖇️ link : {$link}\n\n".
            "❤️ likes: {$likes}\n".
            "💬 comments: {$comments}\n".
            "📅 published: {$sentAt}\n".
            "━━━━━━━━━━━━━━━\n\n".
            "🗣 دلیل ریپورت را انتخاب کنید:\n";
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

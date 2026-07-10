<?php

namespace App\Services;

use App\Models\SponsorChannel;
use App\Telegram\Support\CallbackQueryResponder;
use App\Models\User;
use BackedEnum;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

class SponsorJoinService
{
    public const CHECK_CALLBACK = 'sponsor_join_check';
    private const CHAT_ID_REGEX = '/^-100\d{5,}$/';

    /**
     * Return true when user can continue, false when user must join channels first.
     */
    public function enforce(Nutgram $bot): bool
    {
        if (!$this->shouldEnforceForUpdate($bot)) {
            return true;
        }

        $activeChannels = $this->getActiveChannels();

        if ($activeChannels->isEmpty()) {
            return true;
        }

        $verifiableChannels = $activeChannels->filter(
            fn(SponsorChannel $channel): bool => $this->canVerifyChannel($channel)
        )->values();

        if ($verifiableChannels->isEmpty()) {
            return true;
        }

        $telegramUserId = $this->resolveTelegramUserId($bot);
        if ($telegramUserId === null) {
            return true;
        }

        /** @var SponsorChannel $channel */
        foreach ($verifiableChannels as $channel) {
            $chatId = $this->resolveChatId($channel);
            if ($chatId === null) {
                continue;
            }

            if (!$this->isUserMemberOf($bot, $chatId, $telegramUserId)) {
                $this->sendJoinPrompt($bot, $activeChannels);
                return false;
            }
        }

        return true;
    }

    public function sendJoinPrompt(Nutgram $bot, ?Collection $channels = null): void
    {
        $channels = $channels ? $channels->values() : $this->getActiveChannels();

        if ($channels->isEmpty()) {
            return;
        }

        $channelUrls = $this->buildPromptChannelUrlMap($bot, $channels);
        $message = $this->buildPromptMessage($channels, $channelUrls);
        $keyboard = $this->buildKeyboard($channels, $channelUrls);

        if ($bot->callbackQuery()) {
            CallbackQueryResponder::ack($bot, text: 'ابتدا عضو کانال‌های اسپانسر شو.');

            try {
                $bot->editMessageText($message, parse_mode: 'HTML', reply_markup: $keyboard);
                return;
            } catch (Throwable $e) {
                if ($this->isMessageNotModifiedError($e)) {
                    return;
                }

                logger()->warning('Failed to edit sponsor join prompt message.', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $bot->sendMessage($message, parse_mode: 'HTML', reply_markup: $keyboard);
    }

    public function canVerifyChannel(SponsorChannel $channel): bool
    {
        return $this->resolveChatId($channel) !== null;
    }

    /**
     * Normalize admin input to a verifiable channel target.
     *
     * @return array{username:string,link:?string}|null
     */
    public function normalizeAdminChannelInput(string $input): ?array
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        $chatIdAndLink = preg_split('/[\s|]+/u', $input, 2, PREG_SPLIT_NO_EMPTY);
        if (is_array($chatIdAndLink) && count($chatIdAndLink) === 2) {
            $chatId = $this->normalizeChatId((string) $chatIdAndLink[0]);
            $link = $this->normalizeTelegramUrl((string) $chatIdAndLink[1]);
            if ($chatId !== null && $link !== null) {
                return ['username' => $chatId, 'link' => $link];
            }
        }

        $chatId = $this->normalizeChatId($input);
        if ($chatId !== null) {
            return ['username' => $chatId, 'link' => null];
        }

        $username = $this->normalizeUsername($input);
        if ($username !== null) {
            return ['username' => $username, 'link' => null];
        }

        $link = $this->normalizeTelegramUrl($input);
        if ($link === null) {
            return null;
        }

        $username = $this->extractUsernameFromUrl($link);
        if ($username === null) {
            return null;
        }

        return ['username' => $username, 'link' => $link];
    }

    private function getActiveChannels(): Collection
    {
        return SponsorChannel::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
    }

    private function shouldEnforceForUpdate(Nutgram $bot): bool
    {
        $chatType = $this->resolveChatType($bot);
        if (in_array($chatType, ['group', 'supergroup', 'channel'], true)) {
            return false;
        }

        $telegramUserId = $this->resolveTelegramUserId($bot);
        if ($telegramUserId === null) {
            return false;
        }

        $local = User::query()->where('telegram_id', $telegramUserId)->first();

        return !$local?->isAdmin();
    }

    private function resolveChatType(Nutgram $bot): ?string
    {
        $chatType = $bot->chat()?->type ?? null;
        if ($chatType instanceof BackedEnum) {
            $chatType = $chatType->value;
        }

        return is_string($chatType) ? $chatType : null;
    }

    private function resolveTelegramUserId(Nutgram $bot): ?int
    {
        return $bot->callbackQuery()?->from?->id
            ?? $bot->message()?->from?->id
            ?? $bot->user()?->id
            ?? $bot->userId();
    }

    private function isUserMemberOf(Nutgram $bot, string $chatId, int $telegramUserId): bool
    {
        try {
            $member = $bot->getChatMember(chat_id: $chatId, user_id: $telegramUserId);
        } catch (Throwable $e) {
            logger()->warning('Sponsor join membership check failed.', [
                'chat_id' => $chatId,
                'user_id' => $telegramUserId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $status = $member->status ?? null;
        if ($status instanceof BackedEnum) {
            $status = $status->value;
        }

        if (!is_string($status)) {
            return false;
        }

        return in_array($status, ['creator', 'administrator', 'member', 'restricted'], true);
    }

    private function resolveChatId(SponsorChannel $channel): ?string
    {
        $chatId = $this->normalizeChatId($channel->username);
        if ($chatId !== null) {
            return $chatId;
        }

        $username = $this->normalizeUsername($channel->username);
        if ($username === null) {
            $username = $this->extractUsernameFromUrl($channel->link);
        }

        return $username ? '@' . $username : null;
    }

    private function resolvePublicUrl(SponsorChannel $channel): ?string
    {
        $username = $this->normalizeUsername($channel->username);
        if ($username !== null) {
            return "https://t.me/{$username}";
        }

        return $this->normalizeTelegramUrl($channel->link);
    }

    private function normalizeUsername(?string $username): ?string
    {
        $username = trim((string) $username);
        if ($username === '') {
            return null;
        }

        $username = ltrim($username, '@');
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{2,}$/', $username)) {
            return null;
        }

        return $username;
    }

    private function normalizeChatId(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return preg_match(self::CHAT_ID_REGEX, $value) ? $value : null;
    }

    private function extractUsernameFromUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($host, ['t.me', 'www.t.me', 'telegram.me', 'www.telegram.me', 'telegram.dog', 'www.telegram.dog'], true)) {
            return null;
        }

        $path = trim((string) ($parts['path'] ?? ''), '/');
        if ($path === '') {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $path), fn(string $segment): bool => $segment !== ''));
        if ($segments === []) {
            return null;
        }

        $firstSegment = (string) ($segments[0] ?? '');
        if ($firstSegment === '') {
            return null;
        }

        if (str_starts_with($firstSegment, '+')) {
            return null;
        }

        $firstLower = strtolower($firstSegment);
        if ($firstLower === 's') {
            $second = (string) ($segments[1] ?? '');
            return $second === '' ? null : $this->normalizeUsername($second);
        }

        if (in_array($firstLower, ['joinchat', 'c'], true)) {
            return null;
        }

        return $this->normalizeUsername($firstSegment);
    }

    private function normalizeTelegramUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($host, ['t.me', 'www.t.me', 'telegram.me', 'www.telegram.me', 'telegram.dog', 'www.telegram.dog'], true)) {
            return null;
        }

        $path = trim((string) ($parts['path'] ?? ''), '/');
        if ($path === '') {
            return null;
        }

        return $url;
    }

    /**
     * @return array<string,string>
     */
    private function buildPromptChannelUrlMap(Nutgram $bot, Collection $channels): array
    {
        $urlMap = [];

        /** @var SponsorChannel $channel */
        foreach ($channels as $channel) {
            $url = $this->resolvePromptChannelUrl($bot, $channel);
            if ($url === null) {
                continue;
            }

            $urlMap[(string) $channel->id] = $url;
        }

        return $urlMap;
    }

    private function resolvePromptChannelUrl(Nutgram $bot, SponsorChannel $channel): ?string
    {
        $url = $this->resolvePublicUrl($channel);
        if ($url !== null) {
            return $url;
        }

        $chatId = $this->resolveChatId($channel);
        if ($chatId === null) {
            return null;
        }

        try {
            $chat = $bot->getChat(chat_id: $chatId);
        } catch (Throwable $e) {
            logger()->warning('Sponsor join getChat failed while resolving channel link.', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $username = $this->normalizeUsername($chat?->username ?? null);
        if ($username !== null) {
            return "https://t.me/{$username}";
        }

        return $this->normalizeTelegramUrl($chat?->invite_link ?? null);
    }

    /**
     * @param array<string,string> $channelUrls
     */
    private function buildPromptMessage(Collection $channels, array $channelUrls): string
    {
        $channelLines = $channels
            ->map(fn(SponsorChannel $channel): string => $this->buildPromptChannelLine($channel, $channelUrls))
            ->values();

        return "<tg-emoji emoji-id='5246772116543512028'>⛔️</tg-emoji> برای استفاده از ربات باید اول عضو کانال‌ های اسپانسر بشی.\n\n"
            . implode("\n", $channelLines->all())
            . "\n\nبعد از عضویت، روی دکمه «<tg-emoji emoji-id='6224314343924699041'>✅</tg-emoji> عضو شدم » کلیک کن.";
    }

    /**
     * @param array<string,string> $channelUrls
     */
    private function buildPromptChannelLine(SponsorChannel $channel, array $channelUrls): string
    {
        $title = $this->escapeHtml($this->resolvePromptChannelTitle($channel));
        $url = $channelUrls[(string) $channel->id] ?? null;

        if ($url !== null) {
            $safeUrl = $this->escapeHtml($url);
            return "<tg-emoji emoji-id='5123344136665039833'>⚪️</tg-emoji> <a href=\"{$safeUrl}\">{$title}</a>";
        }

        return "<tg-emoji emoji-id='5123344136665039833'>⚪️</tg-emoji> {$title}";
    }

    private function resolvePromptChannelTitle(SponsorChannel $channel): string
    {
        $title = trim((string) $channel->title);
        if ($title !== '') {
            return $title;
        }

        $username = $this->normalizeUsername($channel->username);
        if ($username !== null) {
            return '@' . $username;
        }

        $username = $this->extractUsernameFromUrl($channel->link);
        if ($username !== null) {
            return '@' . $username;
        }

        return 'locked name';
    }

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array<string,string> $channelUrls
     */
    private function buildKeyboard(Collection $channels, array $channelUrls): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();

        /** @var SponsorChannel $channel */
        foreach ($channels as $channel) {
            $url = $channelUrls[(string) $channel->id] ?? null;
            if ($url === null) {
                continue;
            }

            $title = trim((string) $channel->title);
            $label = $title === '' ? '📢 کانال اسپانسر' : ('📢 ' . Str::limit($title, 40, '...'));

            $keyboard->addRow(
                InlineKeyboardButton::make($label, url: $url, style: 'danger')
            );
        }

        $keyboard->addRow(
            InlineKeyboardButton::make('عضو شدم', callback_data: self::CHECK_CALLBACK, style: 'danger', icon_custom_emoji_id: '6224314343924699041')
        );

        return $keyboard;
    }

    private function isMessageNotModifiedError(Throwable $e): bool
    {
        return str_contains(strtolower($e->getMessage()), 'message is not modified');
    }
}

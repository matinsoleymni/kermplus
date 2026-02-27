<?php

namespace App\Telegram\Conversations;

use App\Models\User;
use App\Models\WhitelistedTarget;
use App\Services\ChannelReactionService;
use App\Services\WhitelistService;
use App\Telegram\Commands\StartCommand;
use App\Telegram\Handlers\MainMenuHandler;
use App\Telegram\Keyboards\BackToMainKeyboard;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class ChannelReactionConversation extends Conversation
{
    private const REACTION_CALLBACK_PREFIX = 'reaction_emoji_';
    private const MIX_NEGATIVE_CALLBACK = 'reaction_mix_negative';
    private const NEGATIVE_REACTIONS = ['🖕', '💔', '👎', '😢', '💩', '🤮', '🤬', '😡', '🥱', '🍌', '😈'];

    protected ?string $postLink = null;

    protected function getLocalUser(Nutgram $bot): ?User
    {
        $tgUser = $bot->callbackQuery()?->from ?? $bot->user();
        if (!$tgUser) {
            return null;
        }

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

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('ری اکشنر منفی چیه؟', url: 'https://t.me/kermpluslearn/8', style: 'danger', icon: '5305388752162539722'))
            ->addRow(InlineKeyboardButton::make('بازگشت', callback_data: 'main_menu', style: 'danger', icon: '5352759161945867747'));

        $this->sendOrEditMessage(
            $bot,
            "<tg-emoji emoji-id=\"4916086774649848789\">🔗</tg-emoji> لینک پستی که میخوای رگباری اکشن منفی بخوره رو برام بفرست:

 <tg-emoji emoji-id=\"5123344136665039833\">⚪️</tg-emoji> مثلا:
https://t.me/channel/123

<tg-emoji emoji-id=\"6226426402682441481\">⚠️</tg-emoji> حتما باید لینکی که میفرستی از یک کانال پابلیک باشه تا ری اکشن ها به پست مد نظرت بخورن.",
            $keyboard
        );

        $this->next('askEmoji');
    }

    public function askEmoji(Nutgram $bot)
    {
        if ($bot->callbackQuery()?->data === 'main_menu') {
            $this->end();
            app(MainMenuHandler::class)($bot);
            return;
        }

        $link = trim((string)($bot->message()?->text));
        if ($this->interceptStart($bot, $link)) {
            return;
        }

        if (!$this->isValidPostLink($link)) {
            $bot->sendMessage('❌ لینک نامعتبر است. نمونه: https://t.me/channel/123');
            return;
        }

        $whitelist = app(WhitelistService::class);
        if ($whitelist->isWhitelisted($link, WhitelistedTarget::TYPE_TELEGRAM)) {
            $bot->sendMessage($whitelist->getBlockMessage($link, WhitelistedTarget::TYPE_TELEGRAM, 'چنل'));
            $this->end();
            return;
        }

        $this->postLink = $link;

        $keyboard = $this->reactionSelectionKeyboard();

        $bot->sendMessage(
            "<tg-emoji emoji-id=\"5082478549340783285\">👻</tg-emoji> یکی از ری اکشنای زیر رو انتخاب کن تا رگبارش کنم زیر پستی که برام فرستادی:\n\n<tg-emoji emoji-id=\"6226426402682441481\">⚠️</tg-emoji> میتونی با زدن دکمه «میکس ری اکشن منفی» ترکیبی از همه ری اکشنای زیر رو بزنی زیر پست مد نظرت.",
            parse_mode: 'HTML',
            reply_markup: $keyboard
        );

        $this->next('sendReaction');
    }

    public function sendReaction(Nutgram $bot)
    {
        if (!$this->postLink) {
            $bot->sendMessage('⛔️ لینک پست پیدا نشد. دوباره تلاش کنید.', reply_markup: BackToMainKeyboard::make());
            $this->end();
            return;
        }

        $emoji = null;
        $mixNegative = false;
        $data = $bot->callbackQuery()?->data;

        if ($data === 'main_menu') {
            $this->end();
            app(MainMenuHandler::class)($bot);
            return;
        }

        if ($data === self::MIX_NEGATIVE_CALLBACK) {
            $mixNegative = true;
            $bot->answerCallbackQuery(text: 'میکس ری اکشن منفی انتخاب شد.');
        } elseif ($this->isReactionCallback($data)) {
            $picked = $this->extractReactionFromCallback($data);
            if ($picked === null) {
                $bot->answerCallbackQuery(text: 'ری‌اکشن نامعتبر است.');
                $bot->sendMessage('❌ لطفا یکی از دکمه‌های ری‌اکشن را انتخاب کن.', parse_mode: 'HTML', reply_markup: $this->reactionSelectionKeyboard());
                return;
            }

            $emoji = $picked;
            $bot->answerCallbackQuery(text: "ری‌اکشن {$emoji} انتخاب شد.");
        } else {
            $emojiText = trim((string)($bot->message()?->text));
            if ($this->interceptStart($bot, $emojiText)) {
                return;
            }

            if ($emojiText === '' || !in_array($emojiText, self::NEGATIVE_REACTIONS, true)) {
                $bot->sendMessage('❌ لطفا یکی از دکمه‌های ری‌اکشن را انتخاب کن.', parse_mode: 'HTML', reply_markup: $this->reactionSelectionKeyboard());
                return;
            }
            $emoji = $emojiText;
        }

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

        $service = app(ChannelReactionService::class);
        $result = $service->sendReaction($local, $this->postLink, $emoji ?: null, $mixNegative);

        if (isset($result['error'])) {
            $msg = "⚠️ {$result['error']}";
            if (isset($result['status'])) {
                $msg .= "\nکد: {$result['status']}";
            }
            if (!empty($result['details'])) {
                $msg .= "\nجزییات اتصال: {$result['details']}";
            }
            if (!empty($result['body'])) {
                $body = is_array($result['body']) ? json_encode($result['body']) : (string)$result['body'];
                $msg .= "\nجزییات: {$body}";
            }
            $bot->sendMessage($msg, reply_markup: BackToMainKeyboard::make());
            $this->end();
            return;
        }

        $sent = (int)($result['sent'] ?? 0);
        $usedReaction = $result['used_reaction'] ?? ($emoji ?: ($mixNegative ? 'میکس ری اکشن منفی' : '—'));
        $available = $result['available_reactions'] ?? [];
        $errors = $result['errors'] ?? [];

        $message = "✅ درخواست ری‌اکشن ثبت شد.\n";
        $message .= "🔗 لینک: {$this->postLink}\n";
        $message .= "😈 ری‌اکشن استفاده‌شده: {$usedReaction}\n";
        $message .= "📤 تعداد ارسال‌شده: {$sent}\n";

        if (!empty($available)) {
            $message .= "👌 ری‌اکشن‌های مجاز: " . implode(' ', $available) . "\n";
        }

        if (!empty($errors)) {
            $message .= "⚠️ خطاها:\n- " . implode("\n- ", $errors);
        }

        $bot->sendMessage($message, reply_markup: BackToMainKeyboard::make());
        $this->end();
    }

    protected function isValidPostLink(?string $link): bool
    {
        if (!$link) {
            return false;
        }

        return (bool)preg_match('#^(https?://)?(t\.me|telegram\.me)/[^/]+/\\d+#', $link);
    }

    protected function interceptStart(Nutgram $bot, ?string $text): bool
    {
        $text = trim((string) $text);
        if (!str_starts_with($text, '/start')) {
            return false;
        }

        $this->end();
        $bot->setUserData('conversation', null);
        $bot->setUserData('step', null);
        app(StartCommand::class)->handle($bot);

        return true;
    }

    private function isReactionCallback(?string $data): bool
    {
        return is_string($data) && str_starts_with($data, self::REACTION_CALLBACK_PREFIX);
    }

    private function extractReactionFromCallback(string $data): ?string
    {
        $index = (int)substr($data, strlen(self::REACTION_CALLBACK_PREFIX));
        return self::NEGATIVE_REACTIONS[$index] ?? null;
    }

    private function reactionSelectionKeyboard(): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();

        $row = [];
        foreach (self::NEGATIVE_REACTIONS as $index => $reaction) {
            $row[] = InlineKeyboardButton::make($reaction, callback_data: self::REACTION_CALLBACK_PREFIX . $index, style: 'danger');
            if (count($row) === 3) {
                $keyboard->addRow(...$row);
                $row = [];
            }
        }
        if (!empty($row)) {
            $keyboard->addRow(...$row);
        }

        $keyboard->addRow(
            InlineKeyboardButton::make('میکس ری اکشن منفی', callback_data: self::MIX_NEGATIVE_CALLBACK, style: 'danger')
        );

        $keyboard->addRow(
            InlineKeyboardButton::make('بازگشت', callback_data: 'main_menu', style: 'danger', icon: '5352759161945867747')
        );

        return $keyboard;
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
}

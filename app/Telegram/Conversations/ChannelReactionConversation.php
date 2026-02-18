<?php

namespace App\Telegram\Conversations;

use App\Models\User;
use App\Services\ChannelReactionService;
use App\Telegram\Commands\StartCommand;
use App\Telegram\Keyboards\BackToMainKeyboard;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class ChannelReactionConversation extends Conversation
{
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
            ->addRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'main_menu'));

        $bot->sendMessage(
            "🔗 لینک پست کانال تلگرام را بفرست (مثلا https://t.me/channel/123):",
            reply_markup: $keyboard
        );

        $this->next('askEmoji');
    }

    public function askEmoji(Nutgram $bot)
    {
        $link = trim((string)($bot->message()?->text));
        if ($this->interceptStart($bot, $link)) {
            return;
        }

        if (!$this->isValidPostLink($link)) {
            $bot->sendMessage('❌ لینک نامعتبر است. نمونه: https://t.me/channel/123');
            return;
        }

        $this->postLink = $link;

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('🎲 انتخاب خودکار', callback_data: 'reaction_skip_emoji'),
                InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'main_menu')
            );

        $bot->sendMessage(
            "😈 ایموجی ری‌اکشن را بفرست (مثلا 😈). اگر نمی‌خواهی انتخاب کنی، «انتخاب خودکار» را بزن.",
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
        $data = $bot->callbackQuery()?->data;

        if ($data === 'reaction_skip_emoji') {
            $bot->answerCallbackQuery(text: 'با اولین ری‌اکشن مجاز ادامه دادیم.');
        } else {
            $emojiText = trim((string)($bot->message()?->text));
            if ($this->interceptStart($bot, $emojiText)) {
                return;
            }

            if ($emojiText === '') {
                $bot->sendMessage('❌ ایموجی نامعتبر است. دوباره ارسال کنید یا «انتخاب خودکار» را بزنید.');
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
        $result = $service->sendReaction($local, $this->postLink, $emoji ?: null);

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
        $usedReaction = $result['used_reaction'] ?? ($emoji ?: '—');
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
}

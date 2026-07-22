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
use App\Services\FeatureLimitService;
use App\Telegram\Keyboards\PlusRequiredKeyboard;

class ChannelReactionConversation extends Conversation
{
    private const REACTION_CALLBACK_PREFIX = 'reaction_emoji_';
    private const MIX_NEGATIVE_CALLBACK = 'reaction_mix_negative';
    private const MIX_POSITIVE_CALLBACK = 'reaction_mix_positive';
    private const NEGATIVE_REACTIONS = ['🖕', '💔', '👎', '😢', '💩', '🤮', '🤬', '😡', '🥱', '🍌', '😈'];
    private const POSITIVE_REACTIONS = ['❤', '👍', '🔥', '🥰', '👏', '😁', '🎉', '🤩', '🙏', '👌', '😍'];

    private function getAllReactions(): array
    {
        return array_merge(self::NEGATIVE_REACTIONS, self::POSITIVE_REACTIONS);
    }

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

        $limiter = app(FeatureLimitService::class);
        $limit = $limiter->checkNegativeReactionLimit($local);
        if($limit){
            $k = PlusRequiredKeyboard::make(true);
            $bot->sendMessage($limit, reply_markup: $k, parse_mode: "HTML");
            $this->end();
            return;
        }

        $local->last_active_at = now();
        $local->save();

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('ری اکشنر منفی چیه؟', url: 'https://t.me/kermpluslearn/8', style: 'danger', icon_custom_emoji_id: '5305388752162539722'))
            ->addRow(InlineKeyboardButton::make('بازگشت', callback_data: 'main_menu', style: 'danger', icon_custom_emoji_id: '5352759161945867747'));

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
            $bot->sendMessage($whitelist->getBlockMessage($link, WhitelistedTarget::TYPE_TELEGRAM, 'چنل'), parse_mode: 'HTML');
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
        $mixPositive = false;
        $data = $bot->callbackQuery()?->data;

        if ($data === 'main_menu') {
            $this->end();
            app(MainMenuHandler::class)($bot);
            return;
        }

        if ($data === self::MIX_NEGATIVE_CALLBACK) {
            $mixNegative = true;
            $bot->answerCallbackQuery(text: 'میکس ری اکشن منفی انتخاب شد.');
        } elseif ($data === self::MIX_POSITIVE_CALLBACK) {
            $mixPositive = true;
            $bot->answerCallbackQuery(text: 'میکس ری اکشن مثبت انتخاب شد.');
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

            if ($emojiText === '' || !in_array($emojiText, $this->getAllReactions(), true)) {
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

        $result = $service->sendReaction($local, $this->postLink, $emoji ?: null, $mixNegative, $mixPositive);

        $usedReaction = $emoji ?: ($mixNegative ? 'میکس ری اکشن منفی' : ($mixPositive ? 'میکس ری اکشن مثبت' : '—'));

        $targetTime = random_int(20, 22);
        $totalSteps = random_int(1, 6);
        $chatId = $bot->chatId();

        $initialEta = gmdate('i:s', $targetTime);
        $loadingMsg = $bot->sendMessage("<tg-emoji emoji-id='5451732530048802485'>⏳</tg-emoji> <b>درحال زدن ری اکشنای منفی...</b>\n\n[░░░░░░░░░░] 0%\n\n", parse_mode: 'HTML');

        if ($loadingMsg && isset($loadingMsg->message_id)) {
            $start = microtime(true);
            $percent = 0;

            // ۲. حلقه اصلی پیشرفت (Progress Loop)
            for ($step = 1; $step <= $totalSteps; $step++) {
                $elapsedSeconds = (int)(microtime(true) - $start);
                $timeRemaining = max(1, $targetTime - $elapsedSeconds);
                $stepsRemaining = max(1, $totalSteps - $step + 1);

                if ($step === $totalSteps) {
                    $sleepTime = min(4, $timeRemaining);
                    $percent = 100;
                } else {
                    $idealSleep = (int)round($timeRemaining / $stepsRemaining);
                    $sleepTime = random_int(max(2, $idealSleep - 1), $idealSleep + 1);
                    $sleepTime = min($sleepTime, $timeRemaining);

                    $percentRemaining = 100 - $percent;
                    $idealJump = (int)round($percentRemaining / $stepsRemaining);
                    $jump = random_int(max(1, $idealJump - 4), $idealJump + 6);

                    $percent = min(99, $percent + $jump);
                }

                sleep(max(1, $sleepTime));

                // ساخت نوار پیشرفت بصری
                $filledCount = max(0, min(10, (int)round($percent / 10)));
                $emptyCount = 10 - $filledCount;
                $bar = str_repeat('█', $filledCount) . str_repeat('░', $emptyCount);

                $currentElapsed = (int)(microtime(true) - $start);
                $etaSeconds = max(0, $targetTime - $currentElapsed);
                $eta = gmdate('i:s', $etaSeconds);

                $text = "<tg-emoji emoji-id='5451732530048802485'>⏳</tg-emoji> <b>درحال زدن ری اکشنای منفی …...</b>\n\n";
                $text .= "[{$bar}] {$percent}%\n\n";

                try {
                    $bot->editMessageText(
                        text: $text,
                        chat_id: $chatId,
                        message_id: $loadingMsg->message_id,
                        parse_mode: 'HTML'
                    );
                } catch (\Throwable $e) {
                    // نادیده گرفتن خطاهای ویرایش برای جلوگیری از توقف پروسه
                }
            }

            // پاک کردن پیام لودینگ
            try {
                $bot->deleteMessage(chat_id: $chatId, message_id: $loadingMsg->message_id);
            } catch (\Throwable $e) {}
        }

        // ۳. ساخت و ارسال پیام نهایی
        $date = now()->format('Y/m/d');
        $time = now()->format('H:i:s');

        $message = "<tg-emoji emoji-id='4915791289489818259'>✅</tg-emoji> <b>ری‌اکشن با موفقیت در صف ارسال قرار گرفت!</b>\n";
        $message .= "━━━━━━━━━━━━━━━━\n\n";
        $message .= "<tg-emoji emoji-id='4916086774649848789'>🔗</tg-emoji> <b>لینک پست:</b> {$this->postLink}\n";
        $message .= "<tg-emoji emoji-id='5350460637182993292'>🎯</tg-emoji> <b>ری‌اکشن انتخابی:</b> {$usedReaction}\n\n";
        $message .= "<tg-emoji emoji-id='5123359615727174427'>💡</tg-emoji> <i>ری‌اکشن‌ها به مرور و با سرعت استاندارد روی پست شما اعمال خواهند شد.</i>\n\n";
        $message .= "<tg-emoji emoji-id='5431897022456145283'>📆</tg-emoji> {$date}  <tg-emoji emoji-id='4904882772637648609'>⏰</tg-emoji> {$time}\n";

        $bot->sendMessage($message, parse_mode: 'HTML', reply_markup: BackToMainKeyboard::make());
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
        return $this->getAllReactions()[$index] ?? null;
    }
    private function reactionSelectionKeyboard(): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();

        $allReactions = $this->getAllReactions();
        $row = [];

        foreach ($allReactions as $index => $reaction) {
            $row[] = InlineKeyboardButton::make($reaction, callback_data: self::REACTION_CALLBACK_PREFIX . $index, style: 'secondary');

            if (count($row) === 4) {
                $keyboard->addRow(...$row);
                $row = [];
            }
        }
        if (!empty($row)) {
            $keyboard->addRow(...$row);
        }

        $keyboard->addRow(
            InlineKeyboardButton::make('میکس ری اکشن مثبت', callback_data: self::MIX_POSITIVE_CALLBACK, style: 'success')
        );
        $keyboard->addRow(
            InlineKeyboardButton::make('میکس ری اکشن منفی', callback_data: self::MIX_NEGATIVE_CALLBACK, style: 'danger')
        );

        $keyboard->addRow(
            InlineKeyboardButton::make('بازگشت', callback_data: 'main_menu', style: 'primary', icon_custom_emoji_id: '5352759161945867747')
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

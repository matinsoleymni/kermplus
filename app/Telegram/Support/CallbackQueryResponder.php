<?php

namespace App\Telegram\Support;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;

class CallbackQueryResponder
{
    public static function ack(Nutgram $bot, ?string $text = null, bool $showAlert = false): void
    {
        if (!$bot->callbackQuery()) {
            return;
        }

        try {
            if ($text === null && $showAlert === false) {
                $bot->answerCallbackQuery();
                return;
            }

            $bot->answerCallbackQuery(text: $text, show_alert: $showAlert);
        } catch (TelegramException $e) {
            if (!self::isExpiredQueryError($e)) {
                throw $e;
            }

            logger()->warning('Ignored expired Telegram callback query.', [
                'code' => $e->getCode(),
                'message' => (string) $e->getMessage(),
            ]);
        }
    }

    private static function isExpiredQueryError(TelegramException $e): bool
    {
        return stripos((string) $e->getMessage(), 'query is too old and response timeout expired or query ID is invalid') !== false;
    }
}

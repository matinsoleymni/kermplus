<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;

class BotController extends Controller
{
    public function __invoke(Nutgram $bot)
    {
        try {
            $bot->run();
        } catch (TelegramException $e) {
            $message = (string) $e->getMessage();
            $isExpiredQueryError = stripos($message, 'query is too old and response timeout expired or query ID is invalid') !== false;

            if (!$isExpiredQueryError) {
                throw $e;
            }

            logger()->warning('Ignored expired Telegram callback query.', [
                'code' => $e->getCode(),
                'message' => $message,
            ]);
        }

        return response()->noContent(Response::HTTP_OK);
    }
}

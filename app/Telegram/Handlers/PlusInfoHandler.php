<?php

namespace App\Telegram\Handlers;

use App\Telegram\Keyboards\PlusRequiredKeyboard;
use SergiX44\Nutgram\Nutgram;

class PlusInfoHandler
{
    public function __invoke(Nutgram $bot): void
    {
        if ($bot->callbackQuery()) {
            $bot->answerCallbackQuery();
        }

        $data = $bot->callbackQuery()?->data ?? 'plus_info';
        $msg = match ($data) {
            'pro_info' => "💎 نسخه پرو چیه؟\n\n"
                . "• نسخه پرو برای استفاده حرفه‌ای‌تر و سنگین‌تر طراحی شده.\n"
                . "• نسبت به نسخه پلاس امکانات و سقف استفاده بالاتری می‌دهد.\n"
                . "• جزئیات دقیق پلن‌ها را از دکمه «🪱 ارتقا ربات» ببین.",
            'plan_diff_info' => "⁉️ تفاوت نسخه پرو و پلاس\n\n"
                . "• نسخه پلاس: مناسب استفاده روزمره و کامل.\n"
                . "• نسخه پرو: مناسب استفاده حرفه‌ای با محدودیت‌های بالاتر.\n"
                . "• برای مقایسه دقیق قیمت و امکانات، روی «🪱 ارتقا ربات» بزن.",
            default => "👑 نسخه پلاس چیه؟\n\n"
                . "• نسخه پلاس محدودیت‌های اصلی نسخه رایگان را برمی‌دارد.\n"
                . "• برای استفاده کامل از قابلیت‌های اصلی ربات مناسب است.\n"
                . "• جزئیات دقیق پلن‌ها را از دکمه «🪱 ارتقا ربات» ببین.",
        };

        $bot->editMessageText($msg, reply_markup: PlusRequiredKeyboard::make('main_menu'));
    }
}

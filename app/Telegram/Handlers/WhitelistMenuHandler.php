<?php

namespace App\Telegram\Handlers;

use App\Models\User;
use App\Services\FeatureLimitService;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class WhitelistMenuHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $tgUser = $bot->user();
        $local = $tgUser ? User::where('telegram_id', $tgUser->id)->first() : null;

        if (!$local) {
            $bot->sendMessage('⛔️ حساب شما پیدا نشد. ابتدا /start را ارسال کنید.');
            return;
        }

        if ($local->isSuspended()) {
            $bot->sendMessage('⛔️ حساب شما موقتا معلق شده است.');
            return;
        }

        $limit = app(FeatureLimitService::class)->checkWhitelistAdditionLimit($local);
        if ($limit) {
            $bot->sendMessage($limit);
            return;
        }

        $local->last_active_at = now();
        $local->save();

        $msg = "❀ کرم پلاس ❀\n\n";
        $msg .= "به بخش لیست سفید 🤍 خوش اومدی ✋🏻\n";
        $msg .= "اگه شمارت رو به لیست سفید اضافه کنی ، ضد بمبر میشه و کسی نمیتونه اذیتت کنه باهاش :)\n\n";
        $msg .= "📝 فرمت ‌های قابل قبول:\n";
        $msg .= "• با صفر: 09123456789 (۱۱ رقم)\n";
        $msg .= "• بدون صفر: 9123456789 (۱۰ رقم)\n";
        $msg .= "• با کد کشور: 989123456789 (۱۲ رقم)\n\n";
        $msg .= "💡 مثلا :\n";
        $msg .= "• با صفر : 09123456789\n";
        $msg .= "• بدون صفر: 9123456789\n";
        $msg .= "• با کد کشور: 989123456789\n\n";
        $msg .= "⚠️ دقت کن:\n";
        $msg .= "• هر اکانت پلاس فقط میتونه یک شماره رو به وایت لیست اضافه کنه\n";
        $msg .= "• شماره رو بدون فاصله و بدون خط تیره وارد کن\n";
        $msg .= "• فقط اعداد انگلیسی مجازه\n\n";
        $msg .= "📱شماره مورد نظرت رو برام بفرست تا ضد بمبرش کنم :";

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'main_menu'));

        $bot->sendMessage($msg, reply_markup: $keyboard);
    }
}

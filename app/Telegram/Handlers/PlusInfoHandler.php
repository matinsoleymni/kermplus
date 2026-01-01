<?php

namespace App\Telegram\Handlers;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class PlusInfoHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $msg = "🩸 **نسخه پلاس چیه؟**\n\n";
        $msg .= "• دسترسی کامل به بمبر SMS و ایمیل با سقف روزانه بیشتر.\n";
        $msg .= "• فعال شدن ابزارهای ریپورتر، مزاحم‌ساز و کرم‌ریزی بدون محدودیت نسخه رایگان.\n";
        $msg .= "• امکان اضافه کردن وایت‌لیست برای محافظت از شماره و ایمیل‌های خود.\n";
        $msg .= "• پشتیبانی سریع‌تر و دسترسی به آپدیت‌های جدید.\n\n";
        $msg .= "برای خرید یا پرسیدن سوال، از دکمه‌های زیر استفاده کن:";

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🎗 ارتقا به نسخه پلاس🎗', callback_data: 'buy_subscription'))
            ->addRow(InlineKeyboardButton::make('📞 پشتیبانی 📞', url: 'https://t.me/kermsup'))
            ->addRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'main_menu'));

        $bot->editMessageText($msg, reply_markup: $keyboard);
    }
}

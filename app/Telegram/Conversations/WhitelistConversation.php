<?php

namespace App\Telegram\Conversations;

use App\Models\User;
use App\Models\WhitelistedTarget;
use App\Services\FeatureLimitService;
use App\Services\WhitelistService;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class WhitelistConversation extends Conversation
{
    protected function getLocalUser(Nutgram $bot): ?User
    {
        $tgUser = $bot->callbackQuery()?->from ?? $bot->user();
        if (!$tgUser) return null;

        return User::where('telegram_id', $tgUser->id)->first();
    }

    protected function cancelKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('❌ لغو', callback_data: 'cancel_whitelist'));
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

        $limit = app(FeatureLimitService::class)->checkWhitelistAdditionLimit($local);
        if ($limit) {
            $bot->sendMessage($limit);
            $this->end();
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
        $msg .= "⚠️ دقت کن:\n";
        $msg .= "• هر اکانت پلاس فقط میتونه یک شماره رو به وایت لیست اضافه کنه\n";
        $msg .= "• شماره رو بدون فاصله و بدون خط تیره وارد کن\n";
        $msg .= "• فقط اعداد انگلیسی مجازه\n\n";
        $msg .= "📱شماره مورد نظرت رو برام بفرست تا ضد بمبرش کنم :";

        $bot->sendMessage($msg, reply_markup: $this->cancelKeyboard());
        $this->next('awaitValue');
    }

    public function awaitValue(Nutgram $bot)
    {
        if ($bot->callbackQuery()?->data === 'cancel_whitelist') {
            $bot->answerCallbackQuery();
            $bot->sendMessage('❌ لغو شد.');
            $this->end();
            return;
        }

        $value = trim((string)($bot->message()?->text ?? ''));
        if ($value === '' || mb_strlen($value) < 3) {
            $bot->sendMessage('⛔️ مقدار ارسال‌شده معتبر نیست. حداقل ۳ کاراکتر وارد کنید.');
            return;
        }

        $whitelist = app(WhitelistService::class);
        $type = $whitelist->guessType($value);

        if ($whitelist->isWhitelisted($value, $type)) {
            $bot->sendMessage('ℹ️ این مورد از قبل در وایت‌لیست ثبت شده است.');
            $this->end();
            return;
        }

        $bot->setUserData('whitelist_value', $value);
        $bot->setUserData('whitelist_type', $type);

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('✅ بله، اضافه کن', callback_data: 'confirm_whitelist_yes'), InlineKeyboardButton::make('❌ لغو', callback_data: 'cancel_whitelist'));

        $bot->sendMessage(
            "❓ مطمئنی میخوای {$this->typeLabel($type)} {$value} رو به لیست سفید اضافه کنی؟",
            reply_markup: $keyboard
        );
        $this->next('confirmAdd');
    }

    public function confirmAdd(Nutgram $bot)
    {
        $data = $bot->callbackQuery()?->data;
        if ($data === 'cancel_whitelist') {
            $bot->answerCallbackQuery();
            $bot->sendMessage('❌ لغو شد.');
            $this->end();
            return;
        }

        if ($data !== 'confirm_whitelist_yes') {
            $bot->answerCallbackQuery(text: '⛔️ گزینه نامعتبر است.');
            return;
        }

        $value = $bot->getUserData('whitelist_value');
        $type = $bot->getUserData('whitelist_type');

        if (!$value || !$type) {
            $bot->answerCallbackQuery();
            $bot->sendMessage('⛔️ داده‌ای برای ذخیره پیدا نشد.');
            $this->end();
            return;
        }

        $local = $this->getLocalUser($bot);
        if (!$local) {
            $bot->answerCallbackQuery();
            $bot->sendMessage('⛔️ حساب شما پیدا نشد. ابتدا /start را ارسال کنید.');
            $this->end();
            return;
        }

        if ($local->isSuspended()) {
            $bot->answerCallbackQuery();
            $bot->sendMessage('⛔️ حساب شما موقتا معلق شده است.');
            $this->end();
            return;
        }

        $limiter = app(FeatureLimitService::class);
        $limit = $limiter->checkWhitelistAdditionLimit($local);
        if ($limit) {
            $bot->answerCallbackQuery();
            $bot->sendMessage($limit);
            $this->end();
            return;
        }

        $whitelist = app(WhitelistService::class);
        if ($whitelist->isWhitelisted($value, $type)) {
            $bot->answerCallbackQuery();
            $bot->sendMessage('ℹ️ این مورد از قبل در وایت‌لیست ثبت شده است.');
            $this->end();
            return;
        }

        WhitelistedTarget::create([
            'type' => $type,
            'value' => $value,
            'label' => null,
        ]);

        $limiter->recordWhitelistAddition($local);

        $bot->answerCallbackQuery(text: '✅ ثبت شد.');
        $bot->sendMessage("✅ مورد به وایت‌لیست اضافه شد.\nنوع: {$this->typeLabel($type)}\nمقدار: {$value}");
        $this->end();
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            WhitelistedTarget::TYPE_PHONE => 'شماره',
            WhitelistedTarget::TYPE_EMAIL => 'ایمیل',
            WhitelistedTarget::TYPE_TELEGRAM => 'کاربر',
            default => 'هدف',
        };
    }
}

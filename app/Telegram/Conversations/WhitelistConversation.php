<?php

namespace App\Telegram\Conversations;

use App\Models\User;
use App\Models\WhitelistedTarget;
use App\Services\FeatureLimitService;
use App\Services\WhitelistService;
use App\Telegram\Keyboards\PlusRequiredKeyboard;
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

    protected function limitReachedKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('✏️ ویرایش شماره', callback_data: 'whitelist_edit'))
            ->addRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'main_menu'));
    }

    public function start(Nutgram $bot)
    {
        $action = $bot->callbackQuery()?->data;
        $isEditRequest = $action === 'whitelist_edit';

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
        $limit = $limiter->checkWhitelistAdditionLimit($local);

        if (!$isEditRequest && $limit) {
            if ($limit === FeatureLimitService::WHITELIST_ALREADY_ADDED_MESSAGE) {
                $registered = $limiter->getWhitelistAddedTarget($local) ?? 'نامشخص';
                $bot->sendMessage(
                    "{$limit}\n\n📱 شماره ثبت‌شده: {$registered}\nبرای تغییر شماره از دکمه زیر استفاده کن.",
                    reply_markup: $this->limitReachedKeyboard()
                );
            } else {
                $bot->sendMessage($limit, reply_markup: PlusRequiredKeyboard::make('main_menu'));
            }
            $this->end();
            return;
        }

        if ($isEditRequest) {
            if ($limit && $limit !== FeatureLimitService::WHITELIST_ALREADY_ADDED_MESSAGE) {
                $bot->sendMessage($limit, reply_markup: PlusRequiredKeyboard::make('main_menu'));
                $this->end();
                return;
            }

            $previousValue = $limiter->getWhitelistAddedTarget($local);
            if (!$previousValue) {
                $bot->setUserData('whitelist_edit_mode', true);
                $bot->setUserData('whitelist_previous_value', null);

                $msg = "✏️ ویرایش شماره وایت‌لیست\n\n";
                $msg .= "شماره قبلی شما در سیستم مشخص نیست.\n";
                $msg .= "برای ادامه، اول شماره قبلی‌ات که قبلا وایت‌لیست کردی رو بفرست:";

                $bot->sendMessage($msg, reply_markup: $this->cancelKeyboard());
                $this->next('awaitPreviousValue');
                return;
            }

            $bot->setUserData('whitelist_edit_mode', true);
            $bot->setUserData('whitelist_previous_value', $previousValue);

            $msg = "✏️ ویرایش شماره وایت‌لیست\n\n";
            $msg .= "📱 شماره فعلی: {$previousValue}\n\n";
            $msg .= "شماره جدید را با یکی از فرمت‌های زیر بفرست:\n";
            $msg .= "• 09123456789\n";
            $msg .= "• 9123456789\n";
            $msg .= "• 989123456789";

            $bot->sendMessage($msg, reply_markup: $this->cancelKeyboard());
            $this->next('awaitValue');
            return;
        }

        $bot->setUserData('whitelist_edit_mode', false);
        $bot->setUserData('whitelist_previous_value', null);

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

    public function awaitPreviousValue(Nutgram $bot): void
    {
        if ($bot->callbackQuery()?->data === 'cancel_whitelist') {
            $bot->answerCallbackQuery();
            $bot->sendMessage('❌ لغو شد.');
            $this->end();
            return;
        }

        $previousValue = trim((string)($bot->message()?->text ?? ''));
        if ($previousValue === '' || mb_strlen($previousValue) < 3) {
            $bot->sendMessage('⛔️ شماره قبلی معتبر نیست. دوباره ارسال کن.');
            return;
        }

        $whitelist = app(WhitelistService::class);
        $previousType = $whitelist->guessType($previousValue);
        if ($previousType !== WhitelistedTarget::TYPE_PHONE) {
            $bot->sendMessage('⛔️ فقط شماره موبایل قابل قبول است. دوباره شماره قبلی را ارسال کن.');
            return;
        }

        if (!$whitelist->isWhitelisted($previousValue, $previousType)) {
            $bot->sendMessage('⛔️ این شماره در وایت‌لیست پیدا نشد. شماره قبلی صحیح را وارد کن.');
            return;
        }

        $bot->setUserData('whitelist_previous_value', $previousValue);

        $msg = "✅ شماره قبلی تایید شد.\n";
        $msg .= "حالا شماره جدید را بفرست:\n";
        $msg .= "• 09123456789\n";
        $msg .= "• 9123456789\n";
        $msg .= "• 989123456789";

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

        $isEditMode = (bool) $bot->getUserData('whitelist_edit_mode');
        $previousValue = (string) ($bot->getUserData('whitelist_previous_value') ?? '');
        if ($isEditMode && $previousValue !== '') {
            $previousType = $whitelist->guessType($previousValue);
            $isSameValue = $type === $previousType
                && WhitelistedTarget::normalizeValue($value, $type) === WhitelistedTarget::normalizeValue($previousValue, $previousType);
            if ($isSameValue) {
                $bot->sendMessage('ℹ️ این همان شماره ثبت‌شده فعلی شماست. یک شماره جدید وارد کنید.');
                return;
            }
        }

        if ($whitelist->isWhitelisted($value, $type)) {
            $bot->sendMessage('ℹ️ این مورد از قبل در وایت‌لیست ثبت شده است.');
            $this->end();
            return;
        }

        $bot->setUserData('whitelist_value', $value);
        $bot->setUserData('whitelist_type', $type);

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('✅ بله، اضافه کن', callback_data: 'confirm_whitelist_yes'), InlineKeyboardButton::make('❌ لغو', callback_data: 'cancel_whitelist'));

        $confirmAction = $isEditMode ? 'ویرایش کنی' : 'اضافه کنی';
        $bot->sendMessage(
            "❓ مطمئنی میخوای {$this->typeLabel($type)} {$value} رو در لیست سفید {$confirmAction}؟",
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
        $isEditMode = (bool) $bot->getUserData('whitelist_edit_mode');
        $previousValue = (string) ($bot->getUserData('whitelist_previous_value') ?? '');

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
        if ($limit && (!$isEditMode || $limit !== FeatureLimitService::WHITELIST_ALREADY_ADDED_MESSAGE)) {
            $bot->answerCallbackQuery();
            $bot->sendMessage($limit, reply_markup: PlusRequiredKeyboard::make('main_menu'));
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

        if ($isEditMode) {
            if ($previousValue === '') {
                $previousValue = $limiter->getWhitelistAddedTarget($local) ?? '';
            }

            if ($previousValue === '') {
                $bot->answerCallbackQuery();
                $bot->sendMessage('⛔️ شماره قبلی برای ویرایش پیدا نشد.');
                $this->end();
                return;
            }

            $previousType = $whitelist->guessType($previousValue);
            WhitelistedTarget::query()->forIdentifier($previousValue, $previousType)->delete();

            WhitelistedTarget::create([
                'type' => $type,
                'value' => $value,
                'label' => null,
            ]);

            $limiter->updateWhitelistAddedTarget($local, $value);

            $bot->answerCallbackQuery(text: '✅ ویرایش شد.');
            $bot->sendMessage("✅ شماره وایت‌لیست ویرایش شد.\nنوع: {$this->typeLabel($type)}\nمقدار جدید: {$value}");
            $this->end();
            return;
        }

        WhitelistedTarget::create([
            'type' => $type,
            'value' => $value,
            'label' => null,
        ]);

        $limiter->recordWhitelistAddition($local, $value);

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

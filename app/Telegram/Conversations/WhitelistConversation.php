<?php

namespace App\Telegram\Conversations;

use App\Models\User;
use App\Models\WhitelistedTarget;
use App\Services\FeatureLimitService;
use App\Services\WhitelistService;
use App\Telegram\Handlers\MainMenuHandler;
use App\Telegram\Keyboards\PlusRequiredKeyboard;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class WhitelistConversation extends Conversation
{
    private const ACTION_PICK_PHONE = 'whitelist_pick_phone';
    private const ACTION_PICK_EMAIL = 'whitelist_pick_email';
    private const ACTION_PICK_TELEGRAM = 'whitelist_pick_telegram';
    private const ACTION_PICK_INSTAGRAM_EMAIL = 'whitelist_pick_instagram_email';
    private const ACTION_ALREADY_REGISTERED = 'whitelist_already_registered';
    private const ACTION_SHOW_REGISTERED = 'whitelist_show_registered';
    private const ACTION_BACK_MENU = 'whitelist_back_menu';
    private const ACTION_CONFIRM = 'confirm_whitelist_yes';

    protected function getLocalUser(Nutgram $bot): ?User
    {
        $tgUser = $bot->callbackQuery()?->from ?? $bot->user();
        if (!$tgUser) {
            return null;
        }

        return User::where('telegram_id', $tgUser->id)->first();
    }

    protected function inputKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('بازگشت', callback_data: self::ACTION_BACK_MENU, style: 'danger', icon: '5352759161945867747')
            );
    }

    protected function confirmKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('بله', callback_data: self::ACTION_CONFIRM, style: 'danger', icon: '6224314343924699041'),
                InlineKeyboardButton::make('خیر', callback_data: 'cancel_whitelist', style: 'danger', icon: '6224072537265934868')
            );
    }

    protected function menuKeyboard(User $local, WhitelistService $whitelist): InlineKeyboardMarkup
    {
        $targets = $whitelist->getUserTargets($local)->keyBy('type');


        $callbackForType = static function (string $type, string $defaultAction) use ($targets): string {
            return $targets->has($type) ? self::ACTION_ALREADY_REGISTERED : $defaultAction;
        };

        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make("شماره", callback_data: $callbackForType(WhitelistedTarget::TYPE_PHONE, self::ACTION_PICK_PHONE), style: 'danger', icon: '5172893417717367746')
            )
            ->addRow(
                InlineKeyboardButton::make("ایمیل", callback_data: $callbackForType(WhitelistedTarget::TYPE_EMAIL, self::ACTION_PICK_EMAIL), style: 'danger', icon: '5456174900622412791')
            )
            ->addRow(
                InlineKeyboardButton::make("تلگرام", callback_data: $callbackForType(WhitelistedTarget::TYPE_TELEGRAM, self::ACTION_PICK_TELEGRAM), style: 'danger', icon: '5364125616801073577')
            )
            ->addRow(
                InlineKeyboardButton::make("اینستاگرام", callback_data: $callbackForType(WhitelistedTarget::TYPE_INSTAGRAM_EMAIL, self::ACTION_PICK_INSTAGRAM_EMAIL), style: 'danger', icon: '5364310996179503764')
            )
            ->addRow(
                InlineKeyboardButton::make('مشاهده موارد ثبت‌ شده', callback_data: self::ACTION_SHOW_REGISTERED, style: 'danger', icon: '5197269100878907942')
            )
            ->addRow(
                InlineKeyboardButton::make('بازگشت', callback_data: 'main_menu', style: 'danger', icon: '5352759161945867747')
            );
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
        $limit = $limiter->checkWhitelistAdditionLimit($local);
        if ($limit) {
            $bot->sendMessage($limit, parse_mode: 'HTML', reply_markup: PlusRequiredKeyboard::make('main_menu'));
            $this->end();
            return;
        }

        $local->last_active_at = now();
        $local->save();

        $this->showWhitelistMenu($bot, $local);
    }

    public function handleMenuAction(Nutgram $bot): void
    {
        $data = $bot->callbackQuery()?->data;
        if ($data === null) {
            $bot->sendMessage('ℹ️ از دکمه‌های منوی لیست سفید استفاده کن.');
            return;
        }

        if ($data === 'cancel_whitelist') {
            $bot->answerCallbackQuery();
            $bot->sendMessage('❌ لغو شد.');
            $this->end();
            return;
        }

        if ($data === 'main_menu') {
            $bot->answerCallbackQuery();
            $this->end();
            app(MainMenuHandler::class)($bot);
            return;
        }

        $local = $this->getLocalUser($bot);
        if (!$local) {
            $bot->answerCallbackQuery();
            $bot->sendMessage('⛔️ حساب شما پیدا نشد. ابتدا /start را ارسال کنید.');
            $this->end();
            return;
        }

        $whitelist = app(WhitelistService::class);

        if ($data === self::ACTION_SHOW_REGISTERED) {
            $bot->answerCallbackQuery();
            $this->showWhitelistMenu($bot, $local, true);
            return;
        }

        if ($data === self::ACTION_ALREADY_REGISTERED) {
            $bot->answerCallbackQuery(text: '⚠️ این مورد قبلا ثبت شده و قابل ویرایش نیست.');
            return;
        }

        $type = $this->resolveTypeFromAction($data);
        if ($type === null) {
            $bot->answerCallbackQuery(text: '⛔️ گزینه نامعتبر است.');
            return;
        }

        if ($whitelist->getUserTarget($local, $type)) {
            $bot->answerCallbackQuery(text: '⚠️ این مورد قبلا ثبت شده و قابل ویرایش نیست.');
            $this->showWhitelistMenu($bot, $local, true);
            return;
        }

        $bot->answerCallbackQuery();
        $bot->setUserData('whitelist_selected_type', $type);
        $bot->setUserData('whitelist_pending_value', null);

        $text = match ($type) {
            WhitelistedTarget::TYPE_PHONE => "<tg-emoji emoji-id='5172893417717367746'>📞</tg-emoji> ثبت شماره\n\n",
            WhitelistedTarget::TYPE_EMAIL => "<tg-emoji emoji-id='5456174900622412791'>📧</tg-emoji> ثبت ایمیل\n\n",
            WhitelistedTarget::TYPE_TELEGRAM => "<tg-emoji emoji-id='5364125616801073577'>✈️</tg-emoji> ثبت تلگرام\n\n",
            WhitelistedTarget::TYPE_INSTAGRAM_EMAIL => "<tg-emoji emoji-id='5364310996179503764'>📸</tg-emoji> ثبت تلگرام\n\n",
            default => "🧾 ثبت {$whitelist->getTypeLabel($type)}\n\n",
        };
        $text .= $this->typeInstruction($type);

        $disableWebPagePreview = in_array($type, [WhitelistedTarget::TYPE_TELEGRAM, WhitelistedTarget::TYPE_INSTAGRAM_EMAIL], true);
        $this->sendOrEditMessage($bot, $text, $this->inputKeyboard(), $disableWebPagePreview);
        $this->next('awaitValue');
    }

    public function awaitValue(Nutgram $bot): void
    {
        $data = $bot->callbackQuery()?->data;
        if ($data === self::ACTION_BACK_MENU) {
            $bot->answerCallbackQuery();
            $local = $this->getLocalUser($bot);
            if (!$local) {
                $bot->sendMessage('⛔️ حساب شما پیدا نشد. ابتدا /start را ارسال کنید.');
                $this->end();
                return;
            }

            $this->showWhitelistMenu($bot, $local);
            return;
        }

        if ($data === 'cancel_whitelist') {
            $bot->answerCallbackQuery();
            $bot->sendMessage('❌ لغو شد.');
            $this->end();
            return;
        }

        if ($data !== null) {
            $bot->answerCallbackQuery(text: '⛔️ مقدار را به صورت متن ارسال کن.');
            return;
        }

        $local = $this->getLocalUser($bot);
        if (!$local) {
            $bot->sendMessage('⛔️ حساب شما پیدا نشد. ابتدا /start را ارسال کنید.');
            $this->end();
            return;
        }

        $type = (string)($bot->getUserData('whitelist_selected_type') ?? '');
        $whitelist = app(WhitelistService::class);
        if (!in_array($type, $whitelist->getSupportedInputTypes(), true)) {
            $bot->sendMessage('⛔️ نوع وایت‌لیست نامعتبر است. دوباره وارد بخش لیست سفید شو.');
            $this->end();
            return;
        }

        if ($whitelist->getUserTarget($local, $type)) {
            $bot->sendMessage('⚠️ این مورد قبلا ثبت شده و قابل ویرایش نیست.');
            $this->showWhitelistMenu($bot, $local, true);
            return;
        }

        $value = trim((string)($bot->message()?->text ?? ''));
        if (!$whitelist->validateForType($value, $type)) {
            $bot->sendMessage(
                "⛔️ مقدار واردشده معتبر نیست.\n\n" . $this->typeInstruction($type),
                parse_mode: 'HTML',
                disable_web_page_preview: in_array($type, [WhitelistedTarget::TYPE_TELEGRAM, WhitelistedTarget::TYPE_INSTAGRAM_EMAIL], true),
                reply_markup: $this->inputKeyboard()
            );
            return;
        }

        if ($whitelist->isWhitelisted($value, $type)) {
            $bot->sendMessage('ℹ️ این مورد از قبل در وایت‌لیست ثبت شده است. مقدار دیگری ارسال کن یا برگرد.');
            return;
        }

        $displayValue = $whitelist->normalizeForDisplay($value, $type);
        $bot->setUserData('whitelist_pending_value', $displayValue);

        $bot->sendMessage(
            "❓ مطمئنی میخوای {$whitelist->getTypeLabel($type)} زیر ذخیره بشه؟\n\n<code>{$displayValue}</code>",
            parse_mode: 'HTML',
            reply_markup: $this->confirmKeyboard()
        );
        $this->next('confirmAdd');
    }

    public function confirmAdd(Nutgram $bot): void
    {
        $data = $bot->callbackQuery()?->data;
        if ($data === self::ACTION_BACK_MENU) {
            $bot->answerCallbackQuery();
            $local = $this->getLocalUser($bot);
            if (!$local) {
                $bot->sendMessage('⛔️ حساب شما پیدا نشد. ابتدا /start را ارسال کنید.');
                $this->end();
                return;
            }

            $this->showWhitelistMenu($bot, $local);
            return;
        }

        if ($data === 'cancel_whitelist') {
            $bot->answerCallbackQuery();
            $bot->sendMessage('❌ لغو شد.');
            $this->end();
            return;
        }

        if ($data !== self::ACTION_CONFIRM) {
            $bot->answerCallbackQuery(text: '⛔️ گزینه نامعتبر است.');
            return;
        }

        $value = trim((string)$bot->getUserData('whitelist_pending_value'));
        $type = trim((string)$bot->getUserData('whitelist_selected_type'));

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
            $bot->sendMessage($limit, parse_mode: 'HTML', reply_markup: PlusRequiredKeyboard::make('main_menu'));
            $this->end();
            return;
        }

        $whitelist = app(WhitelistService::class);
        if (!in_array($type, $whitelist->getSupportedInputTypes(), true)) {
            $bot->answerCallbackQuery();
            $bot->sendMessage('⛔️ نوع وایت‌لیست نامعتبر است.');
            $this->end();
            return;
        }

        if (!$whitelist->validateForType($value, $type)) {
            $bot->answerCallbackQuery();
            $bot->sendMessage('⛔️ مقدار ارسال‌شده معتبر نیست.');
            $this->end();
            return;
        }

        if ($whitelist->getUserTarget($local, $type)) {
            $bot->answerCallbackQuery();
            $bot->sendMessage('⚠️ این مورد قبلا ثبت شده و قابل ویرایش نیست.');
            $this->showWhitelistMenu($bot, $local, true);
            return;
        }

        if ($whitelist->isWhitelisted($value, $type)) {
            $bot->answerCallbackQuery();
            $bot->sendMessage('ℹ️ این مورد از قبل در وایت‌لیست ثبت شده است.');
            $this->end();
            return;
        }

        try {
            $saved = $whitelist->createForUser($local, $type, $value);
        } catch (\Throwable) {
            $bot->answerCallbackQuery();
            $bot->sendMessage('⛔️ ثبت این مقدار ممکن نیست. احتمالا قبلا در لیست سفید ثبت شده است.');
            $this->end();
            return;
        }
        $limiter->recordWhitelistAddition($local, "{$type}:{$saved->value}");

        $bot->answerCallbackQuery(text: '✅ ذخیره شد.');
        // $bot->sendMessage("✅ {$whitelist->getTypeLabel($type)} با موفقیت ذخیره شد:\n<code>{$saved->value}</code>", parse_mode: 'HTML');

        $bot->setUserData('whitelist_pending_value', null);
        $this->showWhitelistMenu($bot, $local, true);
    }

    private function showWhitelistMenu(Nutgram $bot, User $local, bool $withSummary = false): void
    {
        $whitelist = app(WhitelistService::class);
        $bot->setUserData('whitelist_selected_type', null);
        $bot->setUserData('whitelist_pending_value', null);

        $msg = "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> کرم پلاس <tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji>\n\n";
        $msg .= "به بخش لیست سفید<tg-emoji emoji-id='5429392313493242588'>🤍</tg-emoji> خوش اومدی\n\n";
        $msg .= "اینجا میتونی با ثبت اکانت هات و شمارت ، خودتو از هر کرمی<tg-emoji emoji-id='5134654202894615343'>🪱</tg-emoji> در امان نگه داری\n\n";
        $msg .= "<tg-emoji emoji-id='6226426402682441481'>⚠️</tg-emoji> تو ثبتشون دقت کن چون فقط یک بار میتونی ثبت کنی و امکان ویرایش وجود نداره.";

        if ($withSummary) {
            $msg .= "\n\n" . $this->buildRegisteredSummary($local, $whitelist);
        }

        $this->sendOrEditMessage($bot, $msg, $this->menuKeyboard($local, $whitelist));
        $this->next('handleMenuAction');
    }

    private function buildRegisteredSummary(User $local, WhitelistService $whitelist): string
    {
        $targets = $whitelist->getUserTargets($local)->keyBy('type');

        $line = static function (string $type, string $title) use ($targets): string {
            $value = data_get($targets->get($type), 'value', 'ثبت نشده');
            return "{$title}: {$value}";
        };

        $msg = "<tg-emoji emoji-id='5197269100878907942'>✍️</tg-emoji> موارد ثبت‌شده شما:\n";
        $msg .= "• " . $line(WhitelistedTarget::TYPE_PHONE, 'شماره') . "\n";
        $msg .= "• " . $line(WhitelistedTarget::TYPE_EMAIL, 'ایمیل') . "\n";
        $msg .= "• " . $line(WhitelistedTarget::TYPE_TELEGRAM, 'تلگرام') . "\n";
        $msg .= "• " . $line(WhitelistedTarget::TYPE_INSTAGRAM_EMAIL, 'آیدی اینستاگرام');

        return $msg;
    }

    private function resolveTypeFromAction(string $action): ?string
    {
        return match ($action) {
            self::ACTION_PICK_PHONE => WhitelistedTarget::TYPE_PHONE,
            self::ACTION_PICK_EMAIL => WhitelistedTarget::TYPE_EMAIL,
            self::ACTION_PICK_TELEGRAM => WhitelistedTarget::TYPE_TELEGRAM,
            self::ACTION_PICK_INSTAGRAM_EMAIL => WhitelistedTarget::TYPE_INSTAGRAM_EMAIL,
            default => null,
        };
    }

    private function typeInstruction(string $type): string
    {
        return match ($type) {
            WhitelistedTarget::TYPE_PHONE => "شماره موبایلت رو تو یکی از فرمت‌ های زیر بفرست تا به لیست سفید<tg-emoji emoji-id='5429392313493242588'>🤍</tg-emoji> اضافه کنمش:\n\n• 09123456789\n• 9123456789\n• 989123456789",
            WhitelistedTarget::TYPE_EMAIL => "ایمیلت رو تو فرمت‌ زیر بفرست تا به لیست سفید<tg-emoji emoji-id='5429392313493242588'>🤍</tg-emoji> اضافه کنمش:\n\n• sample@gmail.com",
            WhitelistedTarget::TYPE_TELEGRAM => "ایدی کانال یا اکانتت رو تو یکی از فرمت‌ های زیر بفرست تا به لیست سفید<tg-emoji emoji-id='5429392313493242588'>🤍</tg-emoji> اضافه کنمش:\n\n• username\n• @username\n• https://t.me/username",
            WhitelistedTarget::TYPE_INSTAGRAM_EMAIL => "پیج اینستاگرامت رو تو یکی از فرمت‌ های زیر بفرست تا به لیست سفید<tg-emoji emoji-id='5429392313493242588'>🤍</tg-emoji> اضافه کنمش:\n\n• username\n• @username\n• https://instagram.com/username",
            default => "مقدار را ارسال کن.",
        };
    }

    private function sendOrEditMessage(
        Nutgram $bot,
        string $text,
        ?InlineKeyboardMarkup $keyboard = null,
        bool $disableWebPagePreview = false
    ): void
    {
        $messageId = $bot->callbackQuery()?->message?->message_id;

        if ($messageId) {
            try {
                $bot->editMessageText(
                    chat_id: $bot->user()->id,
                    message_id: $messageId,
                    text: $text,
                    parse_mode: 'HTML',
                    disable_web_page_preview: $disableWebPagePreview,
                    reply_markup: $keyboard
                );
                return;
            } catch (\Throwable) {
                // fallback to a new message
            }
        }

        $bot->sendMessage(
            $text,
            parse_mode: 'HTML',
            disable_web_page_preview: $disableWebPagePreview,
            reply_markup: $keyboard
        );
    }
}

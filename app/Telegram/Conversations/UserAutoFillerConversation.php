<?php

namespace App\Telegram\Conversations;

use App\Models\User;
use App\Models\WhitelistedTarget;
use App\Telegram\Concerns\SendsHarasserProgress;
use App\Telegram\Keyboards\BackToMainKeyboard;
use App\Telegram\Keyboards\PlusRequiredKeyboard;
use App\Services\FeatureLimitService;
use App\Services\AutoFillerRunner;
use App\Services\WhitelistService;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class UserAutoFillerConversation extends Conversation
{
    use SendsHarasserProgress;

    private array $randomNames = [
        'علی اکبری',
        'عسل درویش',
        'رضا مومنی',
        'امیرحسین صبوری',
        'پریسا محمودی',
        'مریم نادری',
        'مهسا سلیمانی',
        'امید مرادی',
        'آرمان رستگار',
        'سارا محمدی',
    ];

    private function replyWithEditPreferred(Nutgram $bot, string $text, ?InlineKeyboardMarkup $keyboard = null): void
    {
        $message = $bot->callbackQuery()?->message;
        if ($message && isset($message->message_id)) {
            try {
                $bot->editMessageText($text, parse_mode: 'HTML', reply_markup: $keyboard);
                return;
            } catch (\Throwable $e) {
                // Fallback to sending a new message if edit fails
            }
        }

        $bot->sendMessage($text, parse_mode: 'HTML', reply_markup: $keyboard);
    }

    protected function getLocalUser(Nutgram $bot): ?User
    {
        $tgUser = $bot->callbackQuery()?->from ?? $bot->user();
        if (!$tgUser) return null;

        return User::where('telegram_id', $tgUser->id)->first();
    }

    protected function collectName(): void
    {
        $this->step = 'name';
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
        $limit = $limiter->checkHarasserLimit($local);
        if ($limit) {
            $this->replyWithEditPreferred($bot, $limit, PlusRequiredKeyboard::make('main_menu'));
            $this->end();
            return;
        }

        $local->last_active_at = now();
        $local->save();

        $intro = "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> <b>کرم پلاس</b> <tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji>\n\n"
            . "اسم و فامیلی تارگتت رو بگو تا تو سایتا با همون اسم ثبتش کنم رگبار اس ام اس و تماس فعال بشه 😈\n\n"
            . "ما میایم فرم تماس صد ها سایت رو با همین اسم و شماره ای که میدی پر میکنیم تا آدمای واقعی تارگتت رو به رگبار تماس ببندن.🙃\n\n"
            . "لطفا یکی از دکمه های زیر رو جهت ادامه انتخاب کن :\n\n"
            . "دکمه ها:\n"
            . " وارد کردن اسم دلخواه ➥ \n"
            . "انتخاب اسم رندوم ➥ \n\n"
            . "بازگشت";

        $this->replyWithEditPreferred($bot, $intro, $this->nameSelectionKeyboard());
        $this->next('handleNameChoice');
    }

    public function awaitName(Nutgram $bot)
    {
        $name = $bot->message()?->text;
        if (!$name || strlen($name) < 2) {
            $bot->sendMessage('⛔️ نام نامعتبر است. لطفا حداقل 2 کاراکتر وارد کنید.');
            return;
        }
        $bot->setUserData('autofill_name', trim($name));
        $this->promptForPhone($bot);
    }

    public function awaitPhone(Nutgram $bot)
    {
        $phone = $bot->message()?->text;
        if (!$phone || !preg_match('/^989\d{9}$|^09\d{9}$/', $phone)) {
            $bot->sendMessage('⛔️ شماره تلفن نامعتبر است. لطفا یک شماره معتبر وارد کنید (مثال: 09xxxxxxxxx)');
            return;
        }

        // Normalize phone to 0-prefixed format
        if (substr($phone, 0, 3) === '989') {
            $phone = '0' . substr($phone, 2);
        }

        $bot->setUserData('autofill_phone', $phone);

        $local = $this->getLocalUser($bot);
        if (!$local) {
            $bot->sendMessage('⛔️ حساب شما پیدا نشد. ابتدا /start را ارسال کنید.');
            $this->end();
            return;
        }

        $whitelist = app(WhitelistService::class);
        if ($whitelist->isWhitelisted($phone, WhitelistedTarget::TYPE_PHONE)) {
            $bot->sendMessage($whitelist->getBlockMessage($phone, WhitelistedTarget::TYPE_PHONE), reply_markup: BackToMainKeyboard::make());
            $this->end();
            return;
        }

        $limiter = app(FeatureLimitService::class);
        $limit = $limiter->checkHarasserLimit($local);
        if ($limit) {
            $this->replyWithEditPreferred($bot, $limit, PlusRequiredKeyboard::make('main_menu'));
            $this->end();
            return;
        }

        $name = $bot->getUserData('autofill_name');
        $sites = config('autofill.sites', []);
        $siteCount = count($sites);

        if ($siteCount === 0) {
            $bot->sendMessage('⛔️ هیچ سایتی برای مزاحم‌ساز پیکربندی نشده است.', reply_markup: BackToMainKeyboard::make());
            $this->end();
            return;
        }

        $limiter->recordHarasserUsage($local);

        $progressMessageId = $this->sendHarasserProgressPreview($bot, $name, $phone, $siteCount);

        $runner = app(AutoFillerRunner::class);
        $result = $runner->run(
            sites: $sites,
            name: $name,
            phone: $phone,
            sleepUs: (int) config('autofill.sleep_us', 100000),
            debug: (bool) config('autofill.debug', false)
        );

        if ($progressMessageId) {
            $this->deleteHarasserProgressMessage($bot, $progressMessageId);
        }

        $this->sendHarasserFinalReport($bot, $name, $phone, $result);
        $this->end();
    }

    private function sendHarasserFinalReport(Nutgram $bot, string $name, string $phone, array $result): void
    {
        $date = now()->format('Y/m/d');
        $time = now()->format('H:i:s');
        $stats = $result['stats'] ?? ['success' => 0, 'failed' => 0, 'total' => 0];

        $message = "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> KermPlus | مزاحم‌ساز تکمیل شد\n" .
            "━━━━━━━━━━━━━━━━\n\n" .
            "👤 هدف: {$name}\n" .
            "📱 شماره: {$phone}\n" .
            "🌐 سایت‌ها: {$stats['total']}\n" .
            "✅ موفق: {$stats['success']}   ❌ ناموفق: {$stats['failed']}\n\n" .
            "📆 {$date}  ⏰ {$time}\n" .
            "• @NitroHostBot •";

        $imagePath = public_path('images/mozahem.png');

        try {
            if (is_readable($imagePath)) {
                $bot->sendPhoto(
                    photo: InputFile::make($imagePath, 'mozahem.png'),
                    caption: $message,
                    reply_markup: BackToMainKeyboard::make()
                );
                return;
            }
        } catch (\Throwable $e) {
            // Fallback to text-only message if sending the image fails
        }

        $bot->sendMessage($message, reply_markup: BackToMainKeyboard::make());
    }

    private function nameSelectionKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('وارد کردن اسم دلخواه ➥', callback_data: 'autofill_custom_name', style: 'danger'))
            ->addRow(InlineKeyboardButton::make('انتخاب اسم رندوم ➥', callback_data: 'autofill_random_name', style: 'danger'))
            ->addRow(InlineKeyboardButton::make('آموزش نحوه استفاده', url: 'https://t.me/kermpluslearn/9', style: 'danger'))
            ->addRow(InlineKeyboardButton::make('بازگشت', callback_data: 'main_menu', style: 'danger', icon: '5352759161945867747'));
    }

    public function handleNameChoice(Nutgram $bot): void
    {
        $data = $bot->callbackQuery()?->data ?? '';

        if ($data === 'autofill_custom_name') {
            $bot->answerCallbackQuery();
            $this->promptForCustomName($bot);
            return;
        }

        if ($data === 'autofill_random_name') {
            $bot->answerCallbackQuery();
            $name = $this->randomNames[array_rand($this->randomNames)];
            $bot->setUserData('autofill_name', $name);
            $bot->sendMessage("🎲 اسم رندوم برای تارگت انتخاب شد:\n{$name}\n\n📱 حالا شماره هدف را وارد کن (مثال: 09xxxxxxxxx یا 989xxxxxxxxx):");
            $this->next('awaitPhone');
            return;
        }

        $name = trim($bot->message()?->text ?? '');
        if ($name !== '' && strlen($name) >= 2) {
            $bot->setUserData('autofill_name', $name);
            $this->promptForPhone($bot);
            return;
        }

        $bot->sendMessage("برای ادامه یکی از دکمه‌ها رو بزن یا اسم و فامیلی تارگت رو مستقیم بفرست.", reply_markup: $this->nameSelectionKeyboard());
        $this->next('handleNameChoice');
    }

    private function promptForCustomName(Nutgram $bot): void
    {
        $text = "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> <b>کرم پلاس</b> <tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji>\n\n"
            . "حله! حالا اسم و فامیلی تارگتت رو به فارسی وارد کن :\n\n"
            . "⚠️ مثال:\n"
            . "علی اکبری\n"
            . "عسل درویش\n"
            . "رضا مومنی";

        $this->replyWithEditPreferred($bot, $text, BackToMainKeyboard::make());
        $this->next('awaitName');
    }

    private function promptForPhone(Nutgram $bot): void
    {
        $bot->sendMessage('📱 حالا شماره هدف را وارد کن (مثال: 09xxxxxxxxx یا 989xxxxxxxxx):');
        $this->next('awaitPhone');
    }
}

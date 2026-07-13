<?php

namespace App\Services\Fcm;

interface FcmSender
{
    /**
     * Deliver a data message to a single FCM registration token.
     *
     * @param  array<string, string>  $data  Flat string map delivered as the FCM data payload.
     */
    public function send(string $token, array $data): FcmResult;
}

<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

class ReferralService
{
    /**
     * تولید کد یکتا برای لینک دعوت
     */
    public function generateUniqueCode(int $length = 8): string
    {
        do {
            $code = Str::upper(Str::random($length));
        } while (User::where('referral_code', $code)->exists());

        return $code;
    }

    /**
     * اطمینان از اینکه کاربر کد رفرال دارد
     */
    public function ensureUserHasCode(User $user): User
    {
        if (!$user->referral_code) {
            $user->referral_code = $this->generateUniqueCode();
            $user->save();
        }

        return $user;
    }
}

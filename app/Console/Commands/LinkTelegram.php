<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;

class LinkTelegram extends Command
{
    protected $signature = 'telegram:link {user} {telegram_id}';

    protected $description = 'Link a Telegram chat id to a local user (user id or email)';

    public function handle(): int
    {
        $userArg = $this->argument('user');
        $tgId = $this->argument('telegram_id');

        if (!is_numeric($tgId)) {
            $this->error('telegram_id must be numeric');
            return self::FAILURE;
        }

        $user = null;
        if (is_numeric($userArg)) {
            $user = User::find((int) $userArg);
        } else {
            $user = User::where('email', $userArg)->first();
        }

        if (!$user) {
            $this->error('User not found');
            return self::FAILURE;
        }

        $user->telegram_id = (int) $tgId;
        $user->save();

        $this->info("Linked telegram_id {$tgId} to user {$user->id} ({$user->email})");
        return self::SUCCESS;
    }
}

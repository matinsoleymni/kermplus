<?php

namespace App\Telegram\Handlers;

use App\Models\Form;
use App\Telegram\Keyboards\UserFormsKeyboard;
use SergiX44\Nutgram\Nutgram;

class UserFormsHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $forms = Form::where('is_active', true)->get();

        if ($forms->isEmpty()) {
            $bot->sendMessage('❌ فرمی موجود نیست.');
            return;
        }

        $msg = "📝 **فرم‌های موجود:**\n\n";

        foreach ($forms as $form) {
            $msg .= "▸ {$form->name}\n";
        }

        $bot->editMessageText($msg, reply_markup: UserFormsKeyboard::make($forms));
    }
}

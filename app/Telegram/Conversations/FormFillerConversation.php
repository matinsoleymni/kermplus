<?php

namespace App\Telegram\Conversations;

use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\User;

class FormFillerConversation extends Conversation
{
    public int $form_id = 0;

    public function start(Nutgram $bot)
    {
        $data = $bot->callbackQuery()?->data;
        if ($data && str_starts_with($data, 'fill_form_')) {
            $this->form_id = (int) str_replace('fill_form_', '', $data);
        }

        $form = Form::find($this->form_id);
        if (!$form) {
            $bot->sendMessage('❌ فرم پیدا نشد.');
            $this->end();
            return;
        }

        if (empty($form->fields)) {
            $bot->sendMessage('❌ فرم هیچ فیلدی ندارد.');
            $this->end();
            return;
        }

        $msg = "📝 فرم: **{$form->name}**\n\n";
        if ($form->description) {
            $msg .= $form->description . "\n\n";
        }
        $msg .= "**فیلد 1/" . count($form->fields) . "**\n";
        $msg .= $form->fields[0]['name'] . " (" . $form->fields[0]['type'] . ")";

        $bot->sendMessage($msg);
        $bot->setUserData('submitted_data', []);
        $bot->setUserData('field_index', 0);
        $bot->setUserData('form_id', $this->form_id);
        $this->next('getFieldValue');
    }

    public function getFieldValue(Nutgram $bot)
    {
        $form_id = (int)$bot->getUserData('form_id');
        $form = Form::find($form_id);

        if (!$form) {
            $bot->sendMessage('❌ فرم پیدا نشد.');
            $this->end();
            return;
        }

        $value = $bot->message()?->text;
        if (!$value) {
            $bot->sendMessage('❌ مقدار نمی‌تواند خالی باشد.');
            return;
        }

        $index = (int)$bot->getUserData('field_index');
        $field = $form->fields[$index];
        $is_valid = true;

        if ($field['type'] === 'email') {
            $is_valid = filter_var($value, FILTER_VALIDATE_EMAIL);
            if (!$is_valid) {
                $bot->sendMessage('❌ ایمیل صحیح نیست.');
            }
        } elseif ($field['type'] === 'phone') {
            $is_valid = preg_match('/^09\d{9}$/', $value);
            if (!$is_valid) {
                $bot->sendMessage('❌ شماره تلفن صحیح نیست.');
            }
        } elseif ($field['type'] === 'number') {
            $is_valid = is_numeric($value);
            if (!$is_valid) {
                $bot->sendMessage('❌ مقدار باید عدد باشد.');
            }
        }

        if (!$is_valid) {
            return;
        }

        $data = $bot->getUserData('submitted_data') ?? [];
        $data[$field['name']] = $value;
        $bot->setUserData('submitted_data', $data);

        $index++;
        if ($index < count($form->fields)) {
            $bot->setUserData('field_index', $index);
            $next_field = $form->fields[$index];
            $msg = "**فیلد " . ($index + 1) . "/" . count($form->fields) . "**\n";
            $msg .= $next_field['name'] . " (" . $next_field['type'] . ")";
            $bot->sendMessage($msg);
        } else {
            $tgUser = $bot->user();
            $local = User::where('telegram_id', $tgUser->id)->first();

            FormSubmission::create([
                'form_id' => $form_id,
                'user_id' => $local->id,
                'data' => $data,
            ]);

            $bot->sendMessage("✅ فرم با موفقیت ارسال شد!");
            $this->end();
        }
    }
}

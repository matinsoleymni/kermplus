<?php

namespace App\Telegram\Conversations;

use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use App\Models\Form;
use App\Models\User;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class AutoFormConversation extends Conversation
{
    protected function getLocalUserByTelegram(Nutgram $bot): ?User
    {
        $tgUser = $bot->callbackQuery()?->from ?? $bot->user();
        if (!$tgUser) {
            return null;
        }

        return User::where('telegram_id', $tgUser->id)->first();
    }

    public function start(Nutgram $bot)
    {
        $local = $this->getLocalUserByTelegram($bot);
        if (!$local || !$local->isAdmin()) {
            $bot->sendMessage('⛔️ دسترسی ندارید. این بخش فقط برای ادمین‌هاست.');
            $this->end();
            return;
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('📋 لیست فرم‌ها', callback_data: 'form_list_admin'),
                InlineKeyboardButton::make('➕ ایجاد فرم', callback_data: 'form_create_admin')
            )
            ->addRow(
                InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'admin_panel')
            );
        $bot->sendMessage('📝 مدیریت فرم‌ها:', reply_markup: $keyboard);
        $this->next('handleMenu');
    }

    public function handleMenu(Nutgram $bot)
    {
        $local = $this->getLocalUserByTelegram($bot);
        if (!$local || !$local->isAdmin()) {
            $bot->sendMessage('⛔️ دسترسی ندارید. این بخش فقط برای ادمین‌هاست.');
            $this->end();
            return;
        }

        $data = $bot->callbackQuery()?->data;

        if ($data === 'form_list_admin') {
            $this->listForms($bot);
        } elseif ($data === 'form_create_admin') {
            $bot->sendMessage('📝 نام فرم را وارد کنید:');
            $this->next('getFormName');
        } elseif ($data === 'admin_panel') {
            AdminPanelConversation::begin($bot);
            $this->end();
        } elseif (str_starts_with($data ?? '', 'form_toggle_')) {
            $formId = (int) str_replace('form_toggle_', '', $data);
            $form = Form::find($formId);
            if ($form) {
                $form->is_active = !$form->is_active;
                $form->save();
                $bot->answerCallbackQuery(text: ($form->is_active ? '✅ فعال' : '❌ غیرفعال') . ' شد.');
            }
            $this->listForms($bot);
        } elseif (str_starts_with($data ?? '', 'form_delete_')) {
            $formId = (int) str_replace('form_delete_', '', $data);
            Form::destroy($formId);
            $bot->answerCallbackQuery(text: 'فرم حذف شد.');
            $this->listForms($bot);
        } else {
            $this->start($bot);
        }
    }

    private function listForms(Nutgram $bot)
    {
        $forms = Form::all();

        if ($forms->isEmpty()) {
            $bot->sendMessage('❌ فرمی موجود نیست.');
            $this->start($bot);
            return;
        }

        $msg = "📋 **لیست فرم‌ها:**\n\n";
        foreach ($forms as $form) {
            $status = $form->is_active ? '✅' : '❌';
            $msg .= "{$status} {$form->name} (" . count($form->fields) . " فیلد)\n";
        }

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($forms as $form) {
            $keyboard->addRow(
                InlineKeyboardButton::make($form->is_active ? '❌' : '✅', callback_data: "form_toggle_{$form->id}"),
                InlineKeyboardButton::make('🗑️ حذف', callback_data: "form_delete_{$form->id}")
            );
        }
        $keyboard->addRow(InlineKeyboardButton::make('➕ جدید', callback_data: 'form_create_admin'));
        $keyboard->addRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'admin_panel'));

        $bot->sendMessage($msg, reply_markup: $keyboard);
    }

    public function getFormName(Nutgram $bot)
    {
        $name = $bot->message()?->text;
        if (!$name) {
            $bot->sendMessage('❌ نام فرم نمی‌تواند خالی باشد.');
            $this->next('getFormName');
            return;
        }

        $bot->setUserData('form_name', $name);
        $bot->sendMessage('📝 توضیح فرم را وارد کنید (یا /skip برای رد کردن):');
        $this->next('getFormDescription');
    }

    public function getFormDescription(Nutgram $bot)
    {
        $text = $bot->message()?->text;
        $description = ($text === '/skip') ? null : $text;

        $bot->setUserData('form_description', $description);
        $bot->sendMessage('🔢 تعداد فیلد‌های فرم را وارد کنید (1-20):');
        $this->next('getFieldCount');
    }

    public function getFieldCount(Nutgram $bot)
    {
        $count = (int)($bot->message()?->text ?? 0);
        if ($count < 1 || $count > 20) {
            $bot->sendMessage('❌ تعداد فیلد باید بین 1 و 20 باشد.');
            $this->next('getFieldCount');
            return;
        }

        $bot->setUserData('field_count', $count);
        $bot->setUserData('fields', []);
        $bot->setUserData('current_field_index', 0);

        $bot->sendMessage("📝 **فیلد 1/{$count}**\nنام فیلد را وارد کنید:");
        $this->next('getFieldName');
    }

    public function getFieldName(Nutgram $bot)
    {
        $fieldName = $bot->message()?->text;
        if (!$fieldName) {
            $bot->sendMessage('❌ نام فیلد نمی‌تواند خالی باشد.');
            $this->next('getFieldName');
            return;
        }

        $bot->setUserData('temp_field_name', $fieldName);

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('📝 متن', callback_data: 'ftype_text'),
                InlineKeyboardButton::make('🔢 عدد', callback_data: 'ftype_number')
            )
            ->addRow(
                InlineKeyboardButton::make('📧 ایمیل', callback_data: 'ftype_email'),
                InlineKeyboardButton::make('📱 تلفن', callback_data: 'ftype_phone')
            );

        $bot->sendMessage('🎯 نوع فیلد را انتخاب کنید:', reply_markup: $keyboard);
        $this->next('getFieldType');
    }

    public function getFieldType(Nutgram $bot)
    {
        $data = $bot->callbackQuery()?->data;
        $type = str_replace('ftype_', '', $data);

        $fieldCount = (int)$bot->getUserData('field_count');
        $index = (int)($bot->getUserData('current_field_index') ?? 0);
        $fields = $bot->getUserData('fields') ?? [];

        $fields[] = [
            'name' => $bot->getUserData('temp_field_name'),
            'type' => $type,
        ];
        $bot->setUserData('fields', $fields);

        $index++;
        if ($index < $fieldCount) {
            $bot->setUserData('current_field_index', $index);
            $bot->sendMessage("📝 **فیلد " . ($index + 1) . "/{$fieldCount}**\nنام فیلد را وارد کنید:");
            $this->next('getFieldName');
        } else {
            Form::create([
                'name' => $bot->getUserData('form_name'),
                'description' => $bot->getUserData('form_description'),
                'fields' => $fields,
                'is_active' => true,
            ]);

            $bot->sendMessage("✅ فرم **{$bot->getUserData('form_name')}** ایجاد شد.");
            $this->end();
        }
    }
}

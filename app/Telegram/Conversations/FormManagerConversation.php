<?php

namespace App\Telegram\Conversations;

use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use App\Models\Form;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class FormManagerConversation extends Conversation
{
    public function start(Nutgram $bot)
    {
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('📋 لیست فرم‌ها', callback_data: 'form_list'),
                InlineKeyboardButton::make('➕ ایجاد فرم', callback_data: 'form_create')
            )
            ->addRow(
                InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'admin_panel')
            );
        $bot->sendMessage('📝 مدیریت فرم‌ها:', reply_markup: $keyboard);
        $this->next('handleMenu');
    }

    public function handleMenu(Nutgram $bot)
    {
        $data = $bot->callbackQuery()?->data;

        if ($data === 'form_list') {
            $forms = Form::all();
            if ($forms->isEmpty()) {
                $bot->sendMessage('❌ فرمی موجود نیست.');
                $this->start($bot);
                return;
            }

            $msg = "📋 **لیست فرم‌ها:**\n\n";
            foreach ($forms as $form) {
                $status = $form->is_active ? '✅' : '❌';
                $msg .= "{$status} {$form->name}\n";
            }

            $keyboard = InlineKeyboardMarkup::make();
            foreach ($forms as $form) {
                $keyboard->addRow(
                    InlineKeyboardButton::make("✏️ {$form->name}", callback_data: "form_view_{$form->id}"),
                    InlineKeyboardButton::make($form->is_active ? '❌' : '✅', callback_data: "form_toggle_{$form->id}"),
                    InlineKeyboardButton::make('🗑️', callback_data: "form_delete_{$form->id}")
                );
            }
            $keyboard->addRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'admin_panel'));
            $bot->sendMessage($msg, reply_markup: $keyboard);
        } elseif ($data === 'form_create') {
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
                $bot->answerCallbackQuery(text: "فرم " . ($form->is_active ? '✅ فعال' : '❌ غیرفعال') . " شد.");
            }
            $this->handleMenu($bot);
        } elseif (str_starts_with($data ?? '', 'form_delete_')) {
            $formId = (int) str_replace('form_delete_', '', $data);
            Form::destroy($formId);
            $bot->answerCallbackQuery(text: "فرم حذف شد.");
            $this->handleMenu($bot);
        } elseif (str_starts_with($data ?? '', 'form_view_')) {
            $formId = (int) str_replace('form_view_', '', $data);
            $form = Form::find($formId);
            if ($form) {
                $msg = "**{$form->name}**\n";
                $msg .= "وضعیت: " . ($form->is_active ? '✅ فعال' : '❌ غیرفعال') . "\n";
                $msg .= "تعداد فیلد: " . count($form->fields) . "\n\n";
                if ($form->description) {
                    $msg .= "توضیح: {$form->description}\n";
                }
            }
            $keyboard = InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'form_list'));
            $bot->sendMessage($msg ?? '❌ فرم پیدا نشد.', reply_markup: $keyboard);
        } else {
            $this->start($bot);
        }
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
        $bot->sendMessage('📝 توضیح فرم را وارد کنید (یا skip برای رد کردن):');
        $this->next('getFormDescription');
    }

    public function getFormDescription(Nutgram $bot)
    {
        $text = $bot->message()?->text;
        $description = ($text === 'skip') ? null : $text;

        $bot->setUserData('form_description', $description);
        $bot->sendMessage('🔢 تعداد فیلد‌های فرم را وارد کنید:');
        $this->next('getFieldCount');
    }

    public function getFieldCount(Nutgram $bot)
    {
        $count = (int)($bot->message()?->text);
        if ($count <= 0 || $count > 20) {
            $bot->sendMessage('❌ تعداد فیلد باید بین 1 و 20 باشد.');
            $this->next('getFieldCount');
            return;
        }

        $bot->setUserData('field_count', $count);
        $bot->setUserData('fields', []);
        $bot->setUserData('current_field', 1);

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

        $bot->setUserData('field_name', $fieldName);

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('📝 متن', callback_data: 'field_type_text'),
                InlineKeyboardButton::make('🔢 عدد', callback_data: 'field_type_number')
            )
            ->addRow(
                InlineKeyboardButton::make('📧 ایمیل', callback_data: 'field_type_email'),
                InlineKeyboardButton::make('📱 تلفن', callback_data: 'field_type_phone')
            );

        $bot->sendMessage('🎯 نوع فیلد را انتخاب کنید:', reply_markup: $keyboard);
        $this->next('getFieldType');
    }

    public function getFieldType(Nutgram $bot)
    {
        $data = $bot->callbackQuery()?->data;
        $type = str_replace('field_type_', '', $data);

        $fieldCount = (int)$bot->getUserData('field_count');
        $currentField = (int)($bot->getUserData('current_field') ?? 1);
        $fields = $bot->getUserData('fields') ?? [];

        $fields[] = [
            'name' => $bot->getUserData('field_name'),
            'type' => $type,
        ];
        $bot->setUserData('fields', $fields);

        $currentField++;
        if ($currentField <= $fieldCount) {
            $bot->setUserData('current_field', $currentField);
            $bot->sendMessage("📝 **فیلد {$currentField}/{$fieldCount}**\nنام فیلد را وارد کنید:");
            $this->next('getFieldName');
        } else {
            Form::create([
                'name' => $bot->getUserData('form_name'),
                'description' => $bot->getUserData('form_description'),
                'fields' => $fields,
                'is_active' => true,
            ]);

            $bot->sendMessage("✅ فرم **{$bot->getUserData('form_name')}** با موفقیت ایجاد شد.");
            $this->end();
        }
    }
}

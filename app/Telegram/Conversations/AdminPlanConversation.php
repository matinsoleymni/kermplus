<?php

namespace App\Telegram\Conversations;

use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Collection;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class AdminPlanConversation extends Conversation
{
    protected function getLocalUserByTelegram(Nutgram $bot): ?User
    {
        $tgUser = $bot->callbackQuery()?->from ?? $bot->user();
        if (!$tgUser) return null;
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

        $plans = $this->getManagedPlans();
        if ($plans->isEmpty()) {
            $bot->sendMessage('⛔️ هیچ پلن Pro/Plus در دیتابیس پیدا نشد. ابتدا seeder را اجرا کنید.');
            $this->end();
            return;
        }

        $bot->sendMessage(
            $this->buildPlansListText($plans),
            reply_markup: $this->buildPlansListKeyboard($plans)
        );
        $this->next('handleInput');
    }

    public function handleInput(Nutgram $bot)
    {
        $local = $this->getLocalUserByTelegram($bot);
        if (!$local || !$local->isAdmin()) {
            $bot->sendMessage('⛔️ دسترسی ندارید.');
            $this->end();
            return;
        }

        $data = $bot->callbackQuery()?->data;
        if (!$data) {
            $this->start($bot);
            return;
        }

        if ($bot->callbackQuery()) {
            $bot->answerCallbackQuery();
        }

        if ($data === 'admin_panel') {
            AdminPanelConversation::begin($bot);
            $this->end();
            return;
        }

        if ($data === 'admin_plan_back') {
            $this->start($bot);
            return;
        }

        if (preg_match('/^admin_plan_open:(\d+)$/', $data, $m)) {
            $plan = SubscriptionPlan::find((int) $m[1]);
            if (!$plan || !$this->isManagedPlan($plan)) {
                $bot->sendMessage('⛔️ فقط پلن‌های pro و plus قابل مدیریت هستند.');
                $this->start($bot);
                return;
            }

            $this->showPlanEditor($bot, $plan);
            return;
        }

        if (preg_match('/^admin_plan_toggle:(\d+)$/', $data, $m)) {
            $plan = SubscriptionPlan::find((int) $m[1]);
            if (!$plan || !$this->isManagedPlan($plan)) {
                $bot->sendMessage('پلن قابل مدیریت نیست.');
                $this->start($bot);
                return;
            }

            $plan->is_active = !$plan->is_active;
            $plan->save();
            $bot->answerCallbackQuery(text: 'وضعیت پلن بروزرسانی شد.');
            $this->showPlanEditor($bot, $plan->fresh());
            return;
        }

        if (preg_match('/^admin_plan_edit:(\d+):(usd|irr|stars)$/', $data, $m)) {
            $plan = SubscriptionPlan::find((int) $m[1]);
            if (!$plan || !$this->isManagedPlan($plan)) {
                $bot->sendMessage('پلن قابل مدیریت نیست.');
                $this->start($bot);
                return;
            }

            $field = $m[2];
            $bot->setUserData('admin_plan_edit_id', $plan->id);
            $bot->setUserData('admin_plan_edit_field', $field);

            $prompt = match ($field) {
                'usd' => "✏️ قیمت ارزی پلن {$plan->name} را وارد کنید (USD):",
                'irr' => "✏️ قیمت ریالی پلن {$plan->name} را وارد کنید:",
                default => "✏️ قیمت استار پلن {$plan->name} را وارد کنید:",
            };

            $bot->sendMessage($prompt);
            $this->next('saveEditedPrice');
            return;
        }

        $this->start($bot);
    }

    public function saveEditedPrice(Nutgram $bot)
    {
        $local = $this->getLocalUserByTelegram($bot);
        if (!$local || !$local->isAdmin()) {
            $bot->sendMessage('⛔️ دسترسی ندارید.');
            $this->end();
            return;
        }

        // کاربر ممکن است به‌جای متن، دکمه‌ای کلیک کند.
        if ($bot->callbackQuery()) {
            $this->handleInput($bot);
            return;
        }

        $text = trim((string) $bot->message()?->text);
        if ($text === '' || !is_numeric($text)) {
            $bot->sendMessage('مقدار نامعتبر است. لطفا عدد ارسال کنید.');
            $this->next('saveEditedPrice');
            return;
        }

        $planId = (int) ($bot->getUserData('admin_plan_edit_id') ?? 0);
        $field = (string) ($bot->getUserData('admin_plan_edit_field') ?? '');
        $plan = SubscriptionPlan::find($planId);

        if (!$plan || !$this->isManagedPlan($plan)) {
            $bot->sendMessage('پلن قابل مدیریت پیدا نشد.');
            $this->start($bot);
            return;
        }

        if (!in_array($field, ['usd', 'irr', 'stars'], true)) {
            $bot->sendMessage('نوع فیلد قیمت نامعتبر است.');
            $this->start($bot);
            return;
        }

        if ($field === 'usd') {
            $value = round((float) $text, 2);
            if ($value < 0) {
                $bot->sendMessage('قیمت ارزی نمی‌تواند منفی باشد.');
                $this->next('saveEditedPrice');
                return;
            }

            $plan->price_usd = $value;
            $plan->price = $value; // legacy compatibility
        }

        if ($field === 'irr') {
            $value = (int) round((float) $text);
            if ($value < 0) {
                $bot->sendMessage('قیمت ریالی نمی‌تواند منفی باشد.');
                $this->next('saveEditedPrice');
                return;
            }

            $plan->price_irr = $value;
        }

        if ($field === 'stars') {
            $value = (int) round((float) $text);
            if ($value < 0) {
                $bot->sendMessage('قیمت استار نمی‌تواند منفی باشد.');
                $this->next('saveEditedPrice');
                return;
            }

            $plan->price_stars = $value;
        }

        $plan->save();
        $bot->sendMessage('✅ قیمت پلن بروزرسانی شد.');
        $this->showPlanEditor($bot, $plan->fresh());
    }

    /**
     * @return Collection<int, SubscriptionPlan>
     */
    protected function getManagedPlans(): Collection
    {
        return SubscriptionPlan::query()
            ->where(function ($q): void {
                $q->whereRaw('LOWER(name) = ?', ['pro'])
                    ->orWhereRaw('LOWER(name) = ?', ['plus']);
            })
            ->get()
            ->sortBy(function (SubscriptionPlan $plan): int {
                return strtolower($plan->name) === 'pro' ? 0 : 1;
            })
            ->values();
    }

    protected function isManagedPlan(SubscriptionPlan $plan): bool
    {
        return in_array(strtolower($plan->name), ['pro', 'plus'], true);
    }

    protected function buildPlansListText(Collection $plans): string
    {
        $msg = "💳 مدیریت پلن‌ها (فقط pro / plus)\n\n";
        $msg .= "برای ویرایش قیمت‌ها روی پلن بزن:\n\n";

        foreach ($plans as $plan) {
            $status = $plan->is_active ? 'فعال' : 'غیرفعال';
            $msg .= "• {$plan->name} ({$status})\n";
            $msg .= "  USD: " . number_format($plan->usdPrice(), 2) . " | ریال: " . number_format($plan->irrPrice()) . " | استار: " . number_format($plan->starsPrice()) . "\n";
        }

        return $msg;
    }

    protected function buildPlansListKeyboard(Collection $plans): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();
        foreach ($plans as $plan) {
            $keyboard->addRow(
                InlineKeyboardButton::make("✏️ {$plan->name}", callback_data: "admin_plan_open:{$plan->id}", style: 'danger')
            );
        }

        $keyboard->addRow(InlineKeyboardButton::make('بازگشت', callback_data: 'admin_panel', style: 'danger', icon: '5352759161945867747'));

        return $keyboard;
    }

    protected function showPlanEditor(Nutgram $bot, SubscriptionPlan $plan): void
    {
        $status = $plan->is_active ? '✅ فعال' : '⛔️ غیرفعال';
        $msg = "🧾 پلن: {$plan->name}\n";
        $msg .= "وضعیت: {$status}\n\n";
        $msg .= "💵 قیمت ارزی (USD): " . number_format($plan->usdPrice(), 2) . "\n";
        $msg .= "💴 قیمت ریالی: " . number_format($plan->irrPrice()) . "\n";
        $msg .= "⭐️ قیمت استار: " . number_format($plan->starsPrice()) . "\n";
        $msg .= "⏱ مدت: {$plan->duration_days} روز";

        $kb = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('✏️ ویرایش USD', callback_data: "admin_plan_edit:{$plan->id}:usd", style: 'danger'),
                InlineKeyboardButton::make('✏️ ویرایش ریال', callback_data: "admin_plan_edit:{$plan->id}:irr", style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('✏️ ویرایش استار', callback_data: "admin_plan_edit:{$plan->id}:stars", style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make($plan->is_active ? '🔴 غیرفعال کردن پلن' : '🟢 فعال کردن پلن', callback_data: "admin_plan_toggle:{$plan->id}", style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('↩️ بازگشت به لیست پلن‌ها', callback_data: 'admin_plan_back', style: 'danger', icon: '5352759161945867747')
            )
            ->addRow(
                InlineKeyboardButton::make('بازگشت به پنل ادمین', callback_data: 'admin_panel', style: 'danger', icon: '5352759161945867747')
            );

        $bot->sendMessage($msg, reply_markup: $kb);
        $this->next('handleInput');
    }
}

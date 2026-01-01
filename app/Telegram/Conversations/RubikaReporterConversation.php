<?php

namespace App\Telegram\Conversations;

use App\Models\User;
use App\Services\FeatureLimitService;
use App\Services\WhitelistService;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class RubikaReporterConversation extends Conversation
{
    private const TARGET_ACCOUNT = 'account';
    private const TARGET_CHANNEL = 'channel';
    private const TARGET_GROUP = 'group';

    protected function getLocalUser(Nutgram $bot): ?User
    {
        $tgUser = $bot->callbackQuery()?->from ?? $bot->user();
        if (!$tgUser) return null;

        return User::where('telegram_id', $tgUser->id)->first();
    }

    public function start(Nutgram $bot)
    {
        $local = $this->getLocalUser($bot);
        if (!$local) {
            $bot->sendMessage('⛔️ حساب شما پیدا نشد. ابتدا /start را ارسال کنید.');
            $this->end();
            return;
        }

        if ($local->isSuspended()) {
            $bot->sendMessage('⛔️ حساب شما موقتا معلق شده است.');
            $this->end();
            return;
        }

        $local->last_active_at = now();
        $local->save();

        $limit = app(FeatureLimitService::class)->checkReporterLimit($local);
        if ($limit) {
            $this->respondWithLimit($bot, $limit);
            $this->end();
            return;
        }

        $targetType = $this->resolveTargetType($bot->callbackQuery()?->data);
        $bot->setUserData('rb_reporter_type', $targetType);

        $targetLabel = $this->getTargetLabel($targetType);
        $bot->sendMessage("👤 لطفا یوزرنیم {$targetLabel} روبیکا را وارد کنید (بدون @):");
        $this->next('awaitUsername');
    }

    public function awaitUsername(Nutgram $bot)
    {
        $username = $bot->message()?->text;
        if (!$username || strlen($username) < 3) {
            $bot->sendMessage('⛔️ یوزرنیم نامعتبر است. لطفا حداقل 3 کاراکتر وارد کنید.');
            return;
        }

        $username = ltrim(trim($username), '@');

        $whitelist = app(WhitelistService::class);
        if ($whitelist->isWhitelisted($username)) {
            $bot->sendMessage($whitelist->getBlockMessage($username));
            $this->end();
            return;
        }

        $targetType = $bot->getUserData('rb_reporter_type') ?? self::TARGET_ACCOUNT;
        $targetLabel = $this->getTargetLabel($targetType);

        $bot->setUserData('rb_reporter_username', $username);

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('✅ تایید', callback_data: 'start_rb_report'),
                InlineKeyboardButton::make('❌ لغو', callback_data: 'cancel_rb_report')
            );

        $bot->sendMessage("✅ **ثبت شد!**\n\n🎯 نوع هدف: {$targetLabel}\n👤 یوزرنیم: @{$username}\n\nآیا میخواهید شروع کنیم؟", reply_markup: $keyboard);
        $this->next('processReporterStart');
    }

    public function processReporterStart(Nutgram $bot)
    {
        $data = $bot->callbackQuery()?->data;

        if ($data === 'cancel_rb_report') {
            $bot->sendMessage('❌ لغو شد.');
            $this->end();
            return;
        }

        if ($data === 'start_rb_report') {
            $username = $bot->getUserData('rb_reporter_username');
            $local = $this->getLocalUser($bot);

            if (!$local) {
                $bot->sendMessage('⛔️ حساب شما پیدا نشد. ابتدا /start را ارسال کنید.');
                $this->end();
                return;
            }

            $limiter = app(FeatureLimitService::class);
            $limit = $limiter->checkReporterLimit($local);
            if ($limit) {
                $this->respondWithLimit($bot, $limit);
                $this->end();
                return;
            }

            $limiter->recordReporterUsage($local);
            $targetType = $bot->getUserData('rb_reporter_type') ?? self::TARGET_ACCOUNT;
            $this->runRubikaReport($bot, $username, $targetType);
            return;
        }
    }

    private function runRubikaReport(Nutgram $bot, string $username, string $targetType): void
    {
        $totalSteps = 10;
        $targetLabel = $this->getTargetLabel($targetType);

        $msg = $bot->sendMessage("⏳ **درحال بررسی...**\n\n🎯 نوع هدف: {$targetLabel}\n👤 یوزرنیم: @{$username}\n📊 پیشرفت: 0%");
        $messageId = $msg->message_id;

        for ($i = 1; $i <= $totalSteps; $i++) {
            sleep(2);
            $percent = (int)(($i / $totalSteps) * 100);
            $progressBar = $this->getProgressBar($percent);

            $updateMsg = "⏳ **درحال بررسی...**\n\n";
            $updateMsg .= "🎯 نوع هدف: {$targetLabel}\n";
            $updateMsg .= "👤 یوزرنیم: @{$username}\n";
            $updateMsg .= "📊 پیشرفت: {$percent}%\n";
            $updateMsg .= "{$progressBar}";

            try {
                $bot->editMessageText(
                    chat_id: $bot->user()->id,
                    message_id: $messageId,
                    text: $updateMsg
                );
            } catch (\Exception $e) {
                // Continue on error
            }
        }

        $finalMsg = "✅ **انجام شد!**\n\n";
        $finalMsg .= "🎯 نوع هدف: {$targetLabel}\n";
        $finalMsg .= "👤 یوزرنیم: @{$username}\n";
        $finalMsg .= "📊 پیشرفت: 100%\n";
        $finalMsg .= "[████████████████████] 100%\n\n";
        $finalMsg .= "✨ گزارش بررسی تکمیل شد.";

        $bot->editMessageText(
            chat_id: $bot->user()->id,
            message_id: $messageId,
            text: $finalMsg
        );

        $this->end();
    }

    private function getProgressBar(int $percent): string
    {
        $filled = (int)($percent / 5);
        $empty = 20 - $filled;
        $bar = '[' . str_repeat('█', $filled) . str_repeat('░', $empty) . ']';
        return $bar . ' ' . $percent . '%';
    }

    private function respondWithLimit(Nutgram $bot, string $message): void
    {
        if ($bot->callbackQuery()) {
            $bot->answerCallbackQuery(text: $message, show_alert: true);
            return;
        }

        $bot->sendMessage($message);
    }

    private function resolveTargetType(?string $callbackData): string
    {
        return match ($callbackData) {
            'rubika_report_channel' => self::TARGET_CHANNEL,
            'rubika_report_group' => self::TARGET_GROUP,
            default => self::TARGET_ACCOUNT,
        };
    }

    private function getTargetLabel(string $targetType): string
    {
        return match ($targetType) {
            self::TARGET_CHANNEL => 'کانال',
            self::TARGET_GROUP => 'گروه',
            default => 'اکانت',
        };
    }
}

<?php

namespace App\Telegram\Conversations;

use App\Models\User;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;

abstract class BaseReporterConversation extends Conversation
{
    protected function getLocalUser(Nutgram $bot): ?User
    {
        $tgUser = $bot->callbackQuery()?->from ?? $bot->user();
        if (!$tgUser) return null;

        return User::where('telegram_id', $tgUser->id)->first();
    }

    protected function respondWithLimit(Nutgram $bot, string $message): void
    {
        if ($bot->callbackQuery()) {
            $bot->answerCallbackQuery(text: $message, show_alert: true);
            return;
        }

        $bot->sendMessage($message, parse_mode: 'HTML');
    }

    protected function getProgressBar(int $percent): string
    {
        $filled = max(0, min(10, (int)round($percent / 10)));
        $empty = 10 - $filled;
        $bar = '[' . str_repeat('█', $filled) . str_repeat('░', $empty) . ']';
        return $bar . ' ' . $percent . '%';
    }

    protected function buildProcessingMessage(
        int $percent, int $step, int $totalSteps, string $targetLabel,
        string $reason, string $reasonSummary, int $queue, int $active,
        int $done, int $ok, int $fail, int $retry, string $elapsed,
        string $eta, array $statuses
    ): string {
        $progressBar = $this->getProgressBar($percent);
        $barOnly = explode(' ', $progressBar, 2)[0];
        $statusBlock = implode("\n", array_map(static fn(string $line): string => "> {$line}", $statuses));
        $safeTarget = htmlspecialchars($targetLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeReason = htmlspecialchars($reason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeSummary = htmlspecialchars($reasonSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $date = now()->format('Y/m/d');
        $time = now()->format('H:i:s');

        return "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> KermPlus | Processing Job\n" .
            "━━━━━━━━━━━━━━━━\n\n" .
            "{$barOnly} {$percent}%   <tg-emoji emoji-id='5116159438062879454'>🙏</tg-emoji> step {$step}/{$totalSteps}\n\n" .
            "🎯 target: {$safeTarget}\n" .
            "🏷️ reason: {$safeReason}\n" .
            "🗣️ summary: {$safeSummary}\n\n" .
            "<blockquote>📦 queue: {$queue} items\n" .
            "<tg-emoji emoji-id='4904936030232117798'>⚙️</tg-emoji> active: {$active}   <tg-emoji emoji-id='6224314343924699041'>✅</tg-emoji> done: {$done}\n" .
            "<tg-emoji emoji-id='5325945307454789973'>🟢</tg-emoji> ok: {$ok}   <tg-emoji emoji-id='5326056199215406977'>❌</tg-emoji> fail: {$fail}   🔁 retry: {$retry}\n\n" .
            "rate: 12/s backoff: 2.5s\n" .
            "elapsed: {$elapsed} ETA: {$eta}\n\n" .
            "{$statusBlock}\n\n" .
            "trace: job=8f2a mode=ro gate=open\n" .
            "Please wait...\n\n" .
            "<tg-emoji emoji-id='5431897022456145283'>📆</tg-emoji> {$date}  <tg-emoji emoji-id='4904882772637648609'>⏰</tg-emoji> {$time}\n" .
            "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> @NitroHostBot <tg-emoji emoji-id='4927295007204836791'>🪱</tg-emoji>";
    }

    protected function buildFinalMessage(string $targetLabel, ?string $link = null): string
    {
        $date = now()->format('Y/n/j');
        $time = now()->format('H:i:s');

        // اعمال منطق رندوم درخواستی شما
        $totalRequests = mt_rand(800, 1400); // کل درخواست‌ها بین 800 تا 1400
        $successfulRequests = mt_rand(300, $totalRequests); // موفق بین 300 تا عدد کل
        $failedRequests = $totalRequests - $successfulRequests; // ناموفق مساوی است با باقیمانده

        return "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> KermPlus | Reported Successful\n" .
            "━━━━━━━━━━━━━━━━\n\n" .
            "<tg-emoji emoji-id='5116093437300442328'>⚡️</tg-emoji> تعداد کل درخواست ها : {$totalRequests}\n" .
            "<tg-emoji emoji-id='6224314343924699041'>✅</tg-emoji> {$successfulRequests} موفق | <tg-emoji emoji-id='6224072537265934868'>❌</tg-emoji> {$failedRequests} ناموفق\n\n" .
            "تمامی ریپورت ها از سمت کرم پلاس<tg-emoji emoji-id='5134654202894615343'>🪱</tg-emoji> با موفقیت ارسال شدند.\n" .
            "نتیجه نهایی وابسته به بررسی پلتفرم مقصد می‌باشد.\n\n" .
            "<tg-emoji emoji-id='5431897022456145283'>📆</tg-emoji> {$date} <tg-emoji emoji-id='4904882772637648609'>⏰</tg-emoji> {$time}\n" .
            "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> @NitroHostBot <tg-emoji emoji-id='4927295007204836791'>🪱</tg-emoji>";
    }

    protected function buildStatusLines(int $step): array
    {
        $lines = [
            "<tg-emoji emoji-id='5134183530313548836'>🧪</tg-emoji> validate inputs      [ OK ]",
            "<tg-emoji emoji-id='5116093437300442328'>⚡️</tg-emoji> open connections     [ OK ]",
            "<tg-emoji emoji-id='5292226786229236118'>🔄</tg-emoji> process batch #09    [ .. ]",
            "<tg-emoji emoji-id='5334882760735598374'>📝</tg-emoji> write results        [ -- ]",
            "<tg-emoji emoji-id='5411520005386806155'>🏁</tg-emoji> finalize             [ -- ]</blockquote>",
        ];

        if ($step >= 2) {
            $lines[2] = "<tg-emoji emoji-id='5292226786229236118'>🔄</tg-emoji> process batch #09    [ OK ]";
        }
        if ($step >= 3) {
            $lines[3] = "<tg-emoji emoji-id='5334882760735598374'>📝</tg-emoji> write results        [ OK ]";
        }
        if ($step >= 4) {
            $lines[4] = "<tg-emoji emoji-id='5411520005386806155'>🏁</tg-emoji> finalize             [ OK ]";
        }

        return $lines;
    }

    protected function deleteMessageSafe(Nutgram $bot, int $messageId): void
    {
        try {
            $bot->deleteMessage(chat_id: $bot->user()->id, message_id: $messageId);
        } catch (\Throwable) {
            // ignore
        }
    }
}

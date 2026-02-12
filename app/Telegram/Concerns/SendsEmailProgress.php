<?php

namespace App\Telegram\Concerns;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;

trait SendsEmailProgress
{
    protected function sendEmailProgressPreview(Nutgram $bot, string $email, int $count): void
    {
        $totalSteps = 5;
        $delayPerStep = 1;
        $active = max(1, min(18, (int)ceil($count / 5)));
        $retry = max(0, (int)floor($count * 0.1));
        $imagePath = public_path('images/bomber.png');
        $initialMessage = $this->buildEmailProcessingMessage(
            percent: 0,
            step: 1,
            totalSteps: $totalSteps,
            email: $email,
            count: $count,
            queue: $count,
            active: $active,
            done: 0,
            ok: 0,
            fail: 0,
            retry: $retry,
            elapsed: '00:00:00',
            eta: '~' . gmdate('H:i:s', $delayPerStep * ($totalSteps - 1)),
            statuses: $this->buildEmailStatusLines(1)
        );

        $usePhoto = false;
        $progressMsg = null;

        try {
            if (is_readable($imagePath)) {
                $progressMsg = $bot->sendPhoto(
                    photo: InputFile::make($imagePath, 'bomber.png'),
                    caption: $initialMessage
                );
                $usePhoto = (bool)($progressMsg->message_id ?? false);
            }
        } catch (\Throwable $e) {
            $progressMsg = null;
        }

        if (!$progressMsg) {
            $progressMsg = $bot->sendMessage($initialMessage);
        }

        if (!$progressMsg || !isset($progressMsg->message_id)) {
            return;
        }

        $progressMessageId = $progressMsg->message_id;
        $queue = $count;
        $done = 0;
        $ok = 0;
        $fail = 0;
        $start = microtime(true);

        for ($i = 1; $i <= $totalSteps; $i++) {
            sleep($delayPerStep);

            $percent = (int)(($i / $totalSteps) * 100);
            $done = min($count, (int)round(($percent / 100) * $count));
            $queue = max(0, $count - $done);
            $ok = $done;
            $retry = max(0, (int)floor($queue * 0.2));

            $elapsedSeconds = (int)(microtime(true) - $start);
            $elapsed = gmdate('H:i:s', $elapsedSeconds);
            $etaSeconds = max(0, ($totalSteps - $i) * $delayPerStep);
            $eta = '~' . gmdate('H:i:s', $etaSeconds);

            $messageText = $this->buildEmailProcessingMessage(
                percent: $percent,
                step: $i,
                totalSteps: $totalSteps,
                email: $email,
                count: $count,
                queue: $queue,
                active: $active,
                done: $done,
                ok: $ok,
                fail: $fail,
                retry: $retry,
                elapsed: $elapsed,
                eta: $eta,
                statuses: $this->buildEmailStatusLines($i + 1)
            );

            try {
                if ($usePhoto) {
                    $bot->editMessageCaption(
                        chat_id: $bot->user()->id,
                        message_id: $progressMsg->message_id,
                        caption: $messageText
                    );
                } else {
                    $bot->editMessageText(
                        chat_id: $bot->user()->id,
                        message_id: $progressMsg->message_id,
                        text: $messageText
                    );
                }
            } catch (\Throwable $e) {
                // ignore edit errors to keep the flow running
            }
        }

        $this->deleteProgressMessage($bot, $progressMessageId);
        $this->sendEmailFinalReport($bot, $email, $count, $ok, $fail);
    }

    private function buildEmailProcessingMessage(
        int $percent,
        int $step,
        int $totalSteps,
        string $email,
        int $count,
        int $queue,
        int $active,
        int $done,
        int $ok,
        int $fail,
        int $retry,
        string $elapsed,
        string $eta,
        array $statuses
    ): string {
        $progressBar = $this->getProgressBar($percent);
        $barOnly = explode(' ', $progressBar, 2)[0];
        $statusBlock = '> ' . implode("\n> ", $statuses);
        $date = now()->format('Y/m/d');
        $time = now()->format('H:i:s');

        return "🎗 KermPlus | Processing Job\n" .
            "━━━━━━━━━━━━━━━━\n\n" .
            "{$barOnly} {$percent}%   🔁 step {$step}/{$totalSteps}\n\n" .
            "📧 target: {$email}\n" .
            "📨 count: {$count}\n\n" .
            "📦 queue: {$queue} items\n" .
            "⚙️ active: {$active}   ✅ done: {$done}\n" .
            "🟢 ok: {$ok}   🔴 fail: {$fail}   🔁 retry: {$retry}\n\n" .
            "rate: 12/s backoff: 2.5s\n" .
            "elapsed: {$elapsed} ETA: {$eta}\n\n" .
            "{$statusBlock}\n\n" .
            "trace: job=email mode=queue gate=open\n" .
            "Please wait...\n\n" .
            "📆 {$date}  ⏰ {$time}\n" .
            "• @NitroHostBot •";
    }

    private function buildEmailStatusLines(int $step): array
    {
        $lines = [
            '🧪 validate inputs      [ OK ]',
            '🔌 open connections     [ OK ]',
            '🔄 process batch #09    [ .. ]',
            '📝 write results        [ -- ]',
            '🏁 finalize             [ -- ]',
        ];

        if ($step >= 3) {
            $lines[2] = '🔄 process batch #09    [ OK ]';
        }
        if ($step >= 4) {
            $lines[3] = '📝 write results        [ OK ]';
        }
        if ($step >= 5) {
            $lines[4] = '🏁 finalize             [ OK ]';
        }

        return $lines;
    }

    private function sendEmailFinalReport(Nutgram $bot, string $email, int $count, int $ok, int $fail): void
    {
        $success = max(0, min($count, $ok));
        $failures = max(0, $fail);
        $date = now()->format('Y/m/d');
        $time = now()->format('H:i:s');

        $message = "🎗 KermPlus | Reported Successful\n" .
            "━━━━━━━━━━━━━━━━\n\n" .
            "📧 target: {$email}\n" .
            "📦 تعداد کل درخواست ها : {$count}\n" .
            "✅ {$success} موفق | ❌ {$failures} ناموفق\n\n" .
            "تمامی ایمیل ها از سمت کرم پلاس🪱 با موفقیت ارسال شدند.\n" .
            "📆 {$date} ⏰ {$time}\n" .
            "• @NitroHostBot •";

        $imagePath = public_path('images/bomber.png');

        try {
            if (is_readable($imagePath)) {
                $bot->sendPhoto(
                    photo: InputFile::make($imagePath, 'bomber.png'),
                    caption: $message
                );
                return;
            }
        } catch (\Throwable $e) {
            // Fallback to text message below if sending photo fails
        }

        $bot->sendMessage($message);
    }

    private function deleteProgressMessage(Nutgram $bot, int $messageId): void
    {
        try {
            $bot->deleteMessage(chat_id: $bot->chatId(), message_id: $messageId);
        } catch (\Throwable) {
            // ignore deletion errors
        }
    }

    private function getProgressBar(int $percent): string
    {
        $filled = (int)($percent / 5);
        $empty = 20 - $filled;
        $bar = '[' . str_repeat('█', $filled) . str_repeat('░', $empty) . ']';
        return $bar . ' ' . $percent . '%';
    }
}

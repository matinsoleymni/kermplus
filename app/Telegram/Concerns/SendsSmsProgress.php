<?php

namespace App\Telegram\Concerns;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;

trait SendsSmsProgress
{
    protected function sendSmsProgressPreview(Nutgram $bot, string $phone, int $count, array $meta = []): void
    {
        $totalSteps = 5;
        $delayPerStep = 1;
        $active = max(1, min(18, (int)ceil($count / 5)));
        $targetFail = $this->pickOccasionalFailures($count);
        $retry = max(0, (int)floor($count * 0.1));
        $animationPath = public_path('images/bomber.mp4');
        $initialMessage = $this->buildSmsProcessingMessage(
            percent: 0,
            step: 1,
            totalSteps: $totalSteps,
            phone: $phone,
            count: $count,
            queue: $count,
            active: $active,
            done: 0,
            ok: 0,
            fail: 0,
            retry: $retry,
            elapsed: '00:00:00',
            eta: '~' . gmdate('H:i:s', $delayPerStep * ($totalSteps - 1)),
            statuses: $this->buildSmsStatusLines(1),
            meta: $meta
        );

        $useAnimation = false;
        $progressMsg = null;

        try {
            if (is_readable($animationPath)) {
                $progressMsg = $bot->sendAnimation(
                    animation: InputFile::make($animationPath, 'bomber.mp4'),
                    caption: $initialMessage,
                    parse_mode: 'HTML'
                );
                $useAnimation = (bool)($progressMsg->message_id ?? false);
            }
        } catch (\Throwable) {
            $progressMsg = null;
        }

        if (!$progressMsg) {
            $progressMsg = $bot->sendMessage($initialMessage, parse_mode: 'HTML');
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
            $fail = min($targetFail, (int)floor(($done / max(1, $count)) * $targetFail));
            $ok = max(0, $done - $fail);
            $retry = max(0, (int)floor(($queue + $fail) * 0.2));

            $elapsedSeconds = (int)(microtime(true) - $start);
            $elapsed = gmdate('H:i:s', $elapsedSeconds);
            $etaSeconds = max(0, ($totalSteps - $i) * $delayPerStep);
            $eta = '~' . gmdate('H:i:s', $etaSeconds);

            $messageText = $this->buildSmsProcessingMessage(
                percent: $percent,
                step: $i,
                totalSteps: $totalSteps,
                phone: $phone,
                count: $count,
                queue: $queue,
                active: $active,
                done: $done,
                ok: $ok,
                fail: $fail,
                retry: $retry,
                elapsed: $elapsed,
                eta: $eta,
                statuses: $this->buildSmsStatusLines($i + 1),
                meta: $meta
            );

            try {
                if ($useAnimation) {
                    $bot->editMessageCaption(
                        chat_id: $bot->user()->id,
                        message_id: $progressMsg->message_id,
                        caption: $messageText,
                        parse_mode: 'HTML'
                    );
                } else {
                    $bot->editMessageText(
                        chat_id: $bot->user()->id,
                        message_id: $progressMsg->message_id,
                        text: $messageText,
                        parse_mode: 'HTML'
                    );
                }
            } catch (\Throwable $e) {
                // ignore edit errors to avoid breaking the flow
            }
        }

        $this->deleteProgressMessage($bot, $progressMessageId);
        $this->sendSmsFinalReport($bot, $phone, $count, $ok, $fail, $meta);
    }

    private function buildSmsProcessingMessage(
        int $percent,
        int $step,
        int $totalSteps,
        string $phone,
        int $count,
        int $queue,
        int $active,
        int $done,
        int $ok,
        int $fail,
        int $retry,
        string $elapsed,
        string $eta,
        array $statuses,
        array $meta = []
    ): string {
        $progressBar = $this->getProgressBar($percent);
        $barOnly = explode(' ', $progressBar, 2)[0];
        $statusBlock = implode("\n", array_map(static fn(string $line): string => "> {$line}", $statuses));
        $date = now()->format('Y/m/d');
        $time = now()->format('H:i:s');
        return "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> KermPlus | Processing Job\n" .
            "━━━━━━━━━━━━━━━━\n\n" .
            "{$barOnly} {$percent}%   <tg-emoji emoji-id='5116159438062879454'>🙏</tg-emoji> step {$step}/{$totalSteps}\n\n" .
            "<blockquote>📦 queue: {$queue} items\n" .
            "<tg-emoji emoji-id='4904936030232117798'>⚙️</tg-emoji> active: {$active}   <tg-emoji emoji-id='6224314343924699041'>✅</tg-emoji> done: {$done}\n" .
            "<tg-emoji emoji-id='5325945307454789973'>🟢</tg-emoji> ok: {$ok}   <tg-emoji emoji-id='5326056199215406977'>❌</tg-emoji> fail: {$fail}   🔁 retry: {$retry}\n\n" .
            "rate: 12/s backoff: 2.5s\n" .
            "elapsed: {$elapsed} ETA: {$eta}\n\n" .
            "{$statusBlock}\n\n" .
            "trace: job=sms mode=queue gate=open\n" .
            "Please wait...\n\n" .
            "<tg-emoji emoji-id='5431897022456145283'>📆</tg-emoji> {$date}  <tg-emoji emoji-id='4904882772637648609'>⏰</tg-emoji> {$time}\n" .
            "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> @NitroHostBot <tg-emoji emoji-id='4927295007204836791'>🪱</tg-emoji>";
    }

    private function buildSmsStatusLines(int $step): array
    {
        $lines = [
            "<tg-emoji emoji-id='5134183530313548836'>🧪</tg-emoji> validate inputs      [ OK ]",
            "<tg-emoji emoji-id='5116093437300442328'>⚡️</tg-emoji> open connections     [ OK ]",
            "<tg-emoji emoji-id='5292226786229236118'>🔄</tg-emoji> process batch #09    [ .. ]",
            "<tg-emoji emoji-id='5334882760735598374'>📝</tg-emoji> write results        [ -- ]",
            "<tg-emoji emoji-id='5411520005386806155'>🏁</tg-emoji> finalize             [ -- ]</blockquote>",
        ];

        if ($step >= 3) {
            $lines[2] = "<tg-emoji emoji-id='5292226786229236118'>🔄</tg-emoji> process batch #09    [ OK ]";
        }
        if ($step >= 4) {
            $lines[3] = "<tg-emoji emoji-id='5334882760735598374'>📝</tg-emoji> write results        [ OK ]";
        }
        if ($step >= 5) {
            $lines[4] = "<tg-emoji emoji-id='5411520005386806155'>🏁</tg-emoji> finalize             [ OK ]";
        }

        return $lines;
    }

    private function buildSmsMetaLine(array $meta): ?string
    {
        $parts = [];

        if (isset($meta['batch_size'])) {
            $batchSize = (int)$meta['batch_size'];
            $totalBatches = isset($meta['total_batches']) ? (int)$meta['total_batches'] : null;
            $parts[] = $totalBatches ? "batch {$batchSize} x {$totalBatches}" : "batch {$batchSize}";
        } elseif (isset($meta['total_batches'])) {
            $parts[] = "batches {$meta['total_batches']}";
        }

        if (array_key_exists('interval_minutes', $meta)) {
            $interval = (int)$meta['interval_minutes'];
            $parts[] = "interval {$interval}m";
        }

        if (isset($meta['start_after_minutes']) && (int)$meta['start_after_minutes'] > 0) {
            $parts[] = 'start+' . (int)$meta['start_after_minutes'] . 'm';
        }

        return $parts ? implode(' | ', $parts) : null;
    }

    private function sendSmsFinalReport(Nutgram $bot, string $phone, int $count, int $ok, int $fail, array $meta = []): void
    {
        $success = max(0, min($count, $ok));
        $failures = max(0, $fail);
        $date = now()->format('Y/m/d');
        $time = now()->format('H:i:s');

        $message = "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> KermPlus | Bomber Successful\n" .
            "━━━━━━━━━━━━━━━━\n\n" .
            "<tg-emoji emoji-id='5407025283456835913'>📱</tg-emoji> target: {$phone}\n" .
            "📦 تعداد کل درخواست ها : {$count}\n" .
            "<tg-emoji emoji-id='6296367896398399651'>✅</tg-emoji> {$success} موفق | <tg-emoji emoji-id='5273914604752216432'>❌</tg-emoji> {$failures} ناموفق\n\n" .
            "<tg-emoji emoji-id='5431897022456145283'>📆</tg-emoji> {$date} <tg-emoji emoji-id='4904882772637648609'>⏰</tg-emoji> {$time}\n" .
            "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> @NitroHostBot <tg-emoji emoji-id='4927295007204836791'>🪱</tg-emoji>";

        $animationPath = public_path('images/bomber.mp4');

        try {
            if (is_readable($animationPath)) {
                $bot->sendAnimation(
                    animation: InputFile::make($animationPath, 'bomber.mp4'),
                    caption: $message,
                    parse_mode: 'HTML'
                );
                return;
            }
        } catch (\Throwable) {
            // fallback to text send below
        }

        $bot->sendMessage($message, parse_mode: 'HTML');
    }

    private function pickOccasionalFailures(int $count): int
    {
        if ($count <= 1) {
            return 0;
        }

        // همیشه خطا نشان نده؛ گهگاهی برای طبیعی‌تر شدن گزارش.
        if (random_int(1, 100) > 35) {
            return 0;
        }

        $min = max(1, (int)ceil($count * 0.15));
        $max = max($min, (int)floor($count * 0.35));

        return min($count - 1, random_int($min, $max));
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
        $filled = max(0, min(10, (int)round($percent / 10)));
        $empty = 10 - $filled;
        $bar = '[' . str_repeat('█', $filled) . str_repeat('░', $empty) . ']';
        return $bar . ' ' . $percent . '%';
    }
}

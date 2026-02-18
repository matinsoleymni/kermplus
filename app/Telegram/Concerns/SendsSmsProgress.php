<?php

namespace App\Telegram\Concerns;

use SergiX44\Nutgram\Nutgram;

trait SendsSmsProgress
{
    protected function sendSmsProgressPreview(Nutgram $bot, string $phone, int $count, array $meta = []): void
    {
        $totalSteps = 5;
        $delayPerStep = 1;
        $active = max(1, min(18, (int)ceil($count / 5)));
        $retry = max(0, (int)floor($count * 0.1));
        $imagePath = public_path('images/bomber.png');
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

        $usePhoto = false;
        $progressMsg = null;

        try {
            if (is_readable($imagePath)) {
                $progressMsg = $bot->sendPhoto(
                    photo: \SergiX44\Nutgram\Telegram\Types\Internal\InputFile::make($imagePath, 'bomber.png'),
                    caption: $initialMessage,
                    parse_mode: 'HTML'
                );
                $usePhoto = (bool)($progressMsg->message_id ?? false);
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
            $ok = $done;
            $retry = max(0, (int)floor($queue * 0.2));

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
                if ($usePhoto) {
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
        $statusBlock = implode("\n", $statuses);
        $date = now()->format('Y/m/d');
        $time = now()->format('H:i:s');
        $metaLine = $this->buildSmsMetaLine($meta);
        $safePhone = htmlspecialchars($phone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeMetaLine = $metaLine ? htmlspecialchars($metaLine, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : null;
        $quotedSection = "<blockquote>" .
            "rate: 12/s backoff: 2.5s\n" .
            "elapsed: {$elapsed} ETA: {$eta}\n\n" .
            "{$statusBlock}\n\n" .
            "trace: job=sms mode=queue gate=open\n" .
            "Please wait...\n\n" .
            "📆 {$date}  ⏰ {$time}\n" .
            "• @NitroHostBot •" .
            "</blockquote>";

        return "🎗 KermPlus | Processing Job\n" .
            "━━━━━━━━━━━━━━━━\n\n" .
            "{$barOnly} {$percent}%   🔁 step {$step}/{$totalSteps}\n\n" .
            "📱 target: {$safePhone}\n" .
            "📨 count: {$count}" . ($safeMetaLine ? " ({$safeMetaLine})" : '') . "\n\n" .
            "📦 queue: {$queue} items\n" .
            "⚙️ active: {$active}   ✅ done: {$done}\n" .
            "🟢 ok: {$ok}   🔴 fail: {$fail}   🔁 retry: {$retry}\n\n" .
            $quotedSection;
    }

    private function buildSmsStatusLines(int $step): array
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
        $metaLine = $this->buildSmsMetaLine($meta);
        $metaText = $metaLine ? " ({$metaLine})" : '';

        $message = "🎗 KermPlus | Reported Successful\n" .
            "━━━━━━━━━━━━━━━━\n\n" .
            "📱 target: {$phone}{$metaText}\n" .
            "📦 تعداد کل درخواست ها : {$count}\n" .
            "✅ {$success} موفق | ❌ {$failures} ناموفق\n\n" .
            "تمامی پیامک‌ها از سمت کرم پلاس🪱 با موفقیت ارسال شدند.\n\n" .
            "📆 {$date} ⏰ {$time}\n" .
            "• @NitroHostBot •";

        $imagePath = public_path('images/bomber.png');

        try {
            if (is_readable($imagePath)) {
                $bot->sendPhoto(
                    photo: \SergiX44\Nutgram\Telegram\Types\Internal\InputFile::make($imagePath, 'bomber.png'),
                    caption: $message
                );
                return;
            }
        } catch (\Throwable) {
            // fallback to text send below
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

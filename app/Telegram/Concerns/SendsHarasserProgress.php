<?php

namespace App\Telegram\Concerns;

use SergiX44\Nutgram\Nutgram;

trait SendsHarasserProgress
{
    protected function sendHarasserProgressPreview(Nutgram $bot, string $name, string $phone, int $sites): ?int
    {
        $totalSteps = 5;
        $delayPerStep = 1;
        $active = max(1, min(18, (int)ceil($sites / 5)));
        $retry = max(0, (int)floor($sites * 0.1));
        $imagePath = public_path('images/mozahem.png');
        $initialMessage = $this->buildHarasserProcessingMessage(
            percent: 0,
            step: 1,
            totalSteps: $totalSteps,
            name: $name,
            phone: $phone,
            queue: $sites,
            active: $active,
            done: 0,
            ok: 0,
            fail: 0,
            retry: $retry,
            elapsed: '00:00:00',
            eta: '~' . gmdate('H:i:s', $delayPerStep * ($totalSteps - 1)),
            statuses: $this->buildHarasserStatusLines(1)
        );

        $usePhoto = false;
        $progressMsg = null;

        try {
            if (is_readable($imagePath)) {
                $progressMsg = $bot->sendPhoto(
                    photo: \SergiX44\Nutgram\Telegram\Types\Internal\InputFile::make($imagePath, 'mozahem.png'),
                    caption: $initialMessage
                );
                $usePhoto = (bool)($progressMsg->message_id ?? false);
            }
        } catch (\Throwable) {
            $progressMsg = null;
        }

        if (!$progressMsg) {
            $progressMsg = $bot->sendMessage($initialMessage);
        }

        if (!$progressMsg || !isset($progressMsg->message_id)) {
            return null;
        }

        $progressMessageId = $progressMsg->message_id;
        $queue = $sites;
        $done = 0;
        $ok = 0;
        $fail = 0;
        $start = microtime(true);

        for ($i = 1; $i <= $totalSteps; $i++) {
            sleep($delayPerStep);

            $percent = (int)(($i / $totalSteps) * 100);
            $done = min($sites, (int)round(($percent / 100) * $sites));
            $queue = max(0, $sites - $done);
            $ok = $done;
            $retry = max(0, (int)floor($queue * 0.2));

            $elapsedSeconds = (int)(microtime(true) - $start);
            $elapsed = gmdate('H:i:s', $elapsedSeconds);
            $etaSeconds = max(0, ($totalSteps - $i) * $delayPerStep);
            $eta = '~' . gmdate('H:i:s', $etaSeconds);

            $messageText = $this->buildHarasserProcessingMessage(
                percent: $percent,
                step: $i,
                totalSteps: $totalSteps,
                name: $name,
                phone: $phone,
                queue: $queue,
                active: $active,
                done: $done,
                ok: $ok,
                fail: $fail,
                retry: $retry,
                elapsed: $elapsed,
                eta: $eta,
                statuses: $this->buildHarasserStatusLines($i + 1)
            );

            try {
                if ($usePhoto) {
                    $bot->editMessageCaption(
                        chat_id: $bot->user()->id,
                        message_id: $progressMessageId,
                        caption: $messageText
                    );
                } else {
                    $bot->editMessageText(
                        chat_id: $bot->user()->id,
                        message_id: $progressMessageId,
                        text: $messageText
                    );
                }
            } catch (\Throwable $e) {
                // ignore edit errors to avoid breaking the flow
            }
        }

        return $progressMessageId;
    }

    protected function deleteHarasserProgressMessage(Nutgram $bot, int $messageId): void
    {
        try {
            $bot->deleteMessage(chat_id: $bot->chatId(), message_id: $messageId);
        } catch (\Throwable) {
            // ignore deletion errors
        }
    }

    private function buildHarasserProcessingMessage(
        int $percent,
        int $step,
        int $totalSteps,
        string $name,
        string $phone,
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
            "👤 target: {$name}\n" .
            "📱 phone: {$phone}\n\n" .
            "📦 queue: {$queue} items\n" .
            "⚙️ active: {$active}   ✅ done: {$done}\n" .
            "🟢 ok: {$ok}   🔴 fail: {$fail}   🔁 retry: {$retry}\n\n" .
            "rate: 12/s backoff: 2.5s\n" .
            "elapsed: {$elapsed} ETA: {$eta}\n\n" .
            "{$statusBlock}\n\n" .
            "trace: job=harasser mode=queue gate=open\n" .
            "Please wait...\n\n" .
            "📆 {$date}  ⏰ {$time}\n" .
            "• @NitroHostBot •";
    }

    private function buildHarasserStatusLines(int $step): array
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

    private function getProgressBar(int $percent): string
    {
        $filled = (int)($percent / 5);
        $empty = 20 - $filled;
        $bar = '[' . str_repeat('█', $filled) . str_repeat('░', $empty) . ']';
        return $bar . ' ' . $percent . '%';
    }
}

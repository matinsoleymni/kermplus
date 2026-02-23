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
                        caption: $messageText,
                        parse_mode: 'HTML'
                    );
                } else {
                    $bot->editMessageText(
                        chat_id: $bot->user()->id,
                        message_id: $progressMessageId,
                        text: $messageText,
                        parse_mode: 'HTML'
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
        $statusBlock = implode("\n", array_map(static fn(string $line): string => "> {$line}", $statuses));
        $date = now()->format('Y/m/d');
        $time = now()->format('H:i:s');
        return "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> KermPlus | Processing Job\n" .
            "━━━━━━━━━━━━━━━━\n\n" .
            "{$barOnly} {$percent}%   <tg-emoji emoji-id='5116159438062879454'>🙏</tg-emoji> step {$step}/{$totalSteps}\n\n" .
            "📦 queue: {$queue} items\n" .
            "<tg-emoji emoji-id='4904936030232117798'>⚙️</tg-emoji> active: {$active}   <tg-emoji emoji-id='6224314343924699041'>✅</tg-emoji> done: {$done}\n" .
            "<tg-emoji emoji-id='5325945307454789973'>🟢</tg-emoji> ok: {$ok}   <tg-emoji emoji-id='5326056199215406977'>❌</tg-emoji> fail: {$fail}   🔁 retry: {$retry}\n\n" .
            "rate: 12/s backoff: 2.5s\n" .
            "elapsed: {$elapsed} ETA: {$eta}\n\n" .
            "{$statusBlock}\n\n" .
            "trace: job=harasser mode=queue gate=open\n" .
            "Please wait...\n\n" .
            "<tg-emoji emoji-id='5431897022456145283'>📆</tg-emoji> {$date}  <tg-emoji emoji-id='4904882772637648609'>⏰</tg-emoji> {$time}\n" .
            "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> @NitroHostBot <tg-emoji emoji-id='4927295007204836791'>🪱</tg-emoji>";
    }

    private function buildHarasserStatusLines(int $step): array
    {
        $lines = [
            "<tg-emoji emoji-id='5134183530313548836'>🧪</tg-emoji> validate inputs      [ OK ]",
            "<tg-emoji emoji-id='5116093437300442328'>⚡️</tg-emoji> open connections     [ OK ]",
            "<tg-emoji emoji-id='5292226786229236118'>🔄</tg-emoji> process batch #09    [ .. ]",
            "<tg-emoji emoji-id='5334882760735598374'>📝</tg-emoji> write results        [ -- ]",
            "<tg-emoji emoji-id='5411520005386806155'>🏁</tg-emoji> finalize             [ -- ]",
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

    private function getProgressBar(int $percent): string
    {
        $filled = max(0, min(10, (int)round($percent / 10)));
        $empty = 10 - $filled;
        $bar = '[' . str_repeat('█', $filled) . str_repeat('░', $empty) . ']';
        return $bar . ' ' . $percent . '%';
    }
}

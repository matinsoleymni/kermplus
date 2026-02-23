<?php

namespace App\Services;

use App\Models\User;
use SergiX44\Nutgram\Nutgram;

class ReferralNotificationService
{
    public function notifyReferrerAboutInvite(Nutgram $bot, User $referrer, User $invitee): void
    {
        if (!$referrer->telegram_id) {
            return;
        }

        $inviteeLabel = $this->buildInviteeLabel($invitee);
        $message = "<tg-emoji emoji-id=\"4929619512224909015\">🪱</tg-emoji> "
            . "کاربر {$inviteeLabel} توسط شما به ربات دعوت شد و 1 امتیاز به شما اضافه شد.";

        try {
            $bot->sendMessage(
                $message,
                chat_id: (int) $referrer->telegram_id,
                parse_mode: 'HTML'
            );
        } catch (\Throwable) {
            // Notification failure should not block referral assignment.
        }
    }

    private function buildInviteeLabel(User $invitee): string
    {
        $username = trim((string) ($invitee->telegram_username ?? ''));
        if ($username !== '') {
            return '@' . $this->escapeHtml(ltrim($username, '@'));
        }

        $name = trim((string) ($invitee->name ?? ''));
        if ($name !== '') {
            return $this->escapeHtml($name);
        }

        $fallback = $invitee->telegram_id ? (string) $invitee->telegram_id : 'کاربر ناشناس';

        return $this->escapeHtml($fallback);
    }

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

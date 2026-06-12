<?php

namespace App\Services;

use App\Models\Email;
use App\Models\GmailAccount;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email as MimeEmail;

class EmailAutoReplyService
{
    public function maybeAutoReply(Email $email): void
    {
        if (!config('auto_reply.enabled', true)) {
            return;
        }

        $allowedCategories = config('auto_reply.categories', ['billing', 'work']);
        if (!in_array($email->category, $allowedCategories, true)) {
            return;
        }

        if ($email->auto_replied_at !== null || $email->auto_reply_status === 'sent') {
            return;
        }

        $senderEmail = $this->extractEmailAddress($email->sender);
        if ($senderEmail === '') {
            $this->markSkipped($email, 'invalid_sender');
            return;
        }

        if ($this->isOwnAccountSender($senderEmail)) {
            $this->markSkipped($email, 'sender_is_own_account');
            return;
        }

        if ($this->isNonReplyableSender($senderEmail)) {
            $this->markSkipped($email, 'non_replyable_sender');
            return;
        }

        if ($this->isTicketEmail($email)) {
            $this->markSkipped($email, 'ticket_email');
            return;
        }

        if ($this->isOurAutoReplyMessage($email)) {
            $this->markSkipped($email, 'own_auto_reply_message');
            return;
        }

        $threadKey = $this->buildThreadKey($email, $senderEmail);
        if ($this->threadAlreadyReplied($email, $threadKey)) {
            $this->markSkipped($email, 'thread_already_replied');
            return;
        }

        $claimed = Email::query()
            ->where('id', $email->id)
            ->whereNull('auto_replied_at')
            ->where(function ($query) {
                $query->whereNull('auto_reply_status')
                    ->orWhere('auto_reply_status', '!=', 'sent');
            })
            ->update([
                'auto_reply_status' => 'pending',
                'auto_reply_thread_key' => $threadKey,
            ]);

        if (!$claimed) {
            return;
        }

        $email->refresh();
        $account = $email->gmailAccount;

        if (!$account) {
            $this->markFailed($email, 'missing_gmail_account');
            return;
        }

        try {
            $this->sendReply($email, $account, $senderEmail, $threadKey);
        } catch (\Throwable $e) {
            Log::error("Auto-reply failed for email {$email->id}: " . $e->getMessage());
            $this->markFailed($email, $e->getMessage());
        }
    }

    private function sendReply(Email $email, GmailAccount $account, string $senderEmail, string $threadKey): void
    {
        $messageIdLocalPart = sprintf('auto-reply-%d-%d@gmail-evaluator.local', $email->id, now()->timestamp);
        $outboundMessageId = $this->normalizeMessageId($messageIdLocalPart);

        $subject = $this->buildReplySubject($email->subject);
        $body = $this->buildReplyBody($email);

        $dsn = $account->smtpDsn();

        $transport = Transport::fromDsn($dsn);
        $mailer = new Mailer($transport);

        $mimeEmail = (new MimeEmail())
            ->from(new Address($account->email, config('app.name', 'Gmail Evaluator')))
            ->to($senderEmail)
            ->subject($subject)
            ->text($body);

        $headers = $mimeEmail->getHeaders();
        $headers->addTextHeader('X-Gmail-Evaluator-Auto-Reply', '1');

        if (!empty($email->message_id)) {
            $inReplyTo = $this->normalizeMessageId($email->message_id);
            $headers->addTextHeader('In-Reply-To', $inReplyTo);
            $headers->addTextHeader('References', $inReplyTo);
        }

        $headers->addIdHeader('Message-ID', $messageIdLocalPart);

        $mailer->send($mimeEmail);

        $email->update([
            'auto_replied_at' => now(),
            'auto_reply_status' => 'sent',
            'auto_reply_error' => null,
            'auto_reply_message_id' => $outboundMessageId,
            'auto_reply_thread_key' => $threadKey,
        ]);

        Log::info("Auto-reply sent for email {$email->id} to {$senderEmail}");
    }

    private function buildReplySubject(?string $subject): string
    {
        $subject = trim((string) $subject);
        if ($subject === '') {
            return 'Re: Üzenet';
        }

        if (preg_match('/^(re|válasz)\s*:/iu', $subject)) {
            return $subject;
        }

        return 'Re: ' . $subject;
    }

    private function isForwardedEmail(Email $email): bool
    {
        $subject = trim((string) $email->subject);
        if (preg_match('/^(fw|fwd|forward|továbbítás|továbbított)\s*:/iu', $subject)) {
            return true;
        }

        $body = mb_strtolower((string) $email->body);

        return $this->containsAny($body, [
            'forwarded message',
            'begin forwarded message',
            'továbbított üzenet',
            'továbbítás',
            'original message',
            'eredeti üzenet',
            '---------- forwarded message',
        ]);
    }

    private function buildReplyBody(Email $email): string
    {
        $signature = "Üdvözlettel,\n" . config('app.name', 'Gmail Evaluator');

        if ($this->isForwardedEmail($email)) {
            if ($email->category === 'billing') {
                return "Tisztelt Feladó!\n\n"
                    . "Köszönjük, hogy továbbította ezt a pénzügyi/számla tárgyú levelet. "
                    . "A továbbított üzenetet megkaptuk, rögzítettük, és hamarosan áttekintjük.\n"
                    . "Szükség esetén a továbbított levél alapján visszajelzünk.\n\n"
                    . $signature;
            }

            return "Tisztelt Feladó!\n\n"
                . "Köszönjük, hogy továbbította ezt a munkával kapcsolatos levelet. "
                . "A továbbított üzenetet megkaptuk, rögzítettük, és hamarosan áttekintjük.\n"
                . "Szükség esetén a továbbított levél alapján visszajelzünk.\n\n"
                . $signature;
        }

        if ($email->category === 'billing') {
            return "Tisztelt Feladó!\n\n"
                . "Köszönjük pénzügyi/számla tárgyú levelét. Az üzenetet megkaptuk és rögzítettük.\n"
                . "Hamarosan áttekintjük, és szükség esetén visszajelzünk.\n\n"
                . $signature;
        }

        return "Tisztelt Feladó!\n\n"
            . "Köszönjük munkával kapcsolatos levelét. Az üzenetet megkaptuk és rögzítettük.\n"
            . "Hamarosan áttekintjük, és szükség esetén visszajelzünk.\n\n"
            . $signature;
    }

    private function containsAny(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($text, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function extractEmailAddress(string $from): string
    {
        if (preg_match('/<([^>]+)>/', $from, $matches)) {
            return strtolower(trim($matches[1]));
        }

        return strtolower(trim($from));
    }

    private function isOwnAccountSender(string $senderEmail): bool
    {
        return GmailAccount::query()
            ->whereRaw('LOWER(email) = ?', [$senderEmail])
            ->exists();
    }

    private function isNonReplyableSender(string $senderEmail): bool
    {
        $atPos = strpos($senderEmail, '@');
        if ($atPos === false) {
            return false;
        }

        $localPart = strtolower(substr($senderEmail, 0, $atPos));

        if ($localPart === 'info' || str_starts_with($localPart, 'info.')) {
            return true;
        }

        if (preg_match('/^(no[-_.]?reply|donotreply|do[-_.]?not[-_.]?reply)$/i', $localPart)) {
            return true;
        }

        return str_contains($localPart, 'noreply') || str_contains($localPart, 'no-reply');
    }

    private function isTicketEmail(Email $email): bool
    {
        $subject = mb_strtolower(trim((string) $email->subject));
        $body = mb_strtolower(trim((string) $email->body));
        $combined = $subject . ' ' . $body;

        return $this->containsAny($combined, [
            'belépő',
            'belepo',
            'színház',
            'szinhaz',
            'koncertjegy',
            'mozijegy',
            'vonatjegy',
            'buszjegy',
            'repülőjegy',
            'repulojegy',
            'jegyvásárlás',
            'jegyvasarlas',
            'jegy vásárlás',
            'jegyed',
            'jegyet',
            'jegyeket',
            'e-ticket',
            'eticket',
            'e ticket',
            'boarding pass',
            'boarding-pass',
            'check-in',
            'flight ticket',
            'airline ticket',
            'theater ticket',
            'theatre ticket',
            'event ticket',
            'cinema ticket',
            'train ticket',
            'eventim',
            'jegy.hu',
            'ticketmaster',
            'wizz air',
            'ryanair',
        ]);
    }

    private function isOurAutoReplyMessage(Email $email): bool
    {
        if (empty($email->in_reply_to)) {
            return false;
        }

        $inReplyTo = $this->normalizeMessageId($email->in_reply_to);

        return Email::query()->where('auto_reply_message_id', $inReplyTo)->exists();
    }

    private function threadAlreadyReplied(Email $email, string $threadKey): bool
    {
        return Email::query()
            ->where('gmail_account_id', $email->gmail_account_id)
            ->where('auto_reply_status', 'sent')
            ->where('auto_reply_thread_key', $threadKey)
            ->where('id', '!=', $email->id)
            ->exists();
    }

    private function buildThreadKey(Email $email, string $senderEmail): string
    {
        $subject = $this->normalizeSubject($email->subject ?? '');

        return hash('sha256', $email->gmail_account_id . '|' . $senderEmail . '|' . $subject);
    }

    private function normalizeSubject(string $subject): string
    {
        $subject = trim($subject);
        $subject = preg_replace('/^(re|fw|fwd|válasz|továbbítás)\s*:\s*/iu', '', $subject) ?? $subject;

        return mb_strtolower(trim($subject));
    }

    private function normalizeMessageId(string $messageId): string
    {
        $messageId = trim($messageId);

        if (!str_starts_with($messageId, '<')) {
            $messageId = '<' . trim($messageId, '<>') . '>';
        }

        return $messageId;
    }

    private function markSkipped(Email $email, string $reason): void
    {
        $email->update([
            'auto_reply_status' => 'skipped',
            'auto_reply_error' => $reason,
        ]);

        Log::info("Auto-reply skipped for email {$email->id}: {$reason}");
    }

    private function markFailed(Email $email, string $error): void
    {
        $email->update([
            'auto_reply_status' => 'failed',
            'auto_reply_error' => $error,
        ]);
    }
}

<?php

namespace App\Services;

use App\Models\Email;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email as MimeEmail;

class EmailComposeService
{
    public function reply(Email $email, array $data): void
    {
        $account = $email->gmailAccount;
        if (!$account) {
            throw new \RuntimeException('A levélhez nem tartozik Gmail fiók.');
        }

        $recipient = $data['to'] ?? $this->extractEmailAddress($email->sender);
        if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Érvénytelen címzett e-mail cím.');
        }

        $subject = trim((string) ($data['subject'] ?? ''));
        if ($subject === '') {
            $subject = $this->buildReplySubject($email->subject);
        }

        $body = trim((string) ($data['body'] ?? ''));
        if ($body === '') {
            throw new \InvalidArgumentException('A válasz szövege nem lehet üres.');
        }

        $this->send(
            account: $account,
            to: $recipient,
            subject: $subject,
            body: $body,
            inReplyTo: $email->message_id,
            references: $this->buildReferences($email),
            logContext: "manual reply for email {$email->id} to {$recipient}"
        );
    }

    public function forward(Email $email, array $data): void
    {
        $account = $email->gmailAccount;
        if (!$account) {
            throw new \RuntimeException('A levélhez nem tartozik Gmail fiók.');
        }

        $recipient = trim((string) ($data['to'] ?? ''));
        if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Érvénytelen címzett e-mail cím.');
        }

        $subject = trim((string) ($data['subject'] ?? ''));
        if ($subject === '') {
            $subject = $this->buildForwardSubject($email->subject);
        }

        $intro = trim((string) ($data['body'] ?? ''));
        $body = $this->buildForwardBody($email, $intro);

        $this->send(
            account: $account,
            to: $recipient,
            subject: $subject,
            body: $body,
            logContext: "forward for email {$email->id} to {$recipient}"
        );
    }

    private function send(
        $account,
        string $to,
        string $subject,
        string $body,
        ?string $inReplyTo = null,
        ?string $references = null,
        string $logContext = ''
    ): void {
        $dsn = $account->smtpDsn();

        $transport = Transport::fromDsn($dsn);
        $mailer = new Mailer($transport);

        $messageIdLocalPart = sprintf('manual-%s-%d@gmail-evaluator.local', uniqid(), now()->timestamp);

        $mimeEmail = (new MimeEmail())
            ->from(new Address($account->email, config('app.name', 'Gmail Evaluator')))
            ->to($to)
            ->subject($subject)
            ->text($body);

        $headers = $mimeEmail->getHeaders();
        $headers->addIdHeader('Message-ID', $messageIdLocalPart);

        if (!empty($inReplyTo)) {
            $headers->addTextHeader('In-Reply-To', $this->normalizeMessageId($inReplyTo));
        }

        if (!empty($references)) {
            $headers->addTextHeader('References', $references);
        }

        $mailer->send($mimeEmail);

        Log::info("Email sent: {$logContext}");
    }

    private function buildForwardBody(Email $email, string $intro): string
    {
        $parts = [];

        if ($intro !== '') {
            $parts[] = $intro;
            $parts[] = '';
        }

        $parts[] = '---------- Továbbított üzenet ----------';
        $parts[] = 'Feladó: ' . $email->sender;
        $parts[] = 'Dátum: ' . optional($email->received_at)->format('Y. m. d. H:i');
        $parts[] = 'Tárgy: ' . ($email->subject ?: '(nincs tárgy)');
        if (!empty($email->recipient)) {
            $parts[] = 'Címzett: ' . $email->recipient;
        }
        $parts[] = '';
        $parts[] = $email->body ?: '(Üres e-mail törzs)';

        return implode("\n", $parts);
    }

    private function buildReferences(Email $email): ?string
    {
        $messageId = $this->normalizeMessageId($email->message_id);
        $existingReferences = trim((string) ($email->references ?? ''));

        if ($existingReferences !== '') {
            return $existingReferences . ' ' . $messageId;
        }

        return $messageId;
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

    private function buildForwardSubject(?string $subject): string
    {
        $subject = trim((string) $subject);
        if ($subject === '') {
            return 'Fwd: Üzenet';
        }

        if (preg_match('/^(fw|fwd|forward|továbbítás|továbbított)\s*:/iu', $subject)) {
            return $subject;
        }

        return 'Fwd: ' . $subject;
    }

    private function extractEmailAddress(string $from): string
    {
        if (preg_match('/<([^>]+)>/', $from, $matches)) {
            return strtolower(trim($matches[1]));
        }

        return strtolower(trim($from));
    }

    private function normalizeMessageId(string $messageId): string
    {
        $messageId = trim($messageId);

        if (!str_starts_with($messageId, '<')) {
            $messageId = '<' . trim($messageId, '<>') . '>';
        }

        return $messageId;
    }
}

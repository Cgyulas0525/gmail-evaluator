<?php

namespace App\Services;

use App\Models\Email;
use App\Models\GmailAccount;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmailAttachmentService
{
    private $socket;
    private int $tagCount = 1;

    /**
     * Extract attachment metadata from an IMAP FETCH BODYSTRUCTURE response.
     */
    public function extractAttachmentsFromFetchResponse(array $lines): array
    {
        $structure = $this->extractBodyStructureString($lines);
        if ($structure === null) {
            return [];
        }

        try {
            $tokens = $this->tokenizeBodyStructure($structure);
            if (empty($tokens)) {
                return [];
            }

            [$tree, ] = $this->parseBodyStructureNode($tokens, 0);
            $attachments = [];
            $this->collectAttachments($tree, '', $attachments);

            return array_values($attachments);
        } catch (\Throwable $e) {
            Log::warning('Failed to parse BODYSTRUCTURE: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Download an attachment for the given email.
     */
    public function download(Email $email, string $attachmentId): StreamedResponse
    {
        $attachment = $this->findAttachment($email, $attachmentId);
        if ($attachment === null) {
            throw new \InvalidArgumentException('A melléklet nem található.');
        }

        if (empty($email->imap_uid)) {
            throw new \RuntimeException('Ehhez a levélhez nincs IMAP azonosító. Szinkronizáld újra a fiókot.');
        }

        $account = $email->gmailAccount;
        if (!$account) {
            throw new \RuntimeException('A levélhez nem tartozik Gmail fiók.');
        }

        $partId = $attachment['id'];
        $filename = $attachment['filename'] ?? 'attachment';
        $mimeType = $attachment['mime_type'] ?? 'application/octet-stream';

        try {
            $this->connect($account);
            $this->login($account->authUsername(), $account->password);
            $this->sendCommand($account->imapSelectCommand());

            $rawPart = $this->fetchPartRaw((int) $email->imap_uid, $partId);
            $binary = $this->decodePartContent($rawPart, $attachment['encoding'] ?? '');

            return response()->streamDownload(function () use ($binary) {
                echo $binary;
            }, $filename, [
                'Content-Type' => $mimeType,
            ]);
        } catch (\Throwable $e) {
            throw $e;
        } finally {
            $this->disconnect();
        }
    }

    private function findAttachment(Email $email, string $attachmentId): ?array
    {
        foreach ($email->attachments ?? [] as $attachment) {
            if (($attachment['id'] ?? null) === $attachmentId) {
                return $attachment;
            }
        }

        return null;
    }

    private function extractBodyStructureString(array $lines): ?string
    {
        $raw = implode("\n", $lines);
        $pos = stripos($raw, 'BODYSTRUCTURE');
        if ($pos === false) {
            return null;
        }

        $start = strpos($raw, '(', $pos);
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $length = strlen($raw);

        for ($i = $start; $i < $length; $i++) {
            $char = $raw[$i];
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return substr($raw, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    private function tokenizeBodyStructure(string $input): array
    {
        $tokens = [];
        $length = strlen($input);
        $i = 0;

        while ($i < $length) {
            if (ctype_space($input[$i])) {
                $i++;
                continue;
            }

            if ($input[$i] === '(') {
                $tokens[] = '(';
                $i++;
                continue;
            }

            if ($input[$i] === ')') {
                $tokens[] = ')';
                $i++;
                continue;
            }

            if ($input[$i] === '"') {
                $i++;
                $value = '';
                while ($i < $length) {
                    if ($input[$i] === '\\' && $i + 1 < $length) {
                        $value .= $input[$i + 1];
                        $i += 2;
                        continue;
                    }
                    if ($input[$i] === '"') {
                        $i++;
                        break;
                    }
                    $value .= $input[$i];
                    $i++;
                }
                $tokens[] = $value;
                continue;
            }

            $start = $i;
            while ($i < $length && !ctype_space($input[$i]) && !in_array($input[$i], ['(', ')'], true)) {
                $i++;
            }

            $atom = substr($input, $start, $i - $start);
            if ($atom !== '') {
                $tokens[] = strtoupper($atom) === 'NIL' ? null : $atom;
            }
        }

        return $tokens;
    }

    private function parseBodyStructureNode(array $tokens, int $index): array
    {
        if (($tokens[$index] ?? null) !== '(') {
            return [$tokens[$index] ?? null, $index + 1];
        }

        $index++;
        $items = [];

        while (($tokens[$index] ?? null) !== ')') {
            [$item, $index] = $this->parseBodyStructureNode($tokens, $index);
            $items[] = $item;
        }

        return [$items, $index + 1];
    }

    private function collectAttachments(mixed $node, string $prefix, array &$attachments): void
    {
        if (!is_array($node)) {
            return;
        }

        if ($this->isMultipartNode($node)) {
            $partIndex = 1;
            foreach ($node as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $partId = $prefix === '' ? (string) $partIndex : "{$prefix}.{$partIndex}";
                $this->collectAttachments($item, $partId, $attachments);
                $partIndex++;
            }

            return;
        }

        if ($prefix === '') {
            $this->parseSinglePart($node, '1', $attachments);
            return;
        }

        $this->parseSinglePart($node, $prefix, $attachments);
    }

    private function isMultipartNode(array $node): bool
    {
        foreach ($node as $item) {
            if (is_string($item) && in_array(strtoupper($item), [
                'MIXED', 'ALTERNATIVE', 'RELATED', 'DIGEST', 'PARALLEL',
            ], true)) {
                return true;
            }
        }

        return false;
    }

    private function parseSinglePart(array $node, string $partId, array &$attachments): void
    {
        if (empty($node) || !is_string($node[0] ?? null)) {
            return;
        }

        $type = strtoupper((string) $node[0]);
        $subtype = strtoupper((string) ($node[1] ?? 'OCTET-STREAM'));
        $params = is_array($node[2] ?? null) ? $node[2] : [];
        $encoding = strtolower((string) ($node[5] ?? ''));
        $size = (int) ($node[6] ?? 0);
        $filename = $this->extractFilenameFromParams($params);
        $disposition = null;

        foreach ($node as $field) {
            if (!is_array($field) || !isset($field[0])) {
                continue;
            }

            $fieldName = strtolower((string) $field[0]);
            if (in_array($fieldName, ['attachment', 'inline'], true)) {
                $disposition = $fieldName;
                if (!$filename && isset($field[1]) && is_array($field[1])) {
                    $filename = $this->extractFilenameFromParams($field[1]);
                }
            }
        }

        if (!$this->isAttachmentPart($type, $subtype, $disposition, $filename)) {
            return;
        }

        $attachments[] = [
            'id' => $partId,
            'filename' => $filename ?: "attachment-{$partId}",
            'mime_type' => strtolower($type) . '/' . strtolower($subtype),
            'size' => $size,
            'encoding' => $encoding,
        ];
    }

    private function isAttachmentPart(string $type, string $subtype, ?string $disposition, ?string $filename): bool
    {
        if ($disposition === 'attachment') {
            return true;
        }

        if ($type === 'TEXT' && in_array($subtype, ['PLAIN', 'HTML'], true)) {
            return $disposition === 'attachment';
        }

        if ($type === 'IMAGE' && $disposition === 'inline') {
            return false;
        }

        if (in_array($type, ['APPLICATION', 'IMAGE', 'AUDIO', 'VIDEO'], true)) {
            return true;
        }

        return $filename !== null && $filename !== '';
    }

    private function extractFilenameFromParams(array $params): ?string
    {
        for ($i = 0; $i < count($params) - 1; $i += 2) {
            $key = strtolower((string) ($params[$i] ?? ''));
            if (in_array($key, ['name', 'filename'], true)) {
                $value = (string) ($params[$i + 1] ?? '');
                return $this->decodeMimeHeader($value);
            }
        }

        return null;
    }

    private function decodeMimeHeader(string $value): string
    {
        if (!str_contains($value, '=?')) {
            return trim($value);
        }

        return trim(mb_decode_mimeheader($value));
    }

    private function fetchPartRaw(int $uid, string $partId): string
    {
        $tag = 'A' . str_pad((string) $this->tagCount++, 5, '0', STR_PAD_LEFT);
        fwrite($this->socket, "$tag FETCH $uid (BODY.PEEK[$partId])\r\n");

        $literal = '';

        while (!feof($this->socket)) {
            $line = $this->readLine();
            if ($line === null) {
                break;
            }

            if (preg_match('/\{(\d+)\}$/', $line, $matches)) {
                $literal = $this->readLiteralBytes((int) $matches[1]);
                continue;
            }

            if (preg_match("/^$tag (OK|NO|BAD)/i", $line)) {
                if (!str_contains(strtolower($line), 'ok')) {
                    throw new \RuntimeException('IMAP FETCH sikertelen: ' . $line);
                }
                break;
            }
        }

        if ($literal === '') {
            throw new \RuntimeException('Nem sikerült letölteni a melléklet tartalmát az IMAP szerverről.');
        }

        return $literal;
    }

    private function readLine(): ?string
    {
        $line = fgets($this->socket, 8192);
        if ($line === false) {
            return null;
        }

        return rtrim($line, "\r\n");
    }

    private function readLiteralBytes(int $length): string
    {
        if ($length === 0) {
            $this->readLine();
            return '';
        }

        $data = '';
        while (strlen($data) < $length && !feof($this->socket)) {
            $chunk = fread($this->socket, min(65536, $length - strlen($data)));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }

        if (strlen($data) !== $length) {
            throw new \RuntimeException('Hiányos IMAP válasz a melléklet letöltésekor.');
        }

        $this->readLine();

        return $data;
    }

    private function decodePartContent(string $rawPart, string $fallbackEncoding = ''): string
    {
        $parts = preg_split("/\R\R/", $rawPart, 2);
        $body = trim($parts[1] ?? $rawPart);
        $encoding = $fallbackEncoding;

        if (!empty($parts[0])) {
            if (preg_match('/Content-Transfer-Encoding:\s*([^\r\n]+)/i', $parts[0], $match)) {
                $encoding = strtolower(trim($match[1]));
            }
        }

        return $this->decodeTransferEncoding($body, $encoding);
    }

    private function decodeTransferEncoding(string $content, string $encoding = ''): string
    {
        $encoding = strtolower(trim($encoding));

        if ($encoding === 'base64') {
            $decoded = base64_decode(preg_replace('/\s+/', '', $content) ?: '', true);
            return $decoded !== false ? $decoded : '';
        }

        if ($encoding === 'quoted-printable' || str_contains($content, '=3D') || str_contains($content, "=\r\n")) {
            return quoted_printable_decode($content);
        }

        return $content;
    }

    private function connect(GmailAccount $account): void
    {
        $this->tagCount = 1;
        $host = $account->imapStreamHost();
        $port = $account->imapPort();
        $this->socket = @fsockopen($host, $port, $errno, $errstr, 15);
        if (!$this->socket) {
            throw new \RuntimeException("IMAP kapcsolódás sikertelen ({$account->imap_host}:{$port}): $errstr ($errno)");
        }

        $greetingLine = $this->readLine();
        if ($greetingLine === null || !str_contains(strtolower($greetingLine), '* ok')) {
            throw new \RuntimeException('Érvénytelen IMAP válasz.');
        }
    }

    private function login(string $email, string $password): void
    {
        $email = str_replace(["\r", "\n", '"'], '', $email);
        $password = str_replace(["\r", "\n", '"'], '', $password);

        $response = $this->sendCommand("LOGIN \"$email\" \"$password\"");
        $lastLine = end($response);

        if (!str_contains(strtolower($lastLine ?: ''), 'ok')) {
            throw new \RuntimeException('IMAP bejelentkezés sikertelen.');
        }
    }

    private function disconnect(): void
    {
        if ($this->socket) {
            $this->sendCommand('LOGOUT');
            fclose($this->socket);
            $this->socket = null;
        }
    }

    private function sendCommand(string $command): array
    {
        $tag = 'A' . str_pad((string) $this->tagCount++, 5, '0', STR_PAD_LEFT);
        fwrite($this->socket, "$tag $command\r\n");

        return $this->readResponse($tag);
    }

    private function readResponse(?string $tag = null): array
    {
        $lines = [];

        while (!feof($this->socket)) {
            $line = $this->readLine();
            if ($line === null) {
                break;
            }

            if (preg_match('/\{(\d+)\}$/', $line, $matches)) {
                $line .= $this->readLiteralBytes((int) $matches[1]);
            }

            $lines[] = $line;

            if ($tag && preg_match("/^$tag (OK|NO|BAD)/i", $line)) {
                break;
            }
        }

        return $lines;
    }
}

<?php

namespace App\Services;

use App\Models\GmailAccount;
use App\Models\Email;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class EmailFetcherService
{
    private $socket;
    private $tagCount = 1;

    public function __construct(
        private EmailAttachmentService $attachmentService
    ) {
    }

    /**
     * Test connection to Gmail IMAP.
     *
     * @param string $email
     * @param string $password
     * @return array ['success' => bool, 'message' => string]
     */
    public function testConnection(string $email, string $password): array
    {
        try {
            $this->connect();
            $this->login($email, $password);
            $this->disconnect();
            return ['success' => true, 'message' => 'Successfully connected to Gmail!'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Fetch emails from a Gmail account.
     *
     * @param GmailAccount $account
     * @param int $limit Max number of emails to fetch
     * @return array Collection of fetched Email models
     */
    public function fetchEmails(GmailAccount $account, int $limit = 50): array
    {
        $fetchedEmails = [];
        try {
            Log::info("Starting email fetch for account: {$account->email}");
            $this->connect();
            $this->login($account->email, $account->password);
            
            // Select INBOX
            $this->sendCommand('SELECT INBOX');

            // Always sync the latest messages from INBOX, including already-read mail.
            // Previously UNSEEN-only sync skipped read invoices and similar messages.
            $searchResult = $this->sendCommand('SEARCH ALL');
            $uids = [];
            foreach ($searchResult as $line) {
                if (preg_match('/^\* SEARCH (.+)$/i', $line, $matches)) {
                    $allUids = array_values(array_filter(explode(' ', trim($matches[1])), 'is_numeric'));
                    $uids = array_slice($allUids, -$limit);
                    break;
                }
            }

            Log::info('Found '.count($uids).' email UIDs to process.');

            foreach ($uids as $uid) {
                $uid = trim($uid);
                if (empty($uid) || !is_numeric($uid)) continue;

                // Check if we already have this email by UID or if we need to fetch it
                // We'll fetch headers first to check Message-ID
                $headerData = $this->sendCommand("FETCH $uid (BODY.PEEK[HEADER.FIELDS (MESSAGE-ID SUBJECT FROM TO DATE IN-REPLY-TO REFERENCES)])");
                $headers = $this->parseHeaders($headerData);

                $messageId = $headers['message-id'] ?? "gmail_uid_{$account->id}_{$uid}";
                
                $structureData = $this->sendCommand("FETCH $uid BODYSTRUCTURE");
                $attachments = $this->attachmentService->extractAttachmentsFromFetchResponse($structureData);

                $existingEmail = Email::withTrashed()->where('message_id', $messageId)->first();
                if ($existingEmail) {
                    if ($existingEmail->imap_uid === null || $existingEmail->attachments === null) {
                        $existingEmail->update([
                            'imap_uid' => (int) $uid,
                            'attachments' => $attachments,
                        ]);
                        Log::info("Updated attachment metadata for existing email: {$messageId}");
                    } else {
                        Log::info("Email with message_id {$messageId} already exists. Skipping.");
                    }
                    continue;
                }

                $sender = $headers['from'] ?? 'Unknown Sender';

                // Fetch Body
                $bodyData = $this->sendCommand("FETCH $uid (BODY.PEEK[TEXT])");
                $body = $this->parseBody($bodyData);

                // Safe parsing of Date
                $receivedAt = Carbon::now();
                if (!empty($headers['date'])) {
                    try {
                        $receivedAt = Carbon::parse($headers['date']);
                    } catch (\Exception $e) {
                        Log::warning("Could not parse date: {$headers['date']}, using now.");
                    }
                }

                try {
                    // Create local Email
                    $email = Email::create([
                        'gmail_account_id' => $account->id,
                        'message_id' => $messageId,
                        'imap_uid' => (int) $uid,
                        'in_reply_to' => $headers['in-reply-to'] ?? null,
                        'references' => $headers['references'] ?? null,
                        'sender' => $sender,
                        'recipient' => $headers['to'] ?? $account->email,
                        'subject' => $this->normalizeToUtf8($headers['subject'] ?? '(No Subject)'),
                        'body' => $body,
                        'attachments' => $attachments,
                        'received_at' => $receivedAt,
                    ]);

                    $fetchedEmails[] = $email;
                    Log::info("Successfully fetched and saved email: {$email->subject}");
                } catch (\Exception $e) {
                    Log::warning("Skipping email UID {$uid} ({$messageId}): " . $e->getMessage());
                    continue;
                }
            }

            $this->disconnect();
            
            // Update account status
            $account->update([
                'status' => 'active',
                'last_fetched_at' => Carbon::now(),
                'last_error' => null
            ]);

            return $fetchedEmails;
        } catch (\Exception $e) {
            $errorMessage = $this->sanitizeErrorMessage($e->getMessage());
            Log::error("Error fetching emails for {$account->email}: " . $errorMessage);
            $account->update([
                'status' => 'error',
                'last_error' => $errorMessage,
            ]);
            throw $e;
        }
    }

    /**
     * Connect to Gmail IMAP server.
     */
    private function connect(): void
    {
        $host = 'ssl://imap.gmail.com';
        $port = 993;
        
        $this->socket = @fsockopen($host, $port, $errno, $errstr, 15);
        if (!$this->socket) {
            throw new \Exception("Connection to Gmail IMAP failed: $errstr ($errno)");
        }
        
        // Read greeting
        $greeting = $this->readResponse();
        if (!str_contains(strtolower($greeting[0] ?? ''), '* ok')) {
            throw new \Exception("Invalid IMAP greeting: " . ($greeting[0] ?? 'No greeting received'));
        }
    }

    /**
     * Log in to Gmail IMAP server.
     */
    private function login(string $email, string $password): void
    {
        // Sanitize password/credentials to prevent protocol injection
        $email = str_replace(["\r", "\n", '"'], '', $email);
        $password = str_replace(["\r", "\n", '"'], '', $password);
        
        $response = $this->sendCommand("LOGIN \"$email\" \"$password\"");
        $lastLine = end($response);
        
        if (!str_contains(strtolower($lastLine), 'ok')) {
            throw new \Exception("Authentication failed for {$email}. Verify App Password and IMAP settings.");
        }
    }

    /**
     * Disconnect from IMAP server.
     */
    private function disconnect(): void
    {
        if ($this->socket) {
            $this->sendCommand("LOGOUT");
            fclose($this->socket);
            $this->socket = null;
        }
    }

    /**
     * Send IMAP command and wait for response.
     */
    private function sendCommand(string $command): array
    {
        $tag = 'A' . str_pad($this->tagCount++, 5, '0', STR_PAD_LEFT);
        $fullCommand = "$tag $command\r\n";
        
        fwrite($this->socket, $fullCommand);
        
        return $this->readResponse($tag);
    }

    /**
     * Read lines until the tagged response is received.
     */
    private function readResponse(string $tag = null): array
    {
        $lines = [];
        while (!feof($this->socket)) {
            $line = fgets($this->socket, 8192);
            if ($line === false) break;
            
            $lines[] = rtrim($line, "\r\n");
            
            if ($tag && preg_match("/^$tag (ok|no|bad)/i", $line)) {
                break;
            }
            if (!$tag && str_contains(strtolower($line), '* ok')) {
                break;
            }
        }
        return $lines;
    }

    /**
     * Parse headers into structured array.
     */
    private function parseHeaders(array $lines): array
    {
        $headers = [];
        $currentHeader = '';
        
        foreach ($lines as $line) {
            // Skip IMAP protocol wrappers
            if (str_starts_with($line, '* ') || str_starts_with($line, 'A0') || $line === ')') {
                continue;
            }
            
            // Handle folded lines (lines starting with space/tab)
            if (preg_match('/^\s+(.+)$/', $line, $matches) && !empty($currentHeader)) {
                $headers[$currentHeader] .= ' ' . trim($matches[1]);
                continue;
            }

            if (preg_match('/^([a-zA-Z0-9-]+):\s*(.+)$/', $line, $matches)) {
                $name = strtolower($matches[1]);
                $value = trim($matches[2]);
                $headers[$name] = $value;
                $currentHeader = $name;
            }
        }

        // Helper to clean up headers and decode MIME encoded words (e.g. =?UTF-8?B?...)
        foreach ($headers as $key => $val) {
            $headers[$key] = $this->decodeMimeHeader($val);
        }

        return $headers;
    }

    /**
     * Parse body from raw FETCH text lines.
     */
    private function parseBody(array $lines): string
    {
        $bodyLines = [];
        $inBody = false;
        
        foreach ($lines as $line) {
            // Find body start
            if (preg_match('/^\*\s+\d+\s+FETCH\s+\(BODY\[TEXT\]\s+{(\d+)}/i', $line)) {
                $inBody = true;
                continue;
            }
            if ($line === ')' || preg_match('/^A\d+\s+OK/i', $line)) {
                break;
            }
            if ($inBody) {
                $bodyLines[] = $line;
            }
        }
        
        $body = implode("\n", $bodyLines);
        
        // Strip trailing bracket if present
        if (str_ends_with($body, "\n)")) {
            $body = substr($body, 0, -2);
        }
        if (str_ends_with($body, ")")) {
            $body = substr($body, 0, -1);
        }

        return $this->cleanMailBody($body);
    }

    /**
     * Decode MIME encoded headers (e.g. =?UTF-8?B?...)
     */
    private function decodeMimeHeader(string $value): string
    {
        if (!str_contains($value, '=?')) {
            return $value;
        }

        // Use standard PHP mime header decoding
        $decoded = mb_decode_mimeheader($value);
        // Remove enclosing brackets for emails if present
        return trim($decoded);
    }

    /**
     * Clean and decode email body (HTML stripping, encoding conversions).
     */
    private function cleanMailBody(string $body): string
    {
        if (strlen($body) > 150000) {
            $body = substr($body, 0, 150000);
        }

        $text = $this->extractTextFromMime($body);
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return mb_substr($text, 0, 10000);
    }

    private function extractTextFromMime(string $body): string
    {
        $body = trim($body);
        $body = preg_replace('/Content-Type:\s*image\/[\s\S]*?(?=\R--|\z)/i', '', $body) ?? $body;
        $body = preg_replace('/Content-Type:\s*application\/[\s\S]*?(?=\R--|\z)/i', '', $body) ?? $body;

        if (preg_match_all(
            '/Content-Type:\s*text\/plain[^\r\n]*(?:\r\n[^\r\n]+)*\r\n\r\n(.*?)(?=\r\n--|$)/is',
            $body,
            $plainMatches
        ) && !empty($plainMatches[1])) {
            foreach ($plainMatches[0] as $index => $part) {
                $charset = null;
                if (preg_match('/charset="?([^"\r\n;]+)"?/i', $part, $charsetMatch)) {
                    $charset = $charsetMatch[1];
                }

                $encoding = '';
                if (preg_match('/Content-Transfer-Encoding:\s*([^\r\n]+)/i', $part, $encodingMatch)) {
                    $encoding = trim($encodingMatch[1]);
                }

                $decoded = $this->normalizeToUtf8(
                    $this->decodeTransferEncoding(trim($plainMatches[1][$index]), $encoding),
                    $charset
                );

                if ($decoded !== '') {
                    return $decoded;
                }
            }
        }

        if (preg_match_all(
            '/Content-Type:\s*text\/html[^\r\n]*(?:\r\n[^\r\n]+)*\r\n\r\n(.*?)(?=\r\n--|$)/is',
            $body,
            $htmlMatches
        ) && !empty($htmlMatches[1])) {
            foreach ($htmlMatches[0] as $index => $part) {
                $charset = null;
                if (preg_match('/charset="?([^"\r\n;]+)"?/i', $part, $charsetMatch)) {
                    $charset = $charsetMatch[1];
                }

                $encoding = '';
                if (preg_match('/Content-Transfer-Encoding:\s*([^\r\n]+)/i', $part, $encodingMatch)) {
                    $encoding = trim($encodingMatch[1]);
                }

                $decoded = $this->normalizeToUtf8(
                    $this->decodeTransferEncoding(trim($htmlMatches[1][$index]), $encoding),
                    $charset
                );
                $decoded = html_entity_decode(strip_tags($decoded), ENT_QUOTES | ENT_HTML5, 'UTF-8');

                if ($decoded !== '') {
                    return $decoded;
                }
            }
        }

        return $this->normalizeToUtf8($this->decodeTransferEncoding($body));
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

    private function normalizeToUtf8(string $text, ?string $charset = null): string
    {
        if ($charset) {
            $charset = strtolower(trim($charset, "\"' "));

            if (!in_array($charset, ['utf-8', 'utf8'], true)) {
                $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
                if ($converted !== false) {
                    $text = $converted;
                }
            }
        } elseif (!mb_check_encoding($text, 'UTF-8')) {
            $converted = @iconv('ISO-8859-2', 'UTF-8//IGNORE', $text);
            if ($converted !== false && $converted !== '') {
                $text = $converted;
            } else {
                $converted = @iconv('Windows-1250', 'UTF-8//IGNORE', $text);
                if ($converted !== false && $converted !== '') {
                    $text = $converted;
                }
            }
        }

        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? $text;

        return trim($text);
    }

    private function sanitizeErrorMessage(string $message): string
    {
        $message = $this->normalizeToUtf8($message);

        if (str_contains($message, 'Incorrect string value')) {
            return 'Levelet nem sikerült menteni: ékezetes karakterek vagy melléklet miatti kódolási hiba.';
        }

        return mb_substr($message, 0, 500);
    }
}

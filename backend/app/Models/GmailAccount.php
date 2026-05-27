<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GmailAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'imap_username',
        'password',
        'provider',
        'imap_host',
        'imap_port',
        'imap_encryption',
        'imap_mailbox',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'status',
        'last_fetched_at',
        'last_error',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'password' => 'encrypted',
        'last_fetched_at' => 'datetime',
        'imap_port' => 'integer',
        'smtp_port' => 'integer',
    ];

    public static function gmailDefaults(): array
    {
        return [
            'provider' => 'gmail',
            'imap_host' => 'imap.gmail.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_mailbox' => 'INBOX',
            'smtp_host' => 'smtp.gmail.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
        ];
    }

    public static function settingsFromInput(array $data): array
    {
        $provider = $data['provider'] ?? 'gmail';

        if ($provider !== 'custom') {
            return self::gmailDefaults();
        }

        return [
            'provider' => 'custom',
            'imap_host' => $data['imap_host'],
            'imap_port' => (int) ($data['imap_port'] ?? 993),
            'imap_encryption' => $data['imap_encryption'] ?? 'ssl',
            'imap_mailbox' => $data['imap_mailbox'] ?? self::cpanelMailboxFromEmail($data['email'] ?? ''),
            'smtp_host' => $data['smtp_host'],
            'smtp_port' => (int) ($data['smtp_port'] ?? 587),
            'smtp_encryption' => $data['smtp_encryption'] ?? 'ssl',
            'imap_username' => $data['imap_username'] ?? null,
        ];
    }

    public static function cpanelMailboxFromEmail(string $email): string
    {
        if ($email === '') {
            return 'INBOX';
        }

        return 'INBOX.' . str_replace('.', '_', $email);
    }

    public function imapMailbox(): string
    {
        return $this->imap_mailbox ?: 'INBOX';
    }

    public function imapSelectCommand(): string
    {
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $this->imapMailbox());

        return 'SELECT "' . $escaped . '"';
    }

    public function authUsername(): string
    {
        return $this->imap_username ?: $this->email;
    }

    public function imapStreamHost(): string
    {
        $host = $this->imap_host ?? 'imap.gmail.com';
        $encryption = $this->imap_encryption ?? 'ssl';

        if ($encryption === 'ssl') {
            return 'ssl://' . $host;
        }

        return $host;
    }

    public function imapPort(): int
    {
        return (int) ($this->imap_port ?? 993);
    }

    public function smtpDsn(): string
    {
        $host = $this->smtp_host ?? 'smtp.gmail.com';
        $port = (int) ($this->smtp_port ?? 587);
        $encryption = $this->smtp_encryption ?? 'tls';
        $scheme = $encryption === 'ssl' ? 'smtps' : 'smtp';

        return sprintf(
            '%s://%s:%s@%s:%d',
            $scheme,
            rawurlencode($this->authUsername()),
            rawurlencode($this->password),
            $host,
            $port
        );
    }

    /**
     * Get the emails fetched from this Gmail account.
     */
    public function emails(): HasMany
    {
        return $this->hasMany(Email::class);
    }
}

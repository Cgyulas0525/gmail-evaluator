<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Email extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'gmail_account_id',
        'message_id',
        'imap_uid',
        'sender',
        'recipient',
        'subject',
        'body',
        'attachments',
        'received_at',
        'sentiment',
        'priority',
        'category',
        'summary',
        'action_items',
        'in_reply_to',
        'references',
        'auto_replied_at',
        'auto_reply_status',
        'auto_reply_error',
        'auto_reply_message_id',
        'auto_reply_thread_key',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'auto_replied_at' => 'datetime',
        'action_items' => 'array',
        'attachments' => 'array',
    ];

    /**
     * Get the Gmail account this email was fetched from.
     */
    public function gmailAccount(): BelongsTo
    {
        return $this->belongsTo(GmailAccount::class);
    }
}

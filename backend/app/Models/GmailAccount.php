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
        'password',
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
    ];

    /**
     * Get the emails fetched from this Gmail account.
     */
    public function emails(): HasMany
    {
        return $this->hasMany(Email::class);
    }
}

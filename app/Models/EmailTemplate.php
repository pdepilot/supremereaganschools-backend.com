<?php

namespace App\Models;

use App\Enums\EmailAudience;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'slug',
    'name',
    'audience',
    'subject',
    'preheader',
    'body',
    'is_system',
    'created_by',
])]
class EmailTemplate extends Model
{
    protected function casts(): array
    {
        return [
            'audience' => EmailAudience::class,
            'is_system' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function outboundMails(): HasMany
    {
        return $this->hasMany(OutboundMail::class);
    }
}

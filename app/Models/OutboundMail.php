<?php

namespace App\Models;

use App\Enums\EmailAudience;
use App\Enums\OutboundMailStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'email_template_id',
    'subject',
    'audience',
    'body',
    'recipient_count',
    'sent_count',
    'failed_count',
    'status',
    'error',
    'recipients',
    'sent_by',
    'sent_at',
])]
class OutboundMail extends Model
{
    protected function casts(): array
    {
        return [
            'audience' => EmailAudience::class,
            'status' => OutboundMailStatus::class,
            'recipients' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'email_template_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}

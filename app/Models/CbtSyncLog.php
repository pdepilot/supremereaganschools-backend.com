<?php

namespace App\Models;

use App\Enums\CbtSyncDirection;
use App\Enums\CbtSyncLogStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'event_id',
    'attempt_id',
    'user_id',
    'direction',
    'protocol',
    'batch_id',
    'payload_hash',
    'status',
    'message',
])]
class CbtSyncLog extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'direction' => CbtSyncDirection::class,
            'status' => CbtSyncLogStatus::class,
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(CbtAttempt::class, 'attempt_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

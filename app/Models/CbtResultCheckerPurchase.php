<?php

namespace App\Models;

use App\Enums\CbtResultCheckerPurchaseStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

#[Fillable([
    'uuid',
    'checker_code',
    'verification_code',
    'student_profile_id',
    'user_id',
    'cbt_result_id',
    'product_id',
    'online_payment_id',
    'amount_kobo',
    'currency',
    'status',
    'checks_allowed',
    'checks_remaining',
    'checked_count',
    'paid_at',
    'expires_at',
    'last_checked_at',
    'revoked_at',
    'revoke_reason',
    'metadata',
])]
class CbtResultCheckerPurchase extends Model
{
    protected function casts(): array
    {
        return [
            'status' => CbtResultCheckerPurchaseStatus::class,
            'amount_kobo' => 'integer',
            'checks_allowed' => 'integer',
            'checks_remaining' => 'integer',
            'checked_count' => 'integer',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'revoked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(CbtResult::class, 'cbt_result_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(CbtResultProduct::class, 'product_id');
    }

    public function onlinePayment(): BelongsTo
    {
        return $this->belongsTo(OnlinePayment::class);
    }

    public function gatewayPayment(): MorphOne
    {
        return $this->morphOne(OnlinePayment::class, 'payable');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function hasChecksRemaining(): bool
    {
        return $this->checks_remaining > 0;
    }
}

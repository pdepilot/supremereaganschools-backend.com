<?php

namespace App\Models;

use App\Enums\OnlinePaymentPurpose;
use App\Enums\OnlinePaymentStatus;
use App\Enums\PaymentProvider;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'uuid',
    'reference',
    'provider',
    'provider_reference',
    'purpose',
    'payable_type',
    'payable_id',
    'user_id',
    'student_profile_id',
    'email',
    'amount_kobo',
    'currency',
    'status',
    'authorization_url',
    'access_code',
    'channel',
    'gateway_status',
    'paid_at',
    'verified_at',
    'failed_at',
    'failure_reason',
    'metadata',
    'provider_payload',
])]
class OnlinePayment extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'purpose' => OnlinePaymentPurpose::class,
            'status' => OnlinePaymentStatus::class,
            'amount_kobo' => 'integer',
            'paid_at' => 'datetime',
            'verified_at' => 'datetime',
            'failed_at' => 'datetime',
            'metadata' => 'array',
            'provider_payload' => 'array',
        ];
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }

    public function isPending(): bool
    {
        return $this->status === OnlinePaymentStatus::Pending;
    }

    public function isPaid(): bool
    {
        return $this->status === OnlinePaymentStatus::Paid;
    }
}

<?php

namespace App\Models;

use App\Enums\FeeChannel;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'reference',
    'student_profile_id',
    'invoice_id',
    'amount_kobo',
    'channel',
    'note',
    'paid_at',
    'status',
    'recorded_by',
    'voided_by',
    'voided_at',
    'void_reason',
])]
class Payment extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'amount_kobo' => 'integer',
            'channel' => FeeChannel::class,
            'paid_at' => 'datetime',
            'status' => PaymentStatus::class,
            'voided_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}

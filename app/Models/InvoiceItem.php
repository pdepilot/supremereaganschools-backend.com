<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'invoice_id',
    'fee_type_id',
    'description',
    'amount_kobo',
    'paid_kobo',
])]
class InvoiceItem extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'amount_kobo' => 'integer',
            'paid_kobo' => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function feeType(): BelongsTo
    {
        return $this->belongsTo(FeeType::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function remainingKobo(): int
    {
        return max(0, $this->amount_kobo - $this->paid_kobo);
    }
}

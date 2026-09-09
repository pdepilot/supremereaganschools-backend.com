<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'code',
    'description',
    'amount_kobo',
    'currency',
    'checks_allowed',
    'duration_days',
    'is_active',
    'created_by',
])]
class CbtResultProduct extends Model
{
    protected function casts(): array
    {
        return [
            'amount_kobo' => 'integer',
            'checks_allowed' => 'integer',
            'duration_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(CbtResultCheckerPurchase::class, 'product_id');
    }
}

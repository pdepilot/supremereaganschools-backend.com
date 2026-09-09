<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'cbt_result_id',
    'student_profile_id',
    'online_payment_id',
    'granted_at',
    'revoked_at',
])]
class CbtResultAccess extends Model
{
    protected $table = 'cbt_result_access';

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(CbtResult::class, 'cbt_result_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }

    public function onlinePayment(): BelongsTo
    {
        return $this->belongsTo(OnlinePayment::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}

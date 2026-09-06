<?php

namespace App\Models;

use App\Enums\AuthPortal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'portal', 'ip_address', 'user_agent', 'logged_in_at'])]
class LoginActivity extends Model
{
    protected function casts(): array
    {
        return [
            'portal' => AuthPortal::class,
            'logged_in_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

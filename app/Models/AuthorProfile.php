<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'public_role',
    'biography',
    'photo_path',
])]
class AuthorProfile extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function photoUrl(): ?string
    {
        if (! filled($this->photo_path)) {
            return null;
        }

        return str_starts_with((string) $this->photo_path, 'http')
            ? (string) $this->photo_path
            : url((string) $this->photo_path);
    }
}

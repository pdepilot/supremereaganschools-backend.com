<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'email',
    'consented_at',
    'status',
    'source',
])]
class NewsletterSubscriber extends Model
{
    protected function casts(): array
    {
        return [
            'consented_at' => 'datetime',
        ];
    }
}

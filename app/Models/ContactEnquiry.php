<?php

namespace App\Models;

use App\Enums\EnquiryStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'phone',
    'email',
    'subject',
    'message',
    'intended_level',
    'enquiry_type',
    'source_url',
    'source_post_id',
    'status',
    'assigned_to',
])]
class ContactEnquiry extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => EnquiryStatus::class,
        ];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(ContactEnquiryReply::class)->orderBy('sent_at')->orderBy('id');
    }

    public function sourcePost(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'source_post_id');
    }
}

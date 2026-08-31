<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'contact_enquiry_id',
    'user_id',
    'subject',
    'body',
    'sent_at',
])]
class ContactEnquiryReply extends Model
{
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(ContactEnquiry::class, 'contact_enquiry_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

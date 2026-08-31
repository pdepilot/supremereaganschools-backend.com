<?php

namespace App\Models;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'title',
    'body',
    'category',
    'audience',
    'department_id',
    'status',
    'published_at',
    'created_by',
])]
class Announcement extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'category' => AnnouncementCategory::class,
            'audience' => AnnouncementAudience::class,
            'status' => AnnouncementStatus::class,
            'published_at' => 'datetime',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

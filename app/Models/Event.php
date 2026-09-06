<?php

namespace App\Models;

use App\Enums\EventStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'title',
    'slug',
    'summary',
    'body',
    'category',
    'starts_at',
    'ends_at',
    'location',
    'cover_image',
    'cover_image_alt',
    'is_featured',
    'status',
    'published_at',
])]
class Event extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => EventStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published_at' => 'datetime',
            'is_featured' => 'boolean',
        ];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', EventStatus::Published)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query
            ->where('starts_at', '>=', now()->startOfDay())
            ->orderBy('starts_at');
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    public function isPublished(): bool
    {
        return $this->status === EventStatus::Published
            && $this->published_at !== null
            && $this->published_at->lte(now());
    }

    public function coverImageUrl(): ?string
    {
        if (blank($this->cover_image)) {
            return null;
        }

        if (str_starts_with($this->cover_image, 'http://') || str_starts_with($this->cover_image, 'https://')) {
            return $this->cover_image;
        }

        if (str_starts_with($this->cover_image, '/storage/')) {
            return $this->cover_image;
        }

        return Storage::disk('public')->url(ltrim($this->cover_image, '/'));
    }
}

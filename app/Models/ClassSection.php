<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['school_class_id', 'arm', 'name', 'is_active'])]
class ClassSection extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ClassSection $section): void {
            if (filled($section->name)) {
                return;
            }

            $className = $section->schoolClass?->name
                ?? SchoolClass::query()->find($section->school_class_id)?->name
                ?? '';

            $arm = trim((string) $section->arm);
            $section->name = $arm === '' ? $className : trim($className.' '.$arm);
        });
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function offerings(): HasMany
    {
        return $this->hasMany(ClassSectionOffering::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'description', 'sort_order', 'is_active'])]
class Level extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function schoolClasses(): HasMany
    {
        return $this->hasMany(SchoolClass::class)->orderBy('sort_order');
    }

    public function admissionApplications(): HasMany
    {
        return $this->hasMany(AdmissionApplication::class);
    }
}

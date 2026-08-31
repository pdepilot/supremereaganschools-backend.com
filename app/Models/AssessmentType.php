<?php

namespace App\Models;

use App\Enums\AssessmentKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['kind', 'name', 'max_score', 'sort_order', 'is_active'])]
class AssessmentType extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'kind' => AssessmentKind::class,
            'max_score' => 'decimal:2',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scores(): HasMany
    {
        return $this->hasMany(AssessmentScore::class);
    }

    public function isExamination(): bool
    {
        return $this->kind === AssessmentKind::Examination;
    }
}

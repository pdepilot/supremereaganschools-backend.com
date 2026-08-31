<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['min_score', 'max_score', 'grade', 'remark', 'sort_order'])]
class GradeScale extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'min_score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public static function forScore(float $score): ?self
    {
        return static::query()
            ->where('min_score', '<=', $score)
            ->where('max_score', '>=', $score)
            ->orderByDesc('min_score')
            ->first();
    }
}

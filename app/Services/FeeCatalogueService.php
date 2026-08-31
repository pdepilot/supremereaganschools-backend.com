<?php

namespace App\Services;

use App\Models\FeeStructure;
use App\Models\FeeType;
use Illuminate\Validation\ValidationException;

class FeeCatalogueService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createType(array $attributes): FeeType
    {
        return FeeType::query()->create([
            'name' => $attributes['name'],
            'code' => strtoupper((string) $attributes['code']),
            'is_active' => $attributes['is_active'] ?? true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateType(FeeType $type, array $attributes): FeeType
    {
        $type->update([
            'name' => $attributes['name'] ?? $type->name,
            'code' => isset($attributes['code']) ? strtoupper((string) $attributes['code']) : $type->code,
            'is_active' => $attributes['is_active'] ?? $type->is_active,
        ]);

        return $type->fresh();
    }

    public function deactivateType(FeeType $type): FeeType
    {
        $type->update(['is_active' => false]);

        return $type->fresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createStructure(array $attributes): FeeStructure
    {
        $this->assertUniqueStructure($attributes);

        return FeeStructure::query()->create([
            'fee_type_id' => $attributes['fee_type_id'],
            'academic_session_id' => $attributes['academic_session_id'],
            'term_id' => $attributes['term_id'] ?? null,
            'level_id' => $attributes['level_id'] ?? null,
            'school_class_id' => $attributes['school_class_id'] ?? null,
            'amount_kobo' => $attributes['amount_kobo'],
        ])->load(['feeType', 'academicSession', 'term', 'level', 'schoolClass']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateStructure(FeeStructure $structure, array $attributes): FeeStructure
    {
        $payload = array_merge($structure->only([
            'fee_type_id',
            'academic_session_id',
            'term_id',
            'level_id',
            'school_class_id',
        ]), $attributes);

        $this->assertUniqueStructure($payload, $structure->id);

        $structure->update($payload);

        return $structure->fresh(['feeType', 'academicSession', 'term', 'level', 'schoolClass']);
    }

    public function deleteStructure(FeeStructure $structure): void
    {
        $structure->delete();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertUniqueStructure(array $attributes, ?int $ignoreId = null): void
    {
        $query = FeeStructure::query()
            ->where('fee_type_id', $attributes['fee_type_id'])
            ->where('academic_session_id', $attributes['academic_session_id']);

        foreach (['term_id', 'level_id', 'school_class_id'] as $column) {
            $value = $attributes[$column] ?? null;
            if ($value === null) {
                $query->whereNull($column);
            } else {
                $query->where($column, $value);
            }
        }

        if ($ignoreId) {
            $query->whereKeyNot($ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'fee_type_id' => 'A fee structure already exists for this type, session, and scope.',
            ]);
        }
    }
}

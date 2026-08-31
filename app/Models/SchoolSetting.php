<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'name',
    'short_name',
    'motto',
    'address',
    'city',
    'state',
    'phone',
    'email',
    'admissions_email',
    'whatsapp',
    'website',
    'timezone',
    'founded_on',
    'office_opens_at',
    'office_closes_at',
    'logo_path',
    'current_academic_session_id',
    'current_term_id',
    'updated_by',
])]
class SchoolSetting extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'founded_on' => 'date',
        ];
    }

    public static function current(): self
    {
        $settings = static::query()->first();

        abort_if($settings === null, 404, 'School settings have not been created.');

        return $settings;
    }

    public function currentAcademicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'current_academic_session_id');
    }

    public function currentTerm(): BelongsTo
    {
        return $this->belongsTo(Term::class, 'current_term_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

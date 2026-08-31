<?php

namespace App\Http\Requests\Admissions;

use App\Enums\Gender;
use App\Enums\GuardianRelationship;
use App\Models\AdmissionApplication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdmissionApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AdmissionApplication::class) ?? true;
    }

    protected function prepareForValidation(): void
    {
        $map = [
            'session' => 'session_name',
            'classApplied' => 'class_applied',
            'entryTerm' => 'entry_term',
            'firstName' => 'first_name',
            'otherNames' => 'other_names',
            'dob' => 'date_of_birth',
            'stateOfOrigin' => 'state_of_origin',
            'homeAddress' => 'home_address',
            'previousSchool' => 'previous_school',
            'lastClass' => 'last_class',
            'parentName' => 'parent_name',
            'parentPhone' => 'parent_phone',
            'parentEmail' => 'parent_email',
            'occupation' => 'parent_occupation',
            'secondPhone' => 'parent_second_phone',
            'parentAddress' => 'parent_address',
            'bloodGroup' => 'blood_group',
        ];

        $merged = [];
        foreach ($map as $from => $to) {
            if ($this->exists($from) && ! $this->filled($to)) {
                $merged[$to] = $this->input($from);
            }
        }

        if ($this->filled('gender')) {
            $merged['gender'] = strtolower((string) $this->input('gender'));
        }
        if ($this->filled('relationship')) {
            $merged['relationship'] = strtolower((string) $this->input('relationship'));
        }

        if ($merged !== []) {
            $this->merge($merged);
        }

        foreach ([
            'passportPhoto' => 'passport_photo',
            'birthCert' => 'birth_certificate',
            'examReceipt' => 'exam_receipt',
        ] as $from => $to) {
            if ($this->file($from) && ! $this->file($to)) {
                $this->files->set($to, $this->file($from));
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'session_name' => ['required', 'string', 'max:40'],
            'level' => ['required', 'string', 'max:40'],
            'class_applied' => ['required', 'string', 'max:80'],
            'entry_term' => ['required', 'string', 'max:40'],
            'surname' => ['required', 'string', 'max:80'],
            'first_name' => ['required', 'string', 'max:80'],
            'other_names' => ['nullable', 'string', 'max:80'],
            'gender' => ['required', Rule::enum(Gender::class)],
            'date_of_birth' => ['required', 'date'],
            'nationality' => ['required', 'string', 'max:80'],
            'state_of_origin' => ['required', 'string', 'max:80'],
            'lga' => ['nullable', 'string', 'max:80'],
            'home_address' => ['required', 'string', 'max:500'],
            'previous_school' => ['nullable', 'string', 'max:255'],
            'last_class' => ['nullable', 'string', 'max:80'],
            'parent_name' => ['required', 'string', 'max:255'],
            'relationship' => ['required', Rule::enum(GuardianRelationship::class)],
            'parent_phone' => ['required', 'string', 'max:40'],
            'parent_email' => ['required', 'email', 'max:255'],
            'parent_occupation' => ['nullable', 'string', 'max:120'],
            'parent_second_phone' => ['nullable', 'string', 'max:40'],
            'parent_address' => ['nullable', 'string', 'max:500'],
            'blood_group' => ['nullable', 'string', 'max:20'],
            'genotype' => ['nullable', 'string', 'max:20'],
            'allergies' => ['nullable', 'string', 'max:2000'],
            'interests' => ['nullable', 'string', 'max:255'],
            'passport_photo' => ['nullable', 'file', 'image', 'max:5120'],
            'passportPhoto' => ['nullable', 'file', 'image', 'max:5120'],
            'birth_certificate' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
            'birthCert' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
            'exam_receipt' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
            'examReceipt' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    /**
     * @return array<string, \Illuminate\Http\UploadedFile|null>
     */
    public function attachments(): array
    {
        return [
            'passport_photo' => $this->file('passport_photo') ?? $this->file('passportPhoto'),
            'birth_certificate' => $this->file('birth_certificate') ?? $this->file('birthCert'),
            'exam_receipt' => $this->file('exam_receipt') ?? $this->file('examReceipt'),
        ];
    }
}

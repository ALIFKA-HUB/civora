<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePointRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'teacher';
    }

    public function rules(): array
    {
        return [
            'selection_id' => ['required', 'uuid'],
            'point_type_version_id' => ['required', 'uuid'],
            'description' => ['required', 'string'],
            'attachment_id' => ['nullable', 'uuid'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['selection_id', 'point_type_version_id', 'description', 'attachment_id']) !== []) {
                $validator->errors()->add('body', 'Field pencatatan tidak diizinkan.');
            }
            $description = $this->input('description');
            if (is_string($description) && (mb_strlen(trim($description)) < 5 || mb_strlen(trim($description)) > 1000)) {
                $validator->errors()->add('description', 'Keterangan harus berisi 5–1.000 karakter.');
            }
        });
    }
}

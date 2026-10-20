<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreResumeRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * The limit is 2 MB because PHP's default upload_max_filesize is 2 MB. A larger file is
     * rejected by PHP before validation runs, which the uploaded rule reports.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:pdf', 'max:2048'],
        ];
    }

    /**
     * Get the custom validation messages for the request.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.max' => 'Upload a PDF of 2 MB or less.',
            'file.uploaded' => 'Upload a PDF of 2 MB or less.',
        ];
    }
}

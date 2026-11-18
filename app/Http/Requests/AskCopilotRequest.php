<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AskCopilotRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'string', 'size:36'],
        ];
    }
}

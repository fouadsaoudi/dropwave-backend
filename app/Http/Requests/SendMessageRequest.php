<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'body' => 'required_without:file|nullable|string|max:4000',
            'file' => 'required_without:body|nullable|file|image|max:15360', // max 15MB
        ];
    }
}

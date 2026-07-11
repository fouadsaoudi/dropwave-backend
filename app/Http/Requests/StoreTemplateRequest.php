<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTemplateRequest extends FormRequest
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
            'channel_id' => 'required|exists:waba_channels,id',
            'name' => ['required', 'string', 'max:512', 'regex:/^[a-z0-9_]+$/'],
            'category' => 'required|in:UTILITY,MARKETING,AUTHENTICATION',
            'language' => 'required|string|max:10',
            'header_type' => 'required|in:none,text,image,document,video',
            'header_content' => 'nullable|string|max:1024',
            'body' => 'required|string|max:4096',
            'footer' => 'nullable|string|max:1024',
            'variable_examples' => 'nullable|array',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'The template name must only contain lowercase alphanumeric characters and underscores (e.g. order_delivery_update).',
        ];
    }
}

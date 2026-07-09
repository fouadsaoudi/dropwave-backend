<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContactRequest extends FormRequest
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
        $tenantId = $this->user()->tenant_id;

        return [
            'name' => 'required|string|max:255',
            'phone_number' => [
                'required',
                'string',
                'max:30',
                // Custom regex to allow only valid phone characters (digits, optional leading +)
                'regex:/^\+?[1-9]\d{1,14}$/', 
                Rule::unique('contacts')->where(function ($query) use ($tenantId) {
                    return $query->where('tenant_id', $tenantId);
                }),
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'phone_number.regex' => 'The phone number format must be E.164 (e.g. +96171000000).',
            'phone_number.unique' => 'A contact with this phone number already exists in this workspace.',
        ];
    }
}

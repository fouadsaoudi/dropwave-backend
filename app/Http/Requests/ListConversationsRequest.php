<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListConversationsRequest extends FormRequest
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
            'status' => 'nullable|string|in:open,resolved,closed',
            'assigned_to' => 'nullable|integer',
            'unassigned' => 'nullable',
            'assigned' => 'nullable',
        ];
    }
}

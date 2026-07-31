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
            'file' => 'required_without:body|nullable|file|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,jpeg,jpg,png,webp,aac,mp3,m4a,amr,ogg,opus,mp4,3gp|max:13312', // max 13MB
        ];
    }
}

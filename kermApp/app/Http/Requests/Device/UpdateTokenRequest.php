<?php

namespace App\Http\Requests\Device;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTokenRequest extends FormRequest
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
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // The token Google issued previously, used to locate the device.
            'old_fcm_token' => ['required', 'string', 'max:512'],
            // The refreshed token to store going forward.
            'fcm_token' => ['required', 'string', 'max:512', 'different:old_fcm_token'],
        ];
    }
}

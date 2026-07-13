<?php

namespace App\Http\Requests\Bot;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SendEventRequest extends FormRequest
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
            'event' => ['required', 'string', 'max:255'],
            // Free-form payload: a plain string ("سلام و درود") or a structured object.
            'data' => ['nullable'],
            // Optional single target; omit to broadcast to all of the owner's devices.
            'device_id' => ['nullable', 'integer'],
        ];
    }
}

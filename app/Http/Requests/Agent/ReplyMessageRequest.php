<?php

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;

class ReplyMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->canAccessAgentPanel();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'message' => 'mensaje',
        ];
    }
}

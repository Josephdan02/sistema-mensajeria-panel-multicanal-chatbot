<?php

namespace App\Http\Requests\Agent;

use App\Enums\ConversationState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateConversationStateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->canAccessAgentPanel();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'state' => ['required', 'string', Rule::in(ConversationState::selectableValues())],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'state' => 'estado',
            'note' => 'nota',
        ];
    }

    public function state(): ConversationState
    {
        return ConversationState::from($this->string('state')->toString());
    }
}

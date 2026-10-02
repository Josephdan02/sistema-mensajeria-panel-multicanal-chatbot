<?php

namespace App\Http\Requests\Agent;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AssignConversationRequest extends FormRequest
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
            'agent_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'agent_id' => 'agente',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $agent = User::query()->find($this->integer('agent_id'));

            if ($agent !== null && ! $agent->canAccessAgentPanel()) {
                $validator->errors()->add('agent_id', 'El usuario seleccionado no es un agente activo.');
            }
        });
    }
}

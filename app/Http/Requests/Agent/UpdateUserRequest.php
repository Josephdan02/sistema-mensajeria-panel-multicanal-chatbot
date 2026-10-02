<?php

namespace App\Http\Requests\Agent;

use App\Http\Controllers\Agent\UserController;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->isSupervisor() && $user->isActive();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $managed */
        $managed = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($managed->id)],
            'role' => ['required', Rule::in(User::AGENT_ROLES)],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'email' => 'correo',
            'role' => 'rol',
            'active' => 'estado',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var User|null $managed */
            $managed = $this->route('user');
            $me = $this->user();

            if ($managed === null || $me === null) {
                return;
            }

            // Nadie puede degradar/desactivar su propia cuenta de supervisor.
            if ($managed->is($me)) {
                if ($this->string('role')->toString() !== User::ROLE_SUPERVISOR) {
                    $validator->errors()->add('role', 'No puedes quitarte tu propio rol de supervisor.');
                }

                if ($this->has('active') && ! $this->boolean('active')) {
                    $validator->errors()->add('active', 'No puedes desactivar tu propio usuario.');
                }
            }

            // No dejar al sistema sin supervisores activos.
            if ($managed->isSupervisor()
                && $this->string('role')->toString() !== User::ROLE_SUPERVISOR
                && ! UserController::anotherActiveSupervisorExists($managed)
            ) {
                $validator->errors()->add('role', 'No puedes degradar al último supervisor activo.');
            }

            if ($managed->active
                && $this->has('active')
                && ! $this->boolean('active')
                && $managed->isSupervisor()
                && ! UserController::anotherActiveSupervisorExists($managed)
            ) {
                $validator->errors()->add('active', 'No puedes desactivar al último supervisor activo.');
            }
        });
    }
}

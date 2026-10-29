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
            'message' => ['nullable', 'string', 'max:2000', 'required_without:attachment'],
            'attachment' => [
                'nullable',
                'file',
                'max:15360',
                // La extensión se valida aquí; el contenido real (MIME y
                // coherencia extensión/MIME) lo valida OutgoingAttachmentService.
                // `extensions` permite formatos que fileinfo identifica como
                // contenedor genérico, p. ej. `.opus` (audio/ogg).
                'extensions:jpg,jpeg,png,webp,pdf,docx,txt,mp3,m4a,ogg,opus,mp4,gif',
                'required_without:message',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'message' => 'mensaje',
            'attachment' => 'archivo',
        ];
    }
}

<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Services\Attachments\AttachmentStorageInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Acceso a los adjuntos de los canales externos desde el panel de agentes.
 *
 * El archivo nunca se expone por una ruta interna ni por una URL pública: se
 * sirve desde el backend tras comprobar la autorización del agente sobre la
 * conversación a la que pertenece el adjunto.
 */
class AttachmentController extends Controller
{
    public function __construct(
        private readonly AttachmentStorageInterface $storage,
    ) {}

    /** Vista de detalle del adjunto (contenido incrustado). */
    public function show(Request $request, Attachment $attachment): View
    {
        Gate::authorize('view', $attachment);

        return view('agent.attachments.show', [
            'attachment' => $attachment->load('conversation:id,user_identifier,channel'),
        ]);
    }

    /**
     * Descarga el archivo original.
     *
     * La respuesta se construye en el servidor: no se redirige ni se expone
     * la ruta del bucket. El tipo MIME se fuerza al del adjunto (validado en
     * la ingesta) para evitar respuestas MIME inseguras.
     */
    public function download(Request $request, Attachment $attachment): SymfonyResponse
    {
        Gate::authorize('download', $attachment);

        if (! $attachment->isAvailable() || ! $this->isSafePath($attachment->storage_path)) {
            return $this->unavailableResponse($request);
        }

        $contents = $this->storage->get($attachment->storage_path);

        if ($contents === null) {
            return $this->unavailableResponse($request);
        }

        $mimeType = $this->safeMimeType($attachment);

        return response($contents, SymfonyResponse::HTTP_OK, [
            'Content-Type' => $mimeType,
            'Content-Length' => (string) strlen($contents),
            'Content-Disposition' => $this->contentDisposition($attachment, $request->boolean('inline')),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * La ruta del objeto debe ser relativa y no puede contener `..` ni
     * barras iniciais: se valida antes de leer del almacenamiento.
     */
    private function isSafePath(?string $path): bool
    {
        if ($path === null || $path === '') {
            return false;
        }

        if (str_starts_with($path, '/') || str_contains($path, '..')) {
            return false;
        }

        return (bool) preg_match('#^[A-Za-z0-9._\-/]+$#', $path);
    }

    /** El adjunto existe pero su archivo no se pudo recuperar. */
    private function unavailableResponse(Request $request): SymfonyResponse
    {
        $message = 'El archivo no está disponible.';

        if ($request->wantsJson()) {
            return response()->json(['success' => false, 'message' => $message], SymfonyResponse::HTTP_NOT_FOUND);
        }

        return response($message, SymfonyResponse::HTTP_NOT_FOUND, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * MIME seguro: solo se sirve el tipo detectado en la ingesta. Cualquier
     * valor desconocido se fuerza a una descarga binaria.
     */
    private function safeMimeType(Attachment $attachment): string
    {
        $mimeType = (string) $attachment->mime_type;

        $allowed = (array) config('attachments.mime.'.$attachment->kind, []);

        if ($mimeType !== '' && in_array($mimeType, $allowed, true)) {
            return $mimeType;
        }

        return 'application/octet-stream';
    }

    /** Nombre de descarga saneado +inline/+attachment. */
    private function contentDisposition(Attachment $attachment, bool $inline): string
    {
        $name = (string) ($attachment->original_name ?: 'archivo.'.($attachment->extension ?: 'bin'));
        $name = preg_replace('/[^A-Za-z0-9._\- ]/u', '_', $name) ?: 'archivo';
        $name = mb_substr($name, 0, 120);

        $disposition = $inline ? 'inline' : 'attachment';

        return $disposition.'; filename="'.$name.'"';
    }
}

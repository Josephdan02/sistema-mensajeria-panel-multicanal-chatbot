<?php

namespace App\Services\Attachments;

/**
 * Almacenamiento privado de adjuntos.
 *
 * La implementación de Supabase guarda el archivo en un bucket privado y
 * devuelve la ruta del objeto (no una URL pública). El acceso del agente se
 * hace siempre a través del backend, que valida la autorización de la
 * conversación antes de devolver el contenido.
 */
interface AttachmentStorageInterface
{
    /**
     * Guarda el contenido en el bucket privado y devuelve la ruta del objeto.
     */
    public function put(string $path, string $contents, string $mimeType): AttachmentStorageResult;

    /**
     * Devuelve el contenido de un objeto almacenado, o null si no existe.
     */
    public function get(string $path): ?string;

    /**
     * Elimina un objeto almacenado. Devuelve true si ya no existe.
     */
    public function delete(string $path): bool;

    /**
     * Indica si el almacenamiento está configurado y disponible.
     */
    public function isAvailable(): bool;
}

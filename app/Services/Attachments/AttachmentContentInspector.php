<?php

namespace App\Services\Attachments;

use Throwable;

/**
 * Utilidades de inspección y saneado de adjuntos.
 *
 * Se comparte entre la ingesta de archivos recibidos y el envío de archivos
 * desde el panel para aplicar exactamente las mismas reglas de tipo, MIME y
 * extensión.
 */
class AttachmentContentInspector
{
    /** Categoría funcional (config/attachments.php) para una extensión. */
    public function kindForExtension(?string $extension): ?string
    {
        if ($extension === null || $extension === '') {
            return null;
        }

        foreach ((array) config('attachments.allowed', []) as $kind => $extensions) {
            if (in_array($extension, (array) $extensions, true)) {
                return (string) $kind;
            }
        }

        return null;
    }

    /** Detecta el MIME real del contenido con fileinfo. */
    public function detectMime(string $contents): ?string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        $mime = $finfo->buffer($contents);

        if ($mime === false || $mime === '') {
            return null;
        }

        // `application/zip` no distingue un DOCX de cualquier otro ZIP: se
        // comprueba la presencia de los marcadores internos de OOXML.
        if ($mime === 'application/zip') {
            return $this->isDocx($contents)
                ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                : $mime;
        }

        // `audio/ogg`, `video/ogg` y `application/ogg` cubren Opus y OGG/Vorbis.
        if (in_array($mime, ['audio/ogg', 'video/ogg', 'application/ogg'], true)) {
            return 'audio/ogg';
        }

        // Algunos contenedores válidos (OGG/Opus, MP4) no los identifica
        // fileinfo si el archivo es muy pequeño: se comprueba la cabecera.
        if ($mime === 'application/octet-stream') {
            return $this->detectMimeByMagicBytes($contents) ?? $mime;
        }

        return $mime;
    }

    /**
     * MIME deducido de la cabecera cuando fileinfo no es concluyente.
     */
    public function detectMimeByMagicBytes(string $contents): ?string
    {
        if (str_starts_with($contents, 'OggS')) {
            return 'audio/ogg';
        }

        if (str_starts_with($contents, 'RIFF') && substr($contents, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        if (str_starts_with($contents, 'RIFF') && substr($contents, 8, 4) === 'WAVE') {
            return 'audio/x-wav';
        }

        if (substr($contents, 4, 4) === 'ftyp') {
            return str_contains(substr($contents, 0, 32), 'M4A') ? 'audio/mp4' : 'video/mp4';
        }

        if (str_starts_with($contents, 'ID3')) {
            return 'audio/mpeg';
        }

        return null;
    }

    /** Detecta un documento DOCX (paquete ZIP con `word/document.xml`). */
    public function isDocx(string $contents): bool
    {
        if (! class_exists(\ZipArchive::class)) {
            return false;
        }

        $path = tempnam(sys_get_temp_dir(), 'att');

        if ($path === false) {
            return false;
        }

        try {
            file_put_contents($path, $contents);

            $zip = new \ZipArchive;

            if ($zip->open($path) !== true) {
                return false;
            }

            $hasDocument = $zip->locateName('word/document.xml') !== false;

            $zip->close();

            return $hasDocument;
        } catch (Throwable) {
            return false;
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /** Extensión esperada para un MIME detectado. */
    public function extensionFromMime(string $mime): ?string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'application/pdf' => 'pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'text/plain' => 'txt',
            'audio/mpeg' => 'mp3',
            'audio/mp4', 'audio/x-m4a' => 'm4a',
            'audio/ogg', 'audio/opus' => 'ogg',
            'video/mp4' => 'mp4',
            default => null,
        };
    }

    /** Comprueba que la extensión declarada sea coherente con el MIME real. */
    public function extensionMatchesMime(string $extension, string $mime): bool
    {
        $expected = $this->extensionFromMime($mime);

        if ($expected === null) {
            return false;
        }

        $normalized = $extension === 'jpeg' ? 'jpg' : $extension;

        if ($expected === 'ogg') {
            return in_array($normalized, ['ogg', 'opus'], true);
        }

        return $normalized === $expected;
    }

    /** Nombre original saneado: sin rutas, sin caracteres de control. */
    public function safeName(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $base = basename(str_replace('\\', '/', $name));
        $base = preg_replace('/[\x00-\x1F\x7F]/u', '', $base) ?? $base;
        $base = trim((string) $base);

        if ($base === '' || $base === '.' || $base === '..') {
            return null;
        }

        return mb_substr($base, 0, 180);
    }

    /** Extensión en minúsculas y sin punto; null si no tiene. */
    public function extensionOf(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return $extension === '' ? null : $extension;
    }
}

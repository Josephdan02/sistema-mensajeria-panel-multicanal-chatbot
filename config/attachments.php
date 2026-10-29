<?php

/*
|--------------------------------------------------------------------------
| Adjuntos de canales externos (Telegram)
|--------------------------------------------------------------------------
|
| Tipos de archivo permitidos, límites de tamaño y reglas de validación.
| Los tamaños son los máximos por categoría. La comparación se hace contra
| el tamaño declarado por Telegram y contra el tamaño real descargado.
|
*/

return [

    /*
    | Driver de almacenamiento: `supabase` (bucket privado de Supabase Storage)
    | o `local` (disco `local` de Laravel, en storage/app/private).
    */
    'driver' => env('ATTACHMENTS_DRIVER', 'supabase'),

    /*
    | Tipos permitidos por categoría. La clave es la categoría funcional
    | (usada por la interfaz del panel) y el valor son las extensiones
    | admitidas, en minúsculas y sin punto.
    */
    'allowed' => [
        'image' => ['jpg', 'jpeg', 'png', 'webp'],
        'document' => ['pdf', 'docx', 'txt'],
        'audio' => ['mp3', 'm4a', 'ogg', 'opus'],
        'video' => ['mp4'],
        'gif' => ['gif'],
    ],

    /*
    | Límites máximos en bytes por categoría.
    */
    'max_size' => [
        'image' => 5 * 1024 * 1024,        // 5 MB
        'document' => 10 * 1024 * 1024,     // 10 MB
        'audio' => 10 * 1024 * 1024,        // 10 MB
        'video' => 15 * 1024 * 1024,        // 15 MB
        'gif' => 5 * 1024 * 1024,           // 5 MB
    ],

    /*
    | MIME permitidos por categoría. El MIME real del archivo (detectado con
    | fileinfo sobre el contenido descargado) debe coincidir con esta lista,
    | además de coincidir con la extensión declarada.
    */
    'mime' => [
        'image' => ['image/jpeg', 'image/png', 'image/webp'],
        'document' => [
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'text/plain',
        ],
        'audio' => ['audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/ogg', 'audio/opus'],
        'video' => ['video/mp4'],
        'gif' => ['image/gif'],
    ],

    /*
    | Categoría por tipo de mensaje de Telegram. Los stickers no se guardan
    | como archivo (Telegram los expone como PNG/WebP estático o animado) y
    | se registran solo con su descripción textual.
    */
    'telegram_types' => [
        'photo' => 'image',
        'document' => 'document',
        'audio' => 'audio',
        'voice' => 'audio',
        'video' => 'video',
        'animation' => 'gif',
    ],

    /*
    | Límite de descarga del Bot API de Telegram (20 MB). Los archivos mayores
    | no se pueden recuperar aunque el paciente los envíe.
    */
    'telegram_max_download_bytes' => 20 * 1024 * 1024,

    /*
    | Tamaño máximo del texto descriptivo que se guarda en el mensaje.
    */
    'description_max_length' => 255,

];

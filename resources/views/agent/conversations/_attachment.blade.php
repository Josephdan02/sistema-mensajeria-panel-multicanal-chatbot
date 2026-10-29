{{--
    Adjunto del cliente en el hilo de la conversación.
    Requiere: $attachment (App\Models\Attachment|null).
    El archivo se sirve siempre por una ruta autenticada del backend: no se
    expone ninguna URL del bucket ni el token del bot.
--}}
@if ($attachment)
    @php
        $downloadUrl = route('agent.attachments.download', $attachment);
        $viewUrl = route('agent.attachments.show', $attachment);
    @endphp

    <div class="attachment attachment--{{ $attachment->kind }}">
        @if ($attachment->isAvailable())
            @if ($attachment->kind === 'image')
                <a class="attachment__preview" href="{{ $viewUrl }}" target="_blank" rel="noopener">
                    <img src="{{ $downloadUrl }}?inline=1" alt="Imagen enviada por el cliente" loading="lazy">
                </a>
            @elseif ($attachment->kind === 'audio')
                <audio class="attachment__player" controls preload="none" src="{{ $downloadUrl }}?inline=1">
                    Tu navegador no puede reproducir este audio.
                </audio>
            @elseif ($attachment->kind === 'video')
                <video class="attachment__player" controls preload="none" src="{{ $downloadUrl }}?inline=1">
                    Tu navegador no puede reproducir este vídeo.
                </video>
            @elseif ($attachment->kind === 'gif')
                <a class="attachment__preview" href="{{ $viewUrl }}" target="_blank" rel="noopener">
                    <img src="{{ $downloadUrl }}?inline=1" alt="GIF enviado por el cliente" loading="lazy">
                </a>
            @endif

            <p class="attachment__meta">
                <span class="attachment__name">{{ $attachment->original_name ?? 'Archivo' }}</span>
                <span class="attachment__type">{{ strtoupper((string) $attachment->extension) }}</span>
                <span class="attachment__size">{{ $attachment->humanSize() }}</span>
            </p>

            <a class="attachment__download" href="{{ $downloadUrl }}">
                {{ $attachment->kind === 'image' || $attachment->kind === 'gif' ? 'Ver original' : 'Descargar' }}
            </a>
        @else
            <p class="attachment__error">
                <strong>Adjunto no disponible</strong>
                <span>{{ $attachment->error_message ?? 'No se pudo recuperar el archivo.' }}</span>
            </p>
        @endif
    </div>
@endif

{{--
    Detalle de un adjunto recibido por un canal externo.
    El contenido se incrusta desde la ruta autenticada del backend.
--}}
@extends('agent.layouts.app')

@section('title', 'Adjunto')

@section('content')
    <div class="attachment-detail">
        <h1 class="attachment-detail__title">
            {{ $attachment->original_name ?? 'Archivo' }}
        </h1>

        <p class="attachment-detail__meta">
            <span>{{ strtoupper((string) $attachment->extension) }}</span>
            <span>{{ $attachment->humanSize() }}</span>
            <span>{{ $attachment->status->label() }}</span>
        </p>

        @if ($attachment->isAvailable())
            @php
                $downloadUrl = route('agent.attachments.download', $attachment);
            @endphp

            @if ($attachment->kind === 'image' || $attachment->kind === 'gif')
                <img class="attachment-detail__image" src="{{ $downloadUrl }}?inline=1" alt="Imagen enviada por el cliente">
            @elseif ($attachment->kind === 'audio')
                <audio class="attachment-detail__player" controls src="{{ $downloadUrl }}?inline=1"></audio>
            @elseif ($attachment->kind === 'video')
                <video class="attachment-detail__player" controls src="{{ $downloadUrl }}?inline=1"></video>
            @endif

            <p>
                <a class="attachment__download" href="{{ $downloadUrl }}">Descargar original</a>
            </p>
        @else
            <p class="attachment__error">
                <strong>El archivo no está disponible.</strong>
                <span>{{ $attachment->error_message ?? 'No se pudo recuperar el archivo.' }}</span>
            </p>
        @endif

        <p>
            <a href="{{ route('agent.conversations.show', $attachment->conversation_id) }}">Volver a la conversación</a>
        </p>
    </div>
@endsection

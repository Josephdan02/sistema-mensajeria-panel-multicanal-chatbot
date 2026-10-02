@extends('agent.layouts.app')

@section('title', 'Usuarios')

@section('content')
    <div class="page">
        <header class="page__head page__head--row">
            <div>
                <h1>Usuarios del centro de atención</h1>
                <p class="muted">Solo supervisores. Se reutiliza la tabla <code>users</code>: no hay tabla paralela de agentes.</p>
            </div>
            <a class="btn btn--primary btn--sm" href="{{ route('agent.users.create') }}">Crear agente</a>
        </header>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Correo</th>
                        <th>Rol</th>
                        <th>Estado</th>
                        <th>Última actividad</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $listed)
                        <tr>
                            <td>
                                <span class="table__cell-title">{{ $listed->name }}</span>
                                @if ($listed->is(auth()->user()))
                                    <span class="table__cell-note">Eres tú</span>
                                @endif
                            </td>
                            <td>{{ $listed->email }}</td>
                            <td>{{ $listed->roleLabel() }}</td>
                            <td>
                                @if ($listed->active)
                                    <span class="chip chip--ok">Activo</span>
                                @else
                                    <span class="chip chip--muted">Inactivo</span>
                                @endif
                            </td>
                            <td>{{ $listed->last_seen_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td class="table__actions">
                                <a class="link" href="{{ route('agent.users.edit', $listed) }}">Editar</a>

                                <form method="POST" action="{{ route('agent.users.toggle', $listed) }}" class="inline-form">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="link link--btn">
                                        {{ $listed->active ? 'Desactivar' : 'Activar' }}
                                    </button>
                                </form>

                                <form method="POST" action="{{ route('agent.users.reset-password', $listed) }}" class="inline-form">
                                    @csrf
                                    <button type="submit" class="link link--btn" onclick="return confirm('Se generará una contraseña temporal que deberás comunicar al usuario. ¿Continuar?');">
                                        Restablecer contraseña
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="empty">No hay usuarios del centro de atención.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @include('agent.partials.pagination', ['paginator' => $users])
    </div>
@endsection

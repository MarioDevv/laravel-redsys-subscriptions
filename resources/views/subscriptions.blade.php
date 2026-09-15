@php use MarioDevv\RedsysSubscriptions\Subscription; @endphp
@extends('redsys-subscriptions::layout')

@section('title', 'Suscripciones')
@section('heading', 'Suscripciones')
@section('subheading', 'Busca a un titular y actúa sobre su cobro.')

@section('content')
    <div class="card">
        <div class="cap" style="flex-wrap: wrap; gap: .75rem">
            <form method="get" class="search">
                @if ($status !== '')<input type="hidden" name="status" value="{{ $status }}">@endif
                <input type="search" name="q" value="{{ $search }}"
                       placeholder="Número, pedido, últimos cuatro dígitos o referencia">
                <button type="submit">Buscar</button>
                @if ($search !== '')
                    <a class="btn quiet" href="{{ route('redsys.subscriptions.panel.list', ['status' => $status ?: null]) }}">Quitar</a>
                @endif
            </form>

            <div class="chips">
                <a href="{{ route('redsys.subscriptions.panel.list') }}" class="{{ $status === '' ? 'on' : '' }}">
                    Todas {{ $stats['all'] }}
                </a>
                @foreach (Subscription::statusLabels() as $value => $label)
                    <a href="{{ route('redsys.subscriptions.panel.list', ['status' => $value]) }}"
                       class="{{ $status === $value ? 'on' : '' }}">
                        {{ $label }} {{ $stats['counts'][$value] }}
                    </a>
                @endforeach
            </div>
        </div>

        @if ($subscriptions->isEmpty())
            <div class="empty">
                @if ($search !== '' || $status !== '')
                    <b>Nada encaja con esta búsqueda.</b>
                    <p>Prueba con otro término o vuelve a «Todas».</p>
                @else
                    <b>Todavía no hay suscripciones.</b>
                    <p>Aparecerán aquí en cuanto un titular registre su tarjeta.</p>
                @endif
            </div>
        @else
            <p class="found">
                {{ $subscriptions->total() }}
                {{ $subscriptions->total() === 1 ? 'suscripción' : 'suscripciones' }}
                @if ($search !== '') que coinciden con «{{ $search }}» @endif
                @if ($status !== '') en «{{ Subscription::statusLabels()[$status] }}» @endif
            </p>

            <table class="t-subs">
                {{-- «Pendiente del titular» es la etiqueta más larga y no se
                     abrevia: la columna de estado se dimensiona por ella. --}}
                <colgroup>
                    <col style="width:21%"><col style="width:18%"><col style="width:12%">
                    <col style="width:12%"><col style="width:14%"><col>
                </colgroup>
                <thead>
                    <tr>
                        @php
                            $link = function (string $key) use ($status, $search, $sort, $descending) {
                                // Al pulsar la columna ya ordenada, se invierte.
                                return route('redsys.subscriptions.panel.list', array_filter([
                                    'status' => $status ?: null,
                                    'q'      => $search ?: null,
                                    'orden'  => $key,
                                    'dir'    => $sort === $key && $descending ? 'asc' : 'desc',
                                ]));
                            };
                            $arrow = fn (string $key) => $sort === $key ? ($descending ? '↓' : '↑') : '';
                        @endphp
                        <th>Titular</th>
                        <th>Estado</th>
                        <th class="num">
                            <a class="sorter {{ $sort === 'importe' ? 'on' : '' }}" href="{{ $link('importe') }}"
                               @if ($sort === 'importe') aria-sort="{{ $descending ? 'descending' : 'ascending' }}" @endif>
                                Importe <span aria-hidden="true">{{ $arrow('importe') }}</span>
                            </a>
                        </th>
                        <th class="hide-narrow">Tarjeta</th>
                        <th class="hide-narrow">
                            <a class="sorter {{ $sort === 'cobro' ? 'on' : '' }}" href="{{ $link('cobro') }}"
                               @if ($sort === 'cobro') aria-sort="{{ $descending ? 'descending' : 'ascending' }}" @endif>
                                Próximo cobro <span aria-hidden="true">{{ $arrow('cobro') }}</span>
                            </a>
                        </th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($subscriptions as $s)
                    <tr>
                        <td class="who" title="{{ $s->billableName() }}">
                            <a href="{{ route('redsys.subscriptions.panel.show', $s) }}">{{ $s->billableName() }}</a>
                            <span>Nº {{ $s->id }}@if ($s->name !== 'default'), {{ $s->name }}@endif</span>
                        </td>
                        <td>
                            <span class="pill {{ $s->status }}">{{ $s->statusLabel() }}</span>
                            @if ($s->failures)
                                <span class="why {{ $s->status }}">
                                    Intento {{ $s->failures }} de {{ Subscription::MAX_FAILURES }}
                                </span>
                            @endif
                        </td>
                        <td class="num amount">
                            {{ $s->amountLabel() }}
                            <span>/ {{ ['monthly' => 'mes', 'weekly' => 'semana', 'yearly' => 'año'][$s->interval] ?? $s->interval }}</span>
                        </td>
                        <td class="hide-narrow digits">
                            @if ($s->card_last_four)
                                ···· {{ $s->card_last_four }}
                                @if ($s->card_expiry)
                                    <span class="expiry">
                                        caduca {{ substr($s->card_expiry, 2, 2) }}/{{ substr($s->card_expiry, 0, 2) }}
                                    </span>
                                @endif
                            @elseif ($s->card_token)
                                <span class="muted">referencia guardada</span>
                            @else
                                <span class="muted">sin tarjeta</span>
                            @endif
                        </td>
                        <td class="hide-narrow nowrap">
                            @if ($s->onGracePeriod())
                                <span class="muted" title="{{ $s->ends_at->format('j/n/Y') }}">
                                    acceso {{ Subscription::humanDate($s->ends_at) }}
                                </span>
                            @elseif ($s->status === Subscription::CANCELED)
                                <span class="muted">—</span>
                            @elseif ($s->next_charge_at?->isPast())
                                <span class="late" title="{{ $s->next_charge_at->format('j/n/Y H:i') }}">
                                    venció {{ Subscription::humanDate($s->next_charge_at) }}
                                </span>
                            @else
                                <span class="muted" title="{{ $s->next_charge_at?->format('j/n/Y') }}">
                                    {{ Subscription::humanDate($s->next_charge_at) }}
                                </span>
                            @endif
                        </td>
                        <td>@include('redsys-subscriptions::partials.actions')</td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            @if ($subscriptions->hasPages())
                <div class="pages">
                    <span>{{ $subscriptions->firstItem() }}–{{ $subscriptions->lastItem() }} de {{ $subscriptions->total() }}</span>
                    <span style="display:flex; gap:.4rem">
                        @if ($subscriptions->onFirstPage())
                            <span class="btn off">Anteriores</span>
                        @else
                            <a class="btn" href="{{ $subscriptions->previousPageUrl() }}">Anteriores</a>
                        @endif
                        @if ($subscriptions->hasMorePages())
                            <a class="btn" href="{{ $subscriptions->nextPageUrl() }}">Siguientes</a>
                        @else
                            <span class="btn off">Siguientes</span>
                        @endif
                    </span>
                </div>
            @endif
        @endif
    </div>
@endsection

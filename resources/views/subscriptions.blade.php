@php use MarioDevv\RedsysSubscriptions\Subscription; @endphp
@extends('redsys-subscriptions::layout')

@section('title', 'Suscripciones')
@section('heading', 'Suscripciones')
@section('subheading', 'Busca a un titular y actúa sobre su cobro.')

@section('content')
    <div class="card">
        <div class="cap" style="flex-wrap: wrap; gap: .75rem">
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

            <form method="get" style="display:flex; gap:.4rem; min-width: 18rem; flex: 1">
                @if ($status !== '')<input type="hidden" name="status" value="{{ $status }}">@endif
                <input type="search" name="q" value="{{ $search }}"
                       placeholder="Número, pedido, últimos cuatro dígitos o referencia">
                <button type="submit">Buscar</button>
                @if ($search !== '')
                    <a class="btn quiet" href="{{ route('redsys.subscriptions.panel.list', ['status' => $status ?: null]) }}">Quitar</a>
                @endif
            </form>
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
                <colgroup>
                    <col style="width:24%"><col style="width:13%"><col style="width:13%">
                    <col style="width:12%"><col style="width:15%"><col>
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
                            {{ $s->billableName() }}
                            <span>Nº {{ $s->id }}@if ($s->name !== 'default'), {{ $s->name }}@endif</span>
                        </td>
                        <td>
                            <span class="pill {{ $s->status }}">{{ $s->statusLabel() }}</span>
                            @if ($s->failures)
                                <span class="muted" style="font-size:.78rem; display:block; margin-top:.15rem">
                                    {{ $s->failures }} {{ $s->failures === 1 ? 'intento' : 'intentos' }}
                                </span>
                            @endif
                        </td>
                        <td class="num amount">
                            {{ number_format($s->amount_in_cents / 100, 2, ',', '.') }} €
                            <span>/ {{ ['monthly' => 'mes', 'weekly' => 'semana', 'yearly' => 'año'][$s->interval] ?? $s->interval }}</span>
                        </td>
                        <td class="hide-narrow digits">
                            @if ($s->card_last_four)
                                ···· {{ $s->card_last_four }}
                                <span class="muted" style="font-size:.78rem; display:block">
                                    caduca {{ substr($s->card_expiry, 2, 2) }}/{{ substr($s->card_expiry, 0, 2) }}
                                </span>
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
                        <td>
                            @if ($s->card_token && $s->status !== Subscription::CANCELED)
                                <div class="row-acts">
                                    <form method="post" action="{{ route('redsys.subscriptions.panel.charge', $s) }}"
                                          onsubmit="return confirm('Se cobrarán {{ number_format($s->amount_in_cents / 100, 2, ',', '.') }} € a {{ addslashes($s->billableName()) }} ahora mismo.')">
                                        @csrf
                                        <button type="submit">Cobrar ahora</button>
                                    </form>
                                    <form method="post" action="{{ route('redsys.subscriptions.panel.cancel', $s) }}"
                                          onsubmit="return confirm('{{ addslashes($s->billableName()) }} dejará de pagar. Conserva el acceso hasta el final del periodo que ya pagó.')">
                                        @csrf
                                        <button type="submit" class="quiet risky">Dar de baja</button>
                                    </form>
                                </div>
                            @endif
                        </td>
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

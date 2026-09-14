@php use MarioDevv\RedsysSubscriptions\Subscription; @endphp
@extends('redsys-subscriptions::layout')

@section('title', 'Resumen')
@section('heading', 'Resumen')
@section('subheading', 'Cómo van los cobros recurrentes ahora mismo.')

@section('content')
    <div class="kpis">
        <div class="kpi">
            <div class="k">Ingresos al mes</div>
            <div class="v">{{ number_format($stats['mrr'] / 100, 2, ',', '.') }} <small>€</small></div>
            <div class="s">Suma de lo activo, llevado a mes</div>
        </div>
        <a class="kpi" href="{{ route('redsys.subscriptions.panel.list', ['status' => Subscription::ACTIVE]) }}">
            <div class="k">Activas</div>
            <div class="v">{{ $stats['counts'][Subscription::ACTIVE] }}</div>
            <div class="s">De {{ $stats['all'] }} suscripciones</div>
        </a>
        <a class="kpi" href="{{ route('redsys.subscriptions.panel.list', ['status' => Subscription::PAST_DUE]) }}">
            <div class="k">Sin cobrar</div>
            <div class="v">{{ $stats['counts'][Subscription::PAST_DUE] + $stats['counts'][Subscription::PAST_DUE_SCA] }}</div>
            <div class="s">En reintento o esperando al titular</div>
        </a>
        <a class="kpi" href="{{ route('redsys.subscriptions.panel.list', ['status' => Subscription::INCOMPLETE]) }}">
            <div class="k">Sin terminar</div>
            <div class="v">{{ $stats['counts'][Subscription::INCOMPLETE] }}</div>
            <div class="s">Empezaron el alta y no la acabaron</div>
        </a>
    </div>

    <div class="pair">
        <div class="card">
            <div class="cap">
                <h2>Necesitan atención</h2>
                <a href="{{ route('redsys.subscriptions.panel.list', ['status' => Subscription::PAST_DUE]) }}">Ver reintentos</a>
            </div>

            @if ($attention->isEmpty())
                <div class="empty">
                    <b>Nadie debe nada.</b>
                    <p>Todas las suscripciones se están cobrando bien.</p>
                </div>
            @else
                <table>
                    <tbody>
                    @foreach ($attention as $s)
                        <tr>
                            <td class="who">
                                {{ $s->billableName() }}
                                <span>
                                    @if ($s->status === Subscription::PAST_DUE_SCA)
                                        El banco pide que autentique el pago
                                    @else
                                        {{ $s->failures }} {{ $s->failures === 1 ? 'intento fallido' : 'intentos fallidos' }}
                                    @endif
                                </span>
                            </td>
                            <td class="num"><span class="pill {{ $s->status }}">{{ $s->statusLabel() }}</span></td>
                            <td class="num amount">{{ number_format($s->amount_in_cents / 100, 2, ',', '.') }} €</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="card">
            <div class="cap">
                <h2>Próximos cobros</h2>
                <a href="{{ route('redsys.subscriptions.panel.list') }}">Ver todas</a>
            </div>

            @if ($upcoming->isEmpty())
                <div class="empty">
                    <b>No hay cobros programados.</b>
                    <p>Aparecerán cuando alguien registre su tarjeta.</p>
                </div>
            @else
                <table>
                    <tbody>
                    @foreach ($upcoming as $s)
                        <tr>
                            <td class="who">
                                {{ $s->billableName() }}
                                <span class="digits">
                                    @if ($s->card_last_four)
                                        Tarjeta ···· {{ $s->card_last_four }}
                                    @else
                                        Referencia guardada
                                    @endif
                                </span>
                            </td>
                            <td class="num muted nowrap" title="{{ $s->next_charge_at->format('j/n/Y') }}">
                                {{ Subscription::humanDate($s->next_charge_at) }}
                            </td>
                            <td class="num amount">{{ number_format($s->amount_in_cents / 100, 2, ',', '.') }} €</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
@endsection

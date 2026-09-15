@php use MarioDevv\RedsysSubscriptions\Subscription; @endphp
@extends('redsys-subscriptions::layout')

@section('title', 'Resumen')
@section('heading', 'Resumen')
@section('subheading', 'Cómo van los cobros recurrentes ahora mismo.')

@section('actions')
    {{-- El mismo pase que hace el cron, pero cuando tú lo dices. --}}
    <form method="post" action="{{ route('redsys.subscriptions.panel.run') }}"
          onsubmit="return confirm('Se cobrará ahora mismo todo lo que esté vencido. Los cobros salen de verdad si el entorno es el real.')">
        @csrf
        <button type="submit">Lanzar cobro ahora</button>
    </form>
@endsection

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
                                <a href="{{ route('redsys.subscriptions.panel.show', $s) }}">{{ $s->billableName() }}</a>
                                <span class="why {{ $s->status }}">
                                    @if ($s->status === Subscription::PAST_DUE_SCA)
                                        0195 · el banco pide que autentique
                                    @else
                                        Intento {{ $s->failures }} de {{ Subscription::MAX_FAILURES }}
                                    @endif
                                </span>
                                <span class="digits">
                                    Nº {{ $s->id }}@if ($s->card_last_four) · ···· {{ $s->card_last_four }}@endif
                                </span>
                            </td>
                            <td><span class="pill {{ $s->status }}">{{ $s->statusLabel() }}</span></td>
                            <td class="num amount">{{ $s->amountLabel() }}</td>
                            <td>@include('redsys-subscriptions::partials.actions')</td>
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
                            <td class="num amount">{{ $s->amountLabel() }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="cap">
            <h2>Últimos cobros</h2>
            <span class="pill soon">Hoja de ruta · todavía no se guarda ningún intento</span>
        </div>

        <table>
            <colgroup>
                <col style="width:14%"><col style="width:24%"><col style="width:14%">
                <col><col style="width:12%">
            </colgroup>
            <thead>
                <tr>
                    <th>Cuándo</th>
                    <th>Titular</th>
                    <th class="hide-narrow">Pedido</th>
                    <th>Respuesta de Redsys</th>
                    <th class="num">Importe</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($charges as $charge)
                <tr>
                    <td class="digits">{{ $charge->created_at->format('j/n H:i') }}</td>
                    <td class="who">{{ $charge->subscription->billableName() }}</td>
                    <td class="hide-narrow digits">{{ $charge->order }}</td>
                    <td class="why {{ $charge->status }}">{{ $charge->responseLabel() }}</td>
                    <td class="num amount">{{ $charge->amountLabel() }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">
                        <div class="empty">
                            <b>Aquí irá cada intento de cobro.</b>
                            <p>
                                Hoy solo se guarda el estado final de la suscripción, no lo que
                                respondió Redsys en cada pase. Sin eso el panel no puede decirte
                                si fue un <code>0180</code> o un <code>0195</code>.
                            </p>
                        </div>
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection

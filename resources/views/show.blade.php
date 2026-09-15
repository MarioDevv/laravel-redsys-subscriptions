@php use MarioDevv\RedsysSubscriptions\Subscription; @endphp
@extends('redsys-subscriptions::layout')

@section('title', 'Suscripción Nº ' . $subscription->id)

@section('crumbs')
    <nav class="crumbs">
        <a href="{{ route('redsys.subscriptions.panel.list') }}">← Suscripciones</a>
        <span>/</span>
        <span class="digits">Nº {{ $subscription->id }}</span>
    </nav>
@endsection

@section('heading')
    {{ $subscription->billableName() }}
    <span class="pill {{ $subscription->status }}">{{ $subscription->statusLabel() }}</span>
@endsection

@section('subheading')
    Alta el {{ $subscription->created_at->format('j/n/Y') }} ·
    {{ $subscription->amountLabel() }}
    {{ ['monthly' => 'al mes', 'weekly' => 'a la semana', 'yearly' => 'al año'][$subscription->interval] ?? $subscription->interval }}
@endsection

@section('actions')
    @include('redsys-subscriptions::partials.actions', ['s' => $subscription])
@endsection

@section('content')
    {{-- El aviso que evita el error caro: aquí es donde alguien cancelaría a un
         cliente que paga por confundir 0195 con un rechazo. --}}
    @if ($subscription->status === Subscription::PAST_DUE_SCA)
        <div class="msg wait">
            <b>El banco pide autenticación del titular (0195)</b>
            Esto no es un rechazo de tarjeta: la referencia sigue viva y volverá a cobrar el
            mes que viene. Manda al titular a autenticar. No reintentes en bucle y no la
            canceles.
        </div>
    @elseif ($subscription->status === Subscription::CANCELED && $subscription->card_token === null)
        <div class="msg danger">
            <b>La referencia de la tarjeta ya no vale</b>
            Redsys respondió <code>SIS0321</code> y la referencia se borró. Para volver a
            cobrarle hace falta que el titular registre una tarjeta nueva.
        </div>
    @endif

    <div class="pair">
        <div class="card">
            <div class="cap">
                <h2>Historial de cobros</h2>
                <span class="muted">{{ $charges->count() }} {{ $charges->count() === 1 ? 'intento' : 'intentos' }}</span>
            </div>

            <table>
                <colgroup>
                    <col style="width:20%"><col style="width:20%"><col><col style="width:16%">
                </colgroup>
                <thead>
                    <tr>
                        <th>Cuándo</th>
                        <th class="hide-narrow">Pedido</th>
                        <th>Respuesta de Redsys</th>
                        <th class="num">Importe</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($charges as $charge)
                    <tr>
                        <td class="digits">{{ $charge->created_at->format('j/n H:i') }}</td>
                        <td class="hide-narrow digits">{{ $charge->order }}</td>
                        <td class="why {{ $charge->tone() }}">{{ $charge->responseLabel() }}</td>
                        <td class="num amount">{{ $charge->amountLabel() }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">
                            <div class="empty">
                                <b>Todavía no se le ha cobrado nada.</b>
                                <p>Aparecerá en cuanto pase el primer cobro.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="stack">
            <div class="card">
                <div class="cap"><h2>Tarjeta guardada</h2></div>

                @if ($subscription->card_token)
                    <dl class="kv">
                        <dt>Tarjeta</dt>
                        <dd class="digits">
                            ···· ···· ···· {{ $subscription->card_last_four ?? '????' }}
                            @if ($subscription->card_expiry)
                                <span class="expiry">
                                    caduca {{ substr($subscription->card_expiry, 2, 2) }}/{{ substr($subscription->card_expiry, 0, 2) }}
                                </span>
                            @endif
                        </dd>
                        {{-- La referencia son 40 caracteres que nadie lee enteros;
                             se enseñan las puntas, que es lo que se compara. --}}
                        <dt>Referencia</dt>
                        <dd><code title="{{ $subscription->card_token }}">
                            @if (strlen($subscription->card_token) > 22)
                                {{ substr($subscription->card_token, 0, 12) }}…{{ substr($subscription->card_token, -6) }}
                            @else
                                {{ $subscription->card_token }}
                            @endif
                        </code></dd>
                        <dt>COF transaction id</dt>
                        <dd><code>{{ $subscription->cof_transaction_id ?? '—' }}</code></dd>
                        <dt>Pedido del alta</dt>
                        <dd class="digits">{{ $subscription->checkout_order ?? '—' }}</dd>
                    </dl>
                @else
                    <div class="empty">
                        <b>No hay tarjeta guardada.</b>
                        <p>Sin referencia no se le puede cobrar nada.</p>
                    </div>
                @endif

                {{-- El operador no puede pasar el 3DS por el titular, así que
                     lo que se le da es el enlace para que lo haga él. --}}
                <div class="msg" style="margin: 0 1.1rem 1.1rem; display: block">
                    <b>Enlace para cambiar la tarjeta</b>
                    <p class="muted" style="margin: .2rem 0 .5rem; font-size: .85rem">
                        Mándaselo al titular. Caduca en 7 días y conserva esta suscripción
                        con su número y su historial.
                    </p>
                    <div class="copyable">
                        <code>{{ $cardLink }}</code>
                        <button type="button" class="quiet" data-copy="{{ $cardLink }}">Copiar</button>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="cap"><h2>Cobro</h2></div>
                <dl class="kv">
                    <dt>Importe</dt>
                    <dd>{{ $subscription->amountLabel() }}</dd>
                    <dt>Intervalo</dt>
                    <dd>{{ ['monthly' => 'mensual', 'weekly' => 'semanal', 'yearly' => 'anual'][$subscription->interval] ?? $subscription->interval }}</dd>
                    <dt>Próximo cobro</dt>
                    <dd>
                        @if ($subscription->next_charge_at?->isPast())
                            <span class="late" title="{{ $subscription->next_charge_at->format('j/n/Y H:i') }}">
                                venció {{ Subscription::humanDate($subscription->next_charge_at) }}
                            </span>
                        @else
                            <span title="{{ $subscription->next_charge_at?->format('j/n/Y') }}">
                                {{ Subscription::humanDate($subscription->next_charge_at) }}
                            </span>
                        @endif
                    </dd>
                    <dt>Fallos seguidos</dt>
                    <dd>{{ $subscription->failures }} de {{ Subscription::MAX_FAILURES }}</dd>
                    <dt>Da acceso al servicio</dt>
                    <dd>
                        @if ($subscription->valid())
                            <span class="pill active">Sí</span>
                            @if ($subscription->onGracePeriod())
                                <span class="expiry">
                                    dada de baja, le queda hasta el {{ $subscription->ends_at->format('j/n/Y') }}
                                </span>
                            @endif
                        @else
                            <span class="pill canceled">No</span>
                        @endif
                    </dd>
                </dl>
            </div>
        </div>
    </div>
@endsection

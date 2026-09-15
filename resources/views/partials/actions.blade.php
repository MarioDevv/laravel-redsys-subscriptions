@php use MarioDevv\RedsysSubscriptions\Subscription; @endphp
{{-- Las dos acciones que se piden por teléfono. Sin tarjeta guardada no hay
     nada que cobrar, y una baja ya hecha no se repite. --}}
@if ($s->card_token && $s->status !== Subscription::CANCELED)
    <div class="row-acts">
        <form method="post" action="{{ route('redsys.subscriptions.panel.charge', $s) }}"
              onsubmit="return confirm('Se cobrarán {{ $s->amountLabel() }} a {{ addslashes($s->billableName()) }} ahora mismo.')">
            @csrf
            <button type="submit" class="primary">Cobrar ahora</button>
        </form>
        <form method="post" action="{{ route('redsys.subscriptions.panel.cancel', $s) }}"
              onsubmit="return confirm('{{ addslashes($s->billableName()) }} dejará de pagar. Conserva el acceso hasta el final del periodo que ya pagó.')">
            @csrf
            <button type="submit" class="quiet risky">Dar de baja</button>
        </form>
    </div>
@endif

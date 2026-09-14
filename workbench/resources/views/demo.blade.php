<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Banco de pruebas · Redsys Subscriptions</title>
<style>
    :root { --paper: #f7f6f3; --ink: #1b1a18; --muted: #6b6862; --line: #e4e1da;
            --accent: #1c3d5a; --card: #fffefc; }
    * { box-sizing: border-box; }
    body { margin: 0; padding: 3rem 1.5rem 5rem; background: var(--paper); color: var(--ink);
           font: 15px/1.55 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; }
    main { max-width: 52rem; margin: 0 auto; }
    h1 { font-size: 1.35rem; margin: 0 0 .2rem; letter-spacing: -.02em; }
    h2 { font-size: .76rem; text-transform: uppercase; letter-spacing: .09em; color: var(--muted);
         margin: 2.25rem 0 .8rem; font-weight: 600; }
    p.lead { margin: 0 0 1.75rem; color: var(--muted); }
    .panel { background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: 1.1rem; }
    .open { display: inline-block; background: var(--accent); color: #fff; font-weight: 600;
            padding: .6rem 1.1rem; border-radius: 7px; text-decoration: none; }
    .open:hover { background: #16334c; }
    form { display: inline; }
    button { font: inherit; cursor: pointer; border-radius: 6px; border: 1px solid var(--line);
             background: var(--card); color: var(--ink); padding: .4rem .7rem; }
    button:hover:not(:disabled) { border-color: #c5c0b4; }
    button.tiny { padding: .22rem .48rem; font-size: .75rem;
                  font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
    button:disabled { opacity: .4; cursor: not-allowed; }
    input, select { font: inherit; padding: .4rem .6rem; border: 1px solid var(--line);
                    border-radius: 6px; background: var(--card); }
    input { width: 6.5rem; text-align: right; }
    table { width: 100%; border-collapse: collapse; margin-top: .4rem; }
    td { padding: .5rem .4rem; border-bottom: 1px solid #f1efea; font-size: .88rem; }
    tr:last-child td { border-bottom: 0; }
    .dim { color: var(--muted); }
    .warn { padding: .9rem 1.1rem; border-left: 3px solid #c2761a; background: #fdf4e6;
            border-radius: 6px; margin: 1.75rem 0; font-size: .9rem; }
    .warn code { display: block; margin-top: .5rem; padding: .6rem .7rem; background: #2a2724;
                 color: #f4efe6; border-radius: 5px; overflow-x: auto; white-space: pre; font-size: 12px; }
    pre { background: #2a2724; color: #f4efe6; padding: .9rem 1.1rem; border-radius: 8px;
          overflow-x: auto; font-size: 12.5px; }
    footer { margin-top: 2.5rem; color: #93908a; font-size: .8rem; }
</style>
</head>
<body>
<main>
    <h1>Banco de pruebas</h1>
    <p class="lead">
        Esto no es el paquete: es el taller para desarrollarlo. Fabrica suscripciones,
        muévelas por la máquina de estados y míralas en el panel de verdad.
    </p>

    <a class="open" href="{{ route('redsys.subscriptions.panel.index') }}">Abrir el panel →</a>

    @unless ($configured)
        <div class="warn">
            <strong>Sin credenciales.</strong> El alta real está desactivada; el ciclo simulado
            funciona igual. Para el alta contra Redsys:
            <code>REDSYS_MERCHANT_CODE=… REDSYS_TERMINAL=… REDSYS_SECRET_KEY=… \
  vendor/bin/testbench serve</code>
        </div>
    @endunless

    <h2>Fabricar una suscripción</h2>
    <div class="panel">
        <form method="post" action="/subscribe">
            @csrf
            <input type="number" name="cents" value="1500" min="1" aria-label="Importe en céntimos">
            <span class="dim">céntimos</span>
            <select name="interval" aria-label="Periodicidad">
                <option value="monthly">cada mes</option>
                <option value="weekly">cada semana</option>
                <option value="yearly">cada año</option>
            </select>
            <button type="submit" @disabled(! $configured)>Alta real en Redsys</button>
            <button type="submit" formaction="/subscribe-fake"
                    title="Pasa por el mismo completeCheckout() con lo que devolveria Redsys">Alta simulada</button>
        </form>
    </div>

    <h2>Mover la máquina de estados</h2>
    <div class="panel">
        @if ($subscriptions->isEmpty())
            <p class="dim" style="margin:.4rem 0">Fabrica una arriba.</p>
        @else
            <table>
                @foreach ($subscriptions as $s)
                    <tr>
                        <td class="dim">#{{ $s->id }}</td>
                        <td><strong>{{ $s->statusLabel() }}</strong>
                            <span class="dim" style="font-size:.78rem">{{ $s->status }}</span></td>
                        <td class="dim">{{ number_format($s->amount_in_cents / 100, 2, ',', '.') }} €</td>
                        <td style="text-align:right">
                            @if ($s->card_token)
                                <form method="post" action="/due/{{ $s->id }}">
                                    @csrf<button type="submit" class="tiny" title="Adelanta next_charge_at">vencer</button>
                                </form>
                                @foreach (['authorized' => '0000', 'sca_required' => '0195',
                                           'declined' => '0190', 'token_dead' => 'SIS0321'] as $value => $code)
                                    <form method="post" action="/simulate/{{ $s->id }}/{{ $value }}">
                                        @csrf<button type="submit" class="tiny">{{ $code }}</button>
                                    </form>
                                @endforeach
                            @else
                                <span class="dim">esperando la tarjeta</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>

    <div style="margin-top:1rem">
        <form method="post" action="/charge">
            @csrf<button type="submit">php artisan redsys:charge-subscriptions</button>
        </form>
        @if ($subscriptions->isNotEmpty())
            <form method="post" action="/reset">
                @csrf<button type="submit">borrar todo</button>
            </form>
        @endif
    </div>

    @if ($output)<pre>{{ $output }}</pre>@endif

    <footer>
        Los códigos aplican un resultado de cobro con la misma llamada que usa el comando.
        Tres <code>0190</code> seguidos cancelan; un <code>0195</code> no.
    </footer>
</main>
</body>
</html>

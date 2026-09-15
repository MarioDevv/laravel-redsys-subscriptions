@php use MarioDevv\RedsysSubscriptions\Subscription; @endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title', 'Suscripciones')</title>
{{-- Inter para el texto y JetBrains Mono para las cifras. Si no hay red, la
     pila de respaldo es la del sistema y el panel se lee igual. --}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap">
<style>
    :root {
        --bg:        #f6f7f9;
        --surface:   #ffffff;
        --line:      #e4e8ed;
        --line-soft: #f0f2f5;
        --ink:       #0f1419;
        --body:      #59616d;
        --muted:     #8b939f;
        --accent:    #1f6feb;
        --accent-bg: #e8f0fe;
        --shadow:    0 1px 2px rgba(15,20,25,.05), 0 2px 8px rgba(15,20,25,.035);
        --shadow-sm: 0 1px 2px rgba(15,20,25,.06);
        --radius:    12px;

        /* El color del estado no es decoración: dice qué hacer con la fila.
           Verde no toques, ámbar reintentando, violeta espera al titular,
           gris terminado. Rojo se reserva para lo que destruye algo. */
        --ok-bg:   #e7f5ec;  --ok-ink:   #15803d;
        --warn-bg: #fbf0e2;  --warn-ink: #b45309;
        --wait-bg: #f0eafb;  --wait-ink: #6d28d9;
        --off-bg:  #eef1f4;  --off-ink:  #64748b;
        --bad-bg:  #fdecec;  --bad-ink:  #b91c1c;

        --font: "Inter", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        --mono: "JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    }
    * { box-sizing: border-box; }
    html, body { height: 100%; }
    body {
        margin: 0; background: var(--bg); color: var(--body);
        font: 14px/1.55 var(--font);
        -webkit-font-smoothing: antialiased;
    }
    a { color: inherit; text-decoration: none; }
    h1, h2, h3 { color: var(--ink); margin: 0; }
    :focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; border-radius: 4px; }

    .app { display: grid; grid-template-columns: 15rem 1fr; min-height: 100%; }

    /* ---------- Barra lateral ---------- */
    .side { background: var(--surface); border-right: 1px solid var(--line);
            display: flex; flex-direction: column; }
    .brand { display: flex; align-items: center; gap: .6rem; padding: 1.15rem 1.1rem 1rem; }
    .brand .glyph {
        width: 1.85rem; height: 1.85rem; border-radius: 7px; background: var(--accent);
        color: #fff; display: grid; place-items: center; font-weight: 700; font-size: .85rem;
        flex: none;
    }
    .brand b { color: var(--ink); font-size: .95rem; font-weight: 600; display: block; line-height: 1.2; }
    .brand small { color: var(--muted); font-size: .76rem; display: block;
                   white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    .side nav { padding: .35rem .6rem; display: flex; flex-direction: column; gap: .1rem; }
    .side nav a {
        display: flex; align-items: center; gap: .6rem; padding: .5rem .65rem;
        border-radius: 7px; color: var(--body); font-size: .9rem; font-weight: 500;
    }
    .side nav a svg { width: 1.05rem; height: 1.05rem; flex: none; color: var(--muted); }
    .side nav a:hover { background: var(--line-soft); }
    .side nav a.on { background: var(--accent-bg); color: var(--accent); }
    .side nav a.on svg { color: var(--accent); }
    .side nav a .tag {
        margin-left: auto; font-size: .76rem; font-weight: 600; color: var(--muted);
        background: var(--line-soft); border-radius: 99px; padding: .05rem .45rem;
    }
    .side nav a.on .tag { background: #fff; color: var(--accent); }

    /* El recuento por estado, siempre a la vista y siempre en el mismo orden:
       es el primer sitio donde se mira si algo se ha torcido esta noche. */
    .states { padding: .9rem 1.1rem 0; }
    .states h3 { font-size: .68rem; font-weight: 600; letter-spacing: .06em; color: var(--muted);
                 text-transform: uppercase; margin-bottom: .5rem; }
    .states a { display: flex; align-items: center; gap: .5rem; padding: .27rem 0; font-size: .84rem; }
    .states a:hover { color: var(--ink); }
    .states i { width: .45rem; height: .45rem; border-radius: 50%; flex: none; }
    .states b { margin-left: auto; font-family: var(--mono); font-size: .78rem; font-weight: 500; color: var(--muted); }
    .dot.active { background: var(--ok-ink); }
    .dot.past_due { background: var(--warn-ink); }
    .dot.past_due_sca { background: var(--wait-ink); }
    .dot.canceled, .dot.incomplete { background: var(--off-ink); }

    .side .foot { margin-top: auto; padding: 1rem 1.1rem; border-top: 1px solid var(--line); }
    .env { display: inline-flex; align-items: center; gap: .4rem; font-size: .78rem; font-weight: 600;
           padding: .2rem .55rem; border-radius: 99px; }
    .env.test { background: var(--warn-bg); color: var(--warn-ink); }
    .env.live { background: var(--ok-bg); color: var(--ok-ink); }
    .side .foot p { margin: .5rem 0 0; font-size: .76rem; color: var(--muted); line-height: 1.45; }

    /* ---------- Contenido ---------- */
    .main { padding: 2rem 2.25rem 4rem; min-width: 0; }
    .head { margin-bottom: 1.5rem; display: flex; align-items: center;
            justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
    .crumbs { display: flex; align-items: center; gap: .4rem; margin-bottom: .7rem;
              color: var(--muted); font-size: .85rem; }
    .crumbs a:hover { color: var(--ink); }
    .head h1 { font-size: 1.32rem; font-weight: 600; letter-spacing: -.01em;
               display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; }
    .head p { margin: .2rem 0 0; color: var(--muted); font-size: .9rem; }

    .card { background: var(--surface); border: 1px solid var(--line);
            border-radius: var(--radius); box-shadow: var(--shadow); }
    /* Una sola regla para el aire entre bloques, sea card, pareja o rejilla de
       cifras. Con `.card + .card` se pegaban los que no eran hermanos directos. */
    .stack > * + * { margin-top: 1.15rem; }
    .card .cap { padding: .95rem 1.1rem; border-bottom: 1px solid var(--line-soft);
                 display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
    .card .cap h2 { font-size: .95rem; font-weight: 600; }
    /* Solo el enlace suelto del encabezado («Ver todas»), no lo que cuelgue
       dentro. Sin el `>` se lleva por delante los filtros y su estado activo. */
    .card .cap > a { color: var(--accent); font-size: .85rem; font-weight: 500; }

    .msg { display: flex; gap: .6rem; padding: .75rem 1rem; background: var(--surface);
           border: 1px solid var(--line); border-left: 3px solid var(--accent);
           border-radius: 8px; box-shadow: var(--shadow-sm); margin-bottom: 1.15rem;
           color: var(--ink); font-size: .9rem; }
    .msg.danger { border-left-color: var(--bad-ink); background: var(--bad-bg); border-color: #f3d3d3;
                  color: var(--bad-ink); display: block; }
    .msg.wait { border-left-color: var(--wait-ink); background: var(--wait-bg); border-color: #ddd0f5;
                color: var(--wait-ink); display: block; }
    .msg.danger b, .msg.wait b { display: block; margin-bottom: .2rem; color: inherit; }
    .msg.danger pre { margin: .6rem 0 0; padding: .7rem .8rem; border-radius: 8px; overflow-x: auto;
                      background: var(--ink); color: #d6e2ee; font-family: var(--mono); font-size: .78rem; }

    /* ---------- Tablas ---------- */
    table { width: 100%; border-collapse: collapse; }
    thead th { text-align: left; font-size: .76rem; font-weight: 600; color: var(--muted);
               padding: .65rem 1.1rem; border-bottom: 1px solid var(--line-soft); white-space: nowrap; }
    tbody td { padding: .72rem 1.1rem; border-bottom: 1px solid var(--line-soft); vertical-align: middle; }
    tbody tr:last-child td { border-bottom: 0; }
    tbody tr:hover { background: #fbfcfe; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
    .who { color: var(--ink); font-weight: 550; }
    .who a:hover { color: var(--accent); text-decoration: underline; }
    .who span { display: block; font-weight: 400; color: var(--muted); font-size: .78rem; }
    /* El motivo va en el color de su estado: en una lista de seis, es lo que
       distingue «reintenta solo» de «manda al titular al banco». */
    .why { display: block; font-weight: 500; font-size: .79rem; margin-top: .1rem; }
    .why.past_due, .why.warn { color: var(--warn-ink); }
    .why.past_due_sca, .why.wait { color: var(--wait-ink); }
    .why.ok { color: var(--ok-ink); }
    .why.off { color: var(--muted); }
    /* Importes, pedidos y últimos cuatro dígitos en monoespaciada: se comparan
       en columna y se dictan por teléfono. Sin webfont, la del sistema basta. */
    .amount { color: var(--ink); font-weight: 600; font-family: var(--mono); font-size: .87rem;
              font-variant-numeric: tabular-nums; white-space: nowrap; }
    .amount span { color: var(--muted); font-weight: 400; }
    .muted { color: var(--muted); }
    .digits { font-family: var(--mono); font-size: .85rem; font-variant-numeric: tabular-nums; }
    .expiry { display: block; font-size: .78rem; color: var(--muted); }
    .nowrap { white-space: nowrap; }

    /* El nombre y los importes no parten: se cortan antes con puntos. */
    .t-subs { table-layout: fixed; }
    .t-subs .who { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .t-subs .who span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .t-subs .digits { white-space: nowrap; }
    .t-subs .row-acts { flex-wrap: nowrap; }

    /* Estado: pastilla con punto y palabra. El color nunca va solo. */
    .pill { display: inline-flex; align-items: center; gap: .4rem; padding: .18rem .55rem;
            border-radius: 99px; font-size: .79rem; font-weight: 600; white-space: nowrap; }
    .pill::before { content: ""; width: .4rem; height: .4rem; border-radius: 50%; background: currentColor; }
    .pill.active       { background: var(--ok-bg);   color: var(--ok-ink); }
    .pill.past_due     { background: var(--warn-bg); color: var(--warn-ink); }
    /* Violeta, no rojo: 0195 no es un rechazo de tarjeta, es el titular
       pendiente de autenticar. Pintarlo de error hace que se cancelen
       clientes que pagan. */
    .pill.past_due_sca { background: var(--wait-bg); color: var(--wait-ink); }
    /* Gris, no rojo: una baja es un final ordenado, no una avería. */
    .pill.canceled     { background: var(--off-bg);  color: var(--off-ink); }
    .pill.incomplete   { background: var(--off-bg);  color: var(--off-ink); }
    /* Estas dos no son estados de una suscripción, son el estado de la
       configuración. Van aparte para no colgarse de un nombre de estado. */
    .pill.alert        { background: var(--bad-bg);  color: var(--bad-ink); }

    /* ---------- Controles ---------- */
    button, .btn {
        font: inherit; font-size: .86rem; font-weight: 500; cursor: pointer;
        padding: .38rem .7rem; border: 1px solid var(--line); border-radius: 7px;
        background: var(--surface); color: var(--ink); box-shadow: var(--shadow-sm);
        white-space: nowrap; display: inline-flex; align-items: center; gap: .35rem;
    }
    button:hover, .btn:hover { background: #fbfcfe; border-color: #d9dfe9; }
    /* Una acción principal por fila. Cobrar es la que se busca cuando llama un
       cliente; dar de baja no se pulsa por error si no compite en peso. */
    button.primary, .btn.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
    button.primary:hover, .btn.primary:hover { background: #1a5fd0; border-color: #1a5fd0; }
    button.quiet { box-shadow: none; border-color: transparent; background: transparent; color: var(--body); }
    button.quiet:hover { background: var(--line-soft); }
    button.risky:hover { color: var(--bad-ink); border-color: #f0c7c2; background: #fef7f6; }
    form { display: inline; }
    /* Las dos acciones van en una línea. Si «Dar de baja» cae debajo del botón
       azul deja de leerse como la alternativa y parece otra cosa. */
    .row-acts { display: flex; gap: .35rem; justify-content: flex-end; flex-wrap: nowrap; }
    td:has(> .row-acts) { width: 1%; white-space: nowrap; }

    input[type=search] {
        font: inherit; font-size: .88rem; padding: .42rem .7rem; border: 1px solid var(--line);
        border-radius: 7px; background: var(--surface); color: var(--ink); width: 100%;
        box-shadow: var(--shadow-sm);
    }
    input[type=search]::placeholder { color: var(--muted); }
    input[type=search]:focus { border-color: var(--accent); outline: none;
                               box-shadow: 0 0 0 3px var(--accent-bg); }

    /* Ancho fijo: el buscador no tiene por qué comerse la fila entera, y los
       filtros pierden sitio si crece. */
    .search { display: flex; gap: .4rem; width: 27rem; max-width: 100%; }
    .chips { display: flex; gap: .35rem; flex-wrap: wrap; }
    .chips a { font-size: .84rem; font-weight: 500; padding: .3rem .65rem; border-radius: 99px;
               color: var(--body); background: var(--line-soft); }
    .chips a:hover { background: #e7eaf1; }
    .chips a.on { background: var(--ink); color: #fff; }

    /* Cabecera fija al desplazar listas largas */
    thead th { position: sticky; top: 0; background: var(--surface); z-index: 1; }
    .sorter { display: inline-flex; gap: .25rem; color: inherit; }
    .sorter:hover { color: var(--ink); }
    .sorter.on { color: var(--ink); }
    .num .sorter { justify-content: flex-end; }
    .late { color: var(--warn-ink); font-weight: 550; }
    .found { margin: 0; padding: .6rem 1.1rem; border-bottom: 1px solid var(--line-soft);
             color: var(--muted); font-size: .84rem; }

    .empty { padding: 3.5rem 1.5rem; text-align: center; }
    .empty b { display: block; color: var(--ink); margin-bottom: .25rem; }
    .empty p { margin: 0; color: var(--muted); font-size: .9rem; }

    .pages { display: flex; align-items: center; justify-content: space-between;
             padding: .7rem 1.1rem; border-top: 1px solid var(--line-soft);
             font-size: .85rem; color: var(--muted); }
    .pages .btn.off { color: var(--muted); box-shadow: none; background: var(--line-soft); }

    /* ---------- Cifras y rejillas ---------- */
    .kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; }
    .kpi { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);
           box-shadow: var(--shadow); padding: 1rem 1.1rem; }
    a.kpi { display: block; }
    a.kpi:hover { border-color: #d3d9e6; box-shadow: 0 1px 2px rgba(14,31,53,.08), 0 4px 12px rgba(14,31,53,.06); }
    a.kpi:hover .k { color: var(--accent); }
    .kpi .k { font-size: .8rem; color: var(--muted); font-weight: 500; }
    .kpi .v { font-size: 1.6rem; font-weight: 650; color: var(--ink); letter-spacing: -.02em;
              font-variant-numeric: tabular-nums; margin-top: .25rem; line-height: 1.15; }
    .kpi .v small { font-size: .9rem; font-weight: 500; color: var(--muted); }
    .kpi .s { font-size: .79rem; color: var(--muted); margin-top: .15rem; }
    /* Asimétrico a propósito: lo que necesita atención lleva acciones y motivo,
       los próximos cobros son tres datos. Igualarlos aprieta al que decide. */
    .pair { display: grid; grid-template-columns: 1.75fr 1fr; gap: 1.15rem; align-items: start; }

    .kv { display: grid; grid-template-columns: 12rem 1fr; gap: .1rem 1rem; padding: .3rem 0; }
    .kv > dt { padding: .55rem 1.1rem; color: var(--muted); font-size: .87rem; }
    .kv > dd { padding: .55rem 1.1rem; margin: 0; color: var(--ink); overflow-wrap: anywhere; }
    /* Una URL firmada son 150 caracteres sin un solo espacio. Sin permitirle
       partir, estira la columna y mete scroll horizontal en toda la página. */
    .copyable { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; min-width: 0; }
    .copyable code { overflow-wrap: anywhere; min-width: 0; font-size: .8rem;
                     background: var(--line-soft); padding: .25rem .4rem; border-radius: 5px; }
    .kv > dd code { font-size: .84rem; background: var(--line-soft); padding: .1rem .35rem; border-radius: 4px; }

    @media (max-width: 66rem) { .kpis { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 62rem) {
        .pair { grid-template-columns: 1fr; }
        .kv { grid-template-columns: 1fr; }
        .kv > dt { padding-bottom: 0; }
        .app { grid-template-columns: 1fr; }
        .side { border-right: 0; border-bottom: 1px solid var(--line); }
        .side nav { flex-direction: row; flex-wrap: wrap; }
        .side .foot { margin-top: 0; padding: .75rem 1.1rem; }
        .side .foot p { display: none; }
        .main { padding: 1.5rem 1.1rem 3rem; }
        .hide-narrow { display: none; }
    }
    @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
</style>
</head>
<body>
<div class="app">
    <aside class="side">
        <div class="brand">
            <span class="glyph" aria-hidden="true">R</span>
            <span>
                <b>Suscripciones</b>
                <small class="digits">
                    @if (config('redsys-subscriptions.merchant_code'))
                        Comercio {{ config('redsys-subscriptions.merchant_code') }}
                    @else
                        Sin comercio
                    @endif
                </small>
            </span>
        </div>

        <nav>
            <a href="{{ route('redsys.subscriptions.panel.index') }}"
               class="{{ request()->routeIs('redsys.subscriptions.panel.index') ? 'on' : '' }}">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 11.5 10 5l7 6.5"/><path d="M5 10.5V16h10v-5.5"/>
                </svg>
                Resumen
            </a>
            <a href="{{ route('redsys.subscriptions.panel.list') }}"
               class="{{ request()->routeIs('redsys.subscriptions.panel.list') ? 'on' : '' }}">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
                    <path d="M4 6h12M4 10h12M4 14h8"/>
                </svg>
                Suscripciones
                <span class="tag">{{ $stats['all'] }}</span>
            </a>
            <a href="{{ route('redsys.subscriptions.panel.settings') }}"
               class="{{ request()->routeIs('redsys.subscriptions.panel.settings') ? 'on' : '' }}">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="10" cy="10" r="2.6"/>
                    <path d="M10 3.2v1.4M10 15.4v1.4M16.8 10h-1.4M4.6 10H3.2M14.8 5.2l-1 1M6.2 13.8l-1 1M14.8 14.8l-1-1M6.2 6.2l-1-1"/>
                </svg>
                Ajustes
            </a>
        </nav>

        <div class="states">
            <h3>Estados</h3>
            @foreach (Subscription::statusLabels() as $value => $label)
                <a href="{{ route('redsys.subscriptions.panel.list', ['status' => $value]) }}">
                    <i class="dot {{ $value }}" aria-hidden="true"></i>
                    {{ $label }}
                    <b>{{ $stats['counts'][$value] }}</b>
                </a>
            @endforeach
        </div>

        <div class="foot">
            @if (config('redsys-subscriptions.production'))
                <span class="env live">Entorno real</span>
                <p>Los cobros que lances aquí salen de verdad.</p>
            @else
                <span class="env test">Entorno de pruebas</span>
                <p>Ningún cobro es real mientras estés aquí.</p>
            @endif
        </div>
    </aside>

    <main class="main">
        @yield('crumbs')

        <div class="head">
            <div>
                <h1>@yield('heading')</h1>
                <p>@yield('subheading')</p>
            </div>
            @yield('actions')
        </div>

        @if (session('redsys_message'))
            <p class="msg">{{ session('redsys_message') }}</p>
        @endif

        <div class="stack">
            @yield('content')
        </div>
    </main>
</div>
<script>
    // Estas direcciones se pegan en el panel de Redsys o se mandan al titular.
    // Copiarlas a mano de una tabla es donde se cuelan los errores.
    document.querySelectorAll('[data-copy]').forEach(function (button) {
        button.addEventListener('click', function () {
            navigator.clipboard.writeText(button.dataset.copy).then(function () {
                var before = button.textContent;
                button.textContent = 'Copiada';
                setTimeout(function () { button.textContent = before; }, 1500);
            });
        });
    });
</script>
@stack('scripts')
</body>
</html>

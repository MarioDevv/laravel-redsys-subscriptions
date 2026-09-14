@php use MarioDevv\RedsysSubscriptions\Subscription; @endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title', 'Suscripciones')</title>
<style>
    :root {
        --bg:        #f7f9fc;
        --surface:   #ffffff;
        --line:      #e8ebf1;
        --line-soft: #f1f3f8;
        --ink:       #0e1f35;
        --body:      #4a5772;
        --muted:     #7b879d;
        --accent:    #5b57e0;
        --accent-bg: #eeedfd;
        --shadow:    0 1px 2px rgba(14,31,53,.06), 0 2px 8px rgba(14,31,53,.04);
        --shadow-sm: 0 1px 2px rgba(14,31,53,.07);
        --radius:    10px;

        --ok-bg:   #e7f6ee;  --ok-ink:   #10714a;  --ok-dot:   #17936a;
        --warn-bg: #fdf3e3;  --warn-ink: #8a5a13;  --warn-dot: #d99125;
        --info-bg: #eaf1fd;  --info-ink: #1c4f96;  --info-dot: #3b7cd8;
        --bad-bg:  #fdeceb;  --bad-ink:  #96271f;  --bad-dot:  #d0483c;
        --off-bg:  #f0f2f6;  --off-ink:  #5b6579;  --off-dot:  #9aa4b6;
    }
    * { box-sizing: border-box; }
    html, body { height: 100%; }
    body {
        margin: 0; background: var(--bg); color: var(--body);
        font: 14.5px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", sans-serif;
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
    .brand small { color: var(--muted); font-size: .76rem; }

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

    .side .foot { margin-top: auto; padding: 1rem 1.1rem; border-top: 1px solid var(--line); }
    .env { display: inline-flex; align-items: center; gap: .4rem; font-size: .78rem; font-weight: 600;
           padding: .2rem .55rem; border-radius: 99px; }
    .env.test { background: var(--warn-bg); color: var(--warn-ink); }
    .env.live { background: var(--ok-bg); color: var(--ok-ink); }
    .side .foot p { margin: .5rem 0 0; font-size: .76rem; color: var(--muted); line-height: 1.45; }

    /* ---------- Contenido ---------- */
    .main { padding: 2rem 2.25rem 4rem; min-width: 0; }
    .head { margin-bottom: 1.5rem; }
    .head h1 { font-size: 1.32rem; font-weight: 600; letter-spacing: -.01em; }
    .head p { margin: .2rem 0 0; color: var(--muted); font-size: .9rem; }

    .card { background: var(--surface); border: 1px solid var(--line);
            border-radius: var(--radius); box-shadow: var(--shadow); }
    .card + .card { margin-top: 1.15rem; }
    .card .cap { padding: .95rem 1.1rem; border-bottom: 1px solid var(--line-soft);
                 display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
    .card .cap h2 { font-size: .95rem; font-weight: 600; }
    .card .cap a { color: var(--accent); font-size: .85rem; font-weight: 500; }

    .msg { display: flex; gap: .6rem; padding: .75rem 1rem; background: var(--surface);
           border: 1px solid var(--line); border-left: 3px solid var(--accent);
           border-radius: 8px; box-shadow: var(--shadow-sm); margin-bottom: 1.15rem;
           color: var(--ink); font-size: .9rem; }

    /* ---------- Tablas ---------- */
    table { width: 100%; border-collapse: collapse; }
    thead th { text-align: left; font-size: .76rem; font-weight: 600; color: var(--muted);
               padding: .65rem 1.1rem; border-bottom: 1px solid var(--line-soft); white-space: nowrap; }
    tbody td { padding: .72rem 1.1rem; border-bottom: 1px solid var(--line-soft); vertical-align: middle; }
    tbody tr:last-child td { border-bottom: 0; }
    tbody tr:hover { background: #fbfcfe; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
    .who { color: var(--ink); font-weight: 550; }
    .who span { display: block; font-weight: 400; color: var(--muted); font-size: .78rem; }
    .amount { color: var(--ink); font-weight: 600; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .amount span { color: var(--muted); font-weight: 400; }
    .muted { color: var(--muted); }
    .digits { font-variant-numeric: tabular-nums; letter-spacing: .01em; }
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
    .pill.past_due_sca { background: var(--info-bg); color: var(--info-ink); }
    .pill.canceled     { background: var(--bad-bg);  color: var(--bad-ink); }
    .pill.incomplete   { background: var(--off-bg);  color: var(--off-ink); }

    /* ---------- Controles ---------- */
    button, .btn {
        font: inherit; font-size: .86rem; font-weight: 500; cursor: pointer;
        padding: .38rem .7rem; border: 1px solid var(--line); border-radius: 7px;
        background: var(--surface); color: var(--ink); box-shadow: var(--shadow-sm);
        white-space: nowrap; display: inline-flex; align-items: center; gap: .35rem;
    }
    button:hover, .btn:hover { background: #fbfcfe; border-color: #d9dfe9; }
    button.quiet { box-shadow: none; border-color: transparent; background: transparent; color: var(--body); }
    button.quiet:hover { background: var(--line-soft); }
    button.risky:hover { color: var(--bad-ink); border-color: #f0c7c2; background: #fef7f6; }
    form { display: inline; }
    .row-acts { display: flex; gap: .35rem; justify-content: flex-end; flex-wrap: wrap; }

    input[type=search] {
        font: inherit; font-size: .88rem; padding: .42rem .7rem; border: 1px solid var(--line);
        border-radius: 7px; background: var(--surface); color: var(--ink); width: 100%;
        box-shadow: var(--shadow-sm);
    }
    input[type=search]::placeholder { color: var(--muted); }
    input[type=search]:focus { border-color: var(--accent); outline: none;
                               box-shadow: 0 0 0 3px var(--accent-bg); }

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
    .kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.15rem; }
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
    .pair { display: grid; grid-template-columns: 1fr 1fr; gap: 1.15rem; }
    .pair .card + .card { margin-top: 0; }

    .kv { display: grid; grid-template-columns: 12rem 1fr; gap: .1rem 1rem; padding: .3rem 0; }
    .kv > dt { padding: .55rem 1.1rem; color: var(--muted); font-size: .87rem; }
    .kv > dd { padding: .55rem 1.1rem; margin: 0; color: var(--ink); overflow-wrap: anywhere; }
    .copyable { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
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
                <small>Cobros por Redsys</small>
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
        <div class="head">
            <h1>@yield('heading')</h1>
            <p>@yield('subheading')</p>
        </div>

        @if (session('redsys_message'))
            <p class="msg">{{ session('redsys_message') }}</p>
        @endif

        @yield('content')
    </main>
</div>
@stack('scripts')
</body>
</html>

@extends('redsys-subscriptions::layout')

@section('title', 'Ajustes')
@section('heading', 'Ajustes')
@section('subheading', 'Lo que el paquete está usando ahora mismo. Se cambia en config/redsys-subscriptions.php.')

@section('content')
    <div class="card">
        <div class="cap"><h2>Comercio</h2></div>
        <dl class="kv">
            @foreach ($settings as $label => $value)
                <dt>{{ $label }}</dt>
                <dd>
                    @if ($value === null)
                        <span class="pill alert">Sin configurar</span>
                    @else
                        {{ $value }}
                    @endif
                </dd>
            @endforeach
        </dl>
    </div>

    <div class="card">
        <div class="cap"><h2>Direcciones</h2></div>
        <dl class="kv">
            @foreach ($urls as $label => $url)
                <dt>{{ $label }}</dt>
                <dd class="copyable">
                    <code>{{ $url }}</code>
                    <button type="button" class="quiet" data-copy="{{ $url }}">Copiar</button>
                </dd>
            @endforeach
        </dl>
    </div>

    <div class="card">
        <div class="cap"><h2>Acceso a este panel</h2></div>
        <dl class="kv">
            <dt>Permiso</dt>
            <dd>
                @if ($gateDefined)
                    Decide tu Gate <code>viewRedsysSubscriptions</code>
                @else
                    <span class="pill alert">Solo en local</span>
                @endif
            </dd>
            <dt>Cobro automático</dt>
            <dd><code>php artisan redsys:charge-subscriptions</code></dd>
        </dl>
        @unless ($gateDefined)
            <div class="msg danger" style="margin: 0 1.1rem 1.1rem">
                <b>El Gate no está definido</b>
                Fuera de <code>local</code> no entra nadie hasta que lo definas. Aquí están los
                datos de pago de todos tus clientes, así que el paquete viene cerrado.
                Defínelo en un ServiceProvider:
<pre>use Illuminate\Support\Facades\Gate;
use MarioDevv\RedsysSubscriptions\Authorize;

Gate::define(Authorize::GATE, fn ($user) =&gt; $user-&gt;isAdmin());</pre>
            </div>
        @endunless
    </div>
@endsection

@push('scripts')
<script>
    // La URL de notificación hay que pegarla en el panel de Redsys. Copiarla a
    // mano de una tabla es donde se cuelan los errores.
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
@endpush

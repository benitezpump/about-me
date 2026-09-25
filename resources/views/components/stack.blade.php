@props(['p'])
@if (count($p->stackItems))
<ul class="stack chips" aria-label="Tecnologías">@foreach ($p->stackItems as $t)<li>{{ $t }}</li>@endforeach</ul>
@elseif ($p->stackLegacy)
<p class="stack">{{ $p->stackLegacy }}</p>
@endif

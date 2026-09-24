@props(['p'])
@if ($p->label){{ $p->label }}@else{{ $p->from }} – @if ($p->current)<span class="now">actualidad</span>@else{{ $p->to }}@endif
@endif
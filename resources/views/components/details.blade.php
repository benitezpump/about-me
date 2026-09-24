@props(['p'])
@if (count($p->highlights))
<details>
    <summary>Ver detalle</summary>
    <ul>
        @foreach ($p->highlights as $h)
        <li>@if ($h->label)<strong>{{ $h->label }}:</strong> @endif{{ $h->body }}</li>
        @endforeach
    </ul>
</details>
@endif

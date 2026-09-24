@php($issues = $getLivewire()->issues)
<div role="alert" style="border: 1px solid rgb(var(--danger-500, 239 68 68)); border-radius: .5rem; padding: .75rem 1rem; margin-bottom: 1rem;">
    <p style="margin: 0 0 .5rem;"><strong>No se importó nada.</strong> Corrige esto en el archivo e inténtalo de nuevo:</p>
    <ul style="margin: 0; padding-left: 1.25rem; font-family: ui-monospace, monospace; font-size: .8125rem; overflow-wrap: anywhere;">
        @foreach ($issues as $issue)
            <li>{{ $issue }}</li>
        @endforeach
    </ul>
</div>

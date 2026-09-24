{{-- El archivo lo lee el navegador (FileReader) y su texto se copia a la propiedad `data.text`: no se sube nada al servidor. --}}
<div class="fi-fo-field">
    <label class="fi-fo-field-label" for="json-file" style="font-weight: 600; display: block; margin-bottom: .375rem;">Archivo .json</label>
    <input
        id="json-file"
        type="file"
        accept=".json,application/json"
        x-data
        x-on:change="
            const file = $event.target.files[0];
            if (! file) return;
            const reader = new FileReader();
            reader.onload = () => $wire.set('data.text', String(reader.result || ''));
            reader.readAsText(file);
        "
    >
</div>

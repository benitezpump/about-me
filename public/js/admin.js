(function () {
  /* Confirmación antes de acciones destructivas (formularios con data-confirm). */
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (!window.confirm(form.getAttribute('data-confirm'))) e.preventDefault();
    });
  });

  /* Carga un archivo de texto en un <textarea> (importar): se lee en el navegador y se envía como texto. */
  document.querySelectorAll('input[type="file"][data-load-into]').forEach(function (input) {
    input.addEventListener('change', function () {
      var file = input.files && input.files[0];
      var target = document.getElementById(input.getAttribute('data-load-into'));
      if (!file || !target) return;
      var reader = new FileReader();
      reader.onload = function () { target.value = String(reader.result || ''); };
      reader.readAsText(file);
    });
  });

  /* Editor de filas hijas: agregar, quitar y reordenar. */
  document.querySelectorAll('[data-children]').forEach(function (box) {
    var list = box.querySelector('[data-rows]');
    var tpl = box.querySelector('template[data-template]');
    var counter = list.children.length;

    function focusFirst(row) {
      var input = row.querySelector('input, textarea, select');
      if (input) input.focus();
    }

    box.querySelector('[data-add]').addEventListener('click', function () {
      var html = tpl.innerHTML.split('__i__').join(String(counter++));
      var holder = document.createElement('ol');
      holder.innerHTML = html.trim();
      var row = holder.firstElementChild;
      list.appendChild(row);
      focusFirst(row);
    });

    box.addEventListener('click', function (e) {
      var target = e.target;
      if (!(target instanceof HTMLElement)) return;
      var row = target.closest('[data-row]');
      if (!row) return;

      if (target.hasAttribute('data-remove')) {
        var next = row.nextElementSibling || row.previousElementSibling;
        row.remove();
        if (next) focusFirst(next);
      } else if (target.hasAttribute('data-up') && row.previousElementSibling) {
        list.insertBefore(row, row.previousElementSibling);
        target.focus();
      } else if (target.hasAttribute('data-down') && row.nextElementSibling) {
        list.insertBefore(row.nextElementSibling, row);
        target.focus();
      }
    });

    /* El servidor toma el orden por el índice: se renumera según el orden visible al enviar. */
    box.closest('form').addEventListener('submit', function () {
      var key = box.getAttribute('data-children');
      Array.prototype.forEach.call(list.children, function (row, i) {
        row.querySelectorAll('[name^="children[' + key + ']["]').forEach(function (el) {
          el.name = el.name.replace(/^(children\[[^\]]+\]\[)\d+(\])/, '$1' + i + '$2');
        });
      });
    });
  });
})();

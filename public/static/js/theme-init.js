/* Corre en <head>, antes del primer pintado: aplica el tema claro guardado para evitar un destello. */
try {
  if (localStorage.getItem('theme') === 'light') document.documentElement.setAttribute('data-theme', 'light');
} catch (e) {}

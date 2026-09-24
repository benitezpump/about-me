(function () {
  var root = document.documentElement;
  var btn = document.querySelector('.theme');
  var meta = document.querySelector('meta[name="theme-color"]');
  var colors = { dark: '#0e1320', light: '#f6f7fa' };

  function apply(theme) {
    root.setAttribute('data-theme', theme);
    if (meta) meta.setAttribute('content', colors[theme]);
    if (btn) btn.setAttribute('aria-label', theme === 'dark' ? 'Cambiar a tema claro' : 'Cambiar a tema oscuro');
  }

  apply(root.getAttribute('data-theme') === 'light' ? 'light' : 'dark');

  if (btn) {
    btn.addEventListener('click', function () {
      var next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
      apply(next);
      try { localStorage.setItem('theme', next); } catch (e) {}
    });
  }

  /* Links that open a new tab say so to screen readers. */
  document.querySelectorAll('a[target="_blank"]').forEach(function (a) {
    var s = document.createElement('span');
    s.className = 'sr-only';
    s.textContent = ' (se abre en una pestaña nueva)';
    a.appendChild(s);
  });

  /* Expand / collapse all project details, one control per timeline. */
  var allDetails = [];
  document.querySelectorAll('.expand').forEach(function (button) {
    var timeline = button.parentElement && button.parentElement.querySelector('.timeline');
    if (!timeline) return;
    var details = Array.prototype.slice.call(timeline.querySelectorAll('details'));
    allDetails = allDetails.concat(details);

    if (details.length === 0) { button.hidden = true; return; }

    function sync() {
      var open = details.every(function (d) { return d.open; });
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
      button.textContent = open ? 'Contraer todo' : 'Expandir todo';
    }
    button.addEventListener('click', function () {
      var open = button.getAttribute('aria-expanded') !== 'true';
      details.forEach(function (d) { d.open = open; });
      sync();
    });
    details.forEach(function (d) { d.addEventListener('toggle', sync); });
  });

  /* Printing or saving as PDF should include every detail. */
  var wasOpen = [];
  window.addEventListener('beforeprint', function () {
    wasOpen = allDetails.map(function (d) { return d.open; });
    allDetails.forEach(function (d) { d.open = true; });
  });
  window.addEventListener('afterprint', function () {
    allDetails.forEach(function (d, i) { d.open = wasOpen[i]; });
  });

  /* Mark the section being read in the top bar. */
  var links = {};
  document.querySelectorAll('.top nav a[href^="#"]').forEach(function (a) {
    links[a.getAttribute('href').slice(1)] = a;
  });
  function setCurrent(id) {
    Object.keys(links).forEach(function (k) { links[k].removeAttribute('aria-current'); });
    if (links[id]) links[id].setAttribute('aria-current', 'true');
  }
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting && links[e.target.id]) setCurrent(e.target.id);
      });
    }, { rootMargin: '-35% 0px -60% 0px' });
    /* The last section is too short to reach the detection band, so the page end counts as the last link. */
    var ids = Object.keys(links);
    var last = ids[ids.length - 1];
    window.addEventListener('scroll', function () {
      if (last && window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4) setCurrent(last);
    }, { passive: true });
    ids.forEach(function (id) {
      var s = document.getElementById(id);
      if (s && s.tagName === 'SECTION') io.observe(s);
    });
  }
})();

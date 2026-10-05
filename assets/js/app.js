/* AguaSinCal — JavaScript mínimo y opcional: la web funciona sin él. Sin cookies ni almacenamiento local. */
(function () {
  'use strict';
  var doc = document.documentElement;
  doc.classList.add('js');

  /* Menú móvil */
  var botonMenu = document.querySelector('.menu__boton');
  var nav = document.getElementById('menu-principal');
  if (botonMenu && nav) {
    botonMenu.hidden = false;
    botonMenu.addEventListener('click', function () {
      var abierto = nav.classList.toggle('abierto');
      botonMenu.setAttribute('aria-expanded', abierto ? 'true' : 'false');
    });
  }

  /* Origen del lead: parámetros de campaña y web de procedencia, solo en el formulario de esta página */
  var params = new URLSearchParams(location.search);
  var campos = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid'];
  var referente = '';
  try {
    if (document.referrer && new URL(document.referrer).host !== location.host) { referente = document.referrer; }
  } catch (e) { /* referrer no válido */ }
  document.querySelectorAll('form[data-form-lead]').forEach(function (form) {
    campos.forEach(function (c) {
      if (params.get(c)) { anadirOculto(form, c, params.get(c)); }
    });
    if (referente) { anadirOculto(form, 'ref', referente); }
  });
  function anadirOculto(form, nombre, valor) {
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = nombre;
    input.value = valor.slice(0, 300);
    form.appendChild(input);
  }

  /* Clics en Llamar / WhatsApp (recuento agregado, sin datos personales) */
  document.addEventListener('click', function (ev) {
    var enlace = ev.target.closest && ev.target.closest('[data-evento]');
    if (!enlace || !navigator.sendBeacon) { return; }
    var datos = new FormData();
    datos.append('tipo', enlace.getAttribute('data-evento'));
    datos.append('pagina', location.pathname);
    navigator.sendBeacon('/api/evento.php', datos);
  });

  /* Confirmaciones del panel */
  document.querySelectorAll('form[data-confirmar]').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      if (!window.confirm(form.getAttribute('data-confirmar'))) { ev.preventDefault(); }
    });
  });

  /* Buscador de municipios */
  var municipios = null;
  var cargando = null;
  function normalizar(t) {
    return t.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9 ]+/g, ' ').trim();
  }
  function cargarMunicipios() {
    if (municipios) { return Promise.resolve(municipios); }
    if (!cargando) {
      cargando = fetch('/datos/municipios.json').then(function (r) { return r.json(); }).then(function (lista) {
        lista.forEach(function (m) { m.k = normalizar(m.n); });
        municipios = lista;
        return lista;
      }).catch(function () { municipios = []; return municipios; });
    }
    return cargando;
  }
  function buscar(texto) {
    var q = normalizar(texto);
    if (q.length < 2 || !municipios) { return []; }
    var empiezan = [], contienen = [];
    municipios.forEach(function (m) {
      var i = m.k.indexOf(q);
      if (i === 0) { empiezan.push(m); } else if (i > 0) { contienen.push(m); }
    });
    return empiezan.concat(contienen).slice(0, 8);
  }
  document.querySelectorAll('form[data-buscador]').forEach(function (form) {
    var input = form.querySelector('input[type="search"]');
    var lista = form.querySelector('.buscador__resultados');
    var actuales = [];
    var activo = -1;
    function pintar() {
      lista.innerHTML = '';
      if (!actuales.length) {
        if (input.value.trim().length >= 2 && municipios) {
          var li = document.createElement('li');
          li.className = 'nota';
          li.textContent = 'Sin resultados. Prueba con otro nombre o consulta tu provincia.';
          lista.appendChild(li);
          lista.hidden = false;
        } else { lista.hidden = true; }
        return;
      }
      actuales.forEach(function (m, i) {
        var li = document.createElement('li');
        var a = document.createElement('a');
        a.href = m.r;
        a.setAttribute('role', 'option');
        a.id = 'mun-' + i;
        if (i === activo) { a.setAttribute('aria-selected', 'true'); }
        var nombre = document.createElement('span');
        nombre.textContent = m.n;
        var extra = document.createElement('small');
        extra.textContent = (m.f !== null ? m.f.toLocaleString('es-ES', { maximumFractionDigits: 0 }) + ' °fH · ' : '') + m.p;
        a.appendChild(nombre);
        a.appendChild(extra);
        li.appendChild(a);
        lista.appendChild(li);
      });
      lista.hidden = false;
    }
    input.addEventListener('focus', cargarMunicipios);
    input.addEventListener('input', function () {
      cargarMunicipios().then(function () { actuales = buscar(input.value); activo = -1; pintar(); });
    });
    input.addEventListener('keydown', function (ev) {
      if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
        if (!actuales.length) { return; }
        ev.preventDefault();
        activo = (activo + (ev.key === 'ArrowDown' ? 1 : -1) + actuales.length) % actuales.length;
        pintar();
      } else if (ev.key === 'Escape') {
        lista.hidden = true;
      }
    });
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      cargarMunicipios().then(function () {
        var resultados = actuales.length ? actuales : buscar(input.value);
        var elegido = resultados[activo >= 0 ? activo : 0];
        if (elegido) { location.href = elegido.r; } else { actuales = []; pintar(); }
      });
    });
    document.addEventListener('click', function (ev) {
      if (!form.contains(ev.target)) { lista.hidden = true; }
    });
    // Búsqueda que llega por URL (?q=…), por ejemplo desde el formulario sin JavaScript de otra página.
    if (params.get('q') && form.closest('main')) {
      input.value = params.get('q');
      cargarMunicipios().then(function () { actuales = buscar(input.value); pintar(); });
    }
  });

  /* Calculadora de descalcificador (misma lógica que app/src/Agua.php) */
  var LITROS_PERSONA_DIA = 140, RESIDUAL = 8, CAPACIDAD = 5, SAL_KG_L = 0.15, DIAS = 7;
  var TAMANOS = [8, 10, 12, 15, 20, 25, 30, 35, 40, 50, 60, 75, 100];
  function dimensionar(personas, fh) {
    if (!(personas >= 1) || !(fh > RESIDUAL)) { return null; }
    var m3Dia = personas * LITROS_PERSONA_DIA / 1000;
    var litros = m3Dia * DIAS * (fh - RESIDUAL) / CAPACIDAD;
    var elegido = TAMANOS[TAMANOS.length - 1];
    for (var i = 0; i < TAMANOS.length; i++) { if (TAMANOS[i] >= litros) { elegido = TAMANOS[i]; break; } }
    var regeneraciones = Math.ceil(m3Dia * 365 * (fh - RESIDUAL) / (elegido * CAPACIDAD));
    return { litros: elegido, sal: Math.round(regeneraciones * elegido * SAL_KG_L / 5) * 5, regeneraciones: regeneraciones };
  }
  document.querySelectorAll('[data-calculadora]').forEach(function (calc) {
    var personas = calc.querySelector('input[name="personas"]');
    var dureza = calc.querySelector('input[name="dureza"]');
    var salida = calc.querySelector('.calculadora__resultado');
    function calcular() {
      var p = parseInt(personas.value, 10);
      var fh = parseFloat(String(dureza.value).replace(',', '.'));
      var r = dimensionar(p, fh);
      salida.hidden = false;
      if (!r) {
        salida.textContent = fh <= RESIDUAL
          ? 'Con esta dureza no necesitas descalcificador.'
          : 'Indica el número de personas y la dureza del agua.';
        return;
      }
      salida.innerHTML = '';
      var b1 = document.createElement('b'); b1.textContent = r.litros + ' litros de resina';
      var b2 = document.createElement('b'); b2.textContent = '≈ ' + r.sal + ' kg de sal al año';
      salida.appendChild(document.createTextNode('Te recomendamos un descalcificador de '));
      salida.appendChild(b1);
      salida.appendChild(document.createTextNode('. Gastará '));
      salida.appendChild(b2);
      salida.appendChild(document.createTextNode(' (unas ' + r.regeneraciones + ' regeneraciones).'));
    }
    personas.addEventListener('input', calcular);
    dureza.addEventListener('input', calcular);
    calcular();
  });

  /* Tablas ordenables */
  document.querySelectorAll('table[data-ordenable]').forEach(function (tabla) {
    var cabeceras = tabla.querySelectorAll('thead th');
    cabeceras.forEach(function (th, col) {
      var tipo = th.getAttribute('data-tipo');
      if (!tipo) { return; }
      th.tabIndex = 0;
      function ordenar() {
        var asc = th.getAttribute('aria-sort') !== 'ascending';
        cabeceras.forEach(function (o) { o.removeAttribute('aria-sort'); });
        th.setAttribute('aria-sort', asc ? 'ascending' : 'descending');
        var cuerpo = tabla.tBodies[0];
        var filas = Array.prototype.slice.call(cuerpo.rows);
        filas.sort(function (a, b) {
          var ca = a.cells[col], cb = b.cells[col];
          var va = ca.getAttribute('data-valor') || ca.textContent.trim();
          var vb = cb.getAttribute('data-valor') || cb.textContent.trim();
          var r = tipo === 'numero' ? parseFloat(va) - parseFloat(vb) : va.localeCompare(vb, 'es');
          return asc ? r : -r;
        });
        filas.forEach(function (f) { cuerpo.appendChild(f); });
      }
      th.addEventListener('click', ordenar);
      th.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); ordenar(); } });
    });
  });
})();

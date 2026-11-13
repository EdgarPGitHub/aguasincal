/*
 * Motor mínimo de animación de los vídeos. Todo se dibuja en función del tiempo: pintar(t) deja la imagen exacta
 * del segundo t, así scripts/video-render.js puede capturar los fotogramas uno a uno sin saltos.
 *
 * Cada vídeo define sus escenas con M.escena(id, preparar). preparar(g, {d, f}) dibuja la escena dentro del grupo g
 * y devuelve una función (t) => {...} que la actualiza; d es su duración y f la lista de frases de la narración con
 * su inicio (i) y fin (f) en segundos desde el principio de la escena, para sincronizar la animación con la voz.
 */
(function () {
  'use strict';
  var NS = 'http://www.w3.org/2000/svg';
  var FUNDIDO = 0.45;
  var escenas = {};
  var linea = null;

  var M = window.M = {
    C: {
      fondo: '#f3f7fb', marca: '#0b3a66', acento: '#0a7bbd', tinta: '#0f1f33', tinta2: '#46566c', linea: '#dbe4ee',
      agua: '#d6ecf8', aguaBorde: '#0a7bbd', cal: '#e07b24', calBorde: '#a9530c', sodio: '#2e9e6b', sodioBorde: '#1d6e48',
      resina: '#f3d48a', resinaBorde: '#c3923a', sal: '#ffffff', salBorde: '#9fb3c8', calor: '#d9472b', blanco: '#ffffff',
      blanda: '#2e9e6b', media: '#d1a21f', dura: '#d06a1a', muyDura: '#c2412d'
    },
    escena: function (id, preparar) { escenas[id] = preparar; },
    el: function (tag, attrs, padre, texto) {
      var e = document.createElementNS(NS, tag);
      M.set(e, attrs || {});
      if (texto !== undefined && texto !== null) { e.textContent = texto; }
      if (padre) { padre.appendChild(e); }
      return e;
    },
    set: function (e, attrs) {
      for (var k in attrs) { if (attrs[k] === null) { e.removeAttribute(k); } else { e.setAttribute(k, attrs[k]); } }
      return e;
    },
    /* Progreso de 0 a 1 entre el segundo a y a + dur, con suavizado */
    p: function (t, a, dur, f) {
      if (dur === undefined) { dur = 0.6; }
      var x = dur <= 0 ? (t >= a ? 1 : 0) : Math.min(1, Math.max(0, (t - a) / dur));
      return (f || M.suave)(x);
    },
    suave: function (x) { return x < 0.5 ? 4 * x * x * x : 1 - Math.pow(-2 * x + 2, 3) / 2; },
    salida: function (x) { return 1 - Math.pow(1 - x, 3); },
    rebote: function (x) { var c1 = 1.70158, c3 = c1 + 1; return 1 + c3 * Math.pow(x - 1, 3) + c1 * Math.pow(x - 1, 2); },
    lineal: function (x) { return x; },
    mezcla: function (a, b, p) { return a + (b - a) * p; },
    /* Números pseudoaleatorios repetibles (mismo vídeo en cada render) */
    azar: function (semilla) {
      var s = semilla >>> 0;
      return function () {
        s = (s + 0x6D2B79F5) >>> 0;
        var t = s;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
      };
    }
  };

  window.configurar = function (l) {
    linea = l;
    var lienzo = document.getElementById('lienzo');
    var capaEscenas = M.el('g', {}, lienzo);
    l.escenas.forEach(function (e) {
      if (!escenas[e.id]) { throw new Error('Falta la escena ' + e.id); }
      var g = M.el('g', { id: 'escena-' + e.id, opacity: 0 }, capaEscenas);
      var f = e.frases.map(function (x) { return { i: x.inicio - e.inicio, f: x.fin - e.inicio, texto: x.texto }; });
      e.g = g;
      e.actualizar = escenas[e.id](g, { d: e.fin - e.inicio, f: f });
    });
    if (window.encima) { window.encima(M.el('g', {}, lienzo)); }
  };

  window.pintar = function (t) {
    linea.escenas.forEach(function (e, n) {
      var local = t - e.inicio;
      var ultima = n === linea.escenas.length - 1;
      if (local < 0 || (!ultima && t > e.fin + FUNDIDO)) { e.g.setAttribute('display', 'none'); return; }
      e.g.removeAttribute('display');
      e.g.setAttribute('opacity', n === 0 ? 1 : Math.min(1, local / FUNDIDO));
      e.actualizar(local);
    });
    if (window.encimaPintar) { window.encimaPintar(t, linea); }
  };
})();

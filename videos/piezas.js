/* Piezas de dibujo comunes a todos los vídeos (fondo, textos, iones, gota del logo, etiquetas, tuberías). */
(function () {
  'use strict';
  var C = M.C, el = M.el, set = M.set;

  function poner(e, x, y, s, o) {
    var tr = 'translate(' + x.toFixed(2) + ' ' + y.toFixed(2) + ')';
    if (s !== undefined && s !== 1) { tr += ' scale(' + s.toFixed(3) + ')'; }
    e.setAttribute('transform', tr);
    if (o !== undefined) { e.setAttribute('opacity', Math.max(0, Math.min(1, o)).toFixed(3)); }
    return e;
  }

  function texto(g, x, y, t, o) {
    o = o || {};
    return el('text', {
      x: x, y: y, 'text-anchor': o.ancla || 'middle', 'font-size': o.size || 28, 'font-weight': o.peso || 600, fill: o.color || C.tinta
    }, g, t);
  }

  window.Piezas = {
    poner: poner,
    texto: texto,
    fondo: function (g, color) { return el('rect', { x: 0, y: 0, width: 1280, height: 720, fill: color || C.fondo }, g); },

    /* Ion: Ca y Mg en naranja (lo que forma la cal), Na en verde. Con radio ≥ 11 lleva su símbolo. */
    ion: function (g, tipo, r) {
      var grupo = el('g', {}, g);
      var na = tipo === 'Na';
      el('circle', { r: r, fill: na ? C.sodio : C.cal, stroke: na ? C.sodioBorde : C.calBorde, 'stroke-width': Math.max(1.3, r / 7) }, grupo);
      if (r >= 11) {
        el('text', { y: (r * 0.34).toFixed(1), 'text-anchor': 'middle', 'font-size': (r * 0.92).toFixed(1), 'font-weight': 700, fill: '#fff' }, grupo, tipo);
      }
      return grupo;
    },

    /* La gota del logo, centrada en el origen (unos 20 × 27 px a escala 1) */
    gota: function (g) {
      var gg = el('g', {}, g);
      el('path', { d: 'M16 2.5c-.4 0-.8.2-1 .5C11.3 8 6.5 14 6.5 19.5a9.5 9.5 0 0 0 19 0C25.5 14 20.7 8 17 3c-.2-.3-.6-.5-1-.5Z', fill: '#0a84c6', transform: 'translate(-16 -17)' }, gg);
      el('path', { d: 'M11.2 20.4a4.9 4.9 0 0 0 4.9 4.9', fill: 'none', stroke: '#fff', 'stroke-width': 2, 'stroke-linecap': 'round', transform: 'translate(-16 -17)' }, gg);
      return gg;
    },

    /* Etiqueta con fondo redondeado, centrada en (x, y). Empieza invisible salvo con {visible: true}. */
    pastilla: function (g, x, y, t, o) {
      o = o || {};
      var gg = el('g', { opacity: o.visible ? 1 : 0 }, g);
      var fondo = el('rect', { rx: 999, fill: o.fondo || '#fff', stroke: o.borde || C.linea, 'stroke-width': 2 }, gg);
      var tx = texto(gg, 0, 0, t, { size: o.size || 24, peso: o.peso || 700, color: o.color || C.marca });
      var b = tx.getBBox(), px = 18, py = 9;
      set(fondo, { x: b.x - px, y: b.y - py, width: b.width + 2 * px, height: b.height + 2 * py });
      poner(gg, x, y);
      return gg;
    },

    check: function (g, color) {
      var gg = el('g', {}, g);
      el('circle', { r: 18, fill: color || C.sodio }, gg);
      el('path', { d: 'M-8 0 L-2 6 L9 -6', fill: 'none', stroke: '#fff', 'stroke-width': 4.5, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }, gg);
      return gg;
    },

    /* Tubería que se dibuja (dibujar(0..1)) y por la que corre el agua (fluir(t)); punto(s) da la posición a s px */
    tubo: function (g, d, o) {
      o = o || {};
      var gg = el('g', {}, g);
      var comun = { d: d, fill: 'none', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' };
      var ext = el('path', Object.assign({ stroke: '#9fb3c8', 'stroke-width': o.ancho || 26 }, comun), gg);
      var int = el('path', Object.assign({ stroke: C.agua, 'stroke-width': (o.ancho || 26) - 10 }, comun), gg);
      var flujo = el('path', Object.assign({ stroke: '#8cc8ea', 'stroke-width': 4, 'stroke-dasharray': '12 22', opacity: 0 }, comun), gg);
      var L = ext.getTotalLength();
      return {
        g: gg, L: L,
        dibujar: function (pr) {
          [ext, int].forEach(function (e) { set(e, { 'stroke-dasharray': L + ' ' + L, 'stroke-dashoffset': (L * (1 - pr)).toFixed(1) }); });
          gg.setAttribute('opacity', pr > 0 ? 1 : 0);
          flujo.setAttribute('opacity', pr >= 1 ? 1 : 0);
        },
        fluir: function (t, v) { flujo.setAttribute('stroke-dashoffset', (-(t * (v || 70)) % 34).toFixed(1)); },
        punto: function (s) { return ext.getPointAtLength(Math.max(0, Math.min(L, s))); }
      };
    }
  };
})();

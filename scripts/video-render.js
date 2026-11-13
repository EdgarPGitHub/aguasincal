#!/usr/bin/env node
/*
 * Monta un vídeo a partir de sus escenas (videos/<nombre>/escenas.html) y su narración (scripts/video-voz.py):
 * captura cada fotograma con el navegador y lo une con la voz en un MP4 H.264 + AAC, el formato que reproduce
 * cualquier navegador y que mejor entiende Google.
 *
 *   node scripts/video-render.js videos/descalcificador               # vídeo completo
 *   node scripts/video-render.js videos/descalcificador --fotos=3,12  # solo capturas para revisar (var/videos/)
 *
 * Escribe en assets/videos/: <nombre>.mp4 (1920×1080), <nombre>.jpg (portada 1280×720) y <nombre>.vtt (subtítulos),
 * y en videos/<nombre>/video.json los datos que usa la web (duración, fecha, transcripción).
 * Necesita ffmpeg y Playwright con Chromium.
 */
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const FPS = 30;
const ESCALA = 1.5; // 1280×720 de diseño → 1920×1080

async function main() {
  const carpeta = path.resolve(process.argv[2] || '');
  const nombre = path.basename(carpeta);
  const raiz = path.resolve(__dirname, '..');
  const trabajo = path.join(raiz, 'var', 'videos', nombre);
  const linea = JSON.parse(fs.readFileSync(path.join(trabajo, 'linea.json'), 'utf8'));
  const guion = JSON.parse(fs.readFileSync(path.join(carpeta, 'guion.json'), 'utf8'));
  const fotos = (process.argv.find((a) => a.startsWith('--fotos=')) || '').slice(8);

  const navegador = await chromium.launch();
  const pagina = await navegador.newPage({ viewport: { width: 1280, height: 720 }, deviceScaleFactor: fotos ? 1 : ESCALA });
  const errores = [];
  pagina.on('pageerror', (e) => errores.push(e.message));
  await pagina.goto('file://' + path.join(carpeta, 'escenas.html'));
  await pagina.evaluate(async (l) => { await document.fonts.ready; window.configurar(l); }, linea);
  if (errores.length) { throw new Error(errores.join('\n')); }

  if (fotos) {
    for (const t of fotos.split(',').map(Number)) {
      await pagina.evaluate((x) => window.pintar(x), t);
      const archivo = path.join(trabajo, `foto-${String(t).replace('.', '_')}.png`);
      await pagina.screenshot({ path: archivo });
      console.log(archivo);
    }
    await navegador.close();
    return;
  }

  const destino = path.join(raiz, 'assets', 'videos');
  fs.mkdirSync(destino, { recursive: true });
  const mp4 = path.join(destino, nombre + '.mp4');
  const total = Math.round(linea.duracion * FPS);
  const ffmpeg = spawn('ffmpeg', [
    '-y', '-loglevel', 'error',
    '-f', 'image2pipe', '-framerate', String(FPS), '-c:v', 'mjpeg', '-i', '-',
    '-i', path.join(trabajo, 'voz.wav'),
    '-filter_complex', '[1:a]loudnorm=I=-16:TP=-1.5:LRA=11,aresample=48000[a]',
    '-map', '0:v', '-map', '[a]',
    '-c:v', 'libx264', '-preset', 'slow', '-crf', '21', '-pix_fmt', 'yuv420p', '-profile:v', 'high', '-level', '4.0',
    '-c:a', 'aac', '-b:a', '96k', '-ac', '1',
    '-movflags', '+faststart', '-metadata', 'title=' + guion.titulo, '-shortest', mp4,
  ], { stdio: ['pipe', 'inherit', 'inherit'] });
  const termina = new Promise((ok, mal) => ffmpeg.on('close', (c) => (c === 0 ? ok() : mal(new Error('ffmpeg terminó con ' + c)))));

  const inicio = Date.now();
  for (let f = 0; f < total; f++) {
    await pagina.evaluate((x) => window.pintar(x), f / FPS);
    const jpg = await pagina.screenshot({ type: 'jpeg', quality: 95 });
    if (!ffmpeg.stdin.write(jpg)) { await new Promise((ok) => ffmpeg.stdin.once('drain', ok)); }
    if (f % 300 === 0) { process.stdout.write(`  ${f}/${total} fotogramas\n`); }
  }
  ffmpeg.stdin.end();
  await termina;

  // Portada: el final de la escena indicada en el guion (por defecto, la primera)
  const escenaPortada = linea.escenas.find((e) => e.id === guion.poster) || linea.escenas[0];
  await pagina.evaluate((x) => window.pintar(x), escenaPortada.fin - 0.05);
  const png = path.join(trabajo, 'portada.png');
  await pagina.screenshot({ path: png });
  await navegador.close();
  const jpg = path.join(destino, nombre + '.jpg');
  execFileSync('ffmpeg', ['-y', '-loglevel', 'error', '-i', png, '-vf', 'scale=1280:720:flags=lanczos', '-q:v', '3', jpg]);

  fs.writeFileSync(path.join(destino, nombre + '.vtt'), vtt(linea));

  const datosPrevios = fs.existsSync(path.join(carpeta, 'video.json')) ? JSON.parse(fs.readFileSync(path.join(carpeta, 'video.json'), 'utf8')) : {};
  const hoy = new Date().toISOString().slice(0, 10);
  const duracion = Number(execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', mp4]).toString().trim());
  const datos = {
    titulo: guion.titulo,
    descripcion: guion.descripcion,
    duracion: Math.round(duracion * 10) / 10,
    publicado: datosPrevios.publicado || hoy,
    actualizado: hoy,
    mp4: `/assets/videos/${nombre}.mp4`,
    portada: `/assets/videos/${nombre}.jpg`,
    subtitulos: `/assets/videos/${nombre}.vtt`,
    ancho: 1280 * ESCALA,
    alto: 720 * ESCALA,
    bytes: fs.statSync(mp4).size,
    transcripcion: linea.escenas.map((e) => e.frases.map((x) => x.texto).join(' ')),
    creditos: guion.creditos || 'Animación: AguaSinCal. Voz sintética: Piper, voz «sharvard» (CC BY 3.0).',
  };
  fs.writeFileSync(path.join(carpeta, 'video.json'), JSON.stringify(datos, null, 2) + '\n');
  console.log(`${nombre}: ${datos.duracion} s, ${(datos.bytes / 1048576).toFixed(1)} MB, ${Math.round((Date.now() - inicio) / 1000)} s de render`);
}

/* Subtítulos: una entrada por frase (o por mitades si es larga), en dos líneas como mucho */
function vtt(linea) {
  const hora = (s) => new Date(Math.max(0, s) * 1000).toISOString().slice(11, 23);
  const lineas = ['WEBVTT', ''];
  for (const escena of linea.escenas) {
    for (const frase of escena.frases) {
      for (const [ini, fin, texto] of trocear(frase)) {
        lineas.push(`${hora(ini)} --> ${hora(fin)}`, partir(texto), '');
      }
    }
  }
  return lineas.join('\n');
}

function trocear(frase) {
  const t = frase.texto;
  if (t.length <= 84) { return [[frase.inicio, frase.fin + 0.2, t]]; }
  // Corta por la coma (o el espacio) más cercana a la mitad; el tiempo se reparte según los caracteres
  const mitad = t.length / 2;
  let corte = -1;
  for (let i = 0; i < t.length; i++) {
    if ((t[i] === ',' || t[i] === ':') && (corte < 0 || Math.abs(i - mitad) < Math.abs(corte - mitad))) { corte = i + 1; }
  }
  if (corte < 0 || Math.abs(corte - mitad) > t.length / 4) { corte = t.lastIndexOf(' ', mitad) + 1; }
  const a = t.slice(0, corte).trim(), b = t.slice(corte).trim();
  const medio = frase.inicio + (frase.fin - frase.inicio) * (corte / t.length);
  return [[frase.inicio, medio, a], [medio, frase.fin + 0.2, b]];
}

function partir(texto) {
  if (texto.length <= 42) { return texto; }
  const corte = texto.lastIndexOf(' ', Math.ceil(texto.length / 2) + 4);
  return texto.slice(0, corte) + '\n' + texto.slice(corte + 1);
}

main().catch((e) => { console.error(e); process.exit(1); });

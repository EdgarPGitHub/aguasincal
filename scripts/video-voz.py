#!/usr/bin/env python3
"""
Narración de los vídeos con Qwen3-TTS (modelo libre, licencia Apache 2.0, funciona en local y sin coste).

La voz se diseña una sola vez a partir de una descripción y se guarda como clip de referencia en videos/voz/;
después cada frase se genera clonando esa referencia, así todos los vídeos tienen exactamente la misma voz.

    python3 scripts/video-voz.py --disenar          # una vez: crea videos/voz/referencia.wav (usa videos/voz/voz.json)
    python3 scripts/video-voz.py videos/descalcificador

Preparación (una vez): pip install qwen-tts soundfile (y, opcional, faster-whisper para revisar cada frase).
Los modelos se descargan solos de Hugging Face. En CPU tarda unas 5 veces la duración del audio.

Escribe en var/videos/<nombre>/: voz.wav (narración completa) y linea.json (inicio y fin de cada escena y frase),
que usa scripts/video-render.js. Las frases ya generadas se reutilizan (caché por texto y referencia).
En el guion, una frase puede ser un texto o {"texto": "lo que se lee en pantalla", "voz": "lo que se pronuncia"}
para corregir palabras que se leen mal (siglas, dominios, marcas). Tras conectores, coma: «Por eso, …».
"""
import hashlib
import json
import os
import re
import sys
import unicodedata

import numpy as np
import soundfile as sf

MODELO_DISENO = "Qwen/Qwen3-TTS-12Hz-1.7B-VoiceDesign"
MODELO_CLON = "Qwen/Qwen3-TTS-12Hz-1.7B-Base"
IDIOMA = "Spanish"
ENTRADA = 0.5      # silencio al empezar cada escena
ENTRE_FRASES = 0.4
FINAL = 0.9        # silencio al acabar cada escena (deja ver la animación)
INTENTOS = 3       # si la revisión automática detecta palabras de más o de menos, se repite la frase
ERROR_MAXIMO = 0.12

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CARPETA_VOZ = os.path.join(RAIZ, "videos", "voz")


def cargar(modelo: str):
    import torch
    from qwen_tts import Qwen3TTSModel
    torch.set_num_threads(os.cpu_count() or 4)
    dispositivo = "cuda:0" if torch.cuda.is_available() else "cpu"
    tipo = torch.bfloat16 if dispositivo != "cpu" else torch.float32
    return Qwen3TTSModel.from_pretrained(modelo, device_map=dispositivo, dtype=tipo)


def disenar() -> None:
    """Crea la voz de referencia a partir de videos/voz/voz.json ({"descripcion": ..., "texto": ..., "semilla": n})."""
    import torch
    ajustes = json.load(open(os.path.join(CARPETA_VOZ, "voz.json"), encoding="utf-8"))
    modelo = cargar(MODELO_DISENO)
    torch.manual_seed(int(ajustes.get("semilla", 1234)))
    audio, sr = modelo.generate_voice_design(text=ajustes["texto"], language=IDIOMA, instruct=ajustes["descripcion"])
    sf.write(os.path.join(CARPETA_VOZ, "referencia.wav"), recortar(audio[0], sr), sr)
    print("Voz de referencia creada en videos/voz/referencia.wav")


def narrar(carpeta: str) -> None:
    import torch
    nombre = os.path.basename(carpeta.rstrip("/"))
    guion = json.load(open(os.path.join(carpeta, "guion.json"), encoding="utf-8"))
    trabajo = os.path.join(RAIZ, "var", "videos", nombre)
    cache = os.path.join(RAIZ, "var", "videos", "cache")
    os.makedirs(trabajo, exist_ok=True)
    os.makedirs(cache, exist_ok=True)

    referencia = os.path.join(CARPETA_VOZ, "referencia.wav")
    texto_referencia = json.load(open(os.path.join(CARPETA_VOZ, "voz.json"), encoding="utf-8"))["texto"]
    huella_voz = hashlib.sha1(open(referencia, "rb").read()).hexdigest()[:12]
    modelo = prompt = revisor = None

    t, sr, pistas, escenas = 0.0, None, [], []
    for escena in guion["escenas"]:
        inicio = t
        t += ENTRADA
        frases = []
        for frase in escena["frases"]:
            texto, dicho = (frase, frase) if isinstance(frase, str) else (frase["texto"], frase.get("voz", frase["texto"]))
            clave = hashlib.sha1(f"{MODELO_CLON}|{huella_voz}|{dicho}".encode()).hexdigest()[:16]
            wav = os.path.join(cache, clave + ".wav")
            if not os.path.exists(wav):
                if modelo is None:
                    modelo = cargar(MODELO_CLON)
                    prompt = modelo.create_voice_clone_prompt(ref_audio=referencia, ref_text=texto_referencia)
                    revisor = Revisor.crear()
                mejor = None
                for intento in range(INTENTOS):
                    torch.manual_seed(int(clave[:8], 16) + intento)
                    audio, sr_frase = modelo.generate_voice_clone(text=dicho, language=IDIOMA, voice_clone_prompt=prompt)
                    audio = recortar(audio[0], sr_frase)
                    error = revisor.error(audio, sr_frase, dicho) if revisor else 0.0
                    if mejor is None or error < mejor[0]:
                        mejor = (error, audio, sr_frase)
                    if error <= ERROR_MAXIMO:
                        break
                    print(f"  repito ({error:.0%} de diferencia): {dicho}")
                if mejor[0] > ERROR_MAXIMO:
                    print(f"  AVISO: revisa esta frase al escucharla ({mejor[0]:.0%} de diferencia): {dicho}")
                sf.write(wav, mejor[1], mejor[2])
            audio, sr_frase = sf.read(wav, dtype="float32")
            sr = sr or sr_frase
            duracion = len(audio) / sr
            frases.append({"texto": texto, "inicio": round(t, 3), "fin": round(t + duracion, 3)})
            pistas.append((t, audio))
            t += duracion + ENTRE_FRASES
        t += FINAL - ENTRE_FRASES
        escenas.append({"id": escena["id"], "inicio": round(inicio, 3), "fin": round(t, 3), "frases": frases})

    total = np.zeros(int(t * sr) + sr, dtype=np.float32)
    for inicio, audio in pistas:
        i = int(inicio * sr)
        total[i:i + len(audio)] += audio
    sf.write(os.path.join(trabajo, "voz.wav"), total[: int(t * sr)], sr)
    linea = {"duracion": round(t, 3), "escenas": escenas}
    json.dump(linea, open(os.path.join(trabajo, "linea.json"), "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    print(f"{nombre}: {t:.1f} s, {sum(len(e['frases']) for e in escenas)} frases → {trabajo}")


class Revisor:
    """Transcribe cada frase generada y mide cuánto se aparta del texto (palabras saltadas, repetidas o inventadas)."""

    def __init__(self, modelo):
        self.modelo = modelo

    @classmethod
    def crear(cls):
        try:
            from faster_whisper import WhisperModel
        except ImportError:
            print("  (sin faster-whisper: las frases no se revisan automáticamente)")
            return None
        return cls(WhisperModel("small", device="cpu", compute_type="int8"))

    def error(self, audio: np.ndarray, sr: int, texto: str) -> float:
        if sr != 16000:
            audio = np.interp(np.arange(0, len(audio), sr / 16000), np.arange(len(audio)), audio).astype(np.float32)
        segmentos, _ = self.modelo.transcribe(audio, language="es", beam_size=5)
        oido = " ".join(s.text for s in segmentos)
        a, b = palabras(texto), palabras(oido)
        d = list(range(len(b) + 1))
        for i, x in enumerate(a, 1):
            anterior, d[0] = d[0], i
            for j, y in enumerate(b, 1):
                anterior, d[j] = d[j], min(d[j] + 1, d[j - 1] + 1, anterior + (x != y))
        return d[len(b)] / max(1, len(a))


def palabras(texto: str) -> list:
    t = unicodedata.normalize("NFD", texto.lower())
    t = "".join(c for c in t if unicodedata.category(c) != "Mn")
    return re.sub(r"[^a-z0-9ñ ]+", " ", t).split()


def recortar(audio: np.ndarray, sr: int) -> np.ndarray:
    """Quita el silencio del principio y del final para que los tiempos de cada frase sean exactos."""
    audio = np.asarray(audio, dtype=np.float32)
    voz = np.where(np.abs(audio) > 0.01)[0]
    if len(voz) == 0:
        return audio
    margen = int(0.05 * sr)
    return audio[max(0, voz[0] - margen): voz[-1] + margen]


if __name__ == "__main__":
    if len(sys.argv) > 1 and sys.argv[1] == "--disenar":
        disenar()
    elif len(sys.argv) > 1:
        narrar(sys.argv[1])
    else:
        print(__doc__)

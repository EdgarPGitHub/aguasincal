#!/usr/bin/env python3
"""
Narración de un vídeo: lee videos/<nombre>/guion.json, genera la voz de cada frase con Piper (gratis, sin internet)
y calcula la línea de tiempo que usa scripts/video-render.js.

    python3 scripts/video-voz.py videos/descalcificador

Preparación (una vez): pip install piper-tts soundfile y, en var/videos/modelos/, la voz es_ES-sharvard-medium
(.onnx y .onnx.json, de huggingface.co/rhasspy/piper-voices; licencia CC BY 3.0: se cita en la página del vídeo).
Escribe en var/videos/<nombre>/: voz.wav (narración completa) y linea.json (inicio y fin de cada escena y frase).
Las frases ya generadas se reutilizan (caché por texto y ajustes de voz).

En el guion, una frase puede ser un texto o {"texto": "lo que se lee en pantalla", "voz": "lo que se pronuncia"}
para corregir palabras que la voz lee mal (siglas, dominios, marcas).
"""
import hashlib
import json
import os
import re
import sys

import numpy as np
import soundfile as sf

ENTRADA = 0.5      # silencio al empezar cada escena
ENTRE_FRASES = 0.35
FINAL = 0.9        # silencio al acabar cada escena (deja ver la animación)


def main() -> None:
    carpeta = sys.argv[1].rstrip("/")
    nombre = os.path.basename(carpeta)
    raiz = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    guion = json.load(open(os.path.join(carpeta, "guion.json"), encoding="utf-8"))
    trabajo = os.path.join(raiz, "var", "videos", nombre)
    cache = os.path.join(raiz, "var", "videos", "cache")
    modelos = os.environ.get("VOCES_DIR", os.path.join(raiz, "var", "videos", "modelos"))
    os.makedirs(trabajo, exist_ok=True)
    os.makedirs(cache, exist_ok=True)

    ajustes = {
        "voz": guion.get("voz", "es_ES-sharvard-medium"),
        "hablante": int(guion.get("hablante", 0)),
        "lentitud": float(guion.get("lentitud", 1.0)),   # >1 más despacio
        "enlace": bool(guion.get("enlace", True)),
    }
    voz = None
    sr = None
    t, pistas, escenas = 0.0, [], []
    for escena in guion["escenas"]:
        inicio = t
        t += ENTRADA
        frases = []
        for frase in escena["frases"]:
            texto, dicho = (frase, frase) if isinstance(frase, str) else (frase["texto"], frase.get("voz", frase["texto"]))
            clave = hashlib.sha1(json.dumps([ajustes, dicho], sort_keys=True).encode()).hexdigest()[:16]
            wav = os.path.join(cache, clave + ".wav")
            if not os.path.exists(wav):
                if voz is None:
                    from piper import PiperVoice
                    voz = PiperVoice.load(os.path.join(modelos, ajustes["voz"] + ".onnx"))
                audio, sr_frase = hablar(voz, dicho, ajustes)
                sf.write(wav, recortar(audio, sr_frase), sr_frase)
            audio, sr_frase = sf.read(wav, dtype="float32")
            if sr is None:
                sr = sr_frase
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


def hablar(voz, texto: str, ajustes: dict):
    from piper import SynthesisConfig
    config = SynthesisConfig(speaker_id=ajustes["hablante"], length_scale=ajustes["lentitud"])
    trozos = []
    for fonemas in voz.phonemize(texto):
        if ajustes["enlace"]:
            fonemas = enlazar(fonemas)
        trozos.append(voz.phoneme_ids_to_audio(voz.phonemes_to_ids(fonemas), config))
    return np.concatenate(trozos), voz.config.sample_rate


def enlazar(fonemas: list) -> list:
    """
    Une la consonante final de una palabra con la vocal de la siguiente, como se habla en español
    («por eso» → po-re-so, «el agua» → e-la-gua). Sin esto la voz hace un pequeño corte que suena robótico.
    """
    return list(re.sub(r"(?<=[^aeiou\s,.;:¿?¡!]) (?=[ˈˌ]?[aeiou])", "", "".join(fonemas)))


def recortar(audio: np.ndarray, sr: int) -> np.ndarray:
    """Quita el silencio del principio y del final para que los tiempos de cada frase sean exactos."""
    voz = np.where(np.abs(audio) > 0.01)[0]
    if len(voz) == 0:
        return audio
    margen = int(0.04 * sr)
    return audio[max(0, voz[0] - margen): voz[-1] + margen]


if __name__ == "__main__":
    main()

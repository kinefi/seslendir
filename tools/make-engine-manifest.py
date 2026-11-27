#!/usr/bin/env python3
"""engine-manifest.json üretir (eklentinin otomatik indirmesi bunu okur).

Kullanım:
  python3 tools/make-engine-manifest.py <dosya-klasörü> <taban-url> [--engine sherpa|piper-ort|okur|ema|zerre] [--voice AD] [--out YOL]

Yalnızca eklentinin izin verdiği dosya adları işlenir. Zorunlu dosyalardan biri
yoksa hata verir. Boyut ve SHA-256 değerleri dosyalardan hesaplanır.
"""
import argparse
import hashlib
import json
import os
import sys

PHONEMIZE = [
    "piper-phonemize.js",
    "piper-phonemize-wasm-main.js",
    "piper-phonemize-wasm-main.wasm",
    "piper-phonemize-wasm-main.data",
]
REQUIRED_ORT = [
    "ort.wasm.min.js",
    "ort-wasm-simd-threaded.js",
    "ort-wasm-simd-threaded.wasm",
    "voice.onnx",
    "voice.onnx.json",
] + PHONEMIZE
REQUIRED_OKUR = [
    "ort.wasm.min.js",
    "ort-wasm-simd-threaded.js",
    "ort-wasm-simd-threaded.wasm",
    "okur-config.json",
    "okur-frontend.wasm",
    "okur-text.onnx",
    "okur-sample.onnx",
    "okur-decoder.onnx",
]
ORT3 = [
    "ort.wasm.min.js",
    "ort-wasm-simd-threaded.js",
    "ort-wasm-simd-threaded.wasm",
]
REQUIRED_EMA = ORT3 + ["ema-meta.json", "ema-text.onnx", "ema-sound.onnx", "ema-decoder.onnx"]
REQUIRED_ZERRE = ORT3 + ["zerre-model.onnx", "zerre-model.json", "zerre-lexicon.json"]
REQUIRED = [
    "sherpa-onnx-tts.js",
    "sherpa-onnx-wasm-main-tts.js",
    "sherpa-onnx-wasm-main-tts.wasm",
    "sherpa-onnx-wasm-main-tts.data",
] + PHONEMIZE
OPTIONAL = ["lame.min.js"]


def sha256(path):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for block in iter(lambda: f.read(1024 * 1024), b""):
            h.update(block)
    return h.hexdigest()


def build(directory, base_url, voice, kind="sherpa"):
    required = {"piper-ort": REQUIRED_ORT, "okur": REQUIRED_OKUR, "ema": REQUIRED_EMA, "zerre": REQUIRED_ZERRE}.get(kind, REQUIRED)
    base_url = base_url.rstrip("/") + "/"
    files = []
    for name in required + OPTIONAL:
        path = os.path.join(directory, name)
        if not os.path.isfile(path):
            if name in required:
                raise SystemExit("Eksik zorunlu dosya: " + name)
            continue
        size = os.path.getsize(path)
        if size < 1:
            raise SystemExit("Boş dosya: " + name)
        files.append({"name": name, "size": size, "sha256": sha256(path), "url": base_url + name})
    return {"version": 1, "engine": kind, "voice": voice, "files": files}


def main(argv):
    ap = argparse.ArgumentParser()
    ap.add_argument("directory")
    ap.add_argument("base_url")
    ap.add_argument("--voice", default="tr_TR-fettah-medium")
    ap.add_argument("--engine", default="sherpa", choices=["sherpa", "piper-ort", "okur", "ema", "zerre"])
    ap.add_argument("--out", default=None)
    a = ap.parse_args(argv)
    manifest = build(a.directory, a.base_url, a.voice, a.engine)
    out = a.out or os.path.join(a.directory, "engine-manifest.json")
    with open(out, "w", encoding="utf-8") as f:
        json.dump(manifest, f, ensure_ascii=False, indent=2)
        f.write("\n")
    print("Yazıldı:", out, "(%d dosya)" % len(manifest["files"]))


if __name__ == "__main__":
    main(sys.argv[1:])

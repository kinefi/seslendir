#!/usr/bin/env sh
# Derlenmiş sherpa-onnx WASM TTS dosyalarından eklentiye yüklenecek motor paketini (ZIP) üretir.
#   sh tools/make-engine-pack.sh /yol/derlenen/dosyalar [lame.min.js yolu]
# Beklenen dosyalar: sherpa-onnx-tts.js, sherpa-onnx-wasm-main-tts.{js,wasm,data}
set -e
SRC="${1:?Kullanım: make-engine-pack.sh <derleme-klasörü> [lame.min.js]}"
OUT="yaziyi-seslendir-motor.zip"
TMP="$(mktemp -d)"
for f in sherpa-onnx-tts.js sherpa-onnx-wasm-main-tts.js sherpa-onnx-wasm-main-tts.wasm sherpa-onnx-wasm-main-tts.data; do
  [ -f "$SRC/$f" ] || { echo "Eksik dosya: $SRC/$f" >&2; exit 1; }
  cp "$SRC/$f" "$TMP/"
done
[ -n "$2" ] && [ -f "$2" ] && cp "$2" "$TMP/lame.min.js"
(cd "$TMP" && zip -q -r "$OLDPWD/$OUT" .)
rm -rf "$TMP"
echo "Hazır: $OUT ($(du -h "$OUT" | cut -f1))"

# Seslendir

Turn your Turkish WordPress posts into natural-sounding audio. The speech is generated **offline, inside the editor's browser**, saved to the Media Library, and played back to visitors with a plain `<audio>` player.

- No external TTS service, no API keys, no data leaving your site.
- No server commands: works on **shared hosting** where `exec()` is disabled.
- Audio is created **only when you press a button**, never automatically on save.

🇹🇷 Türkçe: [README.tr.md](README.tr.md)

Source code, issues and releases: <https://github.com/kinefi/seslendir>

## How it works

1. A person with edit access opens **Seslendir** in the left admin menu and presses **Üret** for a post (or selects many).
2. The plugin prepares the text on the server (numbers, dates, currency, abbreviations → spoken Turkish) and splits it into sentences.
3. The browser loads a local WebAssembly speech engine (sherpa-onnx running a Piper Turkish voice) and synthesizes the audio. A blocking dialog shows progress.
4. Optionally you **listen and approve** the result, then it is uploaded through an authenticated REST endpoint and attached to the post.
5. Visitors get a small player above the post. If you edit the post afterwards, the old audio is hidden and marked **Eski** (stale) until you regenerate it.

## Requirements

- WordPress 7.0+, PHP 8.5+ (`mbstring`), HTTPS recommended.
- A modern desktop browser for whoever generates audio (Chrome, Edge, Firefox or Safari with WebAssembly). Visitors only need an `<audio>`-capable browser.
- The speech engine files (≈ 70–100 MB). They are **not bundled**; the plugin downloads them for you (next section).
- For the automatic download: the server must be able to make outbound HTTPS requests to `github.com`. Otherwise use the manual ZIP or FTP route.

## Installation

1. Upload the plugin folder to `wp-content/plugins/` (or install the release zip) and activate it.
2. Open **Seslendir → Kurulum** and press **Otomatik indir ve kur**. The pack is downloaded in small chunks with a progress bar, each file is verified with SHA-256, and an interrupted download resumes where it stopped.
3. Open **Ayarlar** and pick the content types to voice. Posts are enabled by default.

### Models and engine packs

**Seslendir → Ayarlar** lists the supported models; pick one and save. The **Kurulum** tab downloads only the selected model, into its own folder (`wp-content/uploads/seslendir-motor/<model>/`). Switching models keeps the other downloads. Audio generated with a different model is marked **Eski** (stale).

| Model | Engine | Notes |
|---|---|---|
| `piper-sherpa` (default) | sherpa-onnx WebAssembly + Piper `tr_TR-fettah-medium` | Most compatible, verified end to end |
| `piper-ort` | ONNX Runtime Web + the same Piper voice | No C++ build; less tested |
| `okur` | ONNX Runtime Web + [Okur TTS](https://github.com/ahmethakanbesel/okur-tts) | Often more natural; weights are CC BY 4.0 (attribution required); less tested |

**Maintainer, once:** GitHub → **Actions → Build engines → Run workflow** (`models = all`, or one model). Each model is built in its own job, loaded in headless Chromium to check that it really speaks Turkish, and only then published with its `engine-manifest.json` as the GitHub release `motor-piper-sherpa`, `motor-piper-ort` or `motor-okur`. Nothing is published if a smoke test fails.

**Every site:** the **Otomatik indir** button reads the selected model's manifest and downloads its files in small resumable chunks, each verified with SHA-256. If your server cannot reach GitHub, download `seslendir-motor-<model>.zip` from that release and upload it in the **Kurulum** tab, or copy the files by FTP to the model's folder.

Files per model (the manifest lists exactly these and nothing else):

| Model | Files |
|---|---|
| `piper-sherpa` | `sherpa-onnx-tts.js`, `sherpa-onnx-wasm-main-tts.js/.wasm/.data`, phonemizer (below) |
| `piper-ort` | `ort.wasm.min.js`, `ort-wasm-simd-threaded.js/.wasm`, `voice.onnx`, `voice.onnx.json`, phonemizer |
| `okur` | `ort.wasm.min.js`, `ort-wasm-simd-threaded.js/.wasm`, `okur-config.json`, `okur-frontend.wasm`, `okur-text.onnx`, `okur-sample.onnx`, `okur-decoder.onnx` |
| phonemizer (Piper models) | `piper-phonemize.js`, `piper-phonemize-wasm-main.js/.wasm/.data` (Turkish text → phonemes with espeak-ng, in a Web Worker) |
| optional | `lame.min.js` ([lamejs](https://github.com/zhuker/lamejs), LGPL): audio is saved as small MP3 instead of WAV |

Other hosts: set `YS_ENGINE_MANIFEST_URL` (or the `ys_engine_manifest_url` filter) and, if the host is not GitHub or Hugging Face, add it with `ys_engine_allowed_hosts`. Manifests must be `https`, carry a SHA-256 per file and match the selected engine kind. Packs already downloaded by an older version are moved into the new folder automatically.

> **Status note:** the PHP, normalizer, storage, download and UI code is covered by automated tests. Only the `piper-sherpa` pack has been run end to end on a real site; the `piper-ort` and `okur` packs are verified by the workflow's smoke test only.

Okur licences: code Apache-2.0, weights **CC BY 4.0**, so attribution is required: "okur" (Ahmet Hakan Beşel) and the training corpora Mozilla Common Voice (CC0), ISSAI Turkish Speech Corpus (MIT), MediaSpeech (CC BY 4.0) and Google FLEURS (CC BY 4.0). The model card asks not to use the voice to impersonate a real person or pass it off as human. Check the Piper voice's licence before commercial use.

## Using it

- **Yazılar tab:** status per post (🔊 Var / ⚠️ Eski / ➕ Yok), filters, search, bulk actions.
  - **Üret / Yeniden üret** – generate audio for one post (with optional listen-and-approve step).
  - **Önizle** – speak the first few sentences and show the normalized text; nothing is saved.
  - **Dinle / Sil** – play or delete the saved audio.
  - **Eksik olanların hepsini seslendir**, **Eski olanları yenile**, **Seçilenleri seslendir** – batch runs. Keep the tab open until the dialog finishes.
- **Posts list:** an optional read-only **Ses** column links to the generator.
- **Front end:** the player is inserted above the post content automatically, or place `[seslendir]` (optionally `id="123"`) yourself and turn auto-insert off.

## Settings

| Setting | Meaning |
|---|---|
| Content types | Which public post types can be voiced |
| Read title | Speak the post title first |
| Confirm before save | Single-post runs ask you to listen before saving |
| Auto-insert player | Show the player above the content automatically |
| Posts list column | Show the **Ses** column |
| Player label | Heading above the player |
| Speed | 0.6–1.6; changing it marks all audio as stale |
| Speaker number | 0 for single-voice models |
| Size limit (MB) | Upload limit, also capped by PHP `post_max_size` |
| Voice tag | Change when you install a different voice; marks audio as stale |

## Security & privacy

- Everything runs on your site and in your browser; nothing is sent to third parties. The only outbound request is the optional engine download from GitHub, made by the server when an administrator presses the button.
- REST routes need a logged-in user and the REST nonce. Post routes need `edit_post` for the post, listing needs `edit_posts` (authors only see their own posts), engine routes need `manage_options`.
- Engine downloads: the manifest and file URLs must be `https` on an allow-listed host, file names must be one of nine fixed names, sizes are capped, and every file is verified against the SHA-256 in the manifest before it is put in place. Only one download runs at a time.
- Uploads are accepted only if the bytes are genuine WAV or MP3, within the size limit, and match the hash of the text that was generated (a post edited meanwhile is rejected with HTTP 409).
- Private, password-protected and unpublished posts are never voiced, because the audio file URL is public.
- The engine ZIP extractor only writes nine allow-listed file names, ignores any path inside the archive and requires `manage_options`.
- Uninstalling removes the settings and the cron event; generated audio and engine files stay on disk.

## Known limitations

- Quality is that of a single medium-size Piper voice: clear and natural, not studio-grade.
- Generation speed depends on the editor's computer. Long posts take minutes.
- Currency suffixes after an apostrophe are dropped (`₺100'ü` → "yüz lira").
- WAV files are large (≈ 2.6 MB per minute). The engine pack includes `lame.min.js` when it can be fetched, which switches to MP3.
- Interface language: Turkish only.

## Development

```sh
sh tests/run.sh        # PHP lint, JS syntax, PHP tests, JS tests
```

- `tests/` – PHP tests (WordPress functions stubbed in `tests/wp_stubs.php`) and JavaScript tests.
- `tools/make-engine-manifest.py` – writes `engine-manifest.json`; `tools/engine-smoke.mjs` – headless-Chromium smoke test of an engine pack.
- `.github/workflows/tests.yml` – lint and tests on PHP 8.5 and Node.
- `.github/workflows/security.yml` – WordPress security sniffs, PHP 8.5 compatibility, Semgrep, CodeQL, Gitleaks (weekly + on every push/PR).
- `.github/workflows/build-engines.yml` – builds, smoke-tests and publishes the engine packs, one job per model (manual trigger).
- `.github/workflows/release.yml` – push a `vX.Y.Z` tag to build the installable zip and publish a GitHub release. The tag must match the plugin header version, `YS_VERSION` and a `CHANGELOG.md` entry.

## License

GPL-3.0-or-later. See [LICENSE](LICENSE). Voice models, the sherpa-onnx runtime and lamejs have their own licenses; check them before commercial use.

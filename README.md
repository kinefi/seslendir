# Yazıyı Seslendir

Turn your Turkish WordPress posts into natural-sounding audio. The speech is generated **offline, inside the editor's browser**, saved to the Media Library, and played back to visitors with a plain `<audio>` player.

- No external TTS service, no API keys, no data leaving your site.
- No server commands: works on **shared hosting** where `exec()` is disabled.
- Audio is created **only when you press a button**, never automatically on save.

🇹🇷 Türkçe: [README.tr.md](README.tr.md)

Source code, issues and releases: <https://github.com/kinefi/yaziyi-seslendir>

## How it works

1. A person with edit access opens **Yazıyı Seslendir** in the left admin menu and presses **Üret** for a post (or selects many).
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
2. Open **Yazıyı Seslendir → Kurulum** and press **Otomatik indir ve kur**. The pack is downloaded in small chunks with a progress bar, each file is verified with SHA-256, and an interrupted download resumes where it stopped.
3. Open **Ayarlar** and pick the content types to voice. Posts are enabled by default.

### Where the engine pack comes from

There is no ready-made Turkish sherpa-onnx WebAssembly build to download, because each prebuilt sherpa package has its voice baked in. This repository therefore builds one itself:

- **Maintainer, once:** GitHub → **Actions → Build engine pack → Run workflow**. It builds sherpa-onnx for WebAssembly with the Piper `tr_TR-fettah-medium` voice, loads the result in headless Chromium and checks that it really speaks Turkish, then publishes the files plus `engine-manifest.json` as the GitHub release `engine-1`. Nothing is published if the smoke test fails.
- **Every site:** the **Otomatik indir** button reads that manifest and downloads the files.

Until the workflow has run once, the Kurulum tab says the pack is not published yet. If your server cannot reach GitHub, download `yaziyi-seslendir-motor.zip` from the `engine-1` release and upload it in the **Kurulum** tab, or copy the files by FTP to `wp-content/uploads/yaziyi-seslendir-motor/`.

| File | Required | Purpose |
|---|---|---|
| `sherpa-onnx-tts.js` | yes | JavaScript API (`createOfflineTts`) |
| `sherpa-onnx-wasm-main-tts.js` | yes | Emscripten loader |
| `sherpa-onnx-wasm-main-tts.wasm` | yes | Runtime |
| `sherpa-onnx-wasm-main-tts.data` | yes | Packed model and tokens |
| `piper-phonemize.js`, `piper-phonemize-wasm-main.js/.wasm/.data` | yes | Turkish text → phonemes (espeak-ng), run in a Web Worker |
| `lame.min.js` | no | [lamejs](https://github.com/zhuker/lamejs) (LGPL); when present audio is saved as small MP3 files instead of WAV |

Other hosts or a different voice: set the `YS_ENGINE_MANIFEST_URL` constant (or the `ys_engine_manifest_url` filter) and, if the host is not GitHub or Hugging Face, add it with the `ys_engine_allowed_hosts` filter. Manifest files must be `https`, list only the nine file names above, and carry a SHA-256 for each file.

> **Status note:** the PHP, normalizer, storage, download and UI code is covered by automated tests. The WebAssembly engine itself could not be built in the environment where this release was authored, so the *Build engine pack* workflow is its first real run; its smoke test is what proves the pack works. If the build step fails, open the failing step's log (the sherpa-onnx build script name or asset layout may differ between versions; the `sherpa_ref` input lets you pin a version).

### Okur TTS (`okur`)

[Okur](https://github.com/ahmethakanbesel/okur-tts) is a small open Turkish model (one voice, 48 kHz). To use it:

1. Run **Actions → Build engine pack (Okur) → Run workflow** once (publishes release `engine-3`; the Rust text frontend is compiled there).
2. Add `define( 'YS_ENGINE_KIND', 'okur' );` to `wp-config.php`.
3. Use **Kurulum → Otomatik indir ve kur**.

Licences: Okur's code is Apache-2.0; its weights are **CC BY 4.0**, so attribution is required: "okur" (Ahmet Hakan Beşel) and the training corpora Mozilla Common Voice (CC0), ISSAI Turkish Speech Corpus (MIT), MediaSpeech (CC BY 4.0) and Google FLEURS (CC BY 4.0). See the project's NOTICE. The model card asks not to use the voice to impersonate a real person or pass it off as human.

### Alternative engine: ONNX Runtime (`piper-ort`)

Building sherpa-onnx for WebAssembly is the most fragile part of the setup. As an alternative, the plugin can run the Piper model directly with ONNX Runtime Web (no compilation at all):

1. Run **Actions → Build engine pack (ONNX Runtime) → Run workflow** once (publishes release `engine-2`).
2. Add `define( 'YS_ENGINE_KIND', 'piper-ort' );` to `wp-config.php`.
3. Use **Kurulum → Otomatik indir ve kur** as usual.

`sherpa` remains the default until the ONNX Runtime pack has been verified. Check the voice's licence before commercial use.

## Using it

- **Yazılar tab:** status per post (🔊 Var / ⚠️ Eski / ➕ Yok), filters, search, bulk actions.
  - **Üret / Yeniden üret** – generate audio for one post (with optional listen-and-approve step).
  - **Önizle** – speak the first few sentences and show the normalized text; nothing is saved.
  - **Dinle / Sil** – play or delete the saved audio.
  - **Eksik olanların hepsini seslendir**, **Eski olanları yenile**, **Seçilenleri seslendir** – batch runs. Keep the tab open until the dialog finishes.
- **Posts list:** an optional read-only **Ses** column links to the generator.
- **Front end:** the player is inserted above the post content automatically, or place `[yaziyi_seslendir]` (optionally `id="123"`) yourself and turn auto-insert off.

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
- `.github/workflows/build-engine.yml` – builds, smoke-tests and publishes the engine pack (manual trigger).
- `.github/workflows/release.yml` – push a `vX.Y.Z` tag to build the installable zip and publish a GitHub release. The tag must match the plugin header version, `YS_VERSION` and a `CHANGELOG.md` entry.

## License

GPL-3.0-or-later. See [LICENSE](LICENSE). Voice models, the sherpa-onnx runtime and lamejs have their own licenses; check them before commercial use.

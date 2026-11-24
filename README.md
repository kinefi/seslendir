# Yazıyı Seslendir

Turn your Turkish WordPress posts into natural-sounding audio. The speech is generated **offline, inside the editor's browser**, saved to the Media Library, and played back to visitors with a plain `<audio>` player.

- No external TTS service, no API keys, no data leaving your site.
- No server commands: works on **shared hosting** where `exec()` is disabled.
- Audio is created **only when you press a button**, never automatically on save.

🇹🇷 Türkçe: [README.tr.md](README.tr.md)

Source code, issues and releases: <https://github.com/kinefi/yaziyi-seslendir>

## How it works

1. A person with edit access opens **Tools → Yazıyı Seslendir** and presses **Üret** for a post (or selects many).
2. The plugin prepares the text on the server (numbers, dates, currency, abbreviations → spoken Turkish) and splits it into sentences.
3. The browser loads a local WebAssembly speech engine (sherpa-onnx running a Piper Turkish voice) and synthesizes the audio. A blocking dialog shows progress.
4. Optionally you **listen and approve** the result, then it is uploaded through an authenticated REST endpoint and attached to the post.
5. Visitors get a small player above the post. If you edit the post afterwards, the old audio is hidden and marked **Eski** (stale) until you regenerate it.

## Requirements

- WordPress 5.8+, PHP 8.4+ (`mbstring`), HTTPS recommended.
- A modern desktop browser for whoever generates audio (Chrome, Edge, Firefox or Safari with WebAssembly). Visitors only need an `<audio>`-capable browser.
- The speech engine files (see below). They are large (≈ 70–100 MB) and are **not bundled**.

## Installation

1. Upload the plugin folder to `wp-content/plugins/` (or install the release zip) and activate it.
2. Prepare and install the **engine pack** (next section) from **Tools → Yazıyı Seslendir → Kurulum**.
3. Open **Ayarlar** and pick the content types to voice. Posts are enabled by default.

## The engine pack

The pack contains four files built from [sherpa-onnx](https://github.com/k2-fsa/sherpa-onnx)'s WebAssembly TTS target with a Piper Turkish voice baked in, plus one optional file:

| File | Required | Purpose |
|---|---|---|
| `sherpa-onnx-tts.js` | yes | JavaScript API (`createOfflineTts`) |
| `sherpa-onnx-wasm-main-tts.js` | yes | Emscripten loader |
| `sherpa-onnx-wasm-main-tts.wasm` | yes | Runtime |
| `sherpa-onnx-wasm-main-tts.data` | yes | Packed model, tokens and `espeak-ng-data` |
| `lame.min.js` | no | [lamejs](https://github.com/zhuker/lamejs); when present audio is saved as small MP3 files instead of WAV |

Building it, once, on any computer:

1. Get the Turkish voice, e.g. `tr_TR-fettah-medium` (single speaker; its training dataset is CC0, see the model card). A ready-to-use sherpa-onnx copy with `tokens.txt` and `espeak-ng-data/` exists as `csukuangfj/vits-piper-tr_TR-fettah-medium` on Hugging Face.
2. Follow the sherpa-onnx *WebAssembly text-to-speech* build instructions. Put the model as `model.onnx`, plus `tokens.txt` and the `espeak-ng-data/` folder, into the TTS assets directory before building, so they are packed into `sherpa-onnx-wasm-main-tts.data`. Exact script and folder names depend on the sherpa-onnx version, so follow the docs of the version you check out.
3. Zip the outputs with `sh tools/make-engine-pack.sh <build-folder> [lame.min.js]`.
4. Upload the zip in the **Kurulum** tab. If your host's upload limit is too small, copy the files by FTP to the folder shown on that tab (`wp-content/uploads/yaziyi-seslendir-motor/`).

> **Status note:** the PHP, normalizer, storage and UI code is covered by automated tests. The WebAssembly engine itself could not be built or exercised in the environment where this release was authored, so please run a first smoke test on your site: install the pack, press **Önizle** on one post and listen. Report problems with the exact browser message shown in the dialog.

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

- Everything runs on your site and in your browser; nothing is sent to third parties.
- REST routes need a logged-in user, the REST nonce and `edit_post` for the post. Listing needs `edit_posts`; authors only see their own posts.
- Uploads are accepted only if the bytes are genuine WAV or MP3, within the size limit, and match the hash of the text that was generated (a post edited meanwhile is rejected with HTTP 409).
- Private, password-protected and unpublished posts are never voiced, because the audio file URL is public.
- The engine ZIP extractor only writes five allow-listed file names, ignores any path inside the archive and requires `manage_options`.
- Uninstalling removes the settings and the cron event; generated audio stays in the Media Library.

## Known limitations

- Quality is that of a single medium-size Piper voice: clear and natural, not studio-grade.
- Generation speed depends on the editor's computer. Long posts take minutes.
- Currency suffixes after an apostrophe are dropped (`₺100'ü` → "yüz lira").
- WAV files are large (≈ 2.6 MB per minute). Add `lame.min.js` for MP3.
- Interface language: Turkish only.

## Development

```sh
sh tests/run.sh        # PHP lint, JS syntax, PHP tests, JS tests
```

- `tests/` – PHP tests (WordPress functions stubbed in `tests/wp_stubs.php`) and JavaScript tests.
- `.github/workflows/tests.yml` – lint and tests on PHP 8.4 / 8.5 and Node.
- `.github/workflows/security.yml` – WordPress security sniffs, PHP 8.4 compatibility, Semgrep, CodeQL, Gitleaks (weekly + on every push/PR).
- `.github/workflows/release.yml` – push a `vX.Y.Z` tag to build the installable zip and publish a GitHub release. The tag must match the plugin header version, `YS_VERSION` and a `CHANGELOG.md` entry.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE). Voice models and the sherpa-onnx runtime have their own licenses; check them before commercial use.

# Changelog

## [1.3.0] - 2026-11-26
### Added
- **Third engine kind, `okur`**: [Okur TTS](https://github.com/ahmethakanbesel/okur-tts), a small Turkish model (one voice, 48 kHz) run with ONNX Runtime Web. Its text frontend is compiled from Rust to WebAssembly in CI. New workflow `Build engine pack (Okur)` (`build-engine-okur.yml`, release `engine-3`) collects the pack and smoke-tests it in headless Chromium before publishing. Select it with `define( 'YS_ENGINE_KIND', 'okur' );`.
- `assets/ys-okur.js` (JavaScript port of Okur's chunking, noise and timeline helpers, unit-tested), engine-kind tests for `okur`.
- Attribution note in the Kurulum tab when `okur` is selected (the weights are CC BY 4.0).
### Notes
- `sherpa` stays the default. The Okur path has not been run against the real model outside CI.

## [1.2.0] - 2026-11-26
### Added
- **Second engine kind, `piper-ort`** (ONNX Runtime Web + the Piper `tr_TR-fettah-medium` model run directly, with `piper-phonemize` for the text front end). No C++/Emscripten build is needed: the new `Build engine pack (ONNX Runtime)` workflow (`build-engine-ort.yml`) only collects prebuilt files (onnxruntime-web from npm, the voice from the `csukuangfj/vits-piper-tr_TR-fettah-medium` Hugging Face repo, the phonemizer bundle), smoke-tests them in headless Chromium and publishes release `engine-2`.
- `YS_ENGINE_KIND` constant / `ys_engine_kind` filter selects the engine (`sherpa` stays the default). The manifest carries an `engine` field; a manifest for another engine kind is rejected. The default manifest URL follows the kind (`engine-1` / `engine-2`).
- **Requires PHP 8.5+** (was 8.4+). CI runs tests and the compatibility scan on PHP 8.5 only.
- `assets/ys-piper.js` (phoneme → model-input helpers, unit-tested), engine-kind tests, `--engine` option for `tools/make-engine-manifest.py`, `tools/engine-smoke.mjs <dir> [kind]`.

## [1.1.1] - 2026-11-26
### Fixed
- **`Build engine pack` failed at the CMake step** ("No text frontend found"). Current sherpa-onnx no longer uses the voice's `espeak-ng-data`; text must be phonemized separately. The workflow now fetches the `piper-phonemize` WebAssembly bundle (SHA-256 pinned), stages only `model.onnx` and `tokens.txt`, and adds the four `piper-phonemize*` files to the pack.
- The build also failed in kaldifst (`strtoll` undeclared) with Emscripten `latest` (7.0.1). The workflow now defaults to Emscripten 4.0.23, the version sherpa-onnx's own CI uses.
### Changed
- The engine loader phonemizes Turkish text with espeak-ng (`tr`) in a Web Worker (`assets/ys-phonemize-worker.js`) and passes the phoneme codes to sherpa-onnx (`generateWithConfig`). `engine.generate()` is now asynchronous.
- The pack, manifest, ZIP installer and allow-list now cover nine file names (was five).

## [1.1.0] - 2026-11-25
### Added
- **Automatic engine download.** **Yazıyı Seslendir → Kurulum → Otomatik indir ve kur** fetches the speech engine pack from the project's GitHub release (`engine-1`). It downloads in 6 MB HTTP Range chunks driven by a progress bar (works within shared-hosting time limits), resumes interrupted downloads, verifies every file against the SHA-256 in `engine-manifest.json`, and runs one download at a time. The manifest and file URLs must be `https` on an allow-listed host and may only name the five known engine files.
- **`Build engine pack` GitHub workflow** (`.github/workflows/build-engine.yml`, manual trigger): builds sherpa-onnx for WebAssembly with the Piper `tr_TR-fettah-medium` voice, adds the optional `lame.min.js`, smoke-tests the pack in headless Chromium (it must really synthesize Turkish speech) and only then publishes the files, `engine-manifest.json` and a manual-install `seslendir-motor.zip` as the `engine-1` release.
- `tools/make-engine-manifest.py` (manifest writer), `tools/engine-smoke.mjs` + `tools/smoke.html` (smoke test), `assets/ys-engine.js` (engine loader shared by the admin page and the smoke test, so the test exercises the same loading code as the site).
- Filters/constant for other hosts: `YS_ENGINE_MANIFEST_URL`, `ys_engine_manifest_url`, `ys_engine_allowed_hosts`.
- Tests for the downloader (manifest validation, host allow-list, `Content-Range` parsing, resume/reset planning, Python-manifest ↔ PHP-validator compatibility) and for the admin menu and plugin header.

### Changed
- **The admin page now has its own item in the left menu** ("Yazıyı Seslendir", below Comments) instead of being hidden under Tools. The posts-list shortcut and all links use `admin.php?page=seslendir`.
- **Requires WordPress 7.0+** (plugin header `Requires at least: 7.0`) and PHP 8.4+.
- Admin scripts are now loaded only on the tab that needs them (list, setup).
- License is **GPL-3.0-or-later** (header, `LICENSE`, READMEs).
- READMEs and the Kurulum tab explain where the engine pack comes from and the manual ZIP/FTP fallback.

### Fixed
- Users could not find the plugin because it was only reachable from Tools.
- The speech engine files could not be found anywhere for Turkish; they are now built and published by the project itself.

## [1.0.0] - 2026-11-24
### Added
- **Yazıyı Seslendir** admin page (**Tools → Yazıyı Seslendir**) with three tabs: **Yazılar** (post list with audio status), **Kurulum** (speech engine setup) and **Ayarlar** (settings).
- **Button-only generation:** audio is never produced automatically when a post is saved or published. It is created only when someone presses **Üret / Yeniden üret**, **Seçilenleri seslendir**, **Eksik olanların hepsini seslendir** or **Eski olanları yenile**.
- **Browser-side speech engine:** audio is generated in the editor's own browser with a local WebAssembly build of sherpa-onnx running a Piper Turkish voice. No external service, no server commands (`exec`/`shell_exec` are never used), works on shared hosting.
- **Blocking progress dialog** (like the media uploader) with progress bar, current post, cancel-with-confirmation, a leave-page warning and a screen wake lock. It cannot be dismissed with `Esc`, the overlay or a close button while work is running.
- **Preview:** a per-row **Önizle** button reads the first few sentences and shows the exact text that will be spoken (numbers, dates and abbreviations already expanded). For single-post runs the finished audio can be listened to and approved before it is saved (setting **Tek yazıda kaydetmeden önce dinleyip onayla**, on by default).
- **Turkish text normalizer:** numbers (including `bin`/`bir milyon` rules and decimals), dates (`14.10.2026`, `2026-11-24`), times, currency (`₺`, `TL`, `$`, `€`, `£`), percentages, units, common abbreviations (`vb.`, `vs.`, `Dr.`, `Prof.` …), number suffixes (`2026'da` → `iki bin yirmi altıda`), URLs, e-mail addresses, emoji and symbols. Posts are split into sentences with natural pauses (longer after paragraphs and headings).
- **Audio status per post:** 🔊 Var / ⚠️ Eski (text or voice settings changed since generation) / ➕ Yok, with duration and size. Filters, search, pagination and bulk actions.
- **Posts list column:** a read-only **Ses** column with a shortcut to the admin page (can be switched off). No other admin screen is modified and no editor sidebar is added.
- **Front end:** a tiny player (`<audio>` only, no JavaScript, one inline style block) shown above the post content when up-to-date audio exists, or via the `[yaziyi_seslendir]` shortcode. Stale audio is hidden automatically.
- **Storage in the Media Library** (`.mp3` when the optional `lame.min.js` is present, otherwise 16-bit mono `.wav`), linked to the post; removed together with the post.
- **Engine setup tab:** shows which engine files are present, accepts a ZIP (only five allow-listed file names are extracted) or files copied by FTP.
- **Weekly WP-Cron maintenance** that removes orphaned audio attachments. Cron is never used to generate audio.
- **Security:** REST endpoints require login + REST nonce + `edit_post` for the post; uploads are accepted only if the bytes are really WAV/MP3 (magic bytes), within the size limit and for the exact text hash that was generated; private, password-protected and unpublished posts are refused; all output is escaped; no files are executed.
- **Settings:** content types, read title, confirm before save, auto-insert player, posts-list column, player label, speaking speed, speaker number, upload size limit, voice tag.
- **Tests and CI:** `tests/` (PHP unit tests with WordPress stubs, JavaScript tests, `tests/run.sh`) and GitHub workflows for tests (PHP 8.4 / 8.5, Node), security (WPCS security sniffs, PHP 8.4 compatibility, Semgrep, CodeQL, Gitleaks) and release (tag → installable zip).
- English `README.md` and Turkish `README.tr.md`.

### Known limitations
- The speech engine files (WASM + Turkish model, roughly 70–100 MB) are **not bundled**; build or obtain them once and install them from the **Kurulum** tab (see the README).
- The browser must stay open on the page while audio is being generated. Interrupted work resumes post by post; unfinished audio is never saved.
- Currency amounts drop a trailing apostrophe suffix (`₺100'ü` is read as `yüz lira`).
- The interface is Turkish only in this release.

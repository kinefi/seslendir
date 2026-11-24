# Changelog

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

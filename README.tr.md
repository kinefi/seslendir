# Seslendir

WordPress yazılarınızı doğal Türkçe sesle seslendirir. Ses, **çevrimdışı ve editörün kendi tarayıcısında** üretilir, Medya Kitaplığı'na kaydedilir ve ziyaretçilere sade bir `<audio>` oynatıcısıyla sunulur.

- Dış TTS servisi, API anahtarı yok; verileriniz siteden çıkmaz.
- Sunucuda komut çalıştırılmaz: `exec()` kapalı **paylaşımlı hostinglerde** de çalışır.
- Ses **yalnızca bir düğmeye bastığınızda** üretilir; yazı kaydedilince kendiliğinden üretilmez.

🇬🇧 English: [README.md](README.md)

Kaynak kod, sorunlar ve sürümler: <https://github.com/kinefi/seslendir>

## Nasıl çalışır?

1. Düzenleme yetkisi olan biri sol yönetim menüsündeki **Seslendir** sayfasını açar ve bir yazı için **Üret**'e basar (ya da birçok yazı seçer).
2. Eklenti metni sunucuda hazırlar (sayılar, tarihler, para, kısaltmalar → okunuşa çevrilir) ve cümlelere böler.
3. Tarayıcı yerel bir WebAssembly ses motoru (sherpa-onnx üzerinde Piper Türkçe sesi) yükler ve sesi üretir. İlerleme, kapatılamayan bir pencerede gösterilir.
4. İsterseniz sonucu **dinleyip onaylarsınız**; ses kimlik doğrulamalı bir REST uç noktasından yüklenir ve yazıya bağlanır.
5. Ziyaretçiler yazının başında küçük bir oynatıcı görür. Yazıyı sonradan değiştirirseniz eski ses gizlenir ve yeniden üretene kadar **Eski** görünür.

## Gereksinimler

- WordPress 7.0+, PHP 8.5+ (`mbstring`), HTTPS önerilir.
- Sesi üreten kişi için WebAssembly destekleyen güncel bir masaüstü tarayıcı (Chrome, Edge, Firefox, Safari). Ziyaretçiler için `<audio>` desteği yeterli.
- Ses motoru dosyaları (≈ 70–100 MB). Eklentiyle **birlikte gelmezler**; eklenti sizin için indirir (sonraki bölüm).
- Otomatik indirme için sunucunun `github.com` adresine dışarı HTTPS isteği atabilmesi gerekir. Atamıyorsa elle ZIP ya da FTP yolunu kullanın.

## Kurulum

1. Eklenti klasörünü `wp-content/plugins/` içine yükleyin (ya da sürüm ZIP'ini kurun) ve etkinleştirin.
2. **Seslendir → Kurulum** sekmesini açın ve **Otomatik indir ve kur**'a basın. Paket küçük parçalarla, ilerleme çubuğuyla indirilir; her dosya SHA-256 ile doğrulanır; yarıda kalan indirme kaldığı yerden devam eder.
3. **Ayarlar** sekmesinde seslendirilecek içerik türlerini seçin. Varsayılan olarak yalnızca "Yazılar" etkindir.

### Modeller ve motor paketleri

**Seslendir → Ayarlar** desteklenen modelleri listeler; birini seçip kaydedin. **Kurulum** sekmesi yalnızca seçili modeli kendi klasörüne indirir (`wp-content/uploads/seslendir-motor/<model>/`). Model değiştirince diğer indirmeler silinmez. Başka bir modelle üretilmiş sesler **Eski** olarak işaretlenir.

| Model | Motor | Not |
|---|---|---|
| `piper-sherpa` (varsayılan) | sherpa-onnx WebAssembly + Piper `tr_TR-fettah-medium` | En uyumlu, uçtan uca doğrulandı |
| `piper-ort` | ONNX Runtime Web + aynı Piper sesi | C++ derlemesi yok; daha az sınandı |
| `okur` | ONNX Runtime Web + [Okur TTS](https://github.com/ahmethakanbesel/okur-tts) | Çoğunlukla daha doğal; ağırlıklar CC BY 4.0 (atıf gerekir); daha az sınandı |
| `ema` | ONNX Runtime Web + [EMA Lightning](https://huggingface.co/ozcancelik/ema-lightning-onnx) | 48 kHz, tek ses, Apache-2.0; gerçek model dosyalarıyla uçtan uca çalıştırıldı, çıktı referans demoyla aynı |
| `zerre` | ONNX Runtime Web + [Zerre-TTS](https://huggingface.co/erkint/zerre-tts) | 8,5 MB int8, 24 kHz, iki ses (MK, NK; hoparlör ayarı birini seçer), Apache-2.0; deposundaki eğitim verisi notlarına bakın |

**Proje sahibi, bir kez:** GitHub → **Actions → Build engines → Run workflow** (`models = all` ya da tek model). Her model kendi işinde derlenir, başsız Chromium'da gerçekten Türkçe konuştuğu doğrulanır, ancak ondan sonra `engine-manifest.json` ile birlikte `motor-piper-sherpa`, `motor-piper-ort`, `motor-okur`, `motor-ema` ya da `motor-zerre` GitHub sürümü olarak yayımlanır. Duman testi başarısızsa hiçbir şey yayımlanmaz.

**Her site:** **Otomatik indir** düğmesi seçili modelin manifestini okur ve dosyaları küçük, kaldığı yerden devam eden parçalarla indirir; her dosya SHA-256 ile doğrulanır. Sunucunuz GitHub'a ulaşamıyorsa ilgili sürümden `seslendir-motor-<model>.zip` dosyasını indirip **Kurulum** sekmesinden yükleyin ya da dosyaları FTP ile modelin klasörüne kopyalayın.

Modele göre dosyalar (manifest tam olarak bunları listeler, başka bir şey kabul edilmez):

| Model | Dosyalar |
|---|---|
| `piper-sherpa` | `sherpa-onnx-tts.js`, `sherpa-onnx-wasm-main-tts.js/.wasm/.data`, fonemleştirici (aşağıda) |
| `piper-ort` | `ort.wasm.min.js`, `ort-wasm-simd-threaded.js/.wasm`, `voice.onnx`, `voice.onnx.json`, fonemleştirici |
| `okur` | `ort.wasm.min.js`, `ort-wasm-simd-threaded.js/.wasm`, `okur-config.json`, `okur-frontend.wasm`, `okur-text.onnx`, `okur-sample.onnx`, `okur-decoder.onnx` |
| `ema` | `ort.wasm.min.js`, `ort-wasm-simd-threaded.js/.wasm`, `ema-meta.json`, `ema-text.onnx`, `ema-sound.onnx`, `ema-decoder.onnx` |
| `zerre` | `ort.wasm.min.js`, `ort-wasm-simd-threaded.js/.wasm`, `zerre-model.onnx`, `zerre-model.json`, `zerre-lexicon.json` |
| fonemleştirici (Piper modelleri) | `piper-phonemize.js`, `piper-phonemize-wasm-main.js/.wasm/.data` (Türkçe metin → fonem, espeak-ng; Web Worker'da) |
| isteğe bağlı | `lame.min.js` ([lamejs](https://github.com/zhuker/lamejs), LGPL): ses WAV yerine küçük MP3 kaydedilir |

Başka sunucular için `YS_ENGINE_MANIFEST_URL` (ya da `ys_engine_manifest_url` filtresi) ayarlayın; sunucu GitHub/Hugging Face değilse `ys_engine_allowed_hosts` ile ekleyin. Manifest `https` olmalı, her dosya için SHA-256 taşımalı ve seçili motor türüyle eşleşmelidir. Eski sürümün indirdiği paketler yeni klasöre otomatik taşınır.

**Kurulum** sekmesinde ayrıca **Yeniden indir** (modelin dosyalarını silip baştan indirir) ve **Model dosyalarını temizle** (siler) düğmeleri vardır. Dosya adreslerindeki sürüm eki kurulu dosyalardan türetilir; böylece tarayıcı ya da Cloudflare gibi CDN'ler eski bir betiği yeni `.wasm` ile karıştırmaz.

> **Durum notu:** PHP, normalleştirici, depolama, indirme ve arayüz kodu otomatik testlerle sınanmıştır. Gerçek bir sitede uçtan uca yalnızca `piper-sherpa` paketi çalıştırıldı; `piper-ort` ve `okur` yalnızca iş akışının duman testiyle doğrulanır.

Okur lisansları: kod Apache-2.0, ağırlıklar **CC BY 4.0**, yani atıf gerekir: "okur" (Ahmet Hakan Beşel) ve eğitim derlemleri Mozilla Common Voice (CC0), ISSAI Turkish Speech Corpus (MIT), MediaSpeech (CC BY 4.0) ve Google FLEURS (CC BY 4.0). Model kartı, sesin gerçek bir kişiyi taklit etmek ya da insan gibi sunmak için kullanılmamasını ister. Ticari kullanımdan önce Piper sesinin lisansını da kontrol edin.

## Kullanım

- **Yazılar sekmesi:** her yazının durumu (🔊 Var / ⚠️ Eski / ➕ Yok), süzgeçler, arama ve toplu işlemler.
  - **Üret / Yeniden üret**: tek yazı için ses üretir (isteğe bağlı dinle-ve-onayla adımıyla).
  - **Önizle**: ilk birkaç cümleyi okur ve okunacak düzeltilmiş metni gösterir; hiçbir şey kaydedilmez.
  - **Dinle / Sil**: kayıtlı sesi çalar ya da siler.
  - **Eksik olanların hepsini seslendir**, **Eski olanları yenile**, **Seçilenleri seslendir**: toplu işlemler. Pencere bitene kadar sekmeyi açık tutun.
- **Yazılar listesi:** isteğe bağlı, salt-okunur **Ses** sütunu üretim sayfasına kısayol verir.
- **Ön yüz:** oynatıcı yazı içeriğinin üstüne otomatik eklenir. İsterseniz otomatik eklemeyi kapatıp `[seslendir]` kısa kodunu (isteğe bağlı `id="123"`) kendiniz yerleştirin.

## Ayarlar

| Ayar | Anlamı |
|---|---|
| İçerik türleri | Hangi herkese açık türler seslendirilebilir |
| Başlığı da oku | Yazı başlığı önce okunur |
| Kaydetmeden önce onayla | Tek yazıda kaydetmeden önce dinletir |
| Oynatıcıyı otomatik göster | Oynatıcı içeriğin başına kendiliğinden eklenir |
| Yazılar listesinde sütun | **Ses** sütununu gösterir |
| Oynatıcı başlığı | Oynatıcının üstündeki başlık |
| Hız | 0,6–1,6; değişince tüm sesler "eski" sayılır |
| Konuşmacı no | Tek sesli modellerde 0 |
| Boyut sınırı (MB) | Yükleme sınırı; PHP `post_max_size` ile de sınırlanır |
| Ses etiketi | Başka bir ses kurunca değiştirin; sesler "eski" sayılır |

## Güvenlik ve gizlilik

- Her şey sitenizde ve tarayıcınızda çalışır; üçüncü taraflara hiçbir şey gönderilmez. Tek dışarı istek, yönetici düğmeye bastığında sunucunun yaptığı isteğe bağlı motor indirmesidir (GitHub).
- REST uç noktaları oturum açmış kullanıcı ve REST nonce'u ister. Yazı uç noktaları ilgili yazı için `edit_post`, listeleme `edit_posts` (yazarlar yalnızca kendi yazılarını görür), motor uç noktaları `manage_options` ister.
- Motor indirmesi: manifest ve dosya adresleri `https` ve izinli bir sunucuda olmalı, dosya adları dokuz sabit addan biri olmalı, boyutlar sınırlıdır ve her dosya yerine konmadan önce manifestteki SHA-256 ile doğrulanır. Aynı anda yalnızca tek indirme çalışır.
- Yükleme yalnızca gerçekten WAV/MP3 olan, boyut sınırı içindeki ve üretilen metnin özetiyle (hash) eşleşen veriyi kabul eder; arada değişen yazı HTTP 409 ile reddedilir.
- Özel, şifreli ve yayımlanmamış yazılar seslendirilmez; çünkü ses dosyasının adresi herkese açıktır.
- Motor ZIP'i yalnızca dokuz izinli dosya adını yazar, arşivdeki tüm yol bilgisini yok sayar ve `manage_options` yetkisi ister.
- Eklenti silinince ayarlar ve cron olayı kaldırılır; üretilmiş sesler ve motor dosyaları diskte kalır.

## Bilinen sınırlar

- Kalite, tek bir orta boy Piper sesinin kalitesidir: net ve doğal, ama stüdyo düzeyinde değil.
- Üretim hızı editörün bilgisayarına bağlıdır. Uzun yazılar dakikalar sürer.
- Kesme işaretinden sonraki para ekleri atılır (`₺100'ü` → "yüz lira").
- WAV dosyaları büyüktür (dakikada ≈ 2,6 MB). Motor paketi, bulunabildiğinde `lame.min.js` içerir; o zaman MP3 kullanılır.
- Arayüz dili yalnızca Türkçe.

## Geliştirme

```sh
sh tests/run.sh        # PHP lint, JS sözdizimi, PHP testleri, JS testleri
```

- `tests/`: PHP testleri (WordPress işlevleri `tests/wp_stubs.php` içinde taklit edilir) ve JavaScript testleri.
- `tools/make-engine-manifest.py`: `engine-manifest.json` yazar; `tools/engine-smoke.mjs`: motor paketinin başsız Chromium duman testi.
- `.github/workflows/tests.yml`: PHP 8.5 ve Node üzerinde lint ve testler.
- `.github/workflows/security.yml`: WordPress güvenlik kuralları, PHP 8.5 uyumluluğu, Semgrep, CodeQL, Gitleaks (haftalık ve her push/PR'da).
- `.github/workflows/build-engines.yml`: motor paketlerini (her model ayrı iş) derler, duman testinden geçirir ve yayımlar (elle tetiklenir).
- `.github/workflows/release.yml`: `vX.Y.Z` etiketi itilince kurulabilir ZIP'i üretip GitHub sürümü yayımlar. Etiket; eklenti başlığındaki sürüm, `YS_VERSION` ve `CHANGELOG.md` kaydıyla eşleşmelidir.

## Lisans

GPL-3.0-or-later. [LICENSE](LICENSE) dosyasına bakın. Ses modellerinin, sherpa-onnx çalışma zamanının ve lamejs'in kendi lisansları vardır; ticari kullanımdan önce kontrol edin.

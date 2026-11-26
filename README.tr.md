# Yazıyı Seslendir

WordPress yazılarınızı doğal Türkçe sesle seslendirir. Ses, **çevrimdışı ve editörün kendi tarayıcısında** üretilir, Medya Kitaplığı'na kaydedilir ve ziyaretçilere sade bir `<audio>` oynatıcısıyla sunulur.

- Dış TTS servisi, API anahtarı yok; verileriniz siteden çıkmaz.
- Sunucuda komut çalıştırılmaz: `exec()` kapalı **paylaşımlı hostinglerde** de çalışır.
- Ses **yalnızca bir düğmeye bastığınızda** üretilir; yazı kaydedilince kendiliğinden üretilmez.

🇬🇧 English: [README.md](README.md)

Kaynak kod, sorunlar ve sürümler: <https://github.com/kinefi/yaziyi-seslendir>

## Nasıl çalışır?

1. Düzenleme yetkisi olan biri sol yönetim menüsündeki **Yazıyı Seslendir** sayfasını açar ve bir yazı için **Üret**'e basar (ya da birçok yazı seçer).
2. Eklenti metni sunucuda hazırlar (sayılar, tarihler, para, kısaltmalar → okunuşa çevrilir) ve cümlelere böler.
3. Tarayıcı yerel bir WebAssembly ses motoru (sherpa-onnx üzerinde Piper Türkçe sesi) yükler ve sesi üretir. İlerleme, kapatılamayan bir pencerede gösterilir.
4. İsterseniz sonucu **dinleyip onaylarsınız**; ses kimlik doğrulamalı bir REST uç noktasından yüklenir ve yazıya bağlanır.
5. Ziyaretçiler yazının başında küçük bir oynatıcı görür. Yazıyı sonradan değiştirirseniz eski ses gizlenir ve yeniden üretene kadar **Eski** görünür.

## Gereksinimler

- WordPress 7.0+, PHP 8.4+ (`mbstring`), HTTPS önerilir.
- Sesi üreten kişi için WebAssembly destekleyen güncel bir masaüstü tarayıcı (Chrome, Edge, Firefox, Safari). Ziyaretçiler için `<audio>` desteği yeterli.
- Ses motoru dosyaları (≈ 70–100 MB). Eklentiyle **birlikte gelmezler**; eklenti sizin için indirir (sonraki bölüm).
- Otomatik indirme için sunucunun `github.com` adresine dışarı HTTPS isteği atabilmesi gerekir. Atamıyorsa elle ZIP ya da FTP yolunu kullanın.

## Kurulum

1. Eklenti klasörünü `wp-content/plugins/` içine yükleyin (ya da sürüm ZIP'ini kurun) ve etkinleştirin.
2. **Yazıyı Seslendir → Kurulum** sekmesini açın ve **Otomatik indir ve kur**'a basın. Paket küçük parçalarla, ilerleme çubuğuyla indirilir; her dosya SHA-256 ile doğrulanır; yarıda kalan indirme kaldığı yerden devam eder.
3. **Ayarlar** sekmesinde seslendirilecek içerik türlerini seçin. Varsayılan olarak yalnızca "Yazılar" etkindir.

### Motor paketi nereden geliyor?

İndirilebilir hazır bir Türkçe sherpa-onnx WebAssembly paketi yoktur; çünkü sherpa'nın hazır paketlerinin her birinin sesi içine gömülüdür. Bu yüzden bu depo paketi kendisi derler:

- **Proje sahibi, bir kez:** GitHub → **Actions → Build engine pack → Run workflow**. İş akışı sherpa-onnx'i Piper `tr_TR-fettah-medium` sesiyle WebAssembly için derler, sonucu başsız Chromium'da açıp gerçekten Türkçe konuştuğunu doğrular, sonra dosyaları ve `engine-manifest.json`'u `engine-1` GitHub sürümü olarak yayımlar. Duman testi başarısızsa hiçbir şey yayımlanmaz.
- **Her site:** **Otomatik indir** düğmesi bu manifesti okur ve dosyaları indirir.

İş akışı bir kez çalışana kadar Kurulum sekmesi paketin henüz yayımlanmadığını söyler. Sunucunuz GitHub'a ulaşamıyorsa `engine-1` sürümündeki `yaziyi-seslendir-motor.zip` dosyasını indirip **Kurulum** sekmesinden yükleyin ya da dosyaları FTP ile `wp-content/uploads/yaziyi-seslendir-motor/` klasörüne kopyalayın.

| Dosya | Zorunlu | Görevi |
|---|---|---|
| `sherpa-onnx-tts.js` | evet | JavaScript API (`createOfflineTts`) |
| `sherpa-onnx-wasm-main-tts.js` | evet | Emscripten yükleyicisi |
| `sherpa-onnx-wasm-main-tts.wasm` | evet | Çalışma zamanı |
| `sherpa-onnx-wasm-main-tts.data` | evet | Paketlenmiş model ve `tokens.txt` |
| `piper-phonemize.js`, `piper-phonemize-wasm-main.js/.wasm/.data` | evet | Türkçe metin → fonem (espeak-ng); Web Worker'da çalışır |
| `lame.min.js` | hayır | [lamejs](https://github.com/zhuker/lamejs) (LGPL); varsa ses WAV yerine küçük MP3 olarak kaydedilir |

Başka bir sunucu ya da farklı bir ses için `YS_ENGINE_MANIFEST_URL` sabitini (ya da `ys_engine_manifest_url` filtresini) ayarlayın; sunucu GitHub ya da Hugging Face değilse `ys_engine_allowed_hosts` filtresiyle ekleyin. Manifest dosyaları `https` olmalı, yalnızca yukarıdaki dokuz dosya adını içermeli ve her dosya için SHA-256 taşımalıdır.

> **Durum notu:** PHP, normalleştirici, depolama, indirme ve arayüz kodu otomatik testlerle sınanmıştır. WebAssembly motorunun kendisi bu sürümün hazırlandığı ortamda derlenemedi; yani *Build engine pack* iş akışı onun ilk gerçek çalıştırmasıdır ve paketin çalıştığını duman testi kanıtlar. Derleme adımı hata verirse o adımın günlüğünü açın (sherpa-onnx betik adı ya da varlık düzeni sürümler arasında değişebilir; `sherpa_ref` girdisiyle bir sürümü sabitleyebilirsiniz).

### Alternatif motor: ONNX Runtime (`piper-ort`)

sherpa-onnx'i WebAssembly için derlemek kurulumun en kırılgan kısmıdır. Alternatif olarak eklenti, Piper modelini ONNX Runtime Web ile doğrudan çalıştırabilir (hiç derleme yok):

1. Bir kez **Actions → Build engine pack (ONNX Runtime) → Run workflow** çalıştırın (`engine-2` sürümünü yayımlar).
2. `wp-config.php` dosyasına `define( 'YS_ENGINE_KIND', 'piper-ort' );` ekleyin.
3. **Kurulum → Otomatik indir ve kur** düğmesini her zamanki gibi kullanın.

ONNX Runtime paketi doğrulanana kadar varsayılan `sherpa` kalır. Ticari kullanımdan önce sesin lisansını kontrol edin.

## Kullanım

- **Yazılar sekmesi:** her yazının durumu (🔊 Var / ⚠️ Eski / ➕ Yok), süzgeçler, arama ve toplu işlemler.
  - **Üret / Yeniden üret**: tek yazı için ses üretir (isteğe bağlı dinle-ve-onayla adımıyla).
  - **Önizle**: ilk birkaç cümleyi okur ve okunacak düzeltilmiş metni gösterir; hiçbir şey kaydedilmez.
  - **Dinle / Sil**: kayıtlı sesi çalar ya da siler.
  - **Eksik olanların hepsini seslendir**, **Eski olanları yenile**, **Seçilenleri seslendir**: toplu işlemler. Pencere bitene kadar sekmeyi açık tutun.
- **Yazılar listesi:** isteğe bağlı, salt-okunur **Ses** sütunu üretim sayfasına kısayol verir.
- **Ön yüz:** oynatıcı yazı içeriğinin üstüne otomatik eklenir. İsterseniz otomatik eklemeyi kapatıp `[yaziyi_seslendir]` kısa kodunu (isteğe bağlı `id="123"`) kendiniz yerleştirin.

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
- `.github/workflows/tests.yml`: PHP 8.4 / 8.5 ve Node üzerinde lint ve testler.
- `.github/workflows/security.yml`: WordPress güvenlik kuralları, PHP 8.4 uyumluluğu, Semgrep, CodeQL, Gitleaks (haftalık ve her push/PR'da).
- `.github/workflows/build-engine.yml`: motor paketini derler, duman testinden geçirir ve yayımlar (elle tetiklenir).
- `.github/workflows/release.yml`: `vX.Y.Z` etiketi itilince kurulabilir ZIP'i üretip GitHub sürümü yayımlar. Etiket; eklenti başlığındaki sürüm, `YS_VERSION` ve `CHANGELOG.md` kaydıyla eşleşmelidir.

## Lisans

GPL-3.0-or-later. [LICENSE](LICENSE) dosyasına bakın. Ses modellerinin, sherpa-onnx çalışma zamanının ve lamejs'in kendi lisansları vardır; ticari kullanımdan önce kontrol edin.

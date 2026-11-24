# Yazıyı Seslendir

WordPress yazılarınızı doğal Türkçe sesle seslendirir. Ses, **çevrimdışı ve editörün kendi tarayıcısında** üretilir, Medya Kitaplığı'na kaydedilir ve ziyaretçilere sade bir `<audio>` oynatıcısıyla sunulur.

- Dış TTS servisi, API anahtarı yok; verileriniz siteden çıkmaz.
- Sunucuda komut çalıştırılmaz: `exec()` kapalı **paylaşımlı hostinglerde** de çalışır.
- Ses **yalnızca bir düğmeye bastığınızda** üretilir; yazı kaydedilince kendiliğinden üretilmez.

🇬🇧 English: [README.md](README.md)

Kaynak kod, sorunlar ve sürümler: <https://github.com/kinefi/yaziyi-seslendir>

## Nasıl çalışır?

1. Düzenleme yetkisi olan biri **Araçlar → Yazıyı Seslendir** sayfasını açar ve bir yazı için **Üret**'e basar (ya da birçok yazı seçer).
2. Eklenti metni sunucuda hazırlar (sayılar, tarihler, para, kısaltmalar → okunuşa çevrilir) ve cümlelere böler.
3. Tarayıcı yerel bir WebAssembly ses motoru (sherpa-onnx üzerinde Piper Türkçe sesi) yükler ve sesi üretir. İlerleme, kapatılamayan bir pencerede gösterilir.
4. İsterseniz sonucu **dinleyip onaylarsınız**; ses kimlik doğrulamalı bir REST uç noktasından yüklenir ve yazıya bağlanır.
5. Ziyaretçiler yazının başında küçük bir oynatıcı görür. Yazıyı sonradan değiştirirseniz eski ses gizlenir ve yeniden üretene kadar **Eski** görünür.

## Gereksinimler

- WordPress 5.8+, PHP 8.4+ (`mbstring`), HTTPS önerilir.
- Sesi üreten kişi için WebAssembly destekleyen güncel bir masaüstü tarayıcı (Chrome, Edge, Firefox, Safari). Ziyaretçiler için `<audio>` desteği yeterli.
- Ses motoru dosyaları (aşağıya bakın). Büyüktürler (≈ 70–100 MB) ve eklentiyle **birlikte gelmezler**.

## Kurulum

1. Eklenti klasörünü `wp-content/plugins/` içine yükleyin (ya da sürüm ZIP'ini kurun) ve etkinleştirin.
2. **Araçlar → Yazıyı Seslendir → Kurulum** sekmesinden **motor paketini** yükleyin (sonraki bölüm).
3. **Ayarlar** sekmesinde seslendirilecek içerik türlerini seçin. Varsayılan olarak yalnızca "Yazılar" etkindir.

## Motor paketi

Paket, [sherpa-onnx](https://github.com/k2-fsa/sherpa-onnx)'in WebAssembly TTS hedefinden, içine Piper Türkçe sesi gömülerek derlenmiş dört dosyadan ve bir isteğe bağlı dosyadan oluşur:

| Dosya | Zorunlu | Görevi |
|---|---|---|
| `sherpa-onnx-tts.js` | evet | JavaScript API (`createOfflineTts`) |
| `sherpa-onnx-wasm-main-tts.js` | evet | Emscripten yükleyicisi |
| `sherpa-onnx-wasm-main-tts.wasm` | evet | Çalışma zamanı |
| `sherpa-onnx-wasm-main-tts.data` | evet | Paketlenmiş model, `tokens.txt` ve `espeak-ng-data` |
| `lame.min.js` | hayır | [lamejs](https://github.com/zhuker/lamejs); varsa ses WAV yerine küçük MP3 olarak kaydedilir |

Bir kez, herhangi bir bilgisayarda hazırlanır:

1. Türkçe sesi edinin, örneğin `tr_TR-fettah-medium` (tek konuşmacı; eğitim verisi CC0, model kartına bakın). `tokens.txt` ve `espeak-ng-data/` ile birlikte hazır hâli Hugging Face'te `csukuangfj/vits-piper-tr_TR-fettah-medium` adıyla bulunur.
2. sherpa-onnx'in *WebAssembly text-to-speech* derleme yönergelerini izleyin. Derlemeden önce modeli `model.onnx` adıyla, `tokens.txt` ve `espeak-ng-data/` klasörüyle birlikte TTS varlıklar klasörüne koyun; böylece `sherpa-onnx-wasm-main-tts.data` içine paketlenirler. Betik ve klasör adları sherpa-onnx sürümüne göre değişebilir; indirdiğiniz sürümün belgesine uyun.
3. Çıktıları `sh tools/make-engine-pack.sh <derleme-klasörü> [lame.min.js]` ile ZIP'leyin.
4. ZIP'i **Kurulum** sekmesinden yükleyin. Hostingin yükleme sınırı küçükse dosyaları FTP ile o sekmede yazan klasöre (`wp-content/uploads/yaziyi-seslendir-motor/`) kopyalayın.

> **Durum notu:** PHP, normalleştirici, depolama ve arayüz kodu otomatik testlerle sınanmıştır. WebAssembly motorunun kendisi bu sürümün hazırlandığı ortamda derlenip çalıştırılamadı. Bu yüzden kurulumdan sonra ilk iş küçük bir deneme yapın: paketi kurun, bir yazıda **Önizle**'ye basıp dinleyin. Sorun olursa penceredeki hata mesajını aynen iletin.

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

- Her şey sitenizde ve tarayıcınızda çalışır; üçüncü taraflara hiçbir şey gönderilmez.
- REST uç noktaları oturum açmış kullanıcı, REST nonce'u ve ilgili yazı için `edit_post` yetkisi ister. Listeleme `edit_posts` ister; yazarlar yalnızca kendi yazılarını görür.
- Yükleme yalnızca gerçekten WAV/MP3 olan, boyut sınırı içindeki ve üretilen metnin özetiyle (hash) eşleşen veriyi kabul eder; arada değişen yazı HTTP 409 ile reddedilir.
- Özel, şifreli ve yayımlanmamış yazılar seslendirilmez; çünkü ses dosyasının adresi herkese açıktır.
- Motor ZIP'i yalnızca beş izinli dosya adını yazar, arşivdeki tüm yol bilgisini yok sayar ve `manage_options` yetkisi ister.
- Eklenti silinince ayarlar ve cron olayı kaldırılır; üretilmiş sesler Medya Kitaplığı'nda kalır.

## Bilinen sınırlar

- Kalite, tek bir orta boy Piper sesinin kalitesidir: net ve doğal, ama stüdyo düzeyinde değil.
- Üretim hızı editörün bilgisayarına bağlıdır. Uzun yazılar dakikalar sürer.
- Kesme işaretinden sonraki para ekleri atılır (`₺100'ü` → "yüz lira").
- WAV dosyaları büyüktür (dakikada ≈ 2,6 MB). MP3 için `lame.min.js` ekleyin.
- Arayüz dili yalnızca Türkçe.

## Geliştirme

```sh
sh tests/run.sh        # PHP lint, JS sözdizimi, PHP testleri, JS testleri
```

- `tests/`: PHP testleri (WordPress işlevleri `tests/wp_stubs.php` içinde taklit edilir) ve JavaScript testleri.
- `.github/workflows/tests.yml`: PHP 8.4 / 8.5 ve Node üzerinde lint ve testler.
- `.github/workflows/security.yml`: WordPress güvenlik kuralları, PHP 8.4 uyumluluğu, Semgrep, CodeQL, Gitleaks (haftalık ve her push/PR'da).
- `.github/workflows/release.yml`: `vX.Y.Z` etiketi itilince kurulabilir ZIP'i üretip GitHub sürümü yayımlar. Etiket; eklenti başlığındaki sürüm, `YS_VERSION` ve `CHANGELOG.md` kaydıyla eşleşmelidir.

## Lisans

GPL-2.0-or-later. [LICENSE](LICENSE) dosyasına bakın. Ses modellerinin ve sherpa-onnx çalışma zamanının kendi lisansları vardır; ticari kullanımdan önce kontrol edin.

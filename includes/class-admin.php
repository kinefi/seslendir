<?php
defined( 'ABSPATH' ) || exit;

/**
 * Yönetim: sol menüde "Yazıyı Seslendir" sayfası (Yazılar / Kurulum / Ayarlar)
 * ve Yazılar listesindeki salt-okunur "Ses" sütunu.
 * Başka hiçbir yönetim ekranı değiştirilmez.
 */
class YS_Admin {

	const SLUG = 'yaziyi-seslendir';

	private static $hook = '';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_ys_upload_engine', array( __CLASS__, 'handle_engine_upload' ) );
		add_action( 'admin_init', array( __CLASS__, 'columns' ) );
	}

	public static function menu() {
		self::$hook = (string) add_menu_page(
			'Yazıyı Seslendir',
			'Yazıyı Seslendir',
			'edit_posts',
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-controls-volumeon',
			26
		);
	}

	public static function page_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/* ---------- Varlıklar ---------- */

	/** Geçerli sekme (yalnızca görüntüleme; durum değiştirmez). */
	private static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'yazilar'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- yalnızca sekme seçimi.
		if ( ! in_array( $tab, array( 'yazilar', 'kurulum', 'ayarlar' ), true ) || ( 'yazilar' !== $tab && ! current_user_can( 'manage_options' ) ) ) {
			return 'yazilar';
		}
		return $tab;
	}

	public static function assets( $hook ) {
		if ( $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_style( 'ys-admin', YS_URL . 'assets/admin.css', array(), YS_VERSION );
		$tab = self::current_tab();

		if ( 'kurulum' === $tab ) {
			wp_enqueue_script( 'ys-setup', YS_URL . 'assets/setup.js', array(), YS_VERSION, true );
			wp_localize_script(
				'ys-setup',
				'YSSetup',
				array(
					'rest'  => esc_url_raw( rest_url( YS_REST::NS . '/' ) ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
				)
			);
			return;
		}
		if ( 'yazilar' !== $tab ) {
			return;
		}

		$status = YS_Settings::engine_status();
		wp_enqueue_script( 'ys-audio', YS_URL . 'assets/ys-audio.js', array(), YS_VERSION, true );
		wp_enqueue_script( 'ys-engine', YS_URL . 'assets/ys-engine.js', array(), YS_VERSION, true );
		wp_enqueue_script( 'ys-admin', YS_URL . 'assets/admin.js', array( 'ys-audio', 'ys-engine' ), YS_VERSION, true );

		$start = isset( $_GET['ys_start'] ) ? absint( $_GET['ys_start'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- yalnızca otomatik açılacak yazıyı belirtir; işlem REST nonce'u ile yapılır.
		wp_localize_script(
			'ys-admin',
			'YS',
			array(
				'rest'        => esc_url_raw( rest_url( YS_REST::NS . '/' ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'engineUrl'   => esc_url_raw( YS_Settings::engine_url() ),
				'engineReady' => (bool) $status['ready'],
				'lame'        => (bool) $status['lame'],
				'maxBytes'    => (int) YS_Settings::max_bytes(),
				'confirm'     => (bool) YS_Settings::get( 'confirm' ),
				'ver'         => YS_VERSION,
				'startId'     => $start,
				'setupUrl'    => esc_url_raw( self::page_url( array( 'tab' => 'kurulum' ) ) ),
				'canSetup'    => current_user_can( 'manage_options' ),
			)
		);
	}

	/* ---------- Sayfa ---------- */

	public static function render() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html( 'Bu sayfaya erişim yetkiniz yok.' ) );
		}
		$tab  = self::current_tab();
		$tabs = array( 'yazilar' => 'Yazılar' );
		if ( current_user_can( 'manage_options' ) ) {
			$tabs['kurulum'] = 'Kurulum';
			$tabs['ayarlar'] = 'Ayarlar';
		}

		echo '<div class="wrap ys-wrap"><h1>Yazıyı Seslendir</h1>';
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( self::page_url( array( 'tab' => $key ) ) ),
				$tab === $key ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
		self::print_notice();

		if ( 'kurulum' === $tab ) {
			self::render_setup();
		} elseif ( 'ayarlar' === $tab ) {
			self::render_settings();
		} else {
			echo '<div id="ys-app" class="ys-app"><noscript>Bu sayfa JavaScript gerektirir.</noscript></div>';
		}
		echo '</div>';
	}

	private static function notice( $type, $msg ) {
		set_transient( 'ys_notice_' . get_current_user_id(), array( $type, $msg ), 60 );
	}

	private static function print_notice() {
		$key = 'ys_notice_' . get_current_user_id();
		$n   = get_transient( $key );
		if ( is_array( $n ) ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $n[0] ), esc_html( $n[1] ) );
		}
	}

	/* ---------- Kurulum sekmesi ---------- */

	private static function render_setup() {
		$st  = YS_Settings::engine_status();
		$dir = YS_Settings::engine_dir();

		echo '<h2>Ses motoru</h2>';
		echo '<p>Sesler, ziyaretçiye değil <strong>sizin tarayıcınıza</strong> yüklenen yerel bir motorla üretilir. Hiçbir dış servis kullanılmaz; motor dosyaları sitenizin <code>uploads</code> klasöründe durur.</p>';

		echo '<div class="ys-card"><h3>Otomatik kurulum (önerilen)</h3>';
		echo '<p>Motor paketini (Türkçe ses modeli dahil, yaklaşık 70–100 MB) projenin GitHub sürümünden indirir. İndirme küçük parçalarla yapılır, paylaşımlı hostinglerde de çalışır ve her dosya SHA-256 ile doğrulanır. Sayfayı kapatırsanız kaldığı yerden devam eder.</p>';
		echo '<div id="ys-setup-auto"><noscript>Bu bölüm JavaScript gerektirir.</noscript></div></div>';

		echo '<h3>Dosya durumu</h3>';
		echo '<table class="widefat striped ys-engine"><thead><tr><th>Dosya</th><th>Durum</th><th>Boyut</th></tr></thead><tbody>';
		foreach ( $st['files'] as $name => $size ) {
			$required = in_array( $name, YS_Settings::engine_required(), true );
			if ( $size > 0 ) {
				$state = '✅ Var';
			} else {
				$state = $required ? '❌ Eksik (zorunlu)' : '— Yok (isteğe bağlı: MP3 kodlayıcı)';
			}
			printf( '<tr><td><code>%s</code></td><td>%s</td><td>%s</td></tr>', esc_html( $name ), esc_html( $state ), esc_html( $size > 0 ? size_format( $size ) : '—' ) );
		}
		echo '</tbody></table>';
		printf( '<p>%s</p>', esc_html( $st['ready'] ? 'Motor hazır. Yazılar sekmesinden seslendirmeye başlayabilirsiniz.' : 'Motor henüz hazır değil.' ) );

		echo '<h3>Elle kurulum (yedek)</h3>';
		echo '<p>Sunucunuz dış adreslere bağlanamıyorsa GitHub sürümlerindeki <code>yaziyi-seslendir-motor.zip</code> dosyasını bilgisayarınıza indirip buradan yükleyin. Yalnızca yukarıdaki dosya adları kabul edilir; başka her şey yok sayılır.</p>';
		printf( '<form method="post" action="%s" enctype="multipart/form-data">', esc_url( admin_url( 'admin-post.php' ) ) );
		echo '<input type="hidden" name="action" value="ys_upload_engine">';
		wp_nonce_field( 'ys_engine_upload' );
		echo '<input type="file" name="pack" accept=".zip" required> ';
		submit_button( 'Yükle', 'primary', 'submit', false );
		echo '</form>';

		echo '<h3>Ya da FTP ile</h3>';
		printf(
			'<p>Dosyalar büyükse (yaklaşık 100 MB) hosting yükleme sınırına takılabilirsiniz. O zaman dosyaları FTP/Dosya Yöneticisi ile şu klasöre kopyalayın:</p><p><code>%s</code></p>',
			esc_html( $dir )
		);
		echo '<p>Klasör yoksa oluşturun. Sayfayı yenilediğinizde yukarıdaki tablo güncellenir.</p>';
	}

	public static function handle_engine_upload() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( 'Yetkiniz yok.' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ys_engine_upload' );
		$back = self::page_url( array( 'tab' => 'kurulum' ) );

		$tmp = isset( $_FILES['pack']['tmp_name'] ) ? (string) $_FILES['pack']['tmp_name'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sunucunun verdiği geçici yol; is_uploaded_file ile doğrulanır.
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			self::notice( 'error', 'Dosya alınamadı. Boyut sınırını (upload_max_filesize) aşmış olabilir; FTP yöntemini deneyin.' );
			wp_safe_redirect( $back );
			exit;
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			self::notice( 'error', 'Sunucuda ZipArchive yok. Dosyaları FTP ile kopyalayın.' );
			wp_safe_redirect( $back );
			exit;
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp ) ) {
			self::notice( 'error', 'ZIP dosyası açılamadı.' );
			wp_safe_redirect( $back );
			exit;
		}

		$dir = YS_Settings::engine_dir();
		wp_mkdir_p( $dir );
		if ( ! is_file( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$allowed = YS_Settings::engine_allowed_names();
		$done    = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$entry = (string) $zip->getNameIndex( $i );
			$base  = basename( $entry );
			if ( '/' === substr( $entry, -1 ) || ! in_array( $base, $allowed, true ) ) {
				continue; // Yalnızca izin verilen dosya adları; yol bilgisi hiç kullanılmaz.
			}
			$in = $zip->getStream( $entry );
			if ( ! $in ) {
				continue;
			}
			$target = $dir . '/' . $base;
			$out    = fopen( $target . '.tmp', 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( ! $out ) {
				fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				continue;
			}
			stream_copy_to_stream( $in, $out );
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			if ( rename( $target . '.tmp', $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				$done[] = $base;
			}
		}
		$zip->close();

		if ( $done ) {
			self::notice( 'success', 'Yüklendi: ' . implode( ', ', $done ) . '.' );
		} else {
			self::notice( 'error', 'ZIP içinde tanınan motor dosyası bulunamadı.' );
		}
		wp_safe_redirect( $back );
		exit;
	}

	/* ---------- Ayarlar sekmesi ---------- */

	private static function render_settings() {
		$s     = YS_Settings::all();
		$types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'] );
		$name = YS_Settings::OPT;

		echo '<form method="post" action="options.php">';
		settings_fields( YS_Settings::GROUP );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">Seslendirilecek içerik türleri</th><td>';
		foreach ( $types as $type => $obj ) {
			$is_on = in_array( $type, (array) $s['post_types'], true );
			printf(
				'<label style="display:block"><input type="checkbox" name="%s[post_types][]" value="%s"%s> %s</label>',
				esc_attr( $name ),
				esc_attr( $type ),
				$is_on ? ' checked' : '',
				esc_html( $obj->labels->name )
			);
		}
		echo '</td></tr>';

		self::checkbox_row( 'read_title', 'Başlığı da oku', $s );
		self::checkbox_row( 'confirm', 'Tek yazıda kaydetmeden önce dinleyip onayla', $s );
		self::checkbox_row( 'autoinsert', 'Oynatıcıyı yazının başında otomatik göster (kapalıysa [yaziyi_seslendir] kısa kodunu kullanın)', $s );
		self::checkbox_row( 'show_column', 'Yazılar listesinde "Ses" sütununu göster', $s );

		printf(
			'<tr><th scope="row"><label for="ys-label">Oynatıcı başlığı</label></th><td><input id="ys-label" type="text" class="regular-text" name="%s[label]" value="%s"></td></tr>',
			esc_attr( $name ),
			esc_attr( $s['label'] )
		);
		printf(
			'<tr><th scope="row"><label for="ys-speed">Konuşma hızı</label></th><td><input id="ys-speed" type="number" step="0.05" min="0.6" max="1.6" name="%s[speed]" value="%s"> <span class="description">1.0 normal; değişince tüm sesler "eski" sayılır.</span></td></tr>',
			esc_attr( $name ),
			esc_attr( (string) $s['speed'] )
		);
		printf(
			'<tr><th scope="row"><label for="ys-speaker">Konuşmacı no</label></th><td><input id="ys-speaker" type="number" min="0" name="%s[speaker]" value="%s"> <span class="description">Tek sesli modelde 0.</span></td></tr>',
			esc_attr( $name ),
			esc_attr( (string) $s['speaker'] )
		);
		printf(
			'<tr><th scope="row"><label for="ys-max">Dosya boyutu sınırı (MB)</label></th><td><input id="ys-max" type="number" min="1" max="500" name="%s[max_mb]" value="%s"> <span class="description">Etkin sınır: %s (sunucunun post_max_size değeriyle birlikte hesaplanır).</span></td></tr>',
			esc_attr( $name ),
			esc_attr( (string) $s['max_mb'] ),
			esc_html( size_format( YS_Settings::max_bytes() ) )
		);
		printf(
			'<tr><th scope="row"><label for="ys-tag">Ses etiketi</label></th><td><input id="ys-tag" type="text" class="regular-text" name="%s[voice_tag]" value="%s"> <span class="description">Başka bir ses/model kurduğunuzda değiştirin; tüm sesler "eski" sayılır.</span></td></tr>',
			esc_attr( $name ),
			esc_attr( $s['voice_tag'] )
		);

		echo '</tbody></table>';
		submit_button( 'Ayarları kaydet' );
		echo '</form>';
	}

	private static function checkbox_row( $key, $label, array $s ) {
		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="%s[%s]" value="1"%s> Evet</label></td></tr>',
			esc_html( $label ),
			esc_attr( YS_Settings::OPT ),
			esc_attr( $key ),
			! empty( $s[ $key ] ) ? ' checked' : ''
		);
	}

	/* ---------- Yazılar listesinde "Ses" sütunu ---------- */

	public static function columns() {
		if ( ! YS_Settings::get( 'show_column' ) ) {
			return;
		}
		foreach ( YS_Settings::post_types() as $type ) {
			add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'add_column' ) );
			add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'render_column' ), 10, 2 );
		}
	}

	public static function add_column( $cols ) {
		$cols['ys_audio'] = 'Ses';
		return $cols;
	}

	public static function render_column( $col, $post_id ) {
		if ( 'ys_audio' !== $col ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status || '' !== $post->post_password ) {
			echo '—';
			return;
		}
		$state = YS_Storage::state( $post );
		$icon  = array(
			'ok'    => '🔊 Var',
			'stale' => '⚠️ Eski',
			'none'  => '➕ Yok',
		);
		echo esc_html( $icon[ $state ] );
		if ( current_user_can( 'edit_post', $post_id ) ) {
			printf(
				' · <a href="%s">%s</a>',
				esc_url( self::page_url( array( 'ys_start' => (int) $post_id ) ) ),
				esc_html( 'none' === $state ? 'Üret' : 'Yeniden üret' )
			);
		}
	}
}

<?php
defined( 'ABSPATH' ) || exit;

/**
 * Ses motoru paketinin otomatik indirilmesi.
 *
 * Paket, projenin GitHub sürümünde (engine-N etiketi) duran dosyalardır ve bir
 * "engine-manifest.json" ile tanımlanır. İndirme, paylaşımlı hostinglerin süre
 * sınırlarına takılmamak için küçük parçalar hâlinde (HTTP Range) ve yönetim
 * sayfasındaki ilerleme çubuğuyla yürütülür. Her dosya SHA-256 ile doğrulanır.
 *
 * Saf yardımcılar (validate_manifest, host_allowed, parse_content_range, plan)
 * WordPress'e bağlı değildir ve testlidir.
 */
class YS_Engine {

	const NS       = 'yaziyi-seslendir/v1';
	const CHUNK    = 6291456; // 6 MB.
	const MAX_FILE = 314572800; // 300 MB.
	const MAX_ALL  = 419430400; // 400 MB.
	const LOCK     = 'ys_engine_lock';
	const CACHE    = 'ys_engine_manifest';

	const MANIFEST_DEFAULT = 'https://github.com/kinefi/yaziyi-seslendir/releases/download/engine-1/engine-manifest.json';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		$perm = array( __CLASS__, 'can_manage' );
		register_rest_route(
			self::NS,
			'/engine/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_status' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			self::NS,
			'/engine/step',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_step' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			self::NS,
			'/engine/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_cancel' ),
				'permission_callback' => $perm,
			)
		);
	}

	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/* ---------- Saf yardımcılar ---------- */

	/** Manifestin ve dosya adreslerinin ilk adımda izin verilen sunucuları. */
	public static function allowed_hosts(): array {
		$hosts = array( 'github.com', 'raw.githubusercontent.com', 'huggingface.co' );
		return array_map( 'strtolower', (array) apply_filters( 'ys_engine_allowed_hosts', $hosts ) );
	}

	public static function host_allowed( string $url ): bool {
		$p = wp_parse_url( $url );
		if ( ! is_array( $p ) || empty( $p['scheme'] ) || empty( $p['host'] ) || 'https' !== strtolower( $p['scheme'] ) ) {
			return false;
		}
		if ( isset( $p['user'] ) || isset( $p['pass'] ) || isset( $p['port'] ) ) {
			return false;
		}
		return in_array( strtolower( $p['host'] ), self::allowed_hosts(), true );
	}

	public static function manifest_url(): string {
		$url = defined( 'YS_ENGINE_MANIFEST_URL' ) ? (string) constant( 'YS_ENGINE_MANIFEST_URL' ) : self::MANIFEST_DEFAULT;
		return (string) apply_filters( 'ys_engine_manifest_url', $url );
	}

	/**
	 * Manifesti doğrular.
	 *
	 * @param mixed $data json_decode çıktısı.
	 * @return array{ok:bool,error:string,files:array,voice:string,total:int}
	 */
	public static function validate_manifest( $data ): array {
		$fail = static function ( string $msg ): array {
			return array(
				'ok'    => false,
				'error' => $msg,
				'files' => array(),
				'voice' => '',
				'total' => 0,
			);
		};
		if ( ! is_array( $data ) || 1 !== ( $data['version'] ?? null ) || ! isset( $data['files'] ) || ! is_array( $data['files'] ) ) {
			return $fail( 'Manifest biçimi tanınmadı.' );
		}
		$allowed = YS_Settings::engine_allowed_names();
		$seen    = array();
		$files   = array();
		$total   = 0;
		foreach ( $data['files'] as $f ) {
			if ( ! is_array( $f ) ) {
				return $fail( 'Manifestte geçersiz dosya kaydı var.' );
			}
			$name = $f['name'] ?? null;
			$size = $f['size'] ?? null;
			$sha  = $f['sha256'] ?? null;
			$url  = $f['url'] ?? null;
			if ( ! is_string( $name ) || ! in_array( $name, $allowed, true ) ) {
				return $fail( 'Manifestte izin verilmeyen dosya adı var.' );
			}
			if ( isset( $seen[ $name ] ) ) {
				return $fail( 'Manifestte tekrarlanan dosya var: ' . $name );
			}
			if ( ! is_int( $size ) || $size < 1 || $size > self::MAX_FILE ) {
				return $fail( 'Geçersiz dosya boyutu: ' . $name );
			}
			if ( ! is_string( $sha ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $sha ) ) {
				return $fail( 'Geçersiz SHA-256: ' . $name );
			}
			if ( ! is_string( $url ) || ! self::host_allowed( $url ) ) {
				return $fail( 'Dosya adresine izin verilmiyor: ' . $name );
			}
			$seen[ $name ] = true;
			$total        += $size;
			$files[]       = array(
				'name'   => $name,
				'size'   => $size,
				'sha256' => $sha,
				'url'    => $url,
			);
		}
		foreach ( YS_Settings::engine_required() as $req ) {
			if ( ! isset( $seen[ $req ] ) ) {
				return $fail( 'Manifestte zorunlu dosya yok: ' . $req );
			}
		}
		if ( $total > self::MAX_ALL ) {
			return $fail( 'Paket boyutu sınırı aşıyor.' );
		}
		$voice = isset( $data['voice'] ) && is_string( $data['voice'] ) ? substr( preg_replace( '/[^A-Za-z0-9._-]/', '', $data['voice'] ), 0, 60 ) : '';
		return array(
			'ok'    => true,
			'error' => '',
			'files' => $files,
			'voice' => $voice,
			'total' => $total,
		);
	}

	/** "bytes 0-6291455/76543210" => [başlangıç, bitiş, toplam|null]. */
	public static function parse_content_range( string $h ): ?array {
		if ( 1 !== preg_match( '/^bytes (\d+)-(\d+)\/(\d+|\*)$/', trim( $h ), $m ) ) {
			return null;
		}
		return array( (int) $m[1], (int) $m[2], '*' === $m[3] ? null : (int) $m[3] );
	}

	/**
	 * Sıradaki işi belirler.
	 *
	 * @param array $files Doğrulanmış manifest dosyaları.
	 * @param array $state Ad => array( 'final' => bayt, 'part' => bayt ).
	 */
	public static function plan( array $files, array $state ): array {
		$total_size = 0;
		$total_done = 0;
		$next       = null;
		foreach ( $files as $i => $f ) {
			$final       = (int) ( $state[ $f['name'] ]['final'] ?? 0 );
			$part        = (int) ( $state[ $f['name'] ]['part'] ?? 0 );
			$total_size += $f['size'];
			if ( $final === $f['size'] ) {
				$total_done += $f['size'];
				continue;
			}
			$reset = $part > $f['size'] || ( $part === $f['size'] );
			$start = $reset ? 0 : $part;
			$total_done += $start;
			if ( null === $next ) {
				$next = array(
					'index' => $i,
					'name'  => $f['name'],
					'start' => $start,
					'size'  => $f['size'],
					'reset' => $reset,
				);
			}
		}
		return array(
			'done'       => null === $next,
			'next'       => $next,
			'total_done' => $total_done,
			'total_size' => $total_size,
			'count'      => count( $files ),
		);
	}

	/* ---------- Dosya sistemi ---------- */

	private static function local_state( array $files ): array {
		$dir   = YS_Settings::engine_dir();
		$state = array();
		foreach ( $files as $f ) {
			$state[ $f['name'] ] = array(
				'final' => is_file( $dir . '/' . $f['name'] ) ? (int) filesize( $dir . '/' . $f['name'] ) : 0,
				'part'  => is_file( $dir . '/' . $f['name'] . '.part' ) ? (int) filesize( $dir . '/' . $f['name'] . '.part' ) : 0,
			);
		}
		return $state;
	}

	private static function progress( array $files ): array {
		$p = self::plan( $files, self::local_state( $files ) );
		return array(
			'done'       => $p['done'],
			'file'       => $p['next']['name'] ?? '',
			'index'      => $p['next']['index'] ?? $p['count'],
			'count'      => $p['count'],
			'total_done' => $p['total_done'],
			'total_size' => $p['total_size'],
		);
	}

	/* ---------- Manifest ---------- */

	/** @return array|WP_Error Doğrulanmış manifest. */
	public static function fetch_manifest( bool $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE );
			if ( is_array( $cached ) && ! empty( $cached['ok'] ) ) {
				return $cached;
			}
		}
		$url = self::manifest_url();
		if ( ! self::host_allowed( $url ) ) {
			return new WP_Error( 'ys_manifest_host', 'Manifest adresine izin verilmiyor.', array( 'status' => 400 ) );
		}
		$res = wp_safe_remote_get(
			$url,
			array(
				'timeout'    => 20,
				'redirection' => 5,
				'user-agent' => 'YaziyiSeslendir/' . YS_VERSION,
			)
		);
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'ys_manifest_net', 'Paket bilgisi alınamadı: ' . $res->get_error_message(), array( 'status' => 502 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 404 === $code ) {
			return new WP_Error( 'ys_manifest_missing', 'Motor paketi henüz yayımlanmamış (engine-manifest.json bulunamadı). Depodaki "Build engine pack" iş akışını bir kez çalıştırın ya da paketi elle yükleyin.', array( 'status' => 404 ) );
		}
		if ( 200 !== $code ) {
			return new WP_Error( 'ys_manifest_http', 'Paket bilgisi alınamadı (HTTP ' . $code . ').', array( 'status' => 502 ) );
		}
		$m = self::validate_manifest( json_decode( (string) wp_remote_retrieve_body( $res ), true ) );
		if ( ! $m['ok'] ) {
			return new WP_Error( 'ys_manifest_bad', $m['error'], array( 'status' => 502 ) );
		}
		set_transient( self::CACHE, $m, 10 * MINUTE_IN_SECONDS );
		return $m;
	}

	/* ---------- REST ---------- */

	public static function rest_status( $request ) {
		$st  = YS_Settings::engine_status();
		$out = array(
			'ready'    => $st['ready'],
			'lame'     => $st['lame'],
			'files'    => $st['files'],
			'manifest' => null,
			'error'    => '',
		);
		$m = self::fetch_manifest( ! empty( $request->get_param( 'refresh' ) ) );
		if ( is_wp_error( $m ) ) {
			$out['error'] = $m->get_error_message();
		} else {
			$out['manifest'] = array(
				'voice'    => $m['voice'],
				'total'    => $m['total'],
				'count'    => count( $m['files'] ),
				'progress' => self::progress( $m['files'] ),
			);
		}
		return rest_ensure_response( $out );
	}

	public static function rest_cancel() {
		$dir = YS_Settings::engine_dir();
		foreach ( (array) glob( $dir . '/*.part' ) as $part ) {
			wp_delete_file( $part );
		}
		delete_transient( self::LOCK );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/** Bir parça indirir. İstemci, "done" gelene kadar tekrar çağırır. */
	public static function rest_step() {
		$m = self::fetch_manifest();
		if ( is_wp_error( $m ) ) {
			return $m;
		}
		if ( get_transient( self::LOCK ) ) {
			return new WP_Error( 'ys_busy', 'Başka bir indirme sürüyor. Birkaç saniye bekleyin.', array( 'status' => 409 ) );
		}
		set_transient( self::LOCK, 1, 90 );
		try {
			return self::do_step( $m['files'] );
		} finally {
			delete_transient( self::LOCK );
		}
	}

	private static function do_step( array $files ) {
		$dir = YS_Settings::engine_dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'ys_dir', 'Klasör oluşturulamadı: ' . $dir, array( 'status' => 500 ) );
		}
		if ( ! is_file( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$plan = self::plan( $files, self::local_state( $files ) );
		if ( $plan['done'] ) {
			return rest_ensure_response( self::progress( $files ) );
		}
		$job  = $plan['next'];
		$part = $dir . '/' . $job['name'] . '.part';
		if ( $job['reset'] ) {
			wp_delete_file( $part );
		}
		$file = $files[ $job['index'] ];
		$end  = min( $job['start'] + self::CHUNK, $job['size'] ) - 1;

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 90 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged,WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$tmp = wp_tempnam( $job['name'] );
		$res = wp_safe_remote_get(
			$file['url'],
			array(
				'timeout'             => 60,
				'redirection'         => 5,
				'stream'              => true,
				'filename'            => $tmp,
				'limit_response_size' => self::CHUNK + 1048576,
				'user-agent'          => 'YaziyiSeslendir/' . YS_VERSION,
				'headers'             => array( 'Range' => 'bytes=' . $job['start'] . '-' . $end ),
			)
		);
		if ( is_wp_error( $res ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'ys_dl_net', $job['name'] . ' indirilemedi: ' . $res->get_error_message(), array( 'status' => 502 ) );
		}
		$code     = (int) wp_remote_retrieve_response_code( $res );
		$got      = is_file( $tmp ) ? (int) filesize( $tmp ) : 0;
		$expected = $end - $job['start'] + 1;

		if ( 206 === $code ) {
			$cr = self::parse_content_range( (string) wp_remote_retrieve_header( $res, 'content-range' ) );
			if ( ! $cr || $cr[0] !== $job['start'] || ( null !== $cr[2] && $cr[2] !== $job['size'] ) || $got !== $cr[1] - $cr[0] + 1 || $got < 1 || $got > $expected ) {
				wp_delete_file( $tmp );
				return new WP_Error( 'ys_dl_range', $job['name'] . ': sunucu beklenmeyen bir parça döndürdü. Boyut ya da sürüm değişmiş olabilir; "Sıfırla" deyip yeniden deneyin.', array( 'status' => 502 ) );
			}
			if ( ! self::append( $tmp, $part ) ) {
				wp_delete_file( $tmp );
				return new WP_Error( 'ys_dl_write', 'Dosya yazılamadı. Disk dolu ya da klasör yazılabilir değil.', array( 'status' => 500 ) );
			}
		} elseif ( 200 === $code && 0 === $job['start'] && $got === $job['size'] ) {
			wp_delete_file( $part );
			if ( ! @rename( $tmp, $part ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename
				wp_delete_file( $tmp );
				return new WP_Error( 'ys_dl_write', 'Dosya yazılamadı.', array( 'status' => 500 ) );
			}
		} else {
			wp_delete_file( $tmp );
			$msg = 200 === $code ? 'Sunucu parçalı indirmeyi desteklemiyor.' : 'HTTP ' . $code;
			return new WP_Error( 'ys_dl_http', $job['name'] . ' indirilemedi: ' . $msg, array( 'status' => 502 ) );
		}
		wp_delete_file( $tmp );

		if ( is_file( $part ) && (int) filesize( $part ) === $job['size'] ) {
			$sum = hash_file( 'sha256', $part );
			if ( ! is_string( $sum ) || ! hash_equals( $file['sha256'], $sum ) ) {
				wp_delete_file( $part );
				return new WP_Error( 'ys_dl_hash', $job['name'] . ': SHA-256 doğrulaması başarısız. Dosya silindi; yeniden deneyin.', array( 'status' => 502 ) );
			}
			$final = $dir . '/' . $job['name'];
			wp_delete_file( $final );
			if ( ! rename( $part, $final ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				return new WP_Error( 'ys_dl_write', $job['name'] . ' yerine konamadı.', array( 'status' => 500 ) );
			}
		}
		return rest_ensure_response( self::progress( $files ) );
	}

	private static function append( string $from, string $to ): bool {
		$in  = fopen( $from, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$out = fopen( $to, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $in || ! $out ) {
			if ( $in ) {
				fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			if ( $out ) {
				fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			return false;
		}
		$ok = false !== stream_copy_to_stream( $in, $out );
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $ok;
	}
}

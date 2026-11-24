<?php
defined( 'ABSPATH' ) || exit;

/**
 * REST uç noktaları (yaziyi-seslendir/v1). Hepsi oturum + nonce ister,
 * yazı bazlı olanlar ayrıca edit_post yetkisi ister.
 */
class YS_REST {

	const NS = 'yaziyi-seslendir/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			self::NS,
			'/posts',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_posts' ),
				'permission_callback' => array( __CLASS__, 'can_list' ),
				'args'                => array(
					'page'   => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'status' => array(
						'type'    => 'string',
						'default' => 'all',
						'enum'    => array( 'all', 'none', 'ok', 'stale' ),
					),
					'search' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/queue',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'queue' ),
				'permission_callback' => array( __CLASS__, 'can_list' ),
				'args'                => array(
					'mode' => array(
						'type'    => 'string',
						'default' => 'both',
						'enum'    => array( 'missing', 'stale', 'both' ),
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/post/(?P<id>\d+)/text',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_text' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
			)
		);
		register_rest_route(
			self::NS,
			'/post/(?P<id>\d+)/audio',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'upload_audio' ),
					'permission_callback' => array( __CLASS__, 'can_edit' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'delete_audio' ),
					'permission_callback' => array( __CLASS__, 'can_edit' ),
				),
			)
		);
	}

	/* ---------- Yetki ---------- */

	public static function can_list() {
		return current_user_can( 'edit_posts' );
	}

	public static function can_edit( $request ) {
		return current_user_can( 'edit_post', (int) $request['id'] );
	}

	/* ---------- Yardımcılar ---------- */

	/** Yazı seslendirmeye uygun mu? Özel, şifreli ve taslak yazılar kapsam dışıdır. */
	private static function eligible( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post ) {
			return new WP_Error( 'ys_not_found', 'Yazı bulunamadı.', array( 'status' => 404 ) );
		}
		if ( ! in_array( $post->post_type, YS_Settings::post_types(), true ) ) {
			return new WP_Error( 'ys_type', 'Bu yazı türü seslendirme için etkin değil.', array( 'status' => 400 ) );
		}
		if ( 'publish' !== $post->post_status ) {
			return new WP_Error( 'ys_status', 'Yalnızca yayımlanmış yazılar seslendirilir.', array( 'status' => 400 ) );
		}
		if ( '' !== $post->post_password ) {
			return new WP_Error( 'ys_password', 'Şifreli yazılar seslendirilmez (ses dosyası herkese açık olurdu).', array( 'status' => 400 ) );
		}
		return $post;
	}

	private static function base_args() {
		$args = array(
			'post_type'           => YS_Settings::post_types(),
			'post_status'         => 'publish',
			'has_password'        => false,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
		);
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$args['author'] = get_current_user_id();
		}
		return $args;
	}

	private static function count( array $extra ) {
		$q = new WP_Query(
			array_merge(
				self::base_args(),
				$extra,
				array(
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			)
		);
		return (int) $q->found_posts;
	}

	/* ---------- Uç noktalar ---------- */

	public static function list_posts( $request ) {
		$types = YS_Settings::post_types();
		$empty = array(
			'items'   => array(),
			'total'   => 0,
			'pages'   => 0,
			'summary' => array(
				'total' => 0,
				'with'  => 0,
			),
		);
		if ( ! $types ) {
			return rest_ensure_response( $empty );
		}

		$per    = 20;
		$page   = max( 1, (int) $request['page'] );
		$status = (string) $request['status'];
		$search = sanitize_text_field( (string) $request['search'] );
		$args   = self::base_args();
		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$summary = array(
			'total' => self::count( array() ),
			'with'  => self::count(
				array(
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'     => YS_Storage::META_ATT,
							'compare' => 'EXISTS',
						),
					),
				)
			),
		);

		if ( 'ok' === $status || 'stale' === $status ) {
			$args['meta_query']    = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => YS_Storage::META_ATT,
					'compare' => 'EXISTS',
				),
			);
			$args['posts_per_page'] = 500;
			$args['fields']         = 'ids';
			$ids                    = ( new WP_Query( $args ) )->posts;
			$match                  = array();
			foreach ( $ids as $id ) {
				$post = get_post( $id );
				if ( $post && YS_Storage::state( $post ) === $status ) {
					$match[] = $post;
				}
			}
			$total = count( $match );
			$slice = array_slice( $match, ( $page - 1 ) * $per, $per );
			$items = array();
			foreach ( $slice as $post ) {
				$items[] = YS_Storage::describe( $post, $status );
			}
			return rest_ensure_response(
				array(
					'items'   => $items,
					'total'   => $total,
					'pages'   => (int) ceil( $total / $per ),
					'summary' => $summary,
				)
			);
		}

		if ( 'none' === $status ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => YS_Storage::META_ATT,
					'compare' => 'NOT EXISTS',
				),
			);
		}
		$args['posts_per_page'] = $per;
		$args['paged']          = $page;
		$q                      = new WP_Query( $args );
		$items                  = array();
		foreach ( $q->posts as $post ) {
			$items[] = YS_Storage::describe( $post );
		}
		return rest_ensure_response(
			array(
				'items'   => $items,
				'total'   => (int) $q->found_posts,
				'pages'   => (int) $q->max_num_pages,
				'summary' => $summary,
			)
		);
	}

	/** Toplu üretim kuyruğu: eksik ve/veya eski seslerin yazı kimlikleri. */
	public static function queue( $request ) {
		if ( ! YS_Settings::post_types() ) {
			return rest_ensure_response( array( 'items' => array() ) );
		}
		$mode = (string) $request['mode'];
		$args = array_merge(
			self::base_args(),
			array(
				'posts_per_page' => 1000,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$out  = array();
		foreach ( ( new WP_Query( $args ) )->posts as $id ) {
			$post  = get_post( $id );
			$state = $post ? YS_Storage::state( $post ) : 'ok';
			$want  = ( 'none' === $state && 'stale' !== $mode ) || ( 'stale' === $state && 'missing' !== $mode );
			if ( $want ) {
				$out[] = array(
					'id'    => (int) $id,
					'title' => html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ),
				);
			}
		}
		return rest_ensure_response( array( 'items' => $out ) );
	}

	public static function get_text( $request ) {
		$post = self::eligible( $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$chunks = YS_Normalizer_TR::chunks_for_post( $post, (bool) YS_Settings::get( 'read_title' ) );
		if ( ! $chunks ) {
			return new WP_Error( 'ys_empty', 'Okunacak metin bulunamadı.', array( 'status' => 422 ) );
		}
		$chars = YS_Normalizer_TR::total_chars( $chunks );
		if ( $chars > YS_MAX_CHARS ) {
			return new WP_Error( 'ys_long', 'Yazı çok uzun (' . $chars . ' karakter; sınır ' . YS_MAX_CHARS . ').', array( 'status' => 413 ) );
		}
		return rest_ensure_response(
			array(
				'id'      => (int) $post->ID,
				'title'   => html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ),
				'chunks'  => $chunks,
				'chars'   => $chars,
				'hash'    => YS_Storage::hash_for( $chunks ),
				'speed'   => (float) YS_Settings::get( 'speed' ),
				'speaker' => (int) YS_Settings::get( 'speaker' ),
			)
		);
	}

	public static function upload_audio( $request ) {
		$post = self::eligible( $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$bytes = (string) $request->get_body();
		$len   = strlen( $bytes );
		if ( $len < 1024 ) {
			return new WP_Error( 'ys_small', 'Ses verisi alınamadı.', array( 'status' => 400 ) );
		}
		if ( $len > YS_Settings::max_bytes() ) {
			return new WP_Error( 'ys_big', 'Ses dosyası çok büyük (' . size_format( $len ) . '). Sınır: ' . size_format( YS_Settings::max_bytes() ) . '.', array( 'status' => 413 ) );
		}

		$hash    = sanitize_text_field( (string) $request->get_param( 'hash' ) );
		$chunks  = YS_Normalizer_TR::chunks_for_post( $post, (bool) YS_Settings::get( 'read_title' ) );
		$current = YS_Storage::hash_for( $chunks );
		if ( ! hash_equals( $current, $hash ) ) {
			return new WP_Error( 'ys_changed', 'Yazı ya da ayarlar üretim sırasında değişti. Lütfen yeniden üretin.', array( 'status' => 409 ) );
		}

		$res = YS_Storage::save_audio( $post, $bytes, $hash, (float) $request->get_param( 'duration' ) );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	public static function delete_audio( $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post ) {
			return new WP_Error( 'ys_not_found', 'Yazı bulunamadı.', array( 'status' => 404 ) );
		}
		YS_Storage::delete_audio( $post->ID );
		return rest_ensure_response( YS_Storage::describe( $post, 'none' ) );
	}
}

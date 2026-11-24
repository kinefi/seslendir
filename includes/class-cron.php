<?php
defined( 'ABSPATH' ) || exit;

/**
 * WP-Cron yalnızca bakım içindir: sahipsiz ses dosyalarını temizler.
 * Ses üretimi cron ile yapılmaz (tarayıcıda, düğmeyle yapılır).
 */
class YS_Cron {

	const HOOK = 'ys_weekly_cleanup';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'cleanup' ) );
	}

	public static function activate() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', self::HOOK );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Yazıyla bağı kopmuş ses ek dosyalarını siler (yazı silinmiş ya da başka ses atanmış).
	 */
	public static function cleanup() {
		$q = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => YS_Storage::META_FOR, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);
		$removed = 0;
		foreach ( $q->posts as $att_id ) {
			$post_id = (int) get_post_meta( $att_id, YS_Storage::META_FOR, true );
			$current = $post_id ? (int) get_post_meta( $post_id, YS_Storage::META_ATT, true ) : 0;
			if ( ! $post_id || ! get_post( $post_id ) || $current !== (int) $att_id ) {
				wp_delete_attachment( $att_id, true );
				++$removed;
			}
		}
		return $removed;
	}
}

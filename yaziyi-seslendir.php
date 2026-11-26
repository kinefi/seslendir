<?php
/**
 * Plugin Name:       Yazıyı Seslendir
 * Plugin URI:        https://github.com/kinefi/yaziyi-seslendir
 * Description:       Yazıları doğal Türkçe sesle seslendirir. Ses, yönetim sayfasındaki düğmeyle tarayıcıda üretilir ve Medya Kitaplığı'na kaydedilir; ziyaretçiler hazır dosyayı dinler. Dış servis ve sunucu komutu kullanmaz.
 * Version:           1.1.1
 * Requires at least: 7.0
 * Requires PHP:      8.4
 * Author:            kinefi
 * Author URI:        https://github.com/kinefi
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       yaziyi-seslendir
 */

defined( 'ABSPATH' ) || exit;

define( 'YS_VERSION', '1.1.1' );
define( 'YS_FORMAT', 1 ); // Ses biçimi sürümü: değişirse tüm sesler "eski" sayılır.
define( 'YS_FILE', __FILE__ );
define( 'YS_DIR', plugin_dir_path( __FILE__ ) );
define( 'YS_URL', plugin_dir_url( __FILE__ ) );
define( 'YS_MAX_CHARS', 60000 );

require_once YS_DIR . 'includes/class-settings.php';
require_once YS_DIR . 'includes/class-normalizer-tr.php';
require_once YS_DIR . 'includes/class-storage.php';
require_once YS_DIR . 'includes/class-rest.php';
require_once YS_DIR . 'includes/class-engine.php';
require_once YS_DIR . 'includes/class-frontend.php';
require_once YS_DIR . 'includes/class-cron.php';
if ( is_admin() ) {
	require_once YS_DIR . 'includes/class-admin.php';
}

register_activation_hook( __FILE__, array( 'YS_Cron', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'YS_Cron', 'deactivate' ) );

add_action( 'plugins_loaded', 'ys_boot' );

/**
 * Eklentinin tüm parçalarını başlatır. Hiçbir şey kayıt sırasında kendiliğinden
 * çalışmaz; ses yalnızca yönetim sayfasındaki düğmelerle üretilir.
 */
function ys_boot() {
	YS_Settings::init();
	YS_Storage::init();
	YS_REST::init();
	YS_Engine::init();
	YS_Frontend::init();
	YS_Cron::init();
	if ( is_admin() ) {
		YS_Admin::init();
	}
}

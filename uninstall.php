<?php
// Eklenti silinirken yalnızca ayarlar temizlenir. Üretilmiş ses dosyaları
// Medya Kitaplığı'nda kalır (sitenizin içeriğidir; isterseniz oradan silebilirsiniz).
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'ys_settings' );
wp_clear_scheduled_hook( 'ys_weekly_cleanup' );

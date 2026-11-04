<?php
/**
 * Plugin Name: Letterlift
 * Description: Turn public Mailchimp newsletters into independently editable WordPress drafts.
 * Version: 1.0.2
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: Letterlift
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: letterlift
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
define( 'LETTERLIFT_VERSION', '1.0.2' );
define( 'LETTERLIFT_FILE', __FILE__ );
foreach ( array( 'store', 'remote', 'parser', 'serializer', 'plan', 'executor', 'plugin' ) as $component ) {
    require_once __DIR__ . '/includes/class-' . $component . '.php';
}
register_activation_hook( __FILE__, array( 'Letterlift_Store', 'install' ) );
add_action( 'plugins_loaded', static function () {
    if ( get_option( 'letterlift_schema' ) !== LETTERLIFT_VERSION ) { Letterlift_Store::install(); }
    new Letterlift_Plugin();
} );

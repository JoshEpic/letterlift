<?php
/** Remove only plugin-owned data. Imported WordPress posts and attachments remain. */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }
global $wpdb;
$letterlift_cleanup = static function () use ( $wpdb ) {
    $table = $wpdb->prefix . 'letterlift_sessions';
    // The table name is constructed exclusively from WordPress's configured prefix.
    $wpdb->query( "DROP TABLE IF EXISTS `$table`" );
    delete_option( 'letterlift_settings' ); delete_option( 'letterlift_schema' );
    $pattern = $wpdb->esc_like( 'letterlift_lock_' ) . '%';
    $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->options WHERE option_name LIKE %s", $pattern ) );
};
if ( is_multisite() ) {
    foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $blog_id ) { switch_to_blog( $blog_id ); $letterlift_cleanup(); restore_current_blog(); }
} else { $letterlift_cleanup(); }

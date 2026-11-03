<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Letterlift_Store {
    public static function table(): string { global $wpdb; return $wpdb->prefix . 'letterlift_sessions'; }
    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        dbDelta( "CREATE TABLE $table (
            id varchar(36) NOT NULL,
            source_key char(64) NOT NULL,
            owner bigint(20) unsigned NOT NULL,
            status varchar(24) NOT NULL,
            revision bigint(20) unsigned NOT NULL DEFAULT 1,
            created datetime NOT NULL,
            updated datetime NOT NULL,
            payload longtext NOT NULL,
            PRIMARY KEY  (id),
            KEY source_key (source_key),
            KEY updated (updated)
        ) " . $wpdb->get_charset_collate() . ';' );
        update_option( 'letterlift_schema', LETTERLIFT_VERSION, false );
    }
    public static function create( array $data ): array {
        global $wpdb;
        $data['id'] = wp_generate_uuid4(); $data['revision'] = 1;
        $data['created'] = gmdate( 'Y-m-d H:i:s' ); $data['updated'] = $data['created'];
        $ok = $wpdb->insert( self::table(), array( 'id' => $data['id'], 'source_key' => $data['source_key'], 'owner' => get_current_user_id(), 'status' => $data['status'], 'revision' => 1, 'created' => $data['created'], 'updated' => $data['updated'], 'payload' => wp_json_encode( $data ) ) );
        if ( ! $ok ) { throw new RuntimeException( 'The analysis could not be saved. Please try again.' ); }
        return $data;
    }
    public static function get( string $id ): array {
        global $wpdb;
        $json = $wpdb->get_var( $wpdb->prepare( 'SELECT payload FROM ' . self::table() . ' WHERE id = %s', $id ) );
        if ( ! $json ) { throw new RuntimeException( 'This import no longer exists. Start a new import.' ); }
        return json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
    }
    public static function save( array $data ): array {
        global $wpdb;
        $revision = $data['revision']; $data['revision']++; $data['updated'] = gmdate( 'Y-m-d H:i:s' );
        $ok = $wpdb->update( self::table(), array( 'payload' => wp_json_encode( $data ), 'status' => $data['status'], 'revision' => $data['revision'], 'updated' => $data['updated'] ), array( 'id' => $data['id'], 'revision' => $revision ) );
        if ( $ok !== 1 ) { throw new RuntimeException( 'This import changed in another window. Reload it before continuing.' ); }
        return $data;
    }
    public static function history( ?string $key = null, int $page = 1 ): array {
        global $wpdb;
        $sql = 'SELECT payload FROM ' . self::table(); $args = array();
        if ( $key ) { $sql .= ' WHERE source_key = %s'; $args[] = $key; }
        $sql .= ' ORDER BY created DESC LIMIT 50 OFFSET %d'; $args[] = max( 0, $page - 1 ) * 50;
        return array_map( static function ( $json ) {
            $s = json_decode( $json, true );
            return array( 'id' => $s['id'], 'title' => $s['analysis']['title'], 'url' => $s['url'], 'provider' => 'Mailchimp', 'status' => $s['status'], 'created' => $s['created'], 'posts' => array_values( $s['execution']['posts'] ?? array() ), 'drafts' => count( Letterlift_Plan::destinations( $s['plan'] ) ) );
        }, $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) );
    }
    // An atomic compare-and-swap lease, shared across PHP workers. One bounded operation per request.
    public static function lock( string $key ): string {
        global $wpdb;
        $name = 'letterlift_lock_' . hash( 'sha256', $key );
        $token = ( time() + 300 ) . ':' . wp_generate_uuid4();
        if ( add_option( $name, $token, '', false ) ) { return $token; }
        $old = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s", $name ) );
        if ( $old && (int) $old < time() ) {
            $changed = $wpdb->query( $wpdb->prepare( "UPDATE $wpdb->options SET option_value = %s WHERE option_name = %s AND option_value = %s", $token, $name, $old ) );
            wp_cache_delete( $name, 'options' );
            if ( $changed === 1 ) { return $token; }
        }
        throw new RuntimeException( 'This import is already processing. Wait a moment and resume.' );
    }
    public static function unlock( string $key, string $token ): void {
        global $wpdb;
        $name = 'letterlift_lock_' . hash( 'sha256', $key );
        $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->options WHERE option_name = %s AND option_value = %s", $name, $token ) );
        wp_cache_delete( $name, 'options' );
    }
    public static function cleanup( int $days ): int {
        global $wpdb;
        // Active executions are never removed by retention cleanup.
        return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE updated < %s AND status <> 'importing'", gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS ) ) );
    }
}

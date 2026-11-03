<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Letterlift_Plugin {
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'menu' ) ); add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) ); add_action( 'rest_api_init', array( $this, 'routes' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( LETTERLIFT_FILE ), static function ( $links ) { array_unshift( $links, '<a href="' . esc_url( admin_url( 'tools.php?page=letterlift' ) ) . '">Open Letterlift</a>' ); return $links; } );
    }
    public function menu(): void { add_management_page( 'Letterlift', 'Letterlift', 'manage_options', 'letterlift', array( $this, 'page' ) ); }
    public function page(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        echo '<div class="wrap" id="letterlift"><h1>Letterlift</h1><p>Loading your newsletter workspace…</p></div><noscript><p>Letterlift needs JavaScript to organize and preview imports. Enable JavaScript and reload.</p></noscript>';
    }
    public function assets( string $hook ): void {
        if ( $hook !== 'tools_page_letterlift' ) { return; }
        wp_enqueue_style( 'letterlift', plugins_url( 'assets/admin.css', LETTERLIFT_FILE ), array(), LETTERLIFT_VERSION );
        wp_enqueue_script( 'letterlift', plugins_url( 'assets/admin.js', LETTERLIFT_FILE ), array(), LETTERLIFT_VERSION, true );
        $users = get_users( array( 'capability' => 'edit_posts', 'fields' => array( 'ID', 'display_name' ) ) );
        wp_localize_script( 'letterlift', 'LetterliftConfig', array( 'root' => esc_url_raw( rest_url( 'letterlift/v1/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'editUrl' => admin_url( 'post.php' ), 'categories' => array_map( static fn( $t ) => array( 'id' => $t->term_id, 'name' => $t->name ), get_categories( array( 'hide_empty' => false ) ) ), 'authors' => array_map( static fn( $u ) => array( 'id' => (int) $u->ID, 'name' => $u->display_name ), $users ), 'timezone' => wp_timezone_string() ) );
    }
    public function permission( WP_REST_Request $r ) {
        if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_posts' ) || ! current_user_can( 'upload_files' ) ) { return new WP_Error( 'letterlift_forbidden', 'You need administrator, post editing, and media upload permissions to use Letterlift.', array( 'status' => 403 ) ); }
        if ( ! wp_verify_nonce( $r->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) { return new WP_Error( 'letterlift_nonce', 'Your session expired. Reload this page and try again.', array( 'status' => 403 ) ); }
        return true;
    }
    public function routes(): void {
        $routes = array( '/history' => 'GET', '/settings' => 'GET,POST', '/cleanup' => 'POST', '/analyze' => 'POST', '/sessions/(?P<id>[a-f0-9-]{36})' => 'GET,POST', '/sessions/(?P<id>[a-f0-9-]{36})/(?P<action>approve|tick|retry|source|preview-image)' => 'GET,POST' );
        foreach ( $routes as $route => $methods ) { register_rest_route( 'letterlift/v1', $route, array( 'methods' => $methods, 'callback' => array( $this, 'dispatch' ), 'permission_callback' => array( $this, 'permission' ) ) ); }
    }
    public function dispatch( WP_REST_Request $r ) {
        try {
            if ( strlen( $r->get_body() ) > 262144 ) { throw new RuntimeException( 'The request exceeds supported limits.' ); }
            $path = $r->get_route(); $body = $r->get_json_params() ?: array();
            if ( str_ends_with( $path, '/history' ) ) { return Letterlift_Store::history( null, max( 1, (int) $r->get_param( 'page' ) ) ); }
            if ( str_ends_with( $path, '/settings' ) ) {
                if ( $r->get_method() === 'POST' ) {
                    $settings = array( 'category' => absint( $body['category'] ?? 0 ), 'author' => absint( $body['author'] ?? 0 ), 'mode' => ( $body['mode'] ?? '' ) === 'multiple' ? 'multiple' : 'single', 'featured' => ! empty( $body['featured'] ), 'exclude_boilerplate' => ! empty( $body['exclude_boilerplate'] ), 'retention_days' => min( 3650, max( 1, (int) ( $body['retention_days'] ?? 90 ) ) ) );
                    if ( ! term_exists( $settings['category'], 'category' ) || ( $settings['author'] && ! user_can( $settings['author'], 'edit_posts' ) ) ) { throw new RuntimeException( 'Choose an existing category and authorized author.' ); }
                    update_option( 'letterlift_settings', $settings, false );
                }
                return Letterlift_Plan::defaults();
            }
            if ( str_ends_with( $path, '/cleanup' ) ) { return array( 'removed' => Letterlift_Store::cleanup( (int) Letterlift_Plan::defaults()['retention_days'] ) ); }
            if ( str_ends_with( $path, '/analyze' ) ) { return $this->analyze( $body ); }
            $id = $r['id']; $action = $r['action'] ?? '';
            if ( $r->get_method() === 'GET' ) {
                if ( $action && ! in_array( $action, array( 'source', 'preview-image' ), true ) ) { throw new RuntimeException( 'This operation requires an explicit action.' ); }
                $s = Letterlift_Store::get( $id );
                if ( $action === 'preview-image' ) {
                    $image_id = sanitize_key( $r->get_param( 'image' ) ?? '' );
                    $images = array_column( $s['analysis']['images'], null, 'id' );
                    if ( ! isset( $images[ $image_id ] ) ) { throw new RuntimeException( 'Choose an image from this newsletter.' ); }
                    return Letterlift_Remote::preview( $images[ $image_id ]['url'] );
                }
                return $action === 'source' ? array( 'source' => $s['source'] ) : $this->public_session( $s );
            }
            if ( in_array( $action, array( 'source', 'preview-image' ), true ) ) { throw new RuntimeException( 'The original source is read-only.' ); }
            $token = Letterlift_Store::lock( $id );
            try {
                $s = Letterlift_Store::get( $id );
                if ( (int) ( $body['revision'] ?? 0 ) !== $s['revision'] ) { throw new RuntimeException( 'This import changed in another window. Reload it before continuing.' ); }
                if ( $action === 'approve' ) { $s = Letterlift_Executor::begin( $s ); }
                elseif ( $action === 'tick' ) { $s = Letterlift_Executor::tick( $s ); }
                elseif ( $action === 'retry' ) { $s = Letterlift_Executor::retry( $s ); }
                else {
                    if ( isset( $s['execution'] ) ) { throw new RuntimeException( 'This approved plan is immutable. Start a separate import to change the content.' ); }
                    $s['plan'] = Letterlift_Plan::clean( $s['analysis'], $body['plan'] ?? array() ); $s['status'] = 'in_review';
                    try { Letterlift_Plan::validate( $s['analysis'], $s['plan'] ); $s['status'] = 'ready'; } catch ( RuntimeException $ignored ) { /* Incomplete review is valid saved work. */ }
                    $s = Letterlift_Store::save( $s );
                }
                return $this->public_session( $s );
            } finally { Letterlift_Store::unlock( $id, $token ); }
        } catch ( Throwable $error ) {
            return new WP_Error( 'letterlift_error', $error instanceof RuntimeException ? $error->getMessage() : 'Letterlift could not complete this action. Retry, or ask your administrator to check the server log.', array( 'status' => 400 ) );
        }
    }
    private function analyze( array $body ) {
        $url = trim( (string) ( $body['url'] ?? '' ) ); Letterlift_Remote::validate( $url, true );
        $key = Letterlift_Remote::identity( $url );
        $token = Letterlift_Store::lock( 'source:' . $key );
        try {
        $duplicates = $this->duplicates( $key );
        if ( $duplicates && empty( $body['separate'] ) ) { return new WP_REST_Response( array( 'code' => 'duplicate', 'message' => "We've seen this newsletter before.", 'duplicates' => $duplicates ), 409 ); }
        $response = Letterlift_Remote::fetch( $url ); $final_key = Letterlift_Remote::identity( $response['url'] );
        if ( $final_key !== $key ) { $duplicates = array_merge( $duplicates, $this->duplicates( $final_key ) ); }
        if ( $duplicates && empty( $body['separate'] ) ) { return new WP_REST_Response( array( 'code' => 'duplicate', 'message' => "We've seen this newsletter before.", 'duplicates' => $duplicates ), 409 ); }
        $a = ( new Letterlift_Parser() )->analyze( $response['body'], $response['url'] );
        $s = Letterlift_Store::create( array( 'status' => 'analyzed', 'url' => $response['url'], 'source_key' => $final_key, 'source' => $response['body'], 'analysis' => $a, 'plan' => Letterlift_Plan::initial( $a ), 'duplicates' => array_column( $duplicates, 'id' ) ) );
        return $this->public_session( $s );
        } finally { Letterlift_Store::unlock( 'source:' . $key, $token ); }
    }
    private function duplicates( string $key ): array {
        $history = Letterlift_Store::history( $key );
        if ( $history ) { return $history; }
        // Permanent attribution survives cleanup of temporary analysis/history records.
        $posts = get_posts( array( 'post_type' => 'post', 'post_status' => array( 'draft', 'pending', 'publish', 'private', 'future', 'trash' ), 'posts_per_page' => 50, 'meta_key' => '_letterlift_source_key', 'meta_value' => $key ) );
        if ( ! $posts ) { return array(); }
        return array( array( 'id' => null, 'title' => $posts[0]->post_title, 'status' => 'complete', 'url' => get_post_meta( $posts[0]->ID, '_letterlift_source_url', true ), 'posts' => array_map( static fn( $post ) => array( 'id' => $post->ID, 'edit_url' => get_edit_post_link( $post->ID, 'raw' ) ), $posts ) ) );
    }
    private function public_session( array $s ): array {
        unset( $s['source'] );
        if ( isset( $s['execution'] ) ) { foreach ( $s['execution']['posts'] as &$post ) { $post['edit_url'] = $post['id'] ? get_edit_post_link( $post['id'], 'raw' ) : ''; } unset( $post ); }
        return $s;
    }
}

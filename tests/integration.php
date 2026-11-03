<?php
/** Run only on a disposable local WordPress installation. See README.md. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || getenv( 'LETTERLIFT_TEST_RUN' ) !== '1' || wp_get_environment_type() !== 'local' ) { throw new RuntimeException( 'Tests require a disposable local WordPress site and LETTERLIFT_TEST_RUN=1.' ); }
global $wpdb, $assertions;
wp_set_current_user( 1 );
$assertions = 0; $sessions = array(); $created_posts = array(); $created_media = array();
$settings_before = get_option( 'letterlift_settings', null );
function ll_assert( $condition, string $message ): void { global $assertions; $assertions++; if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $message ); } }
function ll_throws( callable $fn, string $message ): void { try { $fn(); } catch ( RuntimeException $e ) { ll_assert( true, $message ); return; } ll_assert( false, $message ); }
function ll_request( string $path, ?array $body = null, bool $nonce = true ) {
    $request = new WP_REST_Request( $body === null ? 'GET' : 'POST', '/letterlift/v1/' . $path );
    if ( $nonce ) { $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) ); }
    if ( $body !== null ) { $request->set_header( 'Content-Type', 'application/json' ); $request->set_body( wp_json_encode( $body ) ); }
    return rest_do_request( $request );
}
$fixture = file_get_contents( __DIR__ . '/fixtures/legacy.html' );
$remote_mode = 'normal'; $requested = array();
$mock = static function ( $pre, $args, $url ) use ( &$remote_mode, &$requested, $fixture ) {
    $requested[] = $url;
    if ( str_contains( $url, 'mcusercontent.com' ) ) {
        if ( $remote_mode === 'image-failure' ) { return new WP_Error( 'http_request_failed', 'timed out' ); }
        if ( $remote_mode === 'unsafe-redirect' ) { return array( 'response' => array( 'code' => 302 ), 'headers' => array( 'location' => 'http://127.0.0.1/private' ), 'body' => '' ); }
        return array( 'response' => array( 'code' => 200 ), 'headers' => array( 'content-type' => 'image/png' ), 'body' => base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j4ZkAAAAASUVORK5CYII=' ) );
    }
    if ( str_starts_with( $url, 'https://mailchi.mp/' ) ) {
        if ( $remote_mode === 'oversized' ) { return array( 'response' => array( 'code' => 200 ), 'headers' => array( 'content-type' => 'text/html' ), 'body' => str_repeat( 'x', Letterlift_Remote::HTML_LIMIT + 1 ) ); }
        if ( $remote_mode === 'mime' ) { return array( 'response' => array( 'code' => 200 ), 'headers' => array( 'content-type' => 'application/json' ), 'body' => '{}' ); }
        return array( 'response' => array( 'code' => 200 ), 'headers' => array( 'content-type' => 'text/html' ), 'body' => $fixture );
    }
    return new WP_Error( 'blocked_test_network', 'Unexpected request blocked by test suite.' );
};
add_filter( 'pre_http_request', $mock, 10, 3 );
try {
    update_option( 'letterlift_settings', array( 'featured' => false, 'exclude_boilerplate' => false ) );
    $a = ( new Letterlift_Parser() )->analyze( $fixture, 'https://mailchi.mp/letterlift-fixture' );
    ll_assert( count( $a['sections'] ) === 2, 'Legacy headings suggest two stories.' );
    ll_assert( count( $a['images'] ) === 1, 'Nested layout table image extracted once.' );
    ll_assert( str_contains( implode( ' ', array_column( $a['blocks'], 'html' ) ), 'Archive photograph, 1924.' ), 'Caption preserved.' );
    ll_assert( ! $a['warnings'], 'Legacy fixture text reconciles.' );
    $price = array_values( array_filter( $a['blocks'], static fn( $b ) => str_contains( $b['text'], '$12' ) ) )[0];
    ll_assert( ! $price['suggested_exclusion'], 'Price and sale mentions are not boilerplate.' );
    $footer = array_values( array_filter( $a['blocks'], static fn( $b ) => $b['suggested_exclusion'] ) )[0];
    $p = Letterlift_Plan::initial( $a );
    ll_assert( $p['selection'][ $footer['id'] ], 'Boilerplate is retained by default.' );
    $original = $a;
    $p['selection'][ $a['blocks'][1]['id'] ] = false;
    $p['mode'] = 'multiple'; $p['assignments'][ $a['blocks'][0]['id'] ] = 'd2';
    $remember = $p['assignments']; $p['mode'] = 'single'; $p['mode'] = 'multiple';
    ll_assert( $p['assignments'] === $remember, 'Mode switching preserves assignments.' );
    ll_assert( count( Letterlift_Plan::blocks( $a, $p, 'd2' ) ) > 0, 'Cross-story assignment works.' );
    $p = Letterlift_Plan::clean( $a, $p ); Letterlift_Plan::validate( $a, $p );
    ll_assert( $a === $original, 'Selection and organization leave source analysis immutable.' );
    $bad = $p; array_pop( $bad['order'] ); ll_throws( fn() => Letterlift_Plan::clean( $a, $bad ), 'Dropped source blocks rejected.' );
    $bad = $p; $bad['order'][0] = $bad['order'][1]; ll_throws( fn() => Letterlift_Plan::clean( $a, $bad ), 'Repeated blocks rejected.' );
    $bad = $p; $bad['assignments'][ $a['blocks'][0]['id'] ] = ''; ll_throws( fn() => Letterlift_Plan::validate( $a, $bad ), 'Selected unassigned blocks block execution.' );
    $bad = $p; $bad['drafts'][0]['title'] = ''; ll_throws( fn() => Letterlift_Plan::validate( $a, $bad ), 'Untitled destinations rejected.' );
    $modern = ( new Letterlift_Parser() )->analyze( file_get_contents( __DIR__ . '/fixtures/modern.html' ), 'https://mailchi.mp/modern' );
    ll_assert( in_array( 'table', array_column( $modern['blocks'], 'type' ), true ), 'Data table preserved as a structured block.' );
    ll_assert( count( $modern['warnings'] ) > 0, 'Unsupported embed has an integrity warning.' );
    $plain_table = ( new Letterlift_Parser() )->analyze( '<h1>Data</h1><table><tr><td>Year</td><td>Total</td></tr><tr><td>1924</td><td>12</td></tr></table>', 'https://mailchi.mp/data' );
    ll_assert( in_array( 'table', array_column( $plain_table['blocks'], 'type' ), true ), 'Data table without header cells retains its row and column structure.' );
    update_option( 'letterlift_settings', array( 'featured' => false, 'exclude_boilerplate' => true ) );
    $unsupported_footer = ( new Letterlift_Parser() )->analyze( '<h1>Story</h1><p>Article.</p><iframe>Unsubscribe from this region.</iframe>', 'https://mailchi.mp/footer' );
    $footer_plan = Letterlift_Plan::initial( $unsupported_footer ); $uncertain = array_values( array_filter( $unsupported_footer['blocks'], static fn( $b ) => $b['type'] === 'unsupported' ) )[0];
    ll_assert( $footer_plan['selection'][ $uncertain['id'] ], 'High-severity unsupported content is never automatically excluded by a boilerplate default.' );
    $footer_plan['selection'][ $uncertain['id'] ] = false; Letterlift_Plan::validate( $unsupported_footer, $footer_plan ); ll_assert( true, 'Explicitly excluding the affected block is an auditable resolution.' );
    update_option( 'letterlift_settings', array( 'featured' => false, 'exclude_boilerplate' => false ) );
    $modern_plan = Letterlift_Plan::initial( $modern ); ll_throws( fn() => Letterlift_Plan::validate( $modern, $modern_plan ), 'Unresolved high-severity warning blocks execution.' );
    $modern_plan['acknowledged'] = array_column( $modern['warnings'], 'id' ); Letterlift_Plan::validate( $modern, $modern_plan ); ll_assert( true, 'Explicit acknowledgement resolves retained safe fallback.' );
    $xss = ( new Letterlift_Parser() )->analyze( '<body><h1>Safe</h1><p onclick="alert(1)"><a href="javascript:alert(1)">Words</a><img src="https://mcusercontent.com/letterlift-fixture.png" onerror="alert(1)"></p></body>', 'https://mailchi.mp/xss' );
    $safe = implode( ' ', array_column( $xss['blocks'], 'html' ) );
    ll_assert( ! preg_match( '/onclick|onerror|javascript:/i', $safe ), 'Executable HTML and link protocols removed.' );
    $inline = ( new Letterlift_Parser() )->analyze( '<p>Keep <video src="x">Transcript</video> this text.</p>', 'https://mailchi.mp/inline' );
    ll_assert( count( $inline['warnings'] ) > 0, 'Inline unsupported structures are flagged.' );
    foreach ( array( 'file:///etc/passwd', 'http://127.0.0.1/test', 'http://169.254.169.254/latest/meta-data', 'http://10.0.0.1/', 'https://user:password@mailchi.mp/test', 'https://mailchi.mp:8443/test', 'https://evilmailchi.mp/test', 'https://example.org/newsletter' ) as $unsafe ) { ll_throws( fn() => Letterlift_Remote::validate( $unsafe, true ), 'Unsafe or unsupported source rejected.' ); }
    ll_assert( Letterlift_Remote::provider( 'https://us1.campaign-archive.com/test' ) === true, 'Mailchimp campaign archive domains supported.' );
    ll_assert( Letterlift_Remote::identity( 'https://mailchi.mp/news/story?utm_source=one' ) === Letterlift_Remote::identity( 'https://mailchi.mp/news/story?utm_source=two' ), 'Tracking parameters do not defeat source matching.' );
    $remote_mode = 'unsafe-redirect'; ll_throws( fn() => Letterlift_Remote::fetch( 'https://mcusercontent.com/letterlift-fixture.png', true ), 'Unsafe image redirects blocked.' );
    $remote_mode = 'oversized'; ll_throws( fn() => Letterlift_Remote::fetch( 'https://mailchi.mp/test' ), 'Oversized response rejected.' );
    $remote_mode = 'mime'; ll_throws( fn() => Letterlift_Remote::fetch( 'https://mailchi.mp/test' ), 'Unexpected content type rejected.' ); $remote_mode = 'normal';
    $no_nonce = ll_request( 'history', null, false ); ll_assert( $no_nonce->get_status() === 403, 'Nonce required independently of capability.' );
    wp_set_current_user( 0 ); $anonymous = ll_request( 'history' ); ll_assert( $anonymous->get_status() === 403, 'Anonymous users denied.' ); wp_set_current_user( 1 );
    $before_posts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type IN ('post','attachment')" );
    $res = ll_request( 'analyze', array( 'url' => 'https://mailchi.mp/letterlift-fixture' ) );
    ll_assert( $res->get_status() === 200, 'Analyze REST route succeeds: ' . wp_json_encode( $res->get_data() ) );
    $s = $res->get_data(); $sessions[] = $s['id'];
    ll_assert( ! isset( $s['source'] ), 'Raw source is not included in routine API responses.' );
    ll_assert( $before_posts === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type IN ('post','attachment')" ), 'Analysis creates no posts or attachments.' );
    $image_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'attachment'" );
    // WP_REST_Request does not parse a query embedded in its route; set query params explicitly.
    $preview_request = new WP_REST_Request( 'GET', '/letterlift/v1/sessions/' . $s['id'] . '/preview-image' ); $preview_request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) ); $preview_request->set_query_params( array( 'image' => $s['analysis']['images'][0]['id'] ) );
    $preview = rest_do_request( $preview_request );
    ll_assert( $preview->get_status() === 200 && str_starts_with( $preview->get_data()['image'], 'data:image/png;base64,' ), 'Safe image preview generated without attachments.' );
    ll_assert( $image_count === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'attachment'" ), 'Preview does not import media.' );
    $preview_request->set_query_params( array( 'image' => 'not-in-this-source' ) ); ll_assert( rest_do_request( $preview_request )->get_status() === 400, 'Preview cannot fetch arbitrary asset URLs.' );
    $dup = ll_request( 'analyze', array( 'url' => 'https://mailchi.mp/letterlift-fixture?utm_source=test' ) ); ll_assert( $dup->get_status() === 409 && $dup->get_data()['code'] === 'duplicate', 'Duplicate requires explicit separate import.' );
    $stale = ll_request( 'sessions/' . $s['id'], array( 'revision' => 0, 'plan' => $s['plan'] ) ); ll_assert( $stale->get_status() === 400, 'Stale review revision rejected.' );
    $s['plan']['mode'] = 'multiple';
    // Put the image only in the first destination. A failure must not prevent the second draft.
    $save = ll_request( 'sessions/' . $s['id'], array( 'revision' => $s['revision'], 'plan' => $s['plan'] ) ); ll_assert( $save->get_status() === 200, 'Review saved.' ); $s = $save->get_data();
    $approve = ll_request( 'sessions/' . $s['id'] . '/approve', array( 'revision' => $s['revision'] ) ); ll_assert( $approve->get_status() === 200, 'Approval records snapshot.' ); $s = $approve->get_data();
    $hash = $s['execution']['plan_hash'];
    $edit = ll_request( 'sessions/' . $s['id'], array( 'revision' => $s['revision'], 'plan' => $s['plan'] ) ); ll_assert( $edit->get_status() === 400, 'Approved plan cannot be edited.' );
    $remote_mode = 'image-failure';
    for ( $i = 0; $i < 12 && $s['status'] === 'importing'; $i++ ) { $tick = ll_request( 'sessions/' . $s['id'] . '/tick', array( 'revision' => $s['revision'] ) ); ll_assert( $tick->get_status() === 200, 'Bounded execution request succeeds.' ); $s = $tick->get_data(); }
    ll_assert( $s['status'] === 'needs_attention', 'Image failure produces recoverable partial import.' );
    ll_assert( $s['execution']['posts']['d2']['status'] === 'complete' && $s['execution']['posts']['d1']['status'] === 'failed', 'Independent destination completes despite image failure.' );
    $completed = $s['execution']['posts']['d2']['id']; $created_posts[] = $completed;
    // Simulate an interrupted write after wp_insert_post but before the session checkpoint.
    $remote_mode = 'normal'; $retry = ll_request( 'sessions/' . $s['id'] . '/retry', array( 'revision' => $s['revision'] ) ); $s = $retry->get_data();
    $media_tick = ll_request( 'sessions/' . $s['id'] . '/tick', array( 'revision' => $s['revision'] ) ); $s = $media_tick->get_data();
    $local_urls = array(); foreach ( $s['analysis']['images'] as $img ) { $local_urls[ $img['url'] ] = wp_get_attachment_url( $s['execution']['images'][ $img['id'] ]['id'] ); }
    $reserved = wp_insert_post( wp_slash( array( 'post_title' => 'Recovered draft', 'post_status' => 'draft', 'guid' => 'urn:letterlift:' . $s['id'] . ':d1', 'post_content' => Letterlift_Plan::content( Letterlift_Plan::blocks( $s['analysis'], $s['plan'], 'd1' ), $local_urls ) ) ) ); $created_posts[] = $reserved;
    for ( $i = 0; $i < 12 && $s['status'] === 'importing'; $i++ ) { $tick = ll_request( 'sessions/' . $s['id'] . '/tick', array( 'revision' => $s['revision'] ) ); $s = $tick->get_data(); }
    foreach ( $s['execution']['images'] as $img ) { if ( $img['id'] ) { $created_media[] = $img['id']; } }
    ll_assert( $s['status'] === 'complete', 'Retry finishes all destinations.' );
    ll_assert( $s['execution']['posts']['d2']['id'] === $completed, 'Retry preserves completed destination ID.' );
    ll_assert( $s['execution']['posts']['d1']['id'] === $reserved, 'Interrupted post INSERT recovered via stable GUID.' );
    ll_assert( $s['execution']['plan_hash'] === $hash, 'Execution snapshot unchanged across retries.' );
    ll_assert( get_post_status( $completed ) === 'draft' && get_post_status( $reserved ) === 'draft', 'All created posts remain drafts.' );
    $again = ll_request( 'sessions/' . $s['id'] . '/tick', array( 'revision' => $s['revision'] ) ); ll_assert( $again->get_data()['execution']['posts'] === $s['execution']['posts'], 'Repeated completion request is idempotent.' );
    $lock = Letterlift_Store::lock( 'test-lock' ); ll_throws( fn() => Letterlift_Store::lock( 'test-lock' ), 'Concurrent execution excluded by lease.' ); Letterlift_Store::unlock( 'test-lock', $lock );
    // Fresh single-post execution verifies generated content and cross-import media reuse.
    $new = ll_request( 'analyze', array( 'url' => 'https://mailchi.mp/letterlift-fixture', 'separate' => true ) ); $n = $new->get_data(); $sessions[] = $n['id'];
    $n['plan']['single']['featured'] = $n['analysis']['images'][0]['id'];
    $new = ll_request( 'sessions/' . $n['id'], array( 'revision' => $n['revision'], 'plan' => $n['plan'] ) ); $n = $new->get_data();
    $new = ll_request( 'sessions/' . $n['id'] . '/approve', array( 'revision' => $n['revision'] ) ); $n = $new->get_data();
    for ( $i = 0; $i < 12 && $n['status'] === 'importing'; $i++ ) { $new = ll_request( 'sessions/' . $n['id'] . '/tick', array( 'revision' => $n['revision'] ) ); $n = $new->get_data(); }
    ll_assert( $n['status'] === 'complete', 'Single-post import completes.' );
    $single_id = $n['execution']['posts']['single']['id']; $created_posts[] = $single_id;
    $post = get_post( $single_id );
    ll_assert( str_contains( $post->post_content, 'editorial wording' ) && str_contains( $post->post_content, 'Archive photograph, 1924.' ), 'Native post preserves editorial text and caption.' );
    ll_assert( ! str_contains( $post->post_content, 'https://mcusercontent.com/' ), 'Native post uses managed local images.' );
    ll_assert( $n['execution']['images'] === $s['execution']['images'], 'Safe media attachment reused across imports.' );
    ll_assert( get_post_meta( $single_id, '_letterlift_source_url', true ) === 'https://mailchi.mp/letterlift-fixture', 'Private source attribution stored without modifying public content.' );
    ll_assert( str_contains( $post->post_content, '<!-- wp:html -->' ), 'Content uses WordPress core blocks without plugin dependencies.' );
    ll_assert( get_post_thumbnail_id( $single_id ) === $created_media[0], 'Featured image independently assigned to its destination.' );
    // Retention and lifecycle checks run only on this explicitly disposable site.
    $wpdb->update( Letterlift_Store::table(), array( 'updated' => '2000-01-01 00:00:00' ), array( 'id' => $s['id'] ) );
    $wpdb->update( Letterlift_Store::table(), array( 'updated' => '2000-01-01 00:00:00' ), array( 'id' => $n['id'] ) );
    ll_assert( Letterlift_Store::cleanup( 90 ) >= 2, 'Old history can be removed independently of imported content.' );
    ll_assert( get_post( $completed ) && get_post( $reserved ), 'History cleanup preserves imported posts.' );
    $after_cleanup_duplicate = ll_request( 'analyze', array( 'url' => 'https://mailchi.mp/letterlift-fixture' ) );
    ll_assert( $after_cleanup_duplicate->get_status() === 409 && $after_cleanup_duplicate->get_data()['duplicates'][0]['id'] === null, 'Permanent post attribution detects duplicates after temporary history cleanup.' );
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    deactivate_plugins( 'letterlift/letterlift.php', true );
    ll_assert( get_post( $single_id )->post_content === $post->post_content, 'Post remains intact while Letterlift is deactivated.' );
    $activated = activate_plugin( 'letterlift/letterlift.php', '', false, true );
    ll_assert( ! is_wp_error( $activated ), 'Plugin reactivates cleanly.' );
    define( 'WP_UNINSTALL_PLUGIN', 'letterlift/letterlift.php' );
    require dirname( __DIR__ ) . '/uninstall.php';
    ll_assert( get_post( $single_id ) && is_file( get_attached_file( $created_media[0] ) ), 'Uninstall preserves imported posts and media files.' );
    ll_assert( get_option( 'letterlift_settings' ) === false, 'Uninstall removes plugin-owned settings.' );
    Letterlift_Store::install();
    echo "PASS: $assertions assertions on WordPress " . get_bloginfo( 'version' ) . "\n";
} finally {
    remove_filter( 'pre_http_request', $mock, 10 );
    foreach ( array_unique( $created_posts ) as $id ) { wp_delete_post( $id, true ); }
    foreach ( array_unique( $created_media ) as $id ) { wp_delete_attachment( $id, true ); }
    foreach ( $sessions as $id ) { $wpdb->delete( Letterlift_Store::table(), array( 'id' => $id ) ); }
    if ( $settings_before === null ) { delete_option( 'letterlift_settings' ); } else { update_option( 'letterlift_settings', $settings_before ); }
}

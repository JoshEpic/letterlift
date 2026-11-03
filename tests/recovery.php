<?php
/** Real database recovery checks for pre-versioned immutable execution plans. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || getenv( 'LETTERLIFT_TEST_RUN' ) !== '1' || wp_get_environment_type() !== 'local' ) { throw new RuntimeException( 'Use a disposable local WordPress site with LETTERLIFT_TEST_RUN=1.' ); }
global $wpdb;
wp_set_current_user( 1 );
$checks = 0; $post_ids = array(); $session_id = ''; $old_settings = get_option( 'letterlift_settings', null );
$check = static function ( bool $ok, string $message ) use ( &$checks ): void { $checks++; if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $message ); } };
try {
    update_option( 'letterlift_settings', array( 'featured' => false ) );
    $analysis = ( new Letterlift_Parser() )->analyze( '<h2>First story</h2><p>First original content.</p><h2>Second story</h2><p>Second original content.</p>', 'https://mailchi.mp/legacy-recovery-fixture' );
    $plan = Letterlift_Plan::initial( $analysis ); $plan['mode'] = 'multiple';
    $s = Letterlift_Store::create( array( 'url' => 'https://mailchi.mp/legacy-recovery-fixture', 'source_key' => hash( 'sha256', 'legacy-recovery-fixture' ), 'source' => '<p>Saved original source.</p>', 'analysis' => $analysis, 'plan' => $plan, 'status' => 'ready', 'duplicates' => array() ) ); $session_id = $s['id'];
    $s = Letterlift_Executor::begin( $s );
    $check( $s['execution']['serializer_version'] === Letterlift_Serializer::CURRENT, 'New approval pins the native serializer.' );
    $hash = $s['execution']['plan_hash']; $snapshot = $s['execution']['snapshot'];
    // This test constructs the exact pre-upgrade record shape: no serializer_version field.
    unset( $s['execution']['serializer_version'] );
    $first = Letterlift_Plan::blocks( $analysis, $snapshot, 'd1' );
    $legacy_first = Letterlift_Plan::content( $first, array(), Letterlift_Serializer::LEGACY );
    $second = wp_insert_post( array( 'post_title' => 'Completed before upgrade', 'post_status' => 'draft', 'post_content' => 'User edited this completed draft.', 'guid' => 'urn:letterlift:' . $s['id'] . ':d2' ) ); $post_ids[] = $second;
    $s['execution']['posts']['d2']['status'] = 'complete'; $s['execution']['posts']['d2']['id'] = $second;
    $s = Letterlift_Store::save( $s );
    $preview_input = $s; $preview_input['plan']['mode'] = 'single';
    $preview = Letterlift_Serializer::preview( $preview_input );
    $check( $preview['serializer_version'] === Letterlift_Serializer::LEGACY && $preview['drafts']['d1']['content'] === $legacy_first, 'Old approved preview uses the original serializer and snapshot.' );
    // Simulate a created post whose checkpoint was interrupted, then edited by its author.
    $first_id = wp_insert_post( array( 'post_title' => 'Interrupted draft', 'post_status' => 'draft', 'post_content' => 'An author edited this incomplete draft.', 'guid' => 'urn:letterlift:' . $s['id'] . ':d1' ) ); $post_ids[] = $first_id;
    $s = Letterlift_Executor::tick( $s );
    $check( $s['execution']['posts']['d1']['status'] === 'failed' && $s['execution']['posts']['d1']['id'] === $first_id, 'Edited interrupted draft is found and explicitly fails integrity verification.' );
    $check( get_post( $first_id )->post_content === 'An author edited this incomplete draft.', 'Retry preserves editorial changes instead of overwriting them.' );
    $s = Letterlift_Executor::tick( $s ); $check( $s['status'] === 'needs_attention', 'Edited old destination remains recoverable.' );
    // Restoration is an explicit editorial action in this fixture, never an importer migration.
    wp_update_post( wp_slash( array( 'ID' => $first_id, 'post_content' => $legacy_first ) ) );
    $s = Letterlift_Executor::retry( $s ); $s = Letterlift_Executor::tick( $s ); $s = Letterlift_Executor::tick( $s );
    $check( $s['status'] === 'complete', 'Pre-upgrade execution completes after approved content is restored.' );
    $check( $s['execution']['posts']['d1']['id'] === $first_id && $s['execution']['posts']['d2']['id'] === $second, 'Legacy recovery reuses stable post identities.' );
    $check( get_post( $first_id )->post_content === $legacy_first && str_contains( $legacy_first, '<!-- wp:html -->' ), 'Recovered old destination is not silently converted to native blocks.' );
    $check( get_post( $second )->post_content === 'User edited this completed draft.', 'Completed legacy destination remains unchanged during retry.' );
    $check( $s['execution']['plan_hash'] === $hash && $s['execution']['snapshot'] === $snapshot && ! isset( $s['execution']['serializer_version'] ), 'Old plan, hash, and unversioned execution record remain immutable.' );
    $check( get_post_meta( $first_id, '_letterlift_serializer_version', true ) === Letterlift_Serializer::LEGACY, 'Recovered destination records its actual content format.' );
    $future_rejected = false; try { Letterlift_Serializer::generate( $first, array(), 'unavailable-version' ); } catch ( RuntimeException $expected ) { $future_rejected = true; }
    $check( $future_rejected, 'Unknown serializer versions fail explicitly and cannot silently fall back.' );
    echo "PASS: $checks recovery assertions on WordPress " . get_bloginfo( 'version' ) . "\n";
} finally {
    foreach ( $post_ids as $id ) { wp_delete_post( $id, true ); }
    if ( $session_id ) { $wpdb->delete( Letterlift_Store::table(), array( 'id' => $session_id ) ); }
    if ( $old_settings === null ) { delete_option( 'letterlift_settings' ); } else { update_option( 'letterlift_settings', $old_settings ); }
}

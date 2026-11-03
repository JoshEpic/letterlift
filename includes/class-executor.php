<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Letterlift_Executor {
    public static function begin( array $s ): array {
        if ( isset( $s['execution'] ) ) { return $s; }
        $p = Letterlift_Plan::clean( $s['analysis'], $s['plan'] ); Letterlift_Plan::validate( $s['analysis'], $p );
        // Preflight the same conversion used by the preview before freezing execution.
        foreach ( Letterlift_Plan::destinations( $p ) as $d ) { Letterlift_Serializer::generate( Letterlift_Plan::blocks( $s['analysis'], $p, $d['id'] ) ); }
        $images = array(); $posts = array();
        foreach ( Letterlift_Plan::destinations( $p ) as $d ) {
            foreach ( Letterlift_Plan::blocks( $s['analysis'], $p, $d['id'] ) as $b ) { foreach ( $b['images'] as $id ) { $images[ $id ] = array( 'status' => 'pending', 'id' => 0, 'error' => '' ); } }
            $posts[ $d['id'] ] = array( 'status' => 'pending', 'id' => 0, 'title' => $d['title'], 'error' => '' );
        }
        $s['execution'] = array( 'serializer_version' => Letterlift_Serializer::CURRENT, 'snapshot' => $p, 'approved_at' => gmdate( 'c' ), 'approved_by' => get_current_user_id(), 'plan_hash' => hash( 'sha256', wp_json_encode( $p ) ), 'images' => $images, 'posts' => $posts, 'phase' => 'Approved plan recorded' );
        $s['status'] = 'importing'; return Letterlift_Store::save( $s );
    }
    public static function retry( array $s ): array {
        if ( empty( $s['execution'] ) ) { throw new RuntimeException( 'Approve the preview before importing.' ); }
        foreach ( array( 'images', 'posts' ) as $kind ) { foreach ( $s['execution'][ $kind ] as &$op ) { if ( $op['status'] === 'failed' ) { $op['status'] = 'pending'; $op['error'] = ''; } } unset( $op ); }
        $s['status'] = 'importing'; return Letterlift_Store::save( $s );
    }
    public static function tick( array $s ): array {
        if ( $s['status'] !== 'importing' ) { return $s; }
        $e = &$s['execution']; $p = $e['snapshot'];
        foreach ( $e['images'] as $id => &$op ) {
            if ( $op['status'] !== 'pending' ) { continue; }
            $e['phase'] = 'Downloading and validating an image';
            try { $op['id'] = self::image( $s, $id ); $op['status'] = 'complete'; }
            catch ( Throwable $error ) { $op['status'] = 'failed'; $op['error'] = $error instanceof RuntimeException ? $error->getMessage() : 'The image could not be processed. Retry or ask your administrator to inspect the server log.'; }
            return Letterlift_Store::save( $s );
        } unset( $op );
        foreach ( Letterlift_Plan::destinations( $p ) as $d ) {
            $op = &$e['posts'][ $d['id'] ]; if ( $op['status'] !== 'pending' ) { continue; }
            $e['phase'] = 'Creating draft: ' . $d['title'];
            try {
                $blocks = Letterlift_Plan::blocks( $s['analysis'], $p, $d['id'] ); $urls = array();
                foreach ( $blocks as $b ) { foreach ( $b['images'] as $image_id ) { if ( $e['images'][ $image_id ]['status'] !== 'complete' ) { throw new RuntimeException( 'An included image needs attention. Retry the failed image to finish this draft.' ); } } }
                foreach ( $s['analysis']['images'] as $img ) { if ( ( $e['images'][ $img['id'] ]['status'] ?? '' ) === 'complete' ) { $url = wp_get_attachment_url( $e['images'][ $img['id'] ]['id'] ); if ( ! $url ) { throw new RuntimeException( 'An imported image was removed. Restore it before retrying.' ); } $urls[ $img['url'] ] = array( 'url' => $url, 'id' => (int) $e['images'][ $img['id'] ]['id'] ); } }
                $op['id'] = self::post( $s, $d, $blocks, $urls ); $op['status'] = 'complete'; $op['error'] = '';
            } catch ( Throwable $error ) {
                global $wpdb;
                $op['id'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE guid = %s LIMIT 1", 'urn:letterlift:' . $s['id'] . ':' . $d['id'] ) );
                $op['status'] = 'failed'; $op['error'] = $error instanceof RuntimeException ? $error->getMessage() : 'This draft could not be created. Retry or ask your administrator to inspect the server log.'; }
            return Letterlift_Store::save( $s );
        }
        $failed = array_filter( array_merge( array_values( $e['images'] ), array_values( $e['posts'] ) ), static fn( $op ) => $op['status'] !== 'complete' );
        $s['status'] = $failed ? 'needs_attention' : 'complete'; $e['phase'] = $failed ? 'Some drafts need attention' : 'Final integrity checks complete';
        return Letterlift_Store::save( $s );
    }
    private static function image( array $s, string $id ): int {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/media.php'; require_once ABSPATH . 'wp-admin/includes/image.php';
        $images = array_column( $s['analysis']['images'], null, 'id' ); $image = $images[ $id ];
        $key = 'media:' . hash( 'sha256', $image['url'] ); $token = Letterlift_Store::lock( $key );
        try {
            $slug = 'letterlift-' . hash( 'sha256', $image['url'] );
            $existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_name = %s AND post_type = 'attachment' AND post_status <> 'trash' LIMIT 1", $slug ) );
            if ( $existing && is_file( get_attached_file( $existing ) ?: '' ) ) {
                if ( ! wp_get_attachment_metadata( $existing ) ) { $meta = wp_generate_attachment_metadata( $existing, get_attached_file( $existing ) ); if ( is_wp_error( $meta ) || ! $meta ) { throw new RuntimeException( 'The image metadata could not be generated. Retry.' ); } wp_update_attachment_metadata( $existing, $meta ); }
                return $existing;
            }
            $response = Letterlift_Remote::fetch( $image['url'], true ); $body = $response['body'];
            $size = @getimagesizefromstring( $body );
            if ( ! $size || ! in_array( $size['mime'], array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) || $size['mime'] !== $response['mime'] || $size[0] * $size[1] > 40000000 ) { throw new RuntimeException( 'This image failed file validation or exceeds the 40 megapixel limit.' ); }
            $ext = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp' )[ $size['mime'] ];
            $temp = wp_tempnam( 'letterlift' ); if ( ! $temp ) { throw new RuntimeException( 'A temporary image file could not be created.' ); }
            try {
                if ( file_put_contents( $temp, $body ) !== strlen( $body ) ) { throw new RuntimeException( 'The image could not be saved. Check available disk space.' ); }
                $check = wp_check_filetype_and_ext( $temp, $slug . '.' . $ext );
                if ( $check['type'] !== $size['mime'] ) { throw new RuntimeException( 'The image failed WordPress file validation.' ); }
                $attachment = media_handle_sideload( array( 'name' => $slug . '.' . $ext, 'tmp_name' => $temp ), 0, null, array( 'post_name' => $slug, 'post_title' => $image['alt'] ?: 'Newsletter image', 'meta_input' => array( '_letterlift_source_url' => $image['url'], '_wp_attachment_image_alt' => $image['alt'] ) ) );
                if ( is_wp_error( $attachment ) ) { throw new RuntimeException( 'WordPress could not create this image attachment. Check file permissions and retry.' ); }
                return (int) $attachment;
            } finally { if ( is_file( $temp ) ) { wp_delete_file( $temp ); } }
        } finally { Letterlift_Store::unlock( $key, $token ); }
    }
    private static function post( array $s, array $d, array $blocks, array $urls ): int {
        global $wpdb;
        $guid = 'urn:letterlift:' . $s['id'] . ':' . $d['id'];
        $id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE guid = %s LIMIT 1", $guid ) );
        $content = Letterlift_Plan::content( $blocks, $urls, Letterlift_Serializer::version( $s ) );
        if ( ! $id ) {
            // Stable GUID is written in the initial INSERT, closing the create-before-session-save retry window.
            $post = array( 'guid' => $guid, 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => $d['title'], 'post_name' => $d['slug'] ?: sanitize_title( $d['title'] ), 'post_excerpt' => $d['excerpt'] ?: wp_trim_words( Letterlift_Parser::text( $content ), 45, '…' ), 'post_content' => $content, 'post_author' => $d['author'], 'post_category' => array( $d['category'] ) );
            if ( $d['date'] ) { $post['post_date'] = str_replace( 'T', ' ', $d['date'] ) . ':00'; $post['post_date_gmt'] = get_gmt_from_date( $post['post_date'] ); }
            $id = wp_insert_post( wp_slash( $post ), true );
            if ( is_wp_error( $id ) ) { throw new RuntimeException( 'WordPress could not create this draft. Check the post settings and retry.' ); }
        }
        if ( ! get_post( $id ) || get_post_status( $id ) === 'trash' ) { throw new RuntimeException( 'The draft was removed. Restore it from Trash before retrying.' ); }
        $stored = get_post( $id );
        if ( $stored->post_status !== 'draft' ) { throw new RuntimeException( 'This destination is no longer a draft. Review the existing post before retrying.' ); }
        if ( hash( 'sha256', $stored->post_content ) !== hash( 'sha256', $content ) ) { throw new RuntimeException( 'The existing draft content differs from the approved preview. Inspect it and restore the approved content before retrying. Letterlift will preserve your edits.' ); }
        update_post_meta( $id, '_letterlift_serializer_version', Letterlift_Serializer::version( $s ) );
        update_post_meta( $id, '_letterlift_source_key', $s['source_key'] ); update_post_meta( $id, '_letterlift_source_url', $s['url'] ); update_post_meta( $id, '_letterlift_session', $s['id'] ); update_post_meta( $id, '_letterlift_plan_hash', $s['execution']['plan_hash'] );
        if ( get_post_meta( $id, '_letterlift_session', true ) !== $s['id'] || get_post_meta( $id, '_letterlift_plan_hash', true ) !== $s['execution']['plan_hash'] || get_post_meta( $id, '_letterlift_source_url', true ) !== $s['url'] ) { throw new RuntimeException( 'The draft exists, but its import metadata could not be saved. Retry to finish.' ); }
        if ( $d['featured'] ) {
            $attachment = $s['execution']['images'][ $d['featured'] ]['id'];
            if ( get_post_thumbnail_id( $id ) !== (int) $attachment && ! set_post_thumbnail( $id, $attachment ) ) { throw new RuntimeException( 'The draft exists, but its featured image could not be assigned. Retry to finish.' ); }
        }
        return (int) $id;
    }
}

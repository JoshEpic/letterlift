<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Letterlift_Remote {
    public const HTML_LIMIT = 2097152;
    public const IMAGE_LIMIT = 10485760;
    public static function provider( string $url ): bool {
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        return (bool) preg_match( '/(^|\.)(list-manage[12]?\.com|campaign-archive[12]?\.com|mailchi\.mp)$/D', $host );
    }
    public static function validate( string $url, bool $source = false ): string {
        $parts = wp_parse_url( $url );
        if ( ! $parts || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ( isset( $parts['port'] ) && ! in_array( $parts['port'], array( 80, 443 ), true ) ) ) {
            throw new RuntimeException( 'Enter a valid public HTTP or HTTPS URL without credentials.' );
        }
        if ( $source && ! self::provider( $url ) ) { throw new RuntimeException( 'This provider is not supported. Use a public Mailchimp campaign archive URL.' ); }
        if ( ! wp_http_validate_url( $url ) ) { throw new RuntimeException( 'This URL or redirect is unsafe. Use a public newsletter URL.' ); }
        $host = $parts['host'];
        $ips = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : gethostbynamel( $host );
        if ( ! $ips ) { throw new RuntimeException( 'The newsletter host is unavailable. Check the URL and retry.' ); }
        foreach ( $ips as $ip ) {
            if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) { throw new RuntimeException( 'This URL or redirect is unsafe. Use a public newsletter URL.' ); }
        }
        return $url;
    }
    public static function absolute( string $url, string $base ): string {
        return WP_Http::make_absolute_url( html_entity_decode( trim( $url ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $base );
    }
    public static function fetch( string $url, bool $image = false ): array {
        $limit = $image ? self::IMAGE_LIMIT : self::HTML_LIMIT;
        for ( $redirect = 0; $redirect <= 4; $redirect++ ) {
            self::validate( $url, ! $image );
            $response = wp_safe_remote_get( $url, array( 'timeout' => 15, 'redirection' => 0, 'limit_response_size' => $limit + 1, 'cookies' => array(), 'headers' => array( 'Accept' => $image ? 'image/jpeg,image/png,image/gif,image/webp' : 'text/html', 'Accept-Encoding' => 'identity' ) ) );
            if ( is_wp_error( $response ) ) {
                throw new RuntimeException( str_contains( strtolower( $response->get_error_message() ), 'timed out' ) ? 'The request timed out. Try again.' : 'The newsletter or image is unavailable. Check the source and retry.' );
            }
            $code = wp_remote_retrieve_response_code( $response );
            if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
                $next = self::absolute( wp_remote_retrieve_header( $response, 'location' ), $url );
                if ( ! $next || ( str_starts_with( $url, 'https:' ) && ! str_starts_with( $next, 'https:' ) ) ) { throw new RuntimeException( 'This redirect is unsafe.' ); }
                $url = $next; continue;
            }
            if ( in_array( $code, array( 401, 403 ), true ) ) { throw new RuntimeException( 'This page requires authentication or restricts access. Use a public campaign archive.' ); }
            if ( $code !== 200 ) { throw new RuntimeException( 'The newsletter or image is unavailable. Check the URL and retry.' ); }
            $body = wp_remote_retrieve_body( $response );
            if ( strlen( $body ) > $limit || (int) wp_remote_retrieve_header( $response, 'content-length' ) > $limit ) { throw new RuntimeException( 'This newsletter or image exceeds supported limits.' ); }
            $mime = strtolower( trim( explode( ';', wp_remote_retrieve_header( $response, 'content-type' ) )[0] ) );
            if ( ! $image && ! in_array( $mime, array( 'text/html', 'application/xhtml+xml' ), true ) ) { throw new RuntimeException( 'This URL does not return a newsletter HTML page.' ); }
            if ( $image && ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) ) { throw new RuntimeException( 'This image has an unsupported or unsafe file type.' ); }
            return array( 'body' => $body, 'url' => $url, 'mime' => $mime );
        }
        throw new RuntimeException( 'Too many redirects. Use the final public campaign URL.' );
    }
    public static function preview( string $url ): array {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $response = self::fetch( $url, true );
        $size = @getimagesizefromstring( $response['body'] );
        if ( ! $size || $size['mime'] !== $response['mime'] || $size[0] * $size[1] > 40000000 ) { throw new RuntimeException( 'This image failed validation or exceeds the preview limits.' ); }
        if ( strlen( $response['body'] ) <= 1048576 && max( $size[0], $size[1] ) <= 1000 ) { return array( 'image' => 'data:' . $size['mime'] . ';base64,' . base64_encode( $response['body'] ) ); }
        $temp = wp_tempnam( 'letterlift-preview' );
        if ( ! $temp ) { throw new RuntimeException( 'An image preview could not be prepared.' ); }
        $output = '';
        try {
            if ( file_put_contents( $temp, $response['body'] ) !== strlen( $response['body'] ) ) { throw new RuntimeException( 'An image preview could not be saved.' ); }
            $editor = wp_get_image_editor( $temp );
            if ( is_wp_error( $editor ) ) { throw new RuntimeException( 'This image cannot be previewed on this server.' ); }
            $resized = $editor->resize( 800, 800, false );
            if ( is_wp_error( $resized ) ) { throw new RuntimeException( 'This image could not be resized for preview.' ); }
            $result = $editor->save( $temp . '-preview.jpg', 'image/jpeg' );
            if ( is_wp_error( $result ) ) { throw new RuntimeException( 'The image preview could not be generated.' ); }
            $output = $result['path'];
            if ( filesize( $output ) > 1048576 ) { throw new RuntimeException( 'This image preview exceeds supported limits.' ); }
            return array( 'image' => 'data:image/jpeg;base64,' . base64_encode( file_get_contents( $output ) ) );
        } finally { if ( is_file( $temp ) ) { wp_delete_file( $temp ); } if ( $output && is_file( $output ) ) { wp_delete_file( $output ); } }
    }
    public static function identity( string $url ): string {
        $p = wp_parse_url( $url ); parse_str( $p['query'] ?? '', $q );
        // Mailchimp campaign identity excludes tracking query parameters.
        $identity = strtolower( $p['host'] ) . ( $p['path'] ?? '/' );
        if ( isset( $q['u'], $q['id'] ) ) { $identity .= '?u=' . $q['u'] . '&id=' . $q['id']; }
        return hash( 'sha256', $identity );
    }
}

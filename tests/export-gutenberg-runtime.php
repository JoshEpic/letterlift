<?php
/** Read-only export of official WordPress JS dependency paths for the Node validity suite. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || wp_get_environment_type() !== 'local' ) { throw new RuntimeException( 'Use a disposable local WordPress installation.' ); }
$scripts = wp_scripts(); $scripts->all_deps( array( 'wp-block-library', 'wp-format-library' ) ); $files = array();
foreach ( $scripts->to_do as $handle ) {
    $script = $scripts->registered[ $handle ]; if ( ! $script->src ) { continue; }
    $path = ABSPATH . ltrim( wp_parse_url( $script->src, PHP_URL_PATH ), '/' );
    if ( ! is_file( $path ) ) { throw new RuntimeException( 'Expected a locally installed core script: ' . $handle ); }
    $files[] = array( 'handle' => $handle, 'path' => $path );
}
echo wp_json_encode( array( 'wordpress' => get_bloginfo( 'version' ), 'scripts' => $files ), JSON_UNESCAPED_SLASHES );

<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Letterlift_Plan {
    public static function defaults(): array {
        return wp_parse_args( get_option( 'letterlift_settings', array() ), array( 'category' => (int) get_option( 'default_category', 1 ), 'author' => 0, 'mode' => 'single', 'featured' => true, 'exclude_boilerplate' => false, 'retention_days' => 90 ) );
    }
    public static function initial( array $analysis ): array {
        $defaults = self::defaults(); $drafts = array(); $selection = array(); $assignments = array();
        foreach ( $analysis['sections'] as $i => $s ) { $id = 'd' . ( $i + 1 ); $drafts[] = self::draft( $id, $s['title'], $defaults ); foreach ( $s['blocks'] as $b ) { $assignments[ $b ] = $id; } }
        foreach ( $analysis['blocks'] as $b ) { $has_high_warning = (bool) array_filter( $b['warnings'], static fn( $w ) => $w['severity'] === 'high' ); $selection[ $b['id'] ] = ! ( $defaults['exclude_boilerplate'] && $b['suggested_exclusion'] && ! $has_high_warning ); }
        $single = self::draft( 'single', $analysis['title'], $defaults );
        if ( $defaults['featured'] ) {
            foreach ( $drafts as &$d ) { foreach ( $analysis['blocks'] as $b ) { if ( $assignments[ $b['id'] ] === $d['id'] && $selection[ $b['id'] ] && $b['images'] ) { $d['featured'] = $b['images'][0]; break; } } } unset( $d );
            foreach ( $analysis['blocks'] as $b ) { if ( $selection[ $b['id'] ] && $b['images'] ) { $single['featured'] = $b['images'][0]; break; } }
        }
        return array( 'mode' => $defaults['mode'], 'single' => $single, 'drafts' => $drafts, 'selection' => $selection, 'assignments' => $assignments, 'order' => array_column( $analysis['blocks'], 'id' ), 'section_titles' => array_column( $analysis['sections'], 'title', 'id' ), 'acknowledged' => array() );
    }
    private static function draft( string $id, string $title, array $defaults ): array {
        return array( 'id' => $id, 'title' => $title, 'slug' => '', 'excerpt' => '', 'category' => (int) $defaults['category'], 'author' => (int) ( $defaults['author'] ?: get_current_user_id() ), 'date' => '', 'featured' => '' );
    }
    public static function destinations( array $p ): array { return $p['mode'] === 'single' ? array( $p['single'] ) : $p['drafts']; }
    public static function blocks( array $analysis, array $p, string $draft ): array {
        $by_id = array_column( $analysis['blocks'], null, 'id' ); $result = array();
        foreach ( $p['order'] as $id ) { if ( ! empty( $p['selection'][ $id ] ) && ( $p['mode'] === 'single' || ( $p['assignments'][ $id ] ?? '' ) === $draft ) ) { $result[] = $by_id[ $id ]; } }
        return $result;
    }
    public static function clean( array $a, array $p ): array {
        $ids = array_column( $a['blocks'], 'id' );
        if ( ! isset( $p['mode'], $p['single'], $p['drafts'], $p['order'], $p['selection'], $p['assignments'] ) || ! in_array( $p['mode'], array( 'single', 'multiple' ), true ) || ! is_array( $p['drafts'] ) || count( $p['drafts'] ) > 50 ) { throw new RuntimeException( 'Invalid import plan. Reload the import.' ); }
        $order = array_values( $p['order'] ); $sorted = $order; sort( $sorted ); $expected = $ids; sort( $expected );
        if ( $sorted !== $expected ) { throw new RuntimeException( 'The plan must retain every source block exactly once.' ); }
        $draft_ids = array(); $image_ids = array_column( $a['images'], 'id' );
        $clean_draft = static function ( $d, bool $single = false ) use ( &$draft_ids, $image_ids ) {
            if ( ! is_array( $d ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,60}$/D', $d['id'] ?? '' ) || ( $single ? $d['id'] !== 'single' : ( $d['id'] === 'single' || in_array( $d['id'], $draft_ids, true ) ) ) ) { throw new RuntimeException( 'Each destination must have a unique identifier.' ); }
            $draft_ids[] = $d['id']; $author = absint( $d['author'] ?? 0 ); $category = absint( $d['category'] ?? 0 );
            if ( ! user_can( $author, 'edit_posts' ) || ( $author !== get_current_user_id() && ! current_user_can( 'edit_others_posts' ) ) ) { throw new RuntimeException( 'Choose an authorized post author.' ); }
            if ( ! term_exists( $category, 'category' ) ) { throw new RuntimeException( 'Choose an existing category.' ); }
            $date = sanitize_text_field( $d['date'] ?? '' );
            if ( $date && ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $date ) || ! DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $date ) || DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $date )->format( 'Y-m-d\TH:i' ) !== $date ) ) { throw new RuntimeException( 'Enter a valid publication date.' ); }
            $featured = (string) ( $d['featured'] ?? '' );
            if ( $featured && ! in_array( $featured, $image_ids, true ) ) { throw new RuntimeException( 'Choose a featured image from this newsletter.' ); }
            return array( 'id' => $d['id'], 'title' => mb_substr( sanitize_text_field( $d['title'] ?? '' ), 0, 300 ), 'slug' => sanitize_title( $d['slug'] ?? '' ), 'excerpt' => mb_substr( sanitize_textarea_field( $d['excerpt'] ?? '' ), 0, 5000 ), 'author' => $author, 'category' => $category, 'date' => $date, 'featured' => $featured );
        };
        $single = $clean_draft( $p['single'], true ); $drafts = array_map( $clean_draft, $p['drafts'] ); $selection = $assignments = array();
        foreach ( $ids as $id ) {
            $selection[ $id ] = ! empty( $p['selection'][ $id ] ); $assignment = (string) ( $p['assignments'][ $id ] ?? '' );
            if ( $assignment && ! in_array( $assignment, array_column( $drafts, 'id' ), true ) ) { throw new RuntimeException( 'A block refers to a removed destination. Move it to a draft or to Unassigned.' ); }
            $assignments[ $id ] = $assignment;
        }
        $titles = array(); foreach ( $a['sections'] as $s ) { $titles[ $s['id'] ] = sanitize_text_field( $p['section_titles'][ $s['id'] ] ?? $s['title'] ); }
        $warning_ids = array_column( $a['warnings'], 'id' );
        return array( 'mode' => $p['mode'], 'single' => $single, 'drafts' => $drafts, 'order' => $order, 'selection' => $selection, 'assignments' => $assignments, 'section_titles' => $titles, 'acknowledged' => array_values( array_intersect( (array) ( $p['acknowledged'] ?? array() ), $warning_ids ) ) );
    }
    public static function validate( array $a, array $p ): void {
        $drafts = self::destinations( $p ); if ( ! $drafts ) { throw new RuntimeException( 'Create at least one destination draft.' ); }
        foreach ( $a['blocks'] as $b ) { if ( $p['mode'] === 'multiple' && $p['selection'][ $b['id'] ] && empty( $p['assignments'][ $b['id'] ] ) ) { throw new RuntimeException( 'Assign all selected blocks to a draft before importing.' ); } }
        foreach ( $drafts as $d ) {
            $blocks = self::blocks( $a, $p, $d['id'] );
            $meaningful = array_filter( $blocks, static fn( $b ) => $b['text'] !== '' || $b['images'] );
            if ( ! trim( $d['title'] ) || ! $meaningful ) { throw new RuntimeException( 'Every draft needs a title and at least one meaningful selected block. Remove empty destinations.' ); }
            if ( $d['featured'] && ! in_array( $d['featured'], array_merge( array(), ...array_column( $blocks, 'images' ) ), true ) ) { throw new RuntimeException( 'A featured image must belong to included content in its destination.' ); }
        }
        foreach ( $a['warnings'] as $w ) {
            // Excluding an affected block is an explicit, persisted resolution.
            if ( $w['severity'] === 'high' && ( $w['id'] === 'source-integrity' || ! empty( $p['selection'][ $w['id'] ] ) ) && ! in_array( $w['id'], $p['acknowledged'], true ) ) { throw new RuntimeException( 'Resolve the content integrity warnings before creating drafts.' ); }
        }
    }
    public static function content( array $blocks, array $images = array(), string $version = Letterlift_Serializer::CURRENT ): string {
        return Letterlift_Serializer::generate( $blocks, $images, $version )['content'];
    }
}

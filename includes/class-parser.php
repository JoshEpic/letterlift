<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Mailchimp adapter. Layout tables are traversed; data tables remain a single reviewable block. */
final class Letterlift_Parser {
    private array $blocks = array();
    private array $sections = array();
    private array $images = array();
    private string $base = '';
    private DOMDocument $dom;
    public static function allowed(): array {
        return array(
            'p' => array(), 'br' => array(), 'strong' => array(), 'b' => array(), 'em' => array(), 'i' => array(), 'u' => array(), 's' => array(),
            'a' => array( 'href' => true, 'title' => true ), 'h1' => array(), 'h2' => array(), 'h3' => array(), 'h4' => array(), 'h5' => array(), 'h6' => array(),
            'ul' => array(), 'ol' => array( 'start' => true ), 'li' => array(), 'blockquote' => array(), 'cite' => array(), 'hr' => array(),
            'img' => array( 'src' => true, 'alt' => true, 'width' => true, 'height' => true ), 'figure' => array(), 'figcaption' => array(),
            'table' => array(), 'thead' => array(), 'tbody' => array(), 'tfoot' => array(), 'tr' => array(), 'td' => array( 'colspan' => true, 'rowspan' => true ), 'th' => array( 'scope' => true, 'colspan' => true, 'rowspan' => true ),
            'pre' => array(), 'code' => array(), 'sup' => array(), 'sub' => array(), 'dl' => array(), 'dt' => array(), 'dd' => array(),
        );
    }
    public static function text( string $html ): string {
        return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ?? '' );
    }
    public function analyze( string $html, string $base ): array {
        if ( ! class_exists( 'DOMDocument' ) ) { throw new RuntimeException( 'Letterlift requires the PHP DOM extension. Ask your host to enable it.' ); }
        $this->blocks = $this->sections = $this->images = array(); $this->base = $base;
        $this->dom = new DOMDocument();
        $previous = libxml_use_internal_errors( true );
        $loaded = $this->dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
        libxml_clear_errors(); libxml_use_internal_errors( $previous );
        if ( ! $loaded ) { throw new RuntimeException( 'No recognizable content. Check the original newsletter.' ); }
        $xp = new DOMXPath( $this->dom );
        if ( $xp->query( '//input[translate(@type,"PASSWORD","password")="password"]' )->length ) { throw new RuntimeException( 'This page requires authentication. Use a public campaign archive.' ); }
        $title = trim( $xp->evaluate( 'string(//title)' ) );
        // Remove executable and non-editorial metadata only; never remove a promotional content region.
        foreach ( iterator_to_array( $xp->query( '//script|//style|//head|//noscript' ) ) as $node ) { $node->parentNode->removeChild( $node ); }
        $root = $this->dom->getElementsByTagName( 'body' )->item( 0 ) ?? $this->dom->documentElement;
        $source_text = self::text( $root->textContent );
        $this->section( 'Newsletter content' ); $this->walk( $root );
        $this->sections = array_values( array_filter( $this->sections, static fn( $s ) => count( $s['blocks'] ) > 0 ) );
        if ( ! $this->blocks ) { throw new RuntimeException( 'No recognizable content. Inspect the original newsletter or try another campaign.' ); }
        $extracted = self::text( implode( ' ', array_column( $this->blocks, 'text' ) ) );
        $warnings = array();
        // Whitespace differs across layout cells; compare a whitespace-free representation as a second check.
        if ( preg_replace( '/\s+/u', '', $source_text ) !== preg_replace( '/\s+/u', '', $extracted ) ) {
            $warnings[] = array( 'id' => 'source-integrity', 'severity' => 'high', 'message' => 'The extracted text differs from the source. Compare the original and explicitly approve the reviewed selection before importing.' );
        }
        foreach ( $this->blocks as $b ) { foreach ( $b['warnings'] as $w ) { if ( $w['severity'] === 'high' ) { $warnings[] = array( 'id' => $b['id'], 'severity' => 'high', 'message' => 'Review unsupported content in block ' . $b['position'] . ' and explicitly retain its safe representation or exclude it.' ); } } }
        if ( ! $title ) { $title = $this->sections[0]['title']; }
        return array( 'title' => sanitize_text_field( $title ), 'provider' => 'Mailchimp', 'sections' => $this->sections, 'blocks' => $this->blocks, 'images' => array_values( $this->images ), 'warnings' => $warnings, 'confidence' => $warnings ? 'Some content needs attention' : 'Ready for review', 'fingerprint' => hash( 'sha256', $html ), 'text_fingerprint' => hash( 'sha256', $source_text ), 'story_boundaries' => max( 0, count( $this->sections ) - 1 ) );
    }
    private function section( string $title ): void {
        $this->sections[] = array( 'id' => 's' . ( count( $this->sections ) + 1 ), 'title' => $title ?: 'Untitled section', 'type' => 'Suggested story', 'position' => count( $this->sections ) + 1, 'blocks' => array() );
    }
    private function walk( DOMNode $node, int $depth = 0 ): void {
        if ( $depth > 100 ) { throw new RuntimeException( 'This newsletter has a structure that exceeds supported limits.' ); }
        if ( $node instanceof DOMText ) {
            if ( trim( $node->textContent ) ) { $this->add( '<p>' . esc_html( $node->textContent ) . '</p>', 'paragraph' ); }
            return;
        }
        if ( ! ( $node instanceof DOMElement ) ) { return; }
        $tag = strtolower( $node->tagName );
        if ( preg_match( '/^h[1-6]$/', $tag ) ) {
            if ( count( $this->sections ) < 50 && $this->sections[ count( $this->sections ) - 1 ]['blocks'] ) { $this->section( self::text( $node->textContent ) ); }
            else { $this->sections[ count( $this->sections ) - 1 ]['title'] = self::text( $node->textContent ); }
            $this->add( $this->dom->saveHTML( $node ), 'heading' ); return;
        }
        $types = array( 'p' => 'paragraph', 'img' => 'image', 'figure' => 'image', 'figcaption' => 'caption', 'ul' => 'list', 'ol' => 'list', 'blockquote' => 'quote', 'hr' => 'divider', 'pre' => 'structured', 'dl' => 'structured' );
        if ( isset( $types[ $tag ] ) ) { $this->add( $this->dom->saveHTML( $node ), $types[ $tag ] ); return; }
        $looks_like_data = $tag === 'table' && $node->getAttribute( 'role' ) !== 'presentation' && $node->getElementsByTagName( 'table' )->length === 0 && $node->getElementsByTagName( 'tr' )->length >= 2 && $node->getElementsByTagName( 'tr' )->item( 0 )->getElementsByTagName( 'td' )->length >= 2;
        if ( $tag === 'table' && ( $looks_like_data || $node->getElementsByTagName( 'th' )->length || $node->getAttribute( 'role' ) === 'table' ) ) {
            $this->add( $this->dom->saveHTML( $node ), 'table', array( array( 'severity' => 'review', 'message' => 'This table may need formatting review.' ) ) ); return;
        }
        if ( in_array( $tag, array( 'iframe', 'object', 'embed', 'video', 'audio', 'svg', 'canvas', 'form' ), true ) ) {
            $fallback = '<p>' . esc_html( $node->textContent ?: 'Unsupported ' . $tag . ' region — inspect the original newsletter.' ) . '</p>';
            $this->add( $fallback, 'unsupported', array( array( 'severity' => 'high', 'message' => 'Unsupported ' . $tag . ' region. Only the safe text representation can be imported.' ) ) ); return;
        }
        // Keep inline phrasing together within legacy table cells, divs, and spans.
        $buffer = '';
        foreach ( $node->childNodes as $child ) {
            $block = $child instanceof DOMElement && in_array( strtolower( $child->tagName ), array( 'p','div','table','tr','td','th','tbody','thead','tfoot','h1','h2','h3','h4','h5','h6','ul','ol','blockquote','figure','figcaption','hr','section','article','header','footer','main','pre','dl','iframe','object','embed','video','audio','svg','canvas','form' ), true );
            if ( $block ) {
                if ( self::text( $buffer ) || stripos( $buffer, '<img' ) !== false ) { $this->add( '<p>' . $buffer . '</p>', 'paragraph' ); } $buffer = '';
                $this->walk( $child, $depth + 1 );
            } else { $buffer .= $this->dom->saveHTML( $child ); }
        }
        if ( self::text( $buffer ) || stripos( $buffer, '<img' ) !== false ) { $this->add( '<p>' . $buffer . '</p>', stripos( $buffer, '<img' ) !== false ? 'image' : 'paragraph' ); }
    }
    private function add( string $html, string $type, array $warnings = array() ): void {
        if ( count( $this->blocks ) >= 500 ) { throw new RuntimeException( 'This newsletter exceeds the limit of 500 content blocks.' ); }
        $doc = new DOMDocument();
        @$doc->loadHTML( '<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
        $xpath = new DOMXPath( $doc );
        if ( $xpath->query( '//iframe|//object|//embed|//video|//audio|//svg|//canvas|//form' )->length && ! $warnings ) {
            $warnings[] = array( 'severity' => 'high', 'message' => 'This block contains unsupported embedded content. Inspect the original and approve the safe representation or exclude this block.' );
        }
        $block_images = array();
        foreach ( $doc->getElementsByTagName( 'a' ) as $a ) {
            if ( $a->hasAttribute( 'href' ) ) { $a->setAttribute( 'href', Letterlift_Remote::absolute( $a->getAttribute( 'href' ), $this->base ) ); }
        }
        foreach ( $doc->getElementsByTagName( 'img' ) as $img ) {
            $src = Letterlift_Remote::absolute( $img->getAttribute( 'src' ), $this->base );
            $id = 'i' . substr( hash( 'sha256', $src ), 0, 16 );
            if ( count( $this->images ) >= 100 && ! isset( $this->images[ $id ] ) ) { throw new RuntimeException( 'This newsletter exceeds the limit of 100 images.' ); }
            if ( ! preg_match( '~^https?://~i', $src ) ) { $warnings[] = array( 'severity' => 'high', 'message' => 'This image has an unsupported source. Exclude the block or explicitly retain its safe fallback.' ); $img->parentNode->replaceChild( $doc->createTextNode( '[Image unavailable: ' . $img->getAttribute( 'alt' ) . ']' ), $img ); continue; }
            $img->setAttribute( 'src', $src );
            $this->images[ $id ] = array( 'id' => $id, 'url' => $src, 'alt' => sanitize_text_field( $img->getAttribute( 'alt' ) ) ); $block_images[] = $id;
        }
        $body = $doc->getElementsByTagName( 'body' )->item( 0 ); $normalized = '';
        foreach ( $body->childNodes as $child ) { $normalized .= $doc->saveHTML( $child ); }
        $safe = wp_kses( $normalized, self::allowed() );
        $text = self::text( $safe );
        $links = $doc->getElementsByTagName( 'a' );
        if ( $type === 'paragraph' && $links->length === 1 && $text !== '' && self::text( $links->item( 0 )->textContent ) === $text ) { $type = 'button'; }
        if ( self::text( $normalized ) !== $text && ! $warnings ) {
            $warnings[] = array( 'severity' => 'high', 'message' => 'Sanitization changed this block’s text. Inspect the original and approve the safe representation or exclude it.' );
        }
        if ( ! $text && ! $block_images && $type !== 'divider' ) { return; }
        $position = count( $this->blocks ) + 1; $id = 'b' . $position; $index = count( $this->sections ) - 1;
        $boilerplate = (bool) preg_match( '~(unsubscribe|update (your )?(email )?preferences|view (this email|in (your )?browser)|manage (your )?subscription)~i', $text );
        $this->blocks[] = array( 'id' => $id, 'section' => $this->sections[ $index ]['id'], 'position' => $position, 'type' => $type, 'html' => $safe, 'text' => $text, 'images' => $block_images, 'suggested_exclusion' => $boilerplate, 'warnings' => $warnings );
        $this->sections[ $index ]['blocks'][] = $id;
    }
}

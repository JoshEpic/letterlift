<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Versioned translation from the normalized editorial model to WordPress core blocks. */
final class Letterlift_Serializer {
    public const LEGACY = 'html-v1';
    public const CURRENT = 'gutenberg-v2';
    private array $media;
    private array $warnings = array();
    private string $source_id = '';
    private int $source_position = 0;

    private function __construct( array $media ) { $this->media = $media; }

    public static function version( array $session ): string {
        // Absence means an execution was approved before versioning existed. Never upgrade it.
        return isset( $session['execution'] ) ? ( $session['execution']['serializer_version'] ?? self::LEGACY ) : self::CURRENT;
    }

    public static function generate( array $blocks, array $media = array(), string $version = self::CURRENT ): array {
        if ( $version === self::LEGACY ) { return array( 'content' => self::legacy( $blocks, $media ), 'warnings' => array(), 'version' => $version ); }
        if ( $version !== self::CURRENT ) { throw new RuntimeException( 'This import needs a serializer version unavailable in this installation. Restore the compatible Letterlift version to resume it.' ); }
        $serializer = new self( $media );
        $output = array(); $previous = null;
        foreach ( $blocks as $block ) {
            $serializer->source_id = $block['id']; $serializer->source_position = (int) $block['position'];
            $safe = self::safe( $block['html'] );
            $body = self::document( $safe );
            $converted = $block['type'] === 'unsupported' ? array( $serializer->fallback( $safe, 'This extracted region contains unsupported source content.' ) ) : $serializer->children( $body, 0, $block );
            $serialized = serialize_blocks( $converted );
            // Native conversion must preserve visible text, links, image references/alt text and order.
            // If not, preserve the entire safe region; do not flatten or silently omit it.
            if ( self::signature( $safe, $media ) !== self::signature( $serialized ) ) {
                $converted = array( $serializer->fallback( $safe, 'Native conversion could not preserve this region exactly.' ) );
            }
            $last = count( $output ) - 1;
            // Associate only explicitly typed adjacent captions from the same source section.
            // Italic paragraphs are never guessed to be captions.
            if ( $block['type'] === 'caption' && $previous && $previous['section'] === $block['section'] && $previous['position'] + 1 === $block['position'] && $last >= 0 && $output[ $last ]['blockName'] === 'core/image' && ! str_contains( $output[ $last ]['innerHTML'], '<figcaption' ) && count( $converted ) === 1 && $converted[0]['blockName'] === 'core/paragraph' ) {
                $caption = $serializer->inline( $body );
                if ( $caption !== null ) {
                    $html = str_replace( '</figure>', '<figcaption class="wp-element-caption">' . $caption . '</figcaption></figure>', $output[ $last ]['innerHTML'] );
                    $output[ $last ] = self::leaf( 'core/image', $html, $output[ $last ]['attrs'] );
                } else { array_push( $output, ...$converted ); }
            } else { array_push( $output, ...$converted ); }
            $previous = $block;
        }
        $content = serialize_blocks( $output );
        // PHP parsing is a structural check only. JS save()/editor validation is tested separately.
        if ( serialize_blocks( parse_blocks( $content ) ) !== $content ) { throw new RuntimeException( 'The generated WordPress blocks failed structural validation. Review this import before retrying.' ); }
        return array( 'content' => $content, 'warnings' => array_values( $serializer->warnings ), 'version' => $version );
    }

    public static function preview( array $session ): array {
        $plan = $session['execution']['snapshot'] ?? $session['plan'];
        $version = self::version( $session ); $drafts = array();
        foreach ( Letterlift_Plan::destinations( $plan ) as $draft ) {
            $result = self::generate( Letterlift_Plan::blocks( $session['analysis'], $plan, $draft['id'] ), array(), $version );
            $html = '';
            foreach ( parse_blocks( $result['content'] ) as $block ) {
                self::check_tree( $block );
                $html .= render_block( $block );
            }
            $allowed = Letterlift_Parser::allowed();
            foreach ( $allowed as &$attributes ) { $attributes['class'] = true; } unset( $attributes );
            $allowed['div'] = array( 'class' => true );
            $drafts[ $draft['id'] ] = array( 'content' => $result['content'], 'html' => wp_kses( $html, $allowed ), 'warnings' => $result['warnings'] );
        }
        return array( 'revision' => $session['revision'], 'serializer_version' => $version, 'drafts' => $drafts );
    }

    private static function check_tree( array $block ): void {
        $allowed = array( 'core/paragraph', 'core/heading', 'core/image', 'core/list', 'core/list-item', 'core/quote', 'core/separator', 'core/table', 'core/buttons', 'core/button', 'core/html' );
        if ( $block['blockName'] === null && trim( $block['innerHTML'] ) === '' ) { return; }
        if ( ! in_array( $block['blockName'], $allowed, true ) ) { throw new RuntimeException( 'This saved import contains an unsupported block structure. Review the original source before continuing.' ); }
        foreach ( $block['innerBlocks'] as $child ) { self::check_tree( $child ); }
    }

    private static function legacy( array $blocks, array $media ): string {
        // Preserve byte-for-byte output of the original serializer for approved recovery operations.
        $html = '';
        foreach ( $blocks as $b ) {
            $part = $b['html'];
            foreach ( $media as $src => $local ) { $part = str_replace( esc_attr( $src ), esc_attr( is_array( $local ) ? $local['url'] : $local ), $part ); }
            $html .= "<!-- wp:html -->\n" . wp_kses( $part, Letterlift_Parser::allowed() ) . "\n<!-- /wp:html -->\n";
        }
        return $html;
    }

    private static function safe( string $html ): string {
        // Source comments must never inject Gutenberg delimiters into a generated block tree.
        return wp_kses( preg_replace( '/<!--.*?-->/s', '', $html ) ?? '', Letterlift_Parser::allowed() );
    }

    private static function document( string $html ): DOMElement {
        $doc = new DOMDocument(); $old = libxml_use_internal_errors( true );
        $doc->loadHTML( '<?xml encoding="utf-8" ?><html><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
        libxml_clear_errors(); libxml_use_internal_errors( $old );
        return $doc->getElementsByTagName( 'body' )->item( 0 );
    }

    /** A fidelity signature ignores layout whitespace, but preserves all text and asset/link order. */
    private static function signature( string $html, array $media = array() ): array {
        $body = self::document( $html ); $links = $images = array();
        foreach ( $body->getElementsByTagName( 'a' ) as $a ) { $href = $a->getAttribute( 'href' ); $local = $media[ $href ] ?? $href; $links[] = array( is_array( $local ) ? $local['url'] : $local, $a->getAttribute( 'title' ) ); }
        foreach ( $body->getElementsByTagName( 'img' ) as $img ) { $src = $img->getAttribute( 'src' ); $local = $media[ $src ] ?? $src; $images[] = array( is_array( $local ) ? $local['url'] : $local, $img->getAttribute( 'alt' ) ); }
        $formatting = array();
        foreach ( $body->getElementsByTagName( '*' ) as $element ) {
            $tag = array( 'b' => 'strong', 'i' => 'em' )[ $element->tagName ] ?? $element->tagName;
            if ( in_array( $tag, array( 'strong', 'em', 'u', 's', 'sup', 'sub', 'code', 'br' ), true ) ) { $formatting[] = array( $tag, preg_replace( '/\s+/u', '', $element->textContent ) ); }
        }
        return array( preg_replace( '/\s+/u', '', $body->textContent ), $links, $images, $formatting );
    }

    private static function leaf( string $name, string $html, array $attrs = array() ): array {
        return array( 'blockName' => $name, 'attrs' => $attrs, 'innerBlocks' => array(), 'innerHTML' => $html, 'innerContent' => array( $html ) );
    }

    private static function container( string $name, string $open, string $close, array $children, array $attrs = array() ): array {
        $chunks = array( $open );
        foreach ( $children as $i => $child ) { if ( $i ) { $chunks[] = "\n\n"; } $chunks[] = null; }
        $chunks[] = $close;
        return array( 'blockName' => $name, 'attrs' => $attrs, 'innerBlocks' => $children, 'innerHTML' => implode( '', array_filter( $chunks, 'is_string' ) ), 'innerContent' => $chunks );
    }

    private function fallback( string $html, string $reason ): array {
        $this->warnings[ $this->source_id ] = array( 'id' => 'serialization-' . $this->source_id, 'block_id' => $this->source_id, 'severity' => 'review', 'message' => 'Original block ' . $this->source_position . ': ' . $reason . ' Its sanitized markup is preserved in a Custom HTML block; inspect it against the original.' );
        $body = self::document( self::safe( $html ) );
        foreach ( $body->getElementsByTagName( 'img' ) as $img ) { $src = $img->getAttribute( 'src' ); if ( isset( $this->media[ $src ] ) ) { $local = $this->media[ $src ]; $img->setAttribute( 'src', is_array( $local ) ? $local['url'] : $local ); } }
        foreach ( $body->getElementsByTagName( 'a' ) as $a ) { $href = $a->getAttribute( 'href' ); if ( isset( $this->media[ $href ] ) ) { $local = $this->media[ $href ]; $a->setAttribute( 'href', is_array( $local ) ? $local['url'] : $local ); } }
        return self::leaf( 'core/html', self::inner( $body ) );
    }

    private static function inner( DOMNode $node ): string {
        $html = ''; foreach ( $node->childNodes as $child ) { $html .= $node->ownerDocument->saveHTML( $child ); } return $html;
    }

    /** RichText-safe phrasing. Unsupported structures return null so they are preserved as HTML. */
    private function inline( DOMNode $node ): ?string {
        $html = '';
        foreach ( $node->childNodes as $child ) {
            if ( $child instanceof DOMText ) { $html .= esc_html( $child->textContent ); continue; }
            if ( ! ( $child instanceof DOMElement ) ) { continue; }
            $tag = strtolower( $child->tagName );
            if ( in_array( $tag, array( 'p', 'figcaption', 'cite' ), true ) && $node->nodeName === 'body' && $node->childNodes->length === 1 ) { $nested = $this->inline( $child ); if ( $nested === null ) { return null; } $html .= $nested; continue; }
            if ( ! in_array( $tag, array( 'strong', 'b', 'em', 'i', 'u', 's', 'sup', 'sub', 'code', 'a', 'br' ), true ) ) { return null; }
            if ( $tag === 'br' ) { $html .= '<br>'; continue; }
            $content = $this->inline( $child ); if ( $content === null ) { return null; }
            $tag = array( 'b' => 'strong', 'i' => 'em' )[ $tag ] ?? $tag;
            $attrs = '';
            if ( $tag === 'a' ) {
                foreach ( array( 'href', 'title' ) as $attr ) { if ( $child->hasAttribute( $attr ) ) { $value = $child->getAttribute( $attr ); if ( $attr === 'href' && isset( $this->media[ $value ] ) ) { $local = $this->media[ $value ]; $value = is_array( $local ) ? $local['url'] : $local; } $attrs .= ' ' . $attr . '="' . esc_attr( $value ) . '"'; } }
            }
            $html .= '<' . $tag . $attrs . '>' . $content . '</' . $tag . '>';
        }
        return $html;
    }

    private function children( DOMNode $node, int $depth, array $model = array() ): array {
        if ( $depth > 80 ) { return array( $this->fallback( $node->ownerDocument->saveHTML( $node ), 'This deeply nested structure needs formatting review.' ) ); }
        $output = array(); $phrasing = '';
        foreach ( $node->childNodes as $child ) {
            if ( $child instanceof DOMText || ( $child instanceof DOMElement && in_array( strtolower( $child->tagName ), array( 'strong','b','em','i','u','s','sup','sub','code','a','br' ), true ) && ! $child->getElementsByTagName( 'img' )->length ) ) {
                $phrasing .= $node->ownerDocument->saveHTML( $child ); continue;
            }
            if ( trim( $phrasing ) ) { $output[] = self::leaf( 'core/paragraph', '<p>' . ( $this->inline( self::document( $phrasing ) ) ?? $phrasing ) . '</p>' ); } $phrasing = '';
            if ( ! ( $child instanceof DOMElement ) ) { continue; }
            $converted = $this->element( $child, $depth + 1, $model );
            array_push( $output, ...$converted );
        }
        if ( trim( $phrasing ) ) { $output[] = self::leaf( 'core/paragraph', '<p>' . ( $this->inline( self::document( $phrasing ) ) ?? $phrasing ) . '</p>' ); }
        return $output;
    }

    private function element( DOMElement $node, int $depth, array $model ): array {
        $tag = strtolower( $node->tagName ); $inline = $this->inline( $node ); $block = null;
        if ( preg_match( '/^h([1-6])$/D', $tag, $m ) || ( ( $model['type'] ?? '' ) === 'heading' && $tag === 'p' ) ) {
            // Explicit normalized metadata only; never infer a heading from bold styling.
            $level = isset( $m[1] ) ? (int) $m[1] : max( 1, min( 6, (int) ( $model['level'] ?? 2 ) ) );
            if ( $inline !== null ) { $block = self::leaf( 'core/heading', '<h' . $level . ' class="wp-block-heading">' . $inline . '</h' . $level . '>', array( 'level' => $level ) ); }
        } elseif ( ( $model['type'] ?? '' ) === 'button' && $tag === 'p' && $node->getElementsByTagName( 'a' )->length === 1 && ! $node->getElementsByTagName( 'img' )->length ) {
            $link = $node->getElementsByTagName( 'a' )->item( 0 ); $sole_link = true;
            foreach ( $node->childNodes as $child ) { if ( $child !== $link && ! ( $child instanceof DOMText && trim( $child->textContent ) === '' ) ) { $sole_link = false; } }
            if ( $sole_link && $link->hasAttribute( 'href' ) && $this->inline( $link ) !== null && trim( $node->textContent ) === trim( $link->textContent ) ) {
                $attributes = ' href="' . esc_attr( $link->getAttribute( 'href' ) ) . '"';
                if ( $link->hasAttribute( 'title' ) ) { $attributes .= ' title="' . esc_attr( $link->getAttribute( 'title' ) ) . '"'; }
                $button = self::leaf( 'core/button', '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button"' . $attributes . '>' . $this->inline( $link ) . '</a></div>' );
                $block = self::container( 'core/buttons', '<div class="wp-block-buttons">', '</div>', array( $button ) );
            } elseif ( $inline !== null ) { $block = self::leaf( 'core/paragraph', '<p>' . $inline . '</p>' ); }
        } elseif ( in_array( $tag, array( 'p', 'figcaption' ), true ) ) {
            if ( $inline !== null ) { $block = self::leaf( 'core/paragraph', '<p>' . $inline . '</p>' ); }
            elseif ( $node->getElementsByTagName( 'img' )->length && trim( $node->textContent ) === '' ) { return $this->children( $node, $depth, $model ); }
        } elseif ( $tag === 'img' || $tag === 'figure' || ( $tag === 'a' && $node->getElementsByTagName( 'img' )->length ) ) {
            $block = $this->image( $node );
        } elseif ( $tag === 'ul' || $tag === 'ol' ) { $block = $this->list_block( $node, $depth );
        } elseif ( $tag === 'blockquote' ) { $block = $this->quote( $node, $depth );
        } elseif ( $tag === 'hr' ) { $block = self::leaf( 'core/separator', '<hr class="wp-block-separator has-alpha-channel-opacity"/>' );
        } elseif ( $tag === 'table' ) { $block = $this->table( $node ); }
        if ( $block ) { return array( $block ); }
        return array( $this->fallback( $node->ownerDocument->saveHTML( $node ), 'This structure cannot be represented safely with a native block.' ) );
    }

    private function image( DOMElement $node ): ?array {
        $images = $node->tagName === 'img' ? array( $node ) : iterator_to_array( $node->getElementsByTagName( 'img' ) );
        if ( count( $images ) !== 1 ) { return null; }
        $img = $images[0]; $caption = ''; $link = null;
        if ( $node->tagName !== 'img' ) {
            $captions = $node->getElementsByTagName( 'figcaption' ); if ( $captions->length > 1 ) { return null; }
            if ( $captions->length ) { $caption = $this->inline( $captions->item( 0 ) ); if ( $caption === null ) { return null; } }
            // No additional editorial text may be swallowed by image conversion.
            if ( preg_replace( '/\s+/u', '', $node->textContent ) !== preg_replace( '/\s+/u', '', html_entity_decode( strip_tags( $caption ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) { return null; }
        }
        if ( $img->parentNode instanceof DOMElement && $img->parentNode->tagName === 'a' ) { $link = $img->parentNode; if ( $link->hasAttribute( 'title' ) ) { return null; } }
        $src = $img->getAttribute( 'src' ); $local = $this->media[ $src ] ?? array( 'url' => $src, 'id' => 0 );
        $url = is_array( $local ) ? $local['url'] : $local; $id = is_array( $local ) ? (int) ( $local['id'] ?? 0 ) : 0;
        $attrs = array( 'sizeSlug' => 'full', 'linkDestination' => $link ? 'custom' : 'none' );
        if ( $id ) { $attrs['id'] = $id; }
        $html = '<figure class="wp-block-image size-full">';
        if ( $link ) { $href = $link->getAttribute( 'href' ); $target = $this->media[ $href ] ?? $href; $html .= '<a href="' . esc_attr( is_array( $target ) ? $target['url'] : $target ) . '"' . ( $link->hasAttribute( 'title' ) ? ' title="' . esc_attr( $link->getAttribute( 'title' ) ) . '"' : '' ) . '>'; }
        $html .= '<img src="' . esc_attr( $url ) . '" alt="' . esc_attr( $img->getAttribute( 'alt' ) ) . '"' . ( $id ? ' class="wp-image-' . $id . '"' : '' ) . '/>';
        if ( $link ) { $html .= '</a>'; }
        if ( $caption !== '' ) { $html .= '<figcaption class="wp-element-caption">' . $caption . '</figcaption>'; }
        return self::leaf( 'core/image', $html . '</figure>', $attrs );
    }

    private function list_block( DOMElement $node, int $depth ): ?array {
        if ( $depth > 80 ) { return null; }
        $items = array();
        foreach ( $node->childNodes as $li ) {
            if ( $li instanceof DOMText && trim( $li->textContent ) === '' ) { continue; }
            if ( ! ( $li instanceof DOMElement ) || $li->tagName !== 'li' || $li->hasAttribute( 'value' ) ) { return null; }
            $text = ''; $nested = array(); $after_list = false;
            foreach ( $li->childNodes as $child ) {
                if ( $child instanceof DOMElement && in_array( $child->tagName, array( 'ul', 'ol' ), true ) ) { $list = $this->list_block( $child, $depth + 1 ); if ( ! $list ) { return null; } $nested[] = $list; $after_list = true; }
                else { if ( $after_list && trim( $child->textContent ) ) { return null; } $text .= $li->ownerDocument->saveHTML( $child ); }
            }
            $inline = $this->inline( self::document( $text ) ); if ( $inline === null ) { return null; }
            $items[] = self::container( 'core/list-item', '<li>' . $inline, '</li>', $nested );
        }
        if ( ! $items ) { return null; }
        $ordered = $node->tagName === 'ol'; $attrs = array(); $extra = '';
        if ( $ordered ) { $attrs['ordered'] = true; }
        if ( $ordered && $node->hasAttribute( 'type' ) ) {
            $types = array( '1' => 'decimal', 'a' => 'lower-alpha', 'A' => 'upper-alpha', 'i' => 'lower-roman', 'I' => 'upper-roman' );
            $type = $types[ $node->getAttribute( 'type' ) ] ?? null; if ( $type === null ) { return null; }
            $attrs['type'] = $type; if ( $type !== 'decimal' ) { $extra .= ' style="list-style-type:' . $type . '"'; }
        }
        if ( $ordered && $node->hasAttribute( 'start' ) ) { $attrs['start'] = (int) $node->getAttribute( 'start' ); $extra .= ' start="' . $attrs['start'] . '"'; }
        if ( $ordered && $node->hasAttribute( 'reversed' ) ) { $attrs['reversed'] = true; $extra .= ' reversed'; }
        return self::container( 'core/list', '<' . $node->tagName . ' class="wp-block-list"' . $extra . '>', '</' . $node->tagName . '>', $items, $attrs );
    }

    private function quote( DOMElement $node, int $depth ): ?array {
        $clone = $node->cloneNode( true ); $citation = '';
        $cites = $clone->getElementsByTagName( 'cite' );
        if ( $cites->length > 1 ) { return null; }
        if ( $cites->length ) { $cite = $cites->item( 0 ); if ( $cite->parentNode !== $clone || $cite !== $clone->lastElementChild ) { return null; } $citation = $this->inline( $cite ); if ( $citation === null ) { return null; } $cite->parentNode->removeChild( $cite ); }
        $children = $this->children( $clone, $depth ); if ( ! $children ) { return null; }
        return self::container( 'core/quote', '<blockquote class="wp-block-quote">', ( $citation !== '' ? '<cite>' . $citation . '</cite>' : '' ) . '</blockquote>', $children );
    }

    private function table( DOMElement $node ): ?array {
        if ( $node->getElementsByTagName( 'table' )->length || $node->getElementsByTagName( 'img' )->length || $node->getElementsByTagName( 'caption' )->length ) { return null; }
        $parts = array( 'thead' => '', 'tbody' => '', 'tfoot' => '' );
        foreach ( $node->childNodes as $section ) {
            if ( $section instanceof DOMText && trim( $section->textContent ) === '' ) { continue; }
            if ( ! ( $section instanceof DOMElement ) ) { return null; }
            $section_tag = strtolower( $section->tagName );
            if ( ! in_array( $section_tag, array( 'thead', 'tbody', 'tfoot', 'tr' ), true ) ) { return null; }
            $rows = $section_tag === 'tr' ? array( $section ) : iterator_to_array( $section->childNodes );
            $html = '';
            foreach ( $rows as $row ) {
                if ( $row instanceof DOMText && trim( $row->textContent ) === '' ) { continue; }
                if ( ! ( $row instanceof DOMElement ) || $row->tagName !== 'tr' ) { return null; }
                $html .= '<tr>';
                foreach ( $row->childNodes as $cell ) {
                    if ( $cell instanceof DOMText && trim( $cell->textContent ) === '' ) { continue; }
                    if ( ! ( $cell instanceof DOMElement ) || ! in_array( $cell->tagName, array( 'td', 'th' ), true ) ) { return null; }
                    // Merged cells and block content are intentionally preserved as HTML for reliable editing.
                    if ( $cell->hasAttribute( 'colspan' ) || $cell->hasAttribute( 'rowspan' ) ) { return null; }
                    $inline = $this->inline( $cell ); if ( $inline === null ) { return null; }
                    $scope = $cell->tagName === 'th' && $cell->hasAttribute( 'scope' ) ? ' scope="' . esc_attr( $cell->getAttribute( 'scope' ) ) . '"' : '';
                    $html .= '<' . $cell->tagName . $scope . '>' . $inline . '</' . $cell->tagName . '>';
                }
                $html .= '</tr>';
            }
            $parts[ $section_tag === 'tr' ? 'tbody' : $section_tag ] .= $html;
        }
        $html = ''; foreach ( $parts as $tag => $rows ) { if ( $rows !== '' ) { $html .= '<' . $tag . '>' . $rows . '</' . $tag . '>'; } }
        return $html !== '' ? self::leaf( 'core/table', '<figure class="wp-block-table"><table>' . $html . '</table></figure>', array( 'hasFixedLayout' => false ) ) : null;
    }
}

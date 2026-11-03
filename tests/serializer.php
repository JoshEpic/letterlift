<?php
/** Real WordPress structural and content tests. JS validity is checked separately. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || getenv( 'LETTERLIFT_TEST_RUN' ) !== '1' || wp_get_environment_type() !== 'local' ) { throw new RuntimeException( 'Run on a disposable local WordPress site with LETTERLIFT_TEST_RUN=1.' ); }
global $ll_serializer_assertions;
$ll_serializer_assertions = 0;
function ll_native_assert( bool $ok, string $message ): void { global $ll_serializer_assertions; $ll_serializer_assertions++; if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $message ); } }
function ll_native_flat( array $blocks ): array { $all = array(); foreach ( $blocks as $b ) { if ( ! $b['blockName'] ) { continue; } $all[] = $b; array_push( $all, ...ll_native_flat( $b['innerBlocks'] ) ); } return $all; }
function ll_native_model( string $html, string $type = 'paragraph', int $position = 1 ): array { return array( 'id' => 'b' . $position, 'section' => 's1', 'position' => $position, 'type' => $type, 'html' => $html, 'text' => Letterlift_Parser::text( $html ), 'images' => array(), 'warnings' => array() ); }
$cases = array();
$examples = array(
    'paragraph' => array( '<p>Original <strong>bold</strong> and <em>italic</em> with <a href="https://example.org/?a=1&amp;b=2">a link</a>.</p>', 'paragraph', 'core/paragraph' ),
    'heading' => array( '<h3>A meaningful <em>heading</em></h3>', 'heading', 'core/heading' ),
    'unordered' => array( '<ul><li>A</li><li>B</li></ul>', 'list', 'core/list' ),
    'ordered' => array( '<ol start="4"><li>Four</li><li>Five</li></ol>', 'list', 'core/list' ),
    'nested' => array( '<ul><li>Outer <strong>text</strong><ol><li><a href="https://example.org">Inner</a></li></ol></li><li>Last</li></ul>', 'list', 'core/list' ),
    'quote' => array( '<blockquote><p>Exact <em>quotation</em>.</p><cite>An author</cite></blockquote>', 'quote', 'core/quote' ),
    'divider' => array( '<hr>', 'divider', 'core/separator' ),
    'table' => array( '<table><thead><tr><th scope="col">Year</th><th>Count</th></tr></thead><tbody><tr><td>1924</td><td><strong>12</strong></td></tr></tbody></table>', 'table', 'core/table' ),
    'button' => array( '<p><a href="https://example.org/action" title="Full story">Read <em>more</em></a></p>', 'button', 'core/buttons' ),
    'unicode' => array( '<p>東京 — café &amp; “words”, apostrophe\'s &lt;literal&gt; &nbsp; text.</p>', 'paragraph', 'core/paragraph' ),
    'definition-fallback' => array( '<dl><dt>A term</dt><dd>Its definition</dd></dl>', 'structured', 'core/html' ),
    'table-fallback' => array( '<table><tr><th colspan="2">Merged</th></tr><tr><td>A</td><td>B</td></tr></table>', 'table', 'core/html' ),
    'unsupported' => array( '<p>Reviewed safe transcript.</p>', 'unsupported', 'core/html' ),
    'inline-image-fallback' => array( '<p>Words before <img src="https://example.org/inline.png" alt="Inline"> words after.</p>', 'paragraph', 'core/html' ),
    'complex-list-fallback' => array( '<ul><li><p>A paragraph</p><p>Another paragraph</p></li></ul>', 'list', 'core/html' ),
    'code-fallback' => array( '<pre><code>if (a &lt; b) return "✓";</code></pre>', 'structured', 'core/html' ),
);
foreach ( $examples as $name => list( $html, $type, $expected ) ) {
    $model = ll_native_model( $html, $type ); $result = Letterlift_Serializer::generate( array( $model ) ); $cases[ $name ] = $result['content'];
    $blocks = array_values( array_filter( parse_blocks( $result['content'] ), static fn( $b ) => (bool) $b['blockName'] ) );
    ll_native_assert( count( $blocks ) === 1 && $blocks[0]['blockName'] === $expected, "$name uses $expected" );
    ll_native_assert( serialize_blocks( parse_blocks( $result['content'] ) ) === $result['content'], "$name round-trips through WordPress PHP parsing" );
    if ( $expected === 'core/html' ) { ll_native_assert( count( $result['warnings'] ) === 1, "$name has an explicit fallback warning" ); }
}
$heading = parse_blocks( $cases['heading'] )[0]; ll_native_assert( $heading['attrs']['level'] === 3, 'Heading level preserved.' );
$styled = ll_native_model( '<p>A known visual heading</p>', 'heading' ); $styled['level'] = 4;
$cases['metadata-heading'] = Letterlift_Plan::content( array( $styled ) ); ll_native_assert( parse_blocks( $cases['metadata-heading'] )[0]['attrs']['level'] === 4, 'Explicit normalized heading metadata respected.' );
unset( $styled['level'] ); $cases['conservative-heading'] = Letterlift_Plan::content( array( $styled ) ); ll_native_assert( parse_blocks( $cases['conservative-heading'] )[0]['attrs']['level'] === 2, 'Unknown explicit heading level conservatively uses h2.' );
ll_native_assert( parse_blocks( Letterlift_Plan::content( array( ll_native_model( '<p><strong>Not a heading</strong></p>' ) ) ) )[0]['blockName'] === 'core/paragraph', 'Bold text alone never creates a heading.' );
$nested = ll_native_flat( parse_blocks( $cases['nested'] ) ); ll_native_assert( count( array_filter( $nested, static fn( $b ) => $b['blockName'] === 'core/list' ) ) === 2 && count( array_filter( $nested, static fn( $b ) => $b['blockName'] === 'core/list-item' ) ) === 3, 'Nested lists retain native list/list-item nesting.' );
ll_native_assert( str_contains( $cases['paragraph'], '<strong>bold</strong>' ) && str_contains( $cases['paragraph'], '<em>italic</em>' ) && str_contains( $cases['paragraph'], 'https://example.org/?a=1&amp;b=2' ), 'Formatting and exact hyperlink preserved.' );
ll_native_assert( str_contains( $cases['unicode'], '東京' ) && str_contains( $cases['unicode'], 'café' ) && str_contains( $cases['unicode'], '&lt;literal&gt;' ), 'Unicode and safely escaped literal markup preserved.' );
$legacy_fixture = ( new Letterlift_Parser() )->analyze( file_get_contents( __DIR__ . '/fixtures/legacy.html' ), 'https://mailchi.mp/legacy-fixture' );
$cases['legacy-layout-native'] = Letterlift_Plan::content( $legacy_fixture['blocks'] );
ll_native_assert( ! in_array( 'core/table', array_column( ll_native_flat( parse_blocks( $cases['legacy-layout-native'] ) ), 'blockName' ), true ), 'Legacy presentation tables are flattened into source reading order.' );
$media = array( 'https://mcusercontent.com/native-one.png' => array( 'url' => 'https://local.test/uploads/one.png', 'id' => 123 ), 'https://mcusercontent.com/native-two.png' => array( 'url' => 'https://local.test/uploads/two.png', 'id' => 124 ) );
$image = ll_native_model( '<figure><a href="https://example.org/archive"><img src="https://mcusercontent.com/native-one.png" alt="Original &amp; alt"></a><figcaption>Original <em>caption</em>.</figcaption></figure>', 'image' );
$cases['image'] = Letterlift_Plan::content( array( $image ), $media ); $image_block = parse_blocks( $cases['image'] )[0];
ll_native_assert( $image_block['blockName'] === 'core/image' && $image_block['attrs']['id'] === 123, 'Native image references a Media Library attachment ID.' );
ll_native_assert( str_contains( $cases['image'], 'https://local.test/uploads/one.png' ) && ! str_contains( $cases['image'], 'mcusercontent.com' ), 'Image uses a managed local URL.' );
ll_native_assert( str_contains( $cases['image'], 'alt="Original &amp; alt"' ) && str_contains( $cases['image'], '<figcaption class="wp-element-caption">Original <em>caption</em>.' ), 'Alt text and formatted caption retained inside image block.' );
ll_native_assert( str_contains( $cases['image'], '<a href="https://example.org/archive">' ), 'Meaningful image link retained.' );
$caption = ll_native_model( '<figcaption>Explicit adjacent caption.</figcaption>', 'caption', 2 );
$cases['adjacent-caption'] = Letterlift_Plan::content( array( ll_native_model( '<img src="https://mcusercontent.com/native-one.png" alt="One">', 'image' ), $caption ), $media );
ll_native_assert( count( array_filter( parse_blocks( $cases['adjacent-caption'] ), static fn( $b ) => (bool) $b['blockName'] ) ) === 1 && str_contains( $cases['adjacent-caption'], '<figcaption' ), 'Explicit adjacent source caption attaches to its image.' );
$caption['position'] = 8; $cases['moved-caption'] = Letterlift_Plan::content( array( $image, $caption ), $media ); ll_native_assert( count( array_filter( parse_blocks( $cases['moved-caption'] ), static fn( $b ) => (bool) $b['blockName'] ) ) === 2, 'Reorganized captions are not guessed to belong to a different image.' );
$analysis = ( new Letterlift_Parser() )->analyze( file_get_contents( __DIR__ . '/fixtures/native.html' ), 'https://mailchi.mp/native-fixture' );
$result = Letterlift_Serializer::generate( $analysis['blocks'], $media ); $cases['realistic-mailchimp'] = $result['content']; $flat = ll_native_flat( parse_blocks( $result['content'] ) ); $types = array_column( $flat, 'blockName' );
foreach ( array( 'core/heading', 'core/paragraph', 'core/image', 'core/list', 'core/list-item', 'core/quote', 'core/separator', 'core/table', 'core/buttons', 'core/button', 'core/html' ) as $type ) { ll_native_assert( in_array( $type, $types, true ), 'Realistic campaign contains ' . $type ); }
ll_native_assert( count( array_filter( $types, static fn( $type ) => $type === 'core/html' ) ) === 2, 'Only definition and merged table regions require HTML in realistic campaign.' );
ll_native_assert( count( array_filter( $types, static fn( $type ) => $type === 'core/table' ) ) === 1, 'Nested email layout tables do not become data table blocks.' );
ll_native_assert( str_contains( $result['content'], 'wp:image' ) && strpos( $result['content'], 'one.png' ) < strpos( $result['content'], 'two.png' ), 'Image ordering preserved.' );
$reordered = array( ll_native_model( '<p>Second</p>', 'paragraph', 2 ), ll_native_model( '<h2>First source block, moved second</h2>', 'heading', 1 ) );
$cases['reordered'] = Letterlift_Plan::content( $reordered ); ll_native_assert( strpos( $cases['reordered'], 'Second' ) < strpos( $cases['reordered'], 'First source block' ), 'Approved manual block order is respected.' );
$legacy = Letterlift_Serializer::generate( $analysis['blocks'], $media, Letterlift_Serializer::LEGACY ); $expected = '';
foreach ( $analysis['blocks'] as $b ) { $part = $b['html']; foreach ( $media as $src => $local ) { $part = str_replace( esc_attr( $src ), esc_attr( $local['url'] ), $part ); } $expected .= "<!-- wp:html -->\n" . wp_kses( $part, Letterlift_Parser::allowed() ) . "\n<!-- /wp:html -->\n"; }
ll_native_assert( $legacy['content'] === $expected, 'Legacy serialization is byte-for-byte compatible with old approvals.' );
ll_native_assert( Letterlift_Serializer::version( array( 'execution' => array( 'snapshot' => array() ) ) ) === Letterlift_Serializer::LEGACY, 'Unversioned execution uses legacy serializer.' );
ll_native_assert( Letterlift_Serializer::version( array() ) === Letterlift_Serializer::CURRENT, 'Unapproved reviews use native serializer.' );
$injection = ll_native_model( '<p>Safe</p><!-- wp:block {"ref":123} /-->' ); $cases['delimiter-injection'] = Letterlift_Plan::content( array( $injection ) ); ll_native_assert( ! str_contains( $cases['delimiter-injection'], '"ref"' ), 'Source comments cannot inject Gutenberg blocks.' );
wp_set_current_user( 1 ); $plan = Letterlift_Plan::initial( $analysis );
$preview_session = array( 'analysis' => $analysis, 'plan' => $plan, 'revision' => 1 ); $preview = Letterlift_Serializer::preview( $preview_session );
ll_native_assert( $preview['drafts']['single']['content'] === Letterlift_Plan::content( Letterlift_Plan::blocks( $analysis, $plan, 'single' ) ), 'Preview and draft creation use identical serialization rules.' );
ll_native_assert( str_contains( $preview['drafts']['single']['html'], 'wp-block-image' ) && str_contains( $preview['drafts']['single']['html'], '<figcaption' ), 'Rendered preview includes native image/caption markup.' );
ll_native_assert( count( $preview['drafts']['single']['warnings'] ) === 2, 'Fallback warnings are exposed with the corresponding preview.' );
$path = getenv( 'LETTERLIFT_GUTENBERG_CASES' ); if ( $path ) { file_put_contents( $path, wp_json_encode( array( 'wordpress' => get_bloginfo( 'version' ), 'cases' => $cases ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ); }
echo 'PASS: ' . $ll_serializer_assertions . " serializer assertions on WordPress " . get_bloginfo( 'version' ) . "\n";

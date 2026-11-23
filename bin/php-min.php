<?php
/**
 * php-min.php — minify a PHP template (PHP code + inline HTML) to stdout.
 *
 *   Usage:  php bin/php-min.php <path>
 *
 * Two passes:
 *   1. php_strip_whitespace() handles the PHP side — uses PHP's own
 *      tokenizer, so it cannot break semantics (preserves strings,
 *      heredocs and necessary whitespace between identifiers).
 *   2. token_get_all() on the result, then minify_html() rewrites only
 *      T_INLINE_HTML tokens — the parts between ?> and <?php that are
 *      raw HTML and that php_strip_whitespace() leaves alone.
 *
 * The HTML pass:
 *   - Preserves <script>, <style>, <pre>, <textarea> verbatim (their
 *     whitespace is either semantically active or visible to the user).
 *   - Strips HTML comments (except IE conditional comments, in case any
 *     legacy markup still uses them).
 *   - Collapses runs of whitespace, including newlines, into a single
 *     space.
 *   - Does NOT tighten across "> <" boundaries — collapsing a space
 *     between two inline elements (<span> a </span> <span> b </span>)
 *     would visually merge them. Keeping one space is safe and still
 *     removes the vast majority of bloat.
 *
 * Exit codes: 0 ok, 1 usage / IO error.
 */

if ( $argc < 2 ) {
    fwrite( STDERR, "Usage: php php-min.php <path>\n" );
    exit( 1 );
}

$path = $argv[1];
if ( ! is_readable( $path ) ) {
    fwrite( STDERR, "Cannot read: {$path}\n" );
    exit( 1 );
}

// Pass 1 — PHP-side cleanup (comments + token whitespace).
$src = php_strip_whitespace( $path );

// Pass 2 — minify inline HTML AND normalise PHP tag whitespace.
//
// php_strip_whitespace leaves the trailing newline that frequently
// exists inside T_OPEN_TAG and T_CLOSE_TAG, which produces visible
// breaks in the output even though they are semantically useless. We
// rewrite both tag forms to their minimal versions here.
//
// IMPORTANT: PHP one-line comments terminate at the close-tag marker.
// All comments below are intentionally written to avoid the literal
// close-tag sequence inside line comments, otherwise THIS file fails
// to parse (we hit that exact bug before).
$tokens = token_get_all( $src );

$out = '';
foreach ( $tokens as $token ) {
    if ( is_array( $token ) ) {
        [ $type, $value ] = $token;

        if ( $type === T_INLINE_HTML ) {
            $out .= minify_html( $value );
        } elseif ( $type === T_OPEN_TAG ) {
            // PHP requires whitespace after the open tag before the
            // next code token; a single space is the minimum legal form.
            $out .= '<?php ';
        } elseif ( $type === T_OPEN_TAG_WITH_ECHO ) {
            // Short-echo tag. A trailing space is not strictly required
            // but it keeps the output safe against further pipeline
            // passes that might glue identifiers together.
            $out .= '<?= ';
        } elseif ( $type === T_CLOSE_TAG ) {
            // PHP normally swallows the single newline that follows the
            // close tag, so we drop that whitespace entirely here —
            // shorter and equivalent at runtime.
            $out .= '?>';
        } else {
            $out .= $value;
        }
    } else {
        $out .= $token;
    }
}

echo $out;

/**
 * Minify one HTML fragment. See file header for the rules.
 */
function minify_html( string $html ): string {
    // 1. Hide content we must not touch behind placeholders.
    $preserve_tags = '(script|style|pre|textarea)';
    $stash = [];
    $html = preg_replace_callback(
        '/<' . $preserve_tags . '\b[^>]*>.*?<\/\1\s*>/is',
        function ( $m ) use ( &$stash ) {
            $i = count( $stash );
            $stash[] = $m[0];
            return "\x01PH{$i}\x01";
        },
        $html
    );

    // 2. Strip HTML comments (keep IE conditionals — paranoia, almost
    //    certainly absent in 2026 themes but cheap to preserve).
    $html = preg_replace( '/<!--(?!\[if)[\s\S]*?-->/', '', $html );

    // 3. Collapse all whitespace runs to a single space. We intentionally
    //    do NOT remove the space between adjacent tags; that's necessary
    //    for inline-element layout.
    $html = preg_replace( '/\s+/', ' ', $html );

    // 4. Restore the stashed preserve-block content.
    $html = preg_replace_callback(
        '/\x01PH(\d+)\x01/',
        fn( $m ) => $stash[ (int) $m[1] ],
        $html
    );

    return $html;
}

<?php
/**
 * Manifesto / slogan resolver
 * ================================================================
 *
 * Single source of truth for the brand manifesto/slogan, fed by the
 * ACF Options-page repeater `manifesto_lines`. Each row contains a
 * `manifesto` text field with limited inline HTML (em, i, strong, b,
 * span.class).
 *
 * The repeater is intentionally a pool of variations — the home hero
 * picks a random one each load (variety on the front page), while
 * every other place (footer, newsletter, unsubscribe page) takes the
 * FIRST entry as the canonical/primary slogan. The editor can rotate
 * the primary slogan by reordering the rows in the admin without
 * touching code.
 *
 * @package newpixel
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Return the manifesto HTML.
 *
 * The returned string:
 *   - has the outer <p>...</p> stripped (ACF wysiwyg often wraps in <p>)
 *   - has trailing dots/whitespace trimmed, so each callsite can append
 *     its own period and not double-up
 *   - is run through wp_kses to strip everything except a small
 *     allow-list of inline emphasis tags
 *
 * @param bool $random When false (default), returns the FIRST item from
 *                     the repeater — the canonical primary slogan.
 *                     When true, picks one at random — used by the
 *                     home hero so the page has manifesto variety.
 * @return string HTML safe to echo
 */
function newpixel_manifesto( $random = false ) {
    $lines = newpixel_manifesto_lines();
    if ( empty( $lines ) ) {
        return 'Direto ao <em>pixel</em>';
    }
    return $random ? $lines[ array_rand( $lines ) ] : $lines[0];
}

/**
 * Return ALL manifesto lines (cleaned, HTML-safe), in repeater order.
 *
 * Used by the home hero, which renders the full pool + picks one CLIENT-SIDE
 * so the slogan still rotates per visit under full-page cache (instead of
 * freezing to whatever the cache captured). Always returns at least one line.
 *
 * @return string[] HTML-safe strings.
 */
function newpixel_manifesto_lines() {
    $fallback = array( 'Direto ao <em>pixel</em>' );

    if ( ! function_exists( 'get_field' ) ) {
        return $fallback;
    }
    $rows = get_field( 'manifesto_lines', 'option' );
    if ( ! is_array( $rows ) || empty( $rows ) ) {
        return $fallback;
    }

    $out = array();
    foreach ( $rows as $row ) {
        $clean = newpixel_manifesto_clean( isset( $row['manifesto'] ) ? (string) $row['manifesto'] : '' );
        if ( $clean !== '' ) {
            $out[] = $clean;
        }
    }
    return empty( $out ) ? $fallback : $out;
}

/**
 * Strip the ACF wysiwyg <p> wrap + trailing punctuation, then wp_kses down to
 * the small inline allow-list. Returns '' if nothing usable remains.
 */
function newpixel_manifesto_clean( $raw ) {
    $raw = trim( (string) $raw );
    // ACF wysiwyg wraps in <p>; we want bare inline HTML.
    $raw = preg_replace( '#^\s*<p[^>]*>(.*?)</p>\s*$#is', '$1', $raw );
    // Drop trailing punctuation so each caller controls its own terminator.
    $raw = preg_replace( '/[\.\s]+$/u', '', $raw );
    if ( $raw === '' ) {
        return '';
    }
    return wp_kses( $raw, array(
        'em'     => array(),
        'i'      => array(),
        'strong' => array(),
        'b'      => array(),
        'span'   => array( 'class' => array() ),
    ) );
}

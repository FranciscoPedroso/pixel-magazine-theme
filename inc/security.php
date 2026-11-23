<?php
/**
 * Security hardening
 * ============================================================================
 *
 * Defensive tweaks that shrink the attack surface without changing anything for
 * legitimate visitors:
 *
 *   1. Author-archive enumeration — `/?author=1` 302s to `/author/<username>/`,
 *      leaking the login name. We send any author request to the home page
 *      (admins exempt). Pairs with the REST `/users` lock in inc/auth.php.
 *   2. oEmbed — strips author name/URL from the oEmbed JSON (another leak).
 *   3. XML-RPC — disabled (unused; classic brute-force / pingback-amplification
 *      surface), and its discovery headers/links removed.
 *   4. Honeypot helpers — a hidden trap field for the registration + newsletter
 *      forms. Bots fill it, humans never see it; the handlers reject on fill.
 *
 * @package newpixel
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// ===========================================================================
// 1. Block author-archive enumeration (?author=N and /author/<slug>/)
// ===========================================================================
// Runs at priority 0 so it fires BEFORE redirect_canonical (priority 10), which
// would otherwise 301 `?author=1` to `/author/<username>/` and leak the login
// name in the Location header. The site is brand-first (no per-author archives
// in the UI), so author requests just go home. Admins are exempt.
add_action( 'template_redirect', 'newpixel_block_author_enumeration', 0 );
function newpixel_block_author_enumeration() {
    if ( current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( is_author() || isset( $_GET['author'] ) ) {
        wp_safe_redirect( home_url( '/' ) );
        exit;
    }
}

/**
 * Author profile URL for structured data and `article:author`.
 *
 * Can't be `get_author_posts_url()`: that URL contains `user_nicename`, which
 * is the WordPress login (`/author/<login>/`), and the block above redirects it
 * to the home page. Google would get an author with no page, and the username
 * would be public on every article. Points to the "Sobre" page instead, where
 * the person behind the site introduces themselves. Filterable per author
 * (`newpixel_author_profile_url`).
 */
function newpixel_author_profile_url( $author_id ) {
    $about = get_page_by_path( 'sobre-a-pixel-magazine' );
    $url   = $about ? get_permalink( $about ) : home_url( '/' );
    return (string) apply_filters( 'newpixel_author_profile_url', $url, (int) $author_id );
}

// ===========================================================================
// 2. Scrub author info from oEmbed responses (another username leak)
// ===========================================================================
add_filter( 'oembed_response_data', 'newpixel_oembed_strip_author' );
function newpixel_oembed_strip_author( $data ) {
    unset( $data['author_url'], $data['author_name'] );
    return $data;
}

// ===========================================================================
// 2b. Remove the users sitemap from the index
//
// Block 1 above sends every author archive request to the home page, but
// WordPress would still publish `wp-sitemap-users-1.xml` with the author URL
// inside, advertising to Google exactly what we're hiding and making it crawl
// a URL that responds 302. Removing it closes the username leak and saves the
// crawler a useless hop.
//
// This is a brand site with no author archives in its UI, so the sitemap
// loses nothing useful.
// ===========================================================================
add_filter( 'wp_sitemaps_add_provider', 'newpixel_drop_users_sitemap', 10, 2 );
function newpixel_drop_users_sitemap( $provider, $name ) {
    if ( 'users' === $name ) {
        return false;
    }
    return $provider;
}

// ===========================================================================
// 3. Disable XML-RPC (unused; brute-force + pingback-amplification surface)
// ===========================================================================
add_filter( 'xmlrpc_enabled', '__return_false' );

// Don't advertise it: drop the X-Pingback header and the RSD discovery link.
add_filter( 'wp_headers', function ( $headers ) {
    unset( $headers['X-Pingback'] );
    return $headers;
} );
remove_action( 'wp_head', 'rsd_link' );

// ===========================================================================
// 4. Honeypot helpers (shared by the registration + newsletter forms)
// ===========================================================================
// A text input hidden off-screen + aria-hidden + tabindex -1 + autocomplete
// off. Real users (and screen readers) never reach it; dumb spam bots fill
// every field they find. The field name is non-standard so browsers don't
// autofill it for humans (no false positives). Forms submit via
// `new FormData(form)`, so the field rides along automatically.

const NEWPIXEL_HONEYPOT_FIELD = 'pixel_hp';

/**
 * Echo the honeypot field. Call inside a <form>.
 */
function newpixel_honeypot_field() {
    printf(
        '<div aria-hidden="true" style="position:absolute!important;left:-9999px!important;top:-9999px!important;height:1px;width:1px;overflow:hidden;">'
        . '<label>%s</label>'
        . '<input type="text" name="%s" tabindex="-1" autocomplete="off" value="" />'
        . '</div>',
        esc_html__( 'Deixa este campo vazio.', 'newpixel' ),
        esc_attr( NEWPIXEL_HONEYPOT_FIELD )
    );
}

/**
 * True if the honeypot was filled — i.e. the submission is almost certainly a bot.
 */
function newpixel_honeypot_tripped() {
    return ! empty( $_POST[ NEWPIXEL_HONEYPOT_FIELD ] );
}

<?php
/**
 * Newsletter template renderer
 * ================================================================
 *
 * Single responsibility: given an array of issue data + posts, return
 * the complete HTML body of the email (no surrounding wp_mail headers).
 *
 * Why this lives separate from inc/newsletter-issues.php:
 *   The template is ~400 lines of email-safe HTML with inline styles.
 *   Keeping it isolated means the generator/cron/admin file stays
 *   focused on logic and stays under 250 lines, and the template can
 *   be re-rendered for previews without loading the rest of the
 *   newsletter machinery.
 *
 * Email-client constraints applied throughout:
 *   • Tables for layout (flexbox/grid don't work in Outlook Windows)
 *   • CSS inline on every styled element (style attribute)
 *   • Single embedded <style> block in <head> ONLY for dark mode
 *     media queries (which can't be inlined)
 *   • System font stacks only — no web fonts (Newsreader / Inter Tight
 *     wouldn't load in most clients)
 *   • Background colors on <td>, not <div> (Outlook quirk)
 *   • 600px max-width (industry safe rendering)
 *   • Hardcoded image widths in BOTH the attribute and the inline style
 *
 * Dark mode strategy:
 *   • Inline styles render the light palette by default
 *   • The <style> block in <head> uses @media (prefers-color-scheme: dark)
 *     with class hooks (.fg, .fg-muted, .bg-surface, .rule-bg, etc.) and
 *     !important to override the inline styles in supported clients
 *     (Apple Mail iOS+macOS primarily; Outlook Office Insider via the
 *     [data-ogsc] attribute fallback)
 *   • <meta name="color-scheme"> opts the email out of forced auto-
 *     inversion by Gmail/Outlook in the browser, leaving us in control
 *
 * @package newpixel
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// ============================================================================
// Public API
// ============================================================================

/**
 * Render the full email HTML for a newsletter issue.
 *
 * @param array $data {
 *     @type int    $issue_number    Sequential issue number (used in header)
 *     @type string $issue_label     Pre-formatted "Terça · 14 Mai 2026"
 *     @type string $headline_html   Editor's intro paragraph(s) — HTML allowed
 *     @type array  $posts           Array of 5 post records (see below)
 *     @type string $unsubscribe_url Full URL with one-click unsubscribe token
 *     @type string $browser_url     Permalink to view the issue on the site
 *     @type string $subscribed_date Formatted date e.g. "03/01/2026"
 * }
 *
 * Each post record in $posts is shaped:
 *   [
 *     'title'         => 'SpaceX entra na corrida da IA...',
 *     'permalink'     => 'https://pixelmagazine.pt/spacex-cursor/',
 *     'dek'           => 'Musk não quer ficar de fora...',
 *     'image_url'     => 'https://pixelmagazine.pt/wp-content/uploads/...jpg',
 *     'image_alt'     => 'SpaceX e Cursor — fotomontagem',
 *     'category_name' => 'IA',
 *     'category_color'=> '#a78bfa',
 *     'date_label'    => '14 Mai',
 *     'reading_time'  => 5,            // minutes (int)
 *     'is_featured'   => true,         // only the first post is featured
 *   ]
 *
 * @return string Complete <!doctype html>... HTML email body
 */
function newpixel_newsletter_render_html( $data ) {
    $data = wp_parse_args( $data, array(
        'issue_number'    => 1,
        'issue_label'     => '',
        'headline_html'   => '',
        'posts'           => array(),
        'unsubscribe_url' => '#',
        'browser_url'     => '#',
        'subscribed_date' => '',
    ) );

    ob_start();
    include __DIR__ . '/newsletter-template-body.php';
    return ob_get_clean();
}

/**
 * Convert a post ID to the post record shape the template expects.
 * Resolves category, dek, image, reading time — central spot so the
 * generator and previews stay in sync.
 *
 * @param int  $post_id
 * @param bool $is_featured Whether this is the lead/featured post (gets a larger image)
 * @return array Post record (shape documented on newpixel_newsletter_render_html)
 */
function newpixel_newsletter_build_post_record( $post_id, $is_featured = false ) {
    $post = get_post( $post_id );
    if ( ! $post ) return array();

    // Category: use the same "primary category" resolver as the site so
    // the pill colour and label match what the reader sees on the article.
    $cat_term  = function_exists( 'newpixel_get_primary_category' )
        ? newpixel_get_primary_category( $post_id )
        : null;
    $cat_name  = $cat_term ? $cat_term->name : 'Pixel';
    $cat_color = function_exists( 'newpixel_get_post_category_color' )
        ? newpixel_get_post_category_color( $post_id )
        : '#a78bfa';
    $cat_url   = $cat_term ? get_term_link( $cat_term ) : home_url( '/' );
    if ( is_wp_error( $cat_url ) ) $cat_url = home_url( '/' );

    // Dek priority: ACF "dek" → excerpt → first 160 chars of content.
    $dek = function_exists( 'get_field' ) ? (string) get_field( 'dek', $post_id ) : '';
    if ( ! $dek ) { $dek = (string) get_the_excerpt( $post ); }
    if ( ! $dek ) {
        $dek = wp_strip_all_tags( $post->post_content );
        $dek = mb_substr( $dek, 0, 160 );
        if ( mb_strlen( $dek ) === 160 ) $dek .= '…';
    }

    // Image: featured image at a size appropriate for the slot. For the
    // featured post we want ~600px wide (display 584 in the email = 2x for
    // retina = 1168, so the closest theme size "w1024" is fine). For
    // standard cards we don't show an image at all in this design.
    $image_url = '';
    $image_alt = '';
    if ( $is_featured && has_post_thumbnail( $post_id ) ) {
        $thumb_id  = get_post_thumbnail_id( $post_id );
        $image_url = wp_get_attachment_image_url( $thumb_id, 'w1024' );
        if ( ! $image_url ) $image_url = wp_get_attachment_image_url( $thumb_id, 'large' );
        $image_alt = (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );
        if ( ! $image_alt ) $image_alt = wp_strip_all_tags( get_the_title( $post_id ) );
    }

    return array(
        'title'          => wp_strip_all_tags( get_the_title( $post_id ) ),
        'permalink'      => get_permalink( $post_id ),
        'dek'            => $dek,
        'image_url'      => $image_url,
        'image_alt'      => $image_alt,
        'category_name'  => $cat_name,
        'category_color' => $cat_color,
        'category_url'   => $cat_url,
        'date_label'     => newpixel_date_pt( 'd M', $post->post_date ),
        'reading_time'   => function_exists( 'newpixel_reading_time' ) ? (int) newpixel_reading_time( $post_id ) : 5,
        'is_featured'    => (bool) $is_featured,
    );
}

/**
 * Convert any hex colour (#RRGGBB) to an "rgba(...,0.16)" string.
 * Used inline by the template to tint the pill backgrounds at the
 * same opacity used in the design (≈16% of the category accent).
 */
function newpixel_newsletter_hex_to_rgba( $hex, $alpha = 0.16 ) {
    $hex = ltrim( (string) $hex, '#' );
    if ( strlen( $hex ) === 3 ) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if ( strlen( $hex ) !== 6 ) return 'rgba(167,139,250,' . $alpha . ')'; // fallback lavender
    $r = hexdec( substr( $hex, 0, 2 ) );
    $g = hexdec( substr( $hex, 2, 2 ) );
    $b = hexdec( substr( $hex, 4, 2 ) );
    return sprintf( 'rgba(%d,%d,%d,%.2f)', $r, $g, $b, $alpha );
}

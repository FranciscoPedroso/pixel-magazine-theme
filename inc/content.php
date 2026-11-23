<?php
/**
 * ============================================================================
 * Post body content filters — runs on `the_content`.
 * ============================================================================
 *
 * What this does: every editor-inserted image (the `<img class="wp-image-X">`
 * markup the Classic Editor emits via "Add Media") is rewritten into a proper
 * responsive <figure> using the theme's own image-size ladder. Nothing in the
 * editor changes — authors keep using Add Media as normal.
 *
 * The rewrite:
 *   - Wraps the image in <figure class="kf-prose-figure">.
 *   - Uses the soft-crop sizes (w480 / w960 / w1024 / w1920) so the original
 *     aspect ratio is preserved — a 16:9 source stays 16:9, a portrait stays
 *     portrait. No forced cropping.
 *   - Emits a proper srcset + sizes attribute so the browser only downloads
 *     the variant it needs.
 *   - Adds loading="lazy" and decoding="async".
 *   - Pulls the caption from the inline <figcaption> if the editor added one,
 *     otherwise falls back to the Media Library "Caption" field.
 *   - Recognises an `is-wide` CSS class on the <img> for a breakout treatment
 *     (image wider than the prose column on tablet/desktop).
 *
 * Three passes are needed because the editor wraps inline images in different
 * markup depending on context:
 *   1. <p>…just an img…</p>           — the most common case (Add Media inline)
 *   2. <figure><img/><figcaption/></figure>  — the [caption] shortcode result
 *   3. bare <img class="wp-image-X">  — anything else (legacy or inline-in-text)
 *
 * @package newpixel
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Priority 20 so we run AFTER wpautop (priority 10) — the input we see already
// has loose <img>s wrapped in <p> tags by WP core.
add_filter( 'the_content', 'newpixel_rewrite_inline_images', 20 );

/**
 * Top-level filter: scans the_content for editor-inserted images and rewrites
 * each one into a responsive <figure>. Bails fast when there's nothing to do.
 */
function newpixel_rewrite_inline_images( $content ) {
    if ( ! is_string( $content ) || strpos( $content, 'wp-image-' ) === false ) {
        return $content;
    }

    // Pass 1 — <p>…img-only…</p>: replace the entire <p>.
    $content = preg_replace_callback(
        '#<p[^>]*>\s*'
        . '(<img\b[^>]*\bclass="[^"]*\bwp-image-(\d+)[^"]*"[^>]*/?>)'
        . '(\s*<figcaption[^>]*>([\s\S]*?)</figcaption>)?'
        . '\s*</p>#',
        function ( $m ) {
            return newpixel_render_prose_figure( $m[1], (int) $m[2], isset( $m[4] ) ? trim( $m[4] ) : '' );
        },
        $content
    );

    // Pass 2 — <figure>…img + figcaption…</figure>: rewrite, preserving caption.
    $content = preg_replace_callback(
        '#<figure[^>]*>\s*'
        . '(<img\b[^>]*\bclass="[^"]*\bwp-image-(\d+)[^"]*"[^>]*/?>)'
        . '(\s*<figcaption[^>]*>([\s\S]*?)</figcaption>)?'
        . '\s*</figure>#',
        function ( $m ) {
            return newpixel_render_prose_figure( $m[1], (int) $m[2], isset( $m[4] ) ? trim( $m[4] ) : '' );
        },
        $content
    );

    // Pass 3 — any remaining bare <img wp-image-X>: wrap it in a figure.
    // (The new markup emitted by passes 1/2 no longer carries the wp-image-X
    // class, so this won't double-process.)
    $content = preg_replace_callback(
        '#(<img\b[^>]*\bclass="[^"]*\bwp-image-(\d+)[^"]*"[^>]*/?>)#',
        function ( $m ) {
            return newpixel_render_prose_figure( $m[1], (int) $m[2], '' );
        },
        $content
    );

    return $content;
}

/**
 * Build the <figure> markup for one attachment.
 *
 * @param string $original_img  the original <img …> HTML (used to extract alt + is-wide)
 * @param int    $attachment_id the WP attachment ID parsed from the wp-image-X class
 * @param string $inline_caption optional inline figcaption HTML
 */
function newpixel_render_prose_figure( $original_img, $attachment_id, $inline_caption = '' ) {
    if ( ! $attachment_id ) {
        return $original_img;
    }

    // Preserve alt text from the original <img>.
    $alt = '';
    if ( preg_match( '/\balt="([^"]*)"/', $original_img, $m ) ) {
        $alt = $m[1];
    }

    // `is-wide` class on the source <img> triggers the breakout treatment.
    $is_wide = (bool) preg_match( '/\bclass="[^"]*\bis-wide\b/', $original_img );

    // Size ladder — defaults to the soft-crop family which preserves the
    // original aspect ratio. Wide variant uses a larger ladder.
    // Column widths come from .kf-article in _article.scss:
    //   - default: content column = 720px on lg (800 max-width minus 40 gutter ×2)
    //   - is-wide: breakout beyond the column (~880px on xl)
    if ( $is_wide ) {
        $default_size = 'w1920';
        $srcset_sizes = array( 'w480', 'w960', 'w1024', 'w1920' );
        $sizes_attr   = '(min-width: 1280px) 880px, (min-width: 768px) calc(100vw - 80px), calc(100vw - 32px)';
    } else {
        $default_size = 'w1024';
        $srcset_sizes = array( 'w480', 'w960', 'w1024' );
        $sizes_attr   = '(min-width: 1080px) 720px, (min-width: 768px) calc(100vw - 64px), calc(100vw - 32px)';
    }

    // Fetch the default src + its real dimensions.
    $main = wp_get_attachment_image_src( $attachment_id, $default_size );
    if ( ! $main ) {
        // The custom size hasn't been generated yet — fall back to the original.
        $main = wp_get_attachment_image_src( $attachment_id, 'full' );
    }
    if ( ! $main ) {
        // Attachment is gone — leave the original markup alone so the editor
        // can see something is broken.
        return $original_img;
    }
    list( $src, $width, $height ) = $main;

    // Build the srcset string from whichever sizes actually exist.
    $srcset_parts = array();
    foreach ( $srcset_sizes as $size ) {
        $entry = wp_get_attachment_image_src( $attachment_id, $size );
        if ( $entry && (int) $entry[1] > 0 ) {
            $srcset_parts[] = esc_url( $entry[0] ) . ' ' . (int) $entry[1] . 'w';
        }
    }
    $srcset_attr = empty( $srcset_parts )
        ? ''
        : ' srcset="' . esc_attr( implode( ', ', $srcset_parts ) ) . '"';

    // Caption: inline <figcaption> takes precedence; otherwise the
    // Media Library's "Caption" field is the fallback.
    $caption = $inline_caption;
    if ( $caption === '' ) {
        $caption = trim( (string) wp_get_attachment_caption( $attachment_id ) );
    }

    $figure_class = 'kf-prose-figure' . ( $is_wide ? ' kf-prose-figure--wide' : '' );

    $img_html = sprintf(
        '<img src="%s"%s sizes="%s" width="%d" height="%d" loading="lazy" decoding="async" alt="%s">',
        esc_url( $src ),
        $srcset_attr,
        esc_attr( $sizes_attr ),
        (int) $width,
        (int) $height,
        esc_attr( $alt )
    );

    $caption_html = '';
    if ( $caption !== '' ) {
        $caption_html = '<figcaption>' . wp_kses_post( $caption ) . '</figcaption>';
    }

    return sprintf(
        '<figure class="%s">%s%s</figure>',
        esc_attr( $figure_class ),
        $img_html,
        $caption_html
    );
}

<?php
/**
 * Removed articles don't leave 404s behind.
 * ============================================================================
 *
 * A URL that has already earned search impressions shouldn't turn into a dead
 * end when its article is withdrawn. This module makes a removed article
 * (trashed or permanently deleted) 301 to its category.
 *
 *   - TRASHED: WordPress renames the slug to `<slug>__trashed`; on a 404, we
 *     look for a trashed post with that name.
 *   - DELETED: before deletion we store `slug → category URL` in a
 *     non-autoloaded option, since the post no longer exists afterwards.
 *
 * Posts only (not pages), and only when the request is a 404: if the slug
 * exists again (restored or republished article), the article wins.
 *
 * @package newpixel
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

const NEWPIXEL_DELETED_REDIRECTS_OPTION = 'newpixel_deleted_redirects';
const NEWPIXEL_DELETED_REDIRECTS_MAX    = 3000;

/** Target URL for a removed article: its main category, or the home page. */
function newpixel_deleted_redirect_target( $post_id ) {
    $cat = function_exists( 'newpixel_get_primary_category' ) ? newpixel_get_primary_category( $post_id ) : null;
    if ( $cat ) {
        $link = get_category_link( $cat->term_id );
        if ( $link && ! is_wp_error( $link ) ) {
            return $link;
        }
    }
    return home_url( '/' );
}

// Before permanent deletion: store the target (the post still exists here).
add_action( 'before_delete_post', 'newpixel_remember_deleted_post', 10, 2 );
function newpixel_remember_deleted_post( $post_id, $post = null ) {
    $post = $post ?: get_post( $post_id );
    if ( ! $post || 'post' !== $post->post_type || 'revision' === $post->post_type ) {
        return;
    }
    // Drafts that were never published have no known URL.
    if ( '0000-00-00 00:00:00' === $post->post_date_gmt ) {
        return;
    }
    $slug = preg_replace( '/__trashed(-\d+)?$/', '', (string) $post->post_name );
    if ( '' === $slug ) {
        return;
    }

    $map          = get_option( NEWPIXEL_DELETED_REDIRECTS_OPTION, array() );
    $map          = is_array( $map ) ? $map : array();
    $map[ $slug ] = newpixel_deleted_redirect_target( $post_id );
    if ( count( $map ) > NEWPIXEL_DELETED_REDIRECTS_MAX ) {
        $map = array_slice( $map, -NEWPIXEL_DELETED_REDIRECTS_MAX, null, true );
    }
    update_option( NEWPIXEL_DELETED_REDIRECTS_OPTION, $map, false );
}

// On a 404: look for the slug in the trash, then in the deleted map.
add_action( 'template_redirect', 'newpixel_redirect_deleted_post', 20 );
function newpixel_redirect_deleted_post() {
    if ( ! is_404() ) {
        return;
    }
    $path = trim( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ), '/' );
    // Articles live at /<slug>/, a single path segment.
    if ( '' === $path || false !== strpos( $path, '/' ) ) {
        return;
    }
    $slug = sanitize_title( $path );

    $trashed = get_posts( array(
        'name'             => $slug . '__trashed',
        'post_type'        => 'post',
        'post_status'      => 'trash',
        'numberposts'      => 1,
        'suppress_filters' => true,
    ) );
    $target = $trashed ? newpixel_deleted_redirect_target( $trashed[0]->ID ) : '';

    if ( ! $target ) {
        $map    = get_option( NEWPIXEL_DELETED_REDIRECTS_OPTION, array() );
        $target = ( is_array( $map ) && isset( $map[ $slug ] ) ) ? $map[ $slug ] : '';
    }

    if ( $target ) {
        wp_safe_redirect( $target, 301, 'Pixel Magazine' );
        exit;
    }
}

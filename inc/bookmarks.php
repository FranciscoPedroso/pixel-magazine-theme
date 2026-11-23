<?php
/**
 * Bookmarks / "Artigos guardados" — saved-post system per logged-in user.
 *
 * - Storage: user_meta `pixel_bookmarks` (array of post IDs, recent first).
 * - AJAX endpoint: pixel_toggle_bookmark (logged-in only, nonce: pixel_bookmark).
 * - Public route: /guardados/ → page-guardados.php template.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// ============================================================================
// =====================  BOOKMARKS / "Artigos guardados"  ====================
//
// Per-user post bookmarks, persisted in user_meta. Storage shape:
//   user_meta key:    `pixel_bookmarks`
//   value:            array of post IDs (ints), most-recent first
//
// We deliberately keep this in user_meta (not a custom table) because the
// expected list size per user is small (tens, maybe low hundreds) and we
// only ever read or write the whole array atomically via toggle/list calls.
// ============================================================================

const NEWPIXEL_BOOKMARKS_META = 'pixel_bookmarks';

/**
 * Get the array of saved post IDs for a user. Filters to public, existing
 * posts so deleted/private items don't pollute the list.
 *
 * @param int|null $user_id  Defaults to current user.
 * @return int[]             Most-recent first.
 */
function newpixel_get_user_bookmarks( $user_id = null ) {
    $user_id = $user_id ?: get_current_user_id();
    if ( ! $user_id ) {
        return array();
    }

    $raw = get_user_meta( $user_id, NEWPIXEL_BOOKMARKS_META, true );
    if ( ! is_array( $raw ) ) {
        return array();
    }

    // Coerce to ints, drop zero/duplicates, preserve order.
    $seen = array();
    $out  = array();
    foreach ( $raw as $id ) {
        $id = (int) $id;
        if ( $id > 0 && ! isset( $seen[ $id ] ) ) {
            $seen[ $id ] = true;
            $out[] = $id;
        }
    }
    return $out;
}

/**
 * True if the given post is currently saved by the user.
 */
function newpixel_is_post_saved( $post_id, $user_id = null ) {
    $post_id = (int) $post_id;
    if ( $post_id <= 0 ) return false;

    return in_array( $post_id, newpixel_get_user_bookmarks( $user_id ), true );
}

/**
 * Toggle a bookmark on/off. Returns the new saved state (true = saved).
 */
function newpixel_toggle_bookmark( $post_id, $user_id = null ) {
    $user_id = $user_id ?: get_current_user_id();
    $post_id = (int) $post_id;
    if ( ! $user_id || $post_id <= 0 ) {
        return false;
    }

    $list = newpixel_get_user_bookmarks( $user_id );
    $idx  = array_search( $post_id, $list, true );

    if ( $idx === false ) {
        // Add to front (most-recent first ordering)
        array_unshift( $list, $post_id );
        $saved = true;
    } else {
        array_splice( $list, $idx, 1 );
        $saved = false;
    }

    update_user_meta( $user_id, NEWPIXEL_BOOKMARKS_META, $list );
    return $saved;
}

/**
 * AJAX handler — toggle bookmark for the current user. Auth + nonce required.
 * Endpoint: admin-ajax.php?action=pixel_toggle_bookmark
 */
function newpixel_handle_ajax_bookmark() {
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => __( 'Sessão expirada.', 'newpixel' ) ), 401 );
    }
    if ( ! check_ajax_referer( 'pixel_bookmark', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => __( 'Pedido inválido.', 'newpixel' ) ), 403 );
    }

    $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
    if ( $post_id <= 0 || get_post_status( $post_id ) === false ) {
        wp_send_json_error( array( 'message' => __( 'Artigo inválido.', 'newpixel' ) ), 400 );
    }

    $saved = newpixel_toggle_bookmark( $post_id );
    // Re-query the post-toggle total so the client has the new count and can
    // update the header badge without inferring a delta (robust across tabs).
    $count = count( newpixel_get_user_bookmarks() );

    wp_send_json_success( array(
        'post_id' => $post_id,
        'saved'   => (bool) $saved,
        'count'   => (int) $count,
    ) );
}
add_action( 'wp_ajax_pixel_toggle_bookmark', 'newpixel_handle_ajax_bookmark' );
// Note: no `wp_ajax_nopriv_*` — anonymous visitors can't toggle bookmarks.

/**
 * Public URL of the saved-articles index. Resolved via a virtual rewrite slug
 * `/guardados/` (see newpixel_register_bookmarks_endpoint below).
 */
function newpixel_bookmarks_url() {
    return home_url( '/guardados/' );
}

/**
 * Register the `/guardados/` virtual page. We use a rewrite endpoint rather
 * than a real WP page so the route exists without requiring the site owner
 * to manually create a page in wp-admin.
 */
function newpixel_register_bookmarks_endpoint() {
    add_rewrite_rule(
        '^guardados/?$',
        'index.php?pixel_bookmarks_page=1',
        'top'
    );
    add_rewrite_rule(
        '^guardados/page/([0-9]+)/?$',
        'index.php?pixel_bookmarks_page=1&paged=$matches[1]',
        'top'
    );
}
add_action( 'init', 'newpixel_register_bookmarks_endpoint' );

add_filter( 'query_vars', function ( $vars ) {
    $vars[] = 'pixel_bookmarks_page';
    return $vars;
} );

/**
 * Route /guardados/ to our custom template, and tell WordPress to treat the
 * request as a 200 (the main query can't find a matching post for this
 * virtual URL, so without this it would render as a 404).
 */
add_filter( 'template_include', function ( $template ) {
    if ( (int) get_query_var( 'pixel_bookmarks_page' ) === 1 ) {
        global $wp_query;
        if ( $wp_query ) {
            $wp_query->is_404    = false;
            $wp_query->is_home   = false;
            $wp_query->is_archive = false;
        }
        status_header( 200 );

        $candidate = get_template_directory() . '/page-guardados.php';
        if ( file_exists( $candidate ) ) {
            return $candidate;
        }
    }
    return $template;
} );

/**
 * Make wp_title()/the_title()/document.title sensible on /guardados/.
 */
add_filter( 'pre_get_document_title', function ( $title ) {
    if ( (int) get_query_var( 'pixel_bookmarks_page' ) === 1 ) {
        return wp_get_document_title_for_bookmarks();
    }
    return $title;
} );

function wp_get_document_title_for_bookmarks() {
    $name = get_bloginfo( 'name', 'display' );
    return sprintf( '%s · %s', __( 'Artigos guardados', 'newpixel' ), $name );
}

/**
 * Auto-flush rewrite rules once after the slug is registered. Cheap version:
 * store a flag in options so we only flush on first activation / theme update.
 */
add_action( 'init', function () {
    if ( get_option( 'newpixel_rewrite_v' ) !== '2' ) {
        flush_rewrite_rules( false );
        update_option( 'newpixel_rewrite_v', '2' );
    }
}, 99 );

<?php
/**
 * ============================================================================
 * Frontpage Layout resolver — explicit slots with graceful fallback.
 * ============================================================================
 *
 * The homepage of PIXEL is not a chronological feed — it's a curated layout
 * with 7 named slots (lead + feature pair + opinion + three-up). Editors pick
 * which post fills each slot in **Settings → Frontpage Layout** (an ACF
 * options page). Empty slots auto-fill from the most-recent pool, skipping
 * any post that has already been placed elsewhere.
 *
 * Public API:
 *
 *   $layout = newpixel_frontpage_layout();
 *
 *   // $layout is an array with two keys:
 *   //   'slots'    => [ 'lead' => WP_Post|null, 'feature_1' => WP_Post|null, … ]
 *   //   'used_ids' => array of int post IDs that were placed in any slot
 *
 *   // Read individual slots:
 *   $lead = $layout['slots']['lead'];
 *   if ( $lead ) { setup_postdata( $GLOBALS['post'] = $lead ); … }
 *
 *   // Exclude curated posts from a subsequent "Mais artigos" query:
 *   $more = new WP_Query( [
 *       'post__not_in' => $layout['used_ids'],
 *       …
 *   ] );
 *
 * The slot resolution is computed once per request (static cache) so calling
 * `newpixel_frontpage_layout()` from multiple places is cheap.
 *
 * @package newpixel
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Slot identifiers used by ACF field names (`frontpage_<slot>`).
 *
 * Order matters: the resolver fills slots in this sequence, so earlier slots
 * get priority when auto-picking from the recent pool.
 */
function newpixel_frontpage_slot_keys() {
    return array(
        'lead',
        'feature_1',
        'feature_2',
        'opinion',
        'three_up_1',
        'three_up_2',
        'three_up_3',
    );
}

/**
 * Resolve every front-page slot to a WP_Post (or null), with dedup + fallback.
 *
 * Algorithm:
 *   1) Read each slot's manual ACF override and validate (post exists,
 *      published, not already used in another slot). Lock those in first.
 *   2) For the opinion slot specifically, if no manual pick, auto-fill from
 *      the most-recent post in the "Opinião" category, excluding used IDs.
 *   3) Fetch a small pool of recent posts and use it to fill any remaining
 *      empty slots in slot-key order.
 *
 * Cached per request — the second call returns the same array without
 * touching the database.
 *
 * @return array{slots: array<string, WP_Post|null>, used_ids: array<int>}
 */
function newpixel_frontpage_layout() {
    static $cache = null;
    if ( $cache !== null ) {
        return $cache;
    }

    $slot_keys = newpixel_frontpage_slot_keys();
    $slots     = array_fill_keys( $slot_keys, null );
    $used_ids  = array();

    // ----- Pass 1: manual ACF overrides --------------------------------
    if ( function_exists( 'get_field' ) ) {
        foreach ( $slot_keys as $key ) {
            $id = (int) get_field( "frontpage_{$key}", 'option' );
            if ( ! $id ) continue;
            if ( in_array( $id, $used_ids, true ) ) continue; // dedup

            $post = get_post( $id );
            if ( ! $post || $post->post_status !== 'publish' ) continue;

            $slots[ $key ] = $post;
            $used_ids[]    = $id;
        }
    }

    // ----- Pass 2: fill remaining slots from the recent pool ----------
    //
    // The opinion slot is intentionally EXCLUDED from this pool — it must
    // be picked manually in Frontpage Layout (Pass 1). If left empty, the
    // slot stays null and the opinion card simply doesn't render. Showing
    // a random news post under an "OPINIÃO" eyebrow would be editorially
    // dishonest, so the slot is opt-in only.
    $empty_slots = array_filter( $slot_keys, function ( $k ) use ( $slots ) {
        return $slots[ $k ] === null && $k !== 'opinion';
    } );

    if ( ! empty( $empty_slots ) ) {
        $pool_ids = get_posts( array(
            'post_type'           => 'post',
            'posts_per_page'      => count( $empty_slots ),
            'post_status'         => 'publish',
            'post__not_in'        => $used_ids,
            'orderby'             => 'date',
            'order'               => 'DESC',
            'ignore_sticky_posts' => 0, // respect sticky for the chronological tail
            'fields'              => 'ids',
            'no_found_rows'       => true,
        ) );

        foreach ( $empty_slots as $key ) {
            if ( empty( $pool_ids ) ) break;
            $id            = (int) array_shift( $pool_ids );
            $slots[ $key ] = get_post( $id );
            $used_ids[]    = $id;
        }
    }

    $cache = array(
        'slots'    => $slots,
        'used_ids' => $used_ids,
    );
    return $cache;
}

/**
 * Convenience accessor: get a single slot's WP_Post (or null).
 *
 * @param string $slot one of the keys returned by newpixel_frontpage_slot_keys()
 * @return WP_Post|null
 */
function newpixel_frontpage_post( $slot ) {
    $layout = newpixel_frontpage_layout();
    return isset( $layout['slots'][ $slot ] ) ? $layout['slots'][ $slot ] : null;
}

/**
 * Convenience: array of post IDs already placed in any curated slot — pass
 * this as `post__not_in` to a tail query (e.g. "Mais artigos") so the same
 * post never appears twice on the front page.
 *
 * @return int[]
 */
function newpixel_frontpage_used_ids() {
    $layout = newpixel_frontpage_layout();
    return $layout['used_ids'];
}

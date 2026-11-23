<?php
/**
 * Related posts — "Se gostaste deste, lê também".
 *
 * Relevance cascade, all from data already in WP (no API, no cost):
 *   1) posts sharing tags, ranked by number of shared tags (strongest signal)
 *   2) same category (broader)
 *   3) most recent (last resort)
 * Each tier fills only what the previous left empty, so the block is always
 * full when there are enough published posts on the site.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @param int $post_id
 * @param int $limit   how many related IDs to return
 * @return int[]       ordered, de-duplicated post IDs (most relevant first)
 */
function newpixel_get_related_posts( $post_id = null, $limit = 4 ) {
    $post_id = $post_id ?: get_the_ID();
    $limit   = max( 1, (int) $limit );

    $tag_ids = wp_get_post_tags( $post_id, array( 'fields' => 'ids' ) );
    $cat_ids = wp_get_post_categories( $post_id, array( 'fields' => 'ids' ) );

    $exclude   = array( (int) $post_id );
    $collected = array();

    $base_args = array(
        'post_type'           => 'post',
        'post_status'         => 'publish',
        'fields'              => 'ids',
        'no_found_rows'       => true,
        'ignore_sticky_posts' => 1,
        'orderby'             => 'date',
        'order'               => 'DESC',
    );

    // 1) Posts sharing tags, ranked by how many tags they share.
    if ( ! empty( $tag_ids ) ) {
        $tagged = get_posts( array_merge( $base_args, array(
            'posts_per_page' => 20,
            'post__not_in'   => $exclude,
            'tax_query'      => array( array(
                'taxonomy' => 'post_tag',
                'field'    => 'term_id',
                'terms'    => $tag_ids,
            ) ),
        ) ) );

        // Score by shared-tag count; PHP 8 sort is stable, so ties keep the
        // date-DESC order get_posts already returned.
        $scored = array();
        foreach ( $tagged as $tid ) {
            $their_tags    = wp_get_post_tags( $tid, array( 'fields' => 'ids' ) );
            $scored[ $tid ] = count( array_intersect( $tag_ids, $their_tags ) );
        }
        arsort( $scored );
        $collected = array_keys( $scored );
    }

    // 2) Fill from the same category.
    if ( count( $collected ) < $limit && ! empty( $cat_ids ) ) {
        $cat_posts = get_posts( array_merge( $base_args, array(
            'posts_per_page' => $limit - count( $collected ),
            'post__not_in'   => array_merge( $exclude, $collected ),
            'category__in'   => $cat_ids,
        ) ) );
        $collected = array_merge( $collected, $cat_posts );
    }

    // 3) Fill from the most recent posts.
    if ( count( $collected ) < $limit ) {
        $recent = get_posts( array_merge( $base_args, array(
            'posts_per_page' => $limit - count( $collected ),
            'post__not_in'   => array_merge( $exclude, $collected ),
        ) ) );
        $collected = array_merge( $collected, $recent );
    }

    $collected = array_slice( $collected, 0, $limit );

    /**
     * Filter the resolved related post IDs.
     * @param int[] $collected
     * @param int   $post_id
     */
    return apply_filters( 'newpixel_related_posts', $collected, $post_id );
}

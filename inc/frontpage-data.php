<?php
/**
 * Front page — data preparation + small card helpers
 * ================================================================
 *
 * Lifts every query / state / pagination calculation out of
 * front-page.php so the template can stay focused on markup and
 * data-flow becomes testable in one place.
 *
 * Exports:
 *   • newpixel_frontpage_paged_state()       — { paged, is_archive_page }
 *   • newpixel_frontpage_archive_query()     — page-2+ flowing list state
 *   • newpixel_frontpage_more_query( $ids )  — "Mais artigos" list (page 1)
 *   • newpixel_frontpage_pagination_totals() — totals for the page-1 pager
 *   • newpixel_card_dek()                    — dek/intro resolver for cards
 *   • newpixel_card_meta()                   — small "time ago · N coments" meta
 *
 * Curated slot selection (lead, feature pair, opinion, three-up) is
 * handled by inc/frontpage-layout.php — this file glues the rest of
 * the front page together (paged archive, "Mais artigos" tail,
 * pagination math, shared card helpers).
 *
 * @package newpixel
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// ============================================================================
// Pagination state
// ============================================================================

/**
 * Resolve the current page number for the front page (paged via /page/N/).
 *
 * 'paged' is what WP fills in when the home is "Your latest posts";
 * 'page' is what it fills in when the home is a static page. Check
 * both so the front-page works regardless of the WP reading setting.
 *
 * @return array{paged:int, is_archive_page:bool}
 */
function newpixel_frontpage_paged_state() {
    $paged = (int) get_query_var( 'paged' );
    if ( $paged < 2 ) {
        $paged = (int) get_query_var( 'page' );
    }
    $paged = max( 1, $paged );
    return array(
        'paged'           => $paged,
        'is_archive_page' => ( $paged > 1 ),
    );
}

// ============================================================================
// Page 2+ — flowing archive list
// ============================================================================

/**
 * Build the WP_Query and supporting totals for pages 2+ of the front page.
 *
 * Page 1 is the magazine layout, which consumes up to
 * NEWPIXEL_MAGAZINE_POST_COUNT posts (lead 1 + feature pair 2 +
 * opinion 1 + three-up 3 + more 4 = 11 by default). Subsequent pages
 * have to skip those so /page/2/ continues with the next batch rather
 * than duplicating the front page.
 *
 * Excludes the EXACT posts shown on page 1 (curated slots + the "Mais
 * artigos" tail), re-derived here, via `post__not_in`. A fixed date `offset`
 * was wrong: curated slots can pick non-recent posts and the opinion slot can
 * be empty, so page 1 is NOT "the N most recent" — that mismatch dropped posts
 * between page 1 and page 2 (and could duplicate others).
 *
 * The returned WP_Query has `found_posts` and `max_num_pages` overwritten to
 * reflect the page-1-excluded totals, so the pager math stays consistent.
 *
 * @param int $paged The current page number (must be >= 2 to make sense)
 * @return array{
 *   query: WP_Query,
 *   page_post_ids: int[],
 *   max_pages: int,
 *   total_published: int,
 *   magazine_count: int,
 *   paged: int,
 * }
 */
function newpixel_frontpage_archive_query( $paged ) {
    $per_page = 10;

    // Re-derive the EXACT set of posts shown on page 1 (curated slots + the
    // "Mais artigos" tail), so pages 2+ continue without gaps or duplicates —
    // regardless of which posts the curated slots picked. This mirrors what
    // front-page.php builds as $rendered_ids (used_ids + the 4 "more" rows).
    $used_ids = function_exists( 'newpixel_frontpage_used_ids' )
        ? array_map( 'intval', (array) newpixel_frontpage_used_ids() )
        : array();

    // "Mais artigos" tail = up to 4 most-recent posts NOT in a curated slot.
    // Same query shape as newpixel_frontpage_more_query(), but IDs only.
    $more_ids = array_map( 'intval', (array) get_posts( array(
        'post_type'           => 'post',
        'post_status'         => 'publish',
        'posts_per_page'      => 4,
        'post__not_in'        => $used_ids,
        'orderby'             => 'date',
        'order'               => 'DESC',
        'ignore_sticky_posts' => 1,
        'fields'              => 'ids',
        'no_found_rows'       => true,
    ) ) );

    $page1_ids = array_values( array_unique( array_merge( $used_ids, $more_ids ) ) );

    $total_published = (int) wp_count_posts( 'post' )->publish;
    $archive_total   = max( 0, $total_published - count( $page1_ids ) );
    $max_pages       = max( 1, (int) ceil( $archive_total / $per_page ) );

    // Clamp an over-shot URL so it still shows the last available page.
    if ( $paged > $max_pages + 1 ) {
        $paged = $max_pages + 1;
    }

    // Offset INTO the posts that AREN'T on page 1: post__not_in filters first,
    // then offset/posts_per_page slice the remainder — no gaps, no duplicates.
    $offset = ( $paged - 2 ) * $per_page;

    $query = new WP_Query( array(
        'post_type'           => 'post',
        'post_status'         => 'publish',
        'posts_per_page'      => $per_page,
        'post__not_in'        => $page1_ids,
        'offset'              => max( 0, $offset ),
        'ignore_sticky_posts' => 1,
        'orderby'             => 'date',
        'order'               => 'DESC',
        'no_found_rows'       => true,
    ) );

    // Patch in the totals we computed so the pager + count badge are correct.
    $query->found_posts   = $archive_total;
    $query->max_num_pages = $max_pages;

    // IDs being rendered, so the right rail can exclude them (no duplicates).
    $page_post_ids = array();
    if ( $query->have_posts() ) {
        foreach ( $query->posts as $p ) {
            $page_post_ids[] = (int) $p->ID;
        }
    }

    return array(
        'query'           => $query,
        'page_post_ids'   => $page_post_ids,
        'max_pages'       => $max_pages,
        'total_published' => $total_published,
        'magazine_count'  => count( $page1_ids ),
        'paged'           => $paged,
    );
}

// ============================================================================
// Page 1 — "Mais artigos" tail + pagination totals
// ============================================================================

/**
 * Build the WP_Query for the "Mais artigos" list on page 1.
 *
 * Returns the 4 most-recent posts that AREN'T in the curated layout
 * (lead, feature pair, opinion, three-up). Caller is responsible for
 * adding the rendered IDs to $rendered_ids as it loops, so the page-1
 * pagination math has the correct count of consumed posts.
 *
 * @param int[] $rendered_ids Post IDs already used by curated slots
 * @return WP_Query
 */
function newpixel_frontpage_more_query( $rendered_ids ) {
    return new WP_Query( array(
        'post_type'           => 'post',
        'posts_per_page'      => 4,
        'post__not_in'        => $rendered_ids,
        'ignore_sticky_posts' => 1,
        'orderby'             => array( 'date' => 'DESC' ),
    ) );
}

/**
 * Compute the totals needed by the numbered pager at the bottom of page 1.
 *
 * Page 1 consumes everything in `$rendered_ids` (curated + "Mais
 * artigos" rows). Pages 2+ flow through the remaining posts at
 * `$per_page` each. So:
 *     total_pages = 1 + ceil( (total_posts - rendered) / per_page )
 *
 * @param int[] $rendered_ids Post IDs consumed by page 1
 * @param int   $per_page     Posts per page on pages 2+ (default 10)
 * @return array{total_pages:int, total_posts:int}
 */
function newpixel_frontpage_pagination_totals( $rendered_ids, $per_page = 10 ) {
    $total_posts = (int) wp_count_posts( 'post' )->publish;
    $extra_posts = max( 0, $total_posts - count( $rendered_ids ) );
    $extra_pages = (int) ceil( $extra_posts / $per_page );
    return array(
        'total_pages' => 1 + $extra_pages,
        'total_posts' => $total_posts,
    );
}

// ============================================================================
// Card helpers — small functions reused across multiple slot types
// ============================================================================

/**
 * Resolve a display dek/intro for a card.
 *
 * Cascade (first non-empty wins):
 *   1. ACF `dek`         — the dedicated subtitle field (preferred)
 *   2. `get_the_excerpt` — native fallback (uses post_excerpt or
 *                          auto-trims post_content)
 *
 * Falls back to a half-opacity "(sem resumo)" so the layout never
 * collapses on a card that has neither set.
 *
 * @param int $words Word count to truncate to
 * @return string Escaped HTML safe to echo
 */
function newpixel_card_dek( $words = 28 ) {
    $candidates = array();

    if ( function_exists( 'get_field' ) ) {
        $candidates[] = (string) get_field( 'dek' );
    }
    $candidates[] = (string) get_the_excerpt();

    foreach ( $candidates as $raw ) {
        $clean = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( strip_shortcodes( $raw ) ) ) );
        if ( $clean !== '' ) {
            return esc_html( wp_trim_words( $clean, $words ) );
        }
    }

    return '<span style="opacity:0.5">(sem resumo)</span>';
}

/**
 * Render a card author meta block — "publicado há X · N comentários".
 * Single-author site: avatar/name intentionally omitted.
 */
function newpixel_card_meta() {
    ?>
    <div class="kf-card__meta">
        <span><?php newpixel_time_ago(); ?></span>
        <span aria-hidden="true">·</span>
        <?php newpixel_meta_comments(); ?>
    </div>
    <?php
}

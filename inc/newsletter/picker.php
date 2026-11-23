<?php
/**
 * Newsletter — article picker, draft creation, issue rendering
 * ================================================================
 *
 * The "what goes in each edition" part of the pipeline:
 *   • newpixel_newsletter_pick_posts()    — algorithmic post selection
 *   • newpixel_newsletter_create_draft()  — materialise that pick as a CPT post
 *   • newpixel_newsletter_weekday_pt()    — small PT-PT weekday helper
 *   • newpixel_newsletter_render_issue()  — issue post ID → email HTML string
 *
 * Render delegates to inc/newsletter-template.php (loaded on demand) for
 * the actual HTML; this file owns selection logic and the data shaping
 * that the template expects.
 *
 * @package newpixel
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// ============================================================================
// Article query — top-viewed in last 4 days (inclusive), with fallback
// ============================================================================

/**
 * Return the ordered list of post IDs that should appear in the next issue.
 *
 * Order of selection:
 *   1. Pick top N posts from the last 4 days (inclusive), sorted by views DESC.
 *   2. If fewer than N posts came back (e.g. posts with 0 views / no meta),
 *      pull posts from the same 4-day window sorted by post_date DESC.
 *   3. If still under N posts (e.g. fewer than 5 posts published in 4 days),
 *      cascade to last 30 days by views DESC, then all published posts by date DESC.
 *
 * Final ordering applied to the returned array:
 *   • Index 0 = the most-viewed post (featured slot)
 *   • Index 1..N-1 = the remaining posts, sorted by post_date ASC
 *     (oldest → newest, so the email reads chronologically through
 *     the period after the featured story)
 *
 * @param int $limit       How many posts to include in the issue (default 5)
 * @param int $window_days How many days back to consider (default 4)
 * @return int[] Ordered list of post IDs
 */
function newpixel_newsletter_pick_posts( $limit = 5, $window_days = 4 ) {
    $views_key  = defined( 'NEWPIXEL_VIEWS_META_KEY' ) ? NEWPIXEL_VIEWS_META_KEY : '_newpixel_views';
    $after_date = wp_date( 'Y-m-d 00:00:00', strtotime( '-' . (int) $window_days . ' days', current_time( 'timestamp' ) ) );

    $primary = get_posts( array(
        'post_type'           => 'post',
        'post_status'         => 'publish',
        'posts_per_page'      => $limit,
        'meta_key'            => $views_key,
        'orderby'             => 'meta_value_num',
        'order'               => 'DESC',
        'ignore_sticky_posts' => 1,
        'date_query'          => array(
            array(
                'after'     => $after_date,
                'inclusive' => true,
            ),
        ),
        'fields'              => 'ids',
        'no_found_rows'       => true,
    ) );

    $picked = $primary;

    // Fallback 1: posts in the same 4-day window that might not have views meta yet
    if ( count( $picked ) < $limit ) {
        $needed = $limit - count( $picked );
        $fallback_window = get_posts( array(
            'post_type'           => 'post',
            'post_status'         => 'publish',
            'posts_per_page'      => $needed,
            'orderby'             => 'date',
            'order'               => 'DESC',
            'ignore_sticky_posts' => 1,
            'post__not_in'        => $picked,
            'date_query'          => array(
                array(
                    'after'     => $after_date,
                    'inclusive' => true,
                ),
            ),
            'fields'              => 'ids',
            'no_found_rows'       => true,
        ) );
        $picked = array_merge( $picked, $fallback_window );
    }

    // Fallback 2: if still under limit, pull top-viewed from the last 30 days
    if ( count( $picked ) < $limit ) {
        $needed = $limit - count( $picked );
        $fallback_30d = get_posts( array(
            'post_type'           => 'post',
            'post_status'         => 'publish',
            'posts_per_page'      => $needed,
            'meta_key'            => $views_key,
            'orderby'             => 'meta_value_num',
            'order'               => 'DESC',
            'ignore_sticky_posts' => 1,
            'post__not_in'        => $picked,
            'date_query'          => array(
                array( 'after' => '30 days ago' ),
            ),
            'fields'              => 'ids',
            'no_found_rows'       => true,
        ) );
        $picked = array_merge( $picked, $fallback_30d );
    }

    // Fallback 3: if still under limit, pull most-recent published posts
    if ( count( $picked ) < $limit ) {
        $needed = $limit - count( $picked );
        $fallback_any = get_posts( array(
            'post_type'           => 'post',
            'post_status'         => 'publish',
            'posts_per_page'      => $needed,
            'orderby'             => 'date',
            'order'               => 'DESC',
            'ignore_sticky_posts' => 1,
            'post__not_in'        => $picked,
            'fields'              => 'ids',
            'no_found_rows'       => true,
        ) );
        $picked = array_merge( $picked, $fallback_any );
    }

    if ( empty( $picked ) ) return array();

    // Re-shape: featured first (the top-viewed), then the rest by
    // post_date ASC. This matches what the editor explicitly wants:
    // chronological reading after the lead story.
    $featured_id = array_shift( $picked );
    if ( ! empty( $picked ) ) {
        usort( $picked, function ( $a, $b ) {
            $da = get_post_field( 'post_date', $a );
            $db = get_post_field( 'post_date', $b );
            return strcmp( (string) $da, (string) $db );
        } );
    }

    return array_merge( array( $featured_id ), $picked );
}

// ============================================================================
// Draft generation
// ============================================================================

/**
 * Create a new newsletter draft (called by the 07:00 cron, also by the
 * "Generate now" button in admin for testing).
 *
 * Idempotent for the same day: if a draft was already generated today
 * (publish-status === 'draft' AND post_date is today), reuses it instead
 * of creating a duplicate.
 *
 * @return int|WP_Error The created/found draft's post ID, or WP_Error.
 */
function newpixel_newsletter_create_draft() {
    // Avoid duplicates: did we already make a draft today? Match both
    // `draft` and `publish` since both are valid pre-send states.
    $today = current_time( 'Y-m-d' );
    $existing = get_posts( array(
        'post_type'      => NEWPIXEL_NEWSLETTER_CPT,
        'post_status'    => array( 'draft', 'publish' ),
        'posts_per_page' => 1,
        'date_query'     => array(
            array(
                'after'     => $today . ' 00:00:00',
                'before'    => $today . ' 23:59:59',
                'inclusive' => true,
                'column'    => 'post_date',
            ),
        ),
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ) );
    if ( ! empty( $existing ) ) {
        return (int) $existing[0];
    }

    $post_ids = newpixel_newsletter_pick_posts( 5, 4 );
    if ( empty( $post_ids ) ) {
        return new WP_Error(
            'no_posts',
            __( 'Not enough posts found to generate an issue.', 'newpixel' )
        );
    }

    // Issue number and title from registered counter
    $next_number = function_exists( 'newpixel_newsletter_get_next_edition_number' )
        ? newpixel_newsletter_get_next_edition_number()
        : 23;

    $draft_title = function_exists( 'newpixel_newsletter_generate_title' )
        ? newpixel_newsletter_generate_title( $next_number )
        : sprintf( 'Edição #%d · %s · %s', $next_number, newpixel_newsletter_weekday_pt(), newpixel_date_pt( 'j M Y' ) );

    $draft_id = wp_insert_post( array(
        'post_type'   => NEWPIXEL_NEWSLETTER_CPT,
        'post_status' => 'draft',
        'post_title'  => $draft_title,
    ), true );

    if ( is_wp_error( $draft_id ) ) return $draft_id;

    // Random headline pick from the ACF pool (with sane fallback).
    // Editor can edit afterwards if they want a custom intro for this one.
    $headline = function_exists( 'newpixel_newsletter_pick_random_headline' )
        ? newpixel_newsletter_pick_random_headline()
        : '<p>Cinco artigos. Cinco minutos.</p>';
    update_post_meta( $draft_id, NEWPIXEL_NEWSLETTER_META_HEADLINE, $headline );
    update_post_meta( $draft_id, NEWPIXEL_NEWSLETTER_META_POST_IDS, $post_ids );
    // Approval starts off — explicit opt-in to send.
    update_post_meta( $draft_id, NEWPIXEL_NEWSLETTER_META_APPROVED, '' );

    // Notify admin so they know there's a draft to peek at
    newpixel_newsletter_notify_admin_of_draft( $draft_id );

    return (int) $draft_id;
}

/**
 * Return today's weekday in Portuguese, short form ("Terça", "Sexta").
 * Used inside the issue title and the issue_label sent to the template.
 *
 * We index by numeric day-of-week (0=Sunday) instead of going through
 * date_i18n('l') because that latter returns the locale-long form
 * ("Sexta-feira") in pt_PT, which is too verbose for a newsletter
 * header — we want the casual, conversational short form.
 */
function newpixel_newsletter_weekday_pt() {
    $names = array( 'Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado' );
    $dow   = (int) current_time( 'w' );
    return $names[ $dow ] ?? 'Hoje';
}

// ============================================================================
// Rendering — turn a draft post ID into the email HTML string
// ============================================================================

/**
 * Render the HTML body for a given issue post.
 * Loads the template module on demand so the file pair only needs to
 * be parsed when we actually want to render something.
 *
 * @param int  $issue_id            Newsletter CPT post ID
 * @param array $extra_data         Optional overrides (unsubscribe_url, etc.)
 * @return string HTML email body
 */
function newpixel_newsletter_render_issue( $issue_id, $extra_data = array() ) {
    require_once __DIR__ . '/../newsletter-template.php';

    $issue = get_post( $issue_id );
    if ( ! $issue || $issue->post_type !== NEWPIXEL_NEWSLETTER_CPT ) return '';

    $post_ids = (array) get_post_meta( $issue_id, NEWPIXEL_NEWSLETTER_META_POST_IDS, true );
    $headline = (string) get_post_meta( $issue_id, NEWPIXEL_NEWSLETTER_META_HEADLINE, true );
    if ( ! $headline ) {
        $headline = function_exists( 'newpixel_newsletter_pick_random_headline' )
            ? newpixel_newsletter_pick_random_headline()
            : '<p>Cinco artigos. Cinco minutos.</p>';
    }

    $posts = array();
    foreach ( $post_ids as $i => $pid ) {
        $posts[] = newpixel_newsletter_build_post_record( (int) $pid, $i === 0 );
    }

    // Derive the header bits (issue number + the "Sexta · 14 Mai 2026" label)
    // from the post_title. For cron-generated drafts the title already follows
    // the "Edição #N · Weekday · D Mmm YYYY" pattern, so the regex extraction
    // works. For drafts the editor created manually but hasn't saved yet,
    // post_title is WP's default "Auto Draft" — in that case we compute fresh
    // values from the current date + CPT count so the preview reads sensibly
    // instead of showing "AUTO DRAFT" in the header.
    $title    = (string) $issue->post_title;
    $is_blank = ( $title === '' || strcasecmp( $title, 'Auto Draft' ) === 0 );

    if ( $is_blank ) {
        $issue_number = function_exists( 'newpixel_newsletter_get_next_edition_number' )
            ? newpixel_newsletter_get_next_edition_number()
            : 23;
        $issue_label = newpixel_newsletter_weekday_pt() . ' · ' . newpixel_date_pt( 'j M Y' );
    } else {
        preg_match( '/#(\d+)/', $title, $m );
        $issue_number = isset( $m[1] ) ? (int) $m[1] : ( function_exists( 'newpixel_newsletter_get_next_edition_number' ) ? newpixel_newsletter_get_next_edition_number() : 23 );
        $issue_label  = trim( preg_replace( '/^Edição\s+#\d+\s*·\s*/u', '', $title ) );
    }

    $data = array(
        'issue_number'    => $issue_number,
        'issue_label'     => $issue_label,
        'headline_html'   => $headline,
        'posts'           => $posts,
        'unsubscribe_url' => $extra_data['unsubscribe_url'] ?? '#unsubscribe',
        'browser_url'     => $extra_data['browser_url']     ?? '#browser',
        'subscribed_date' => $extra_data['subscribed_date'] ?? '',
    );

    return newpixel_newsletter_render_html( $data );
}

<?php
/**
 * Newsletter — cron scheduling and callbacks
 * ================================================================
 *
 * Schedule overview (manual model: cron generates nothing, it only sends):
 *   • 08:00 / 12:00 / 18:00 daily → newpixel_newsletter_cron_send_window
 *       Tue/Fri only. Finds ALL `publish` + approved issues (meta = '1'),
 *       sends each one and moves it to the trash. Drafts and unapproved
 *       published issues are ignored. There is no date restriction: the three
 *       windows just mean the editor doesn't have to be at the keyboard at a
 *       set time, and the day's first window picks up the issue.
 *
 * `newpixel_newsletter_is_send_day()` is filterable so cadence can be
 * shifted without touching code.
 *
 * @package newpixel
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// ============================================================================
// Schedule registration
// ============================================================================

add_action( 'init', 'newpixel_newsletter_schedule_cron' );
function newpixel_newsletter_schedule_cron() {
    // Only the three send windows. There is NO generation cron: the editor creates
    // issues by hand, and cron only sends those published + approved
    // (see newpixel_newsletter_cron_send_window).
    $jobs = array(
        array( NEWPIXEL_NEWSLETTER_CRON_SEND_0800, 8  ),
        array( NEWPIXEL_NEWSLETTER_CRON_SEND_1200, 12 ),
        array( NEWPIXEL_NEWSLETTER_CRON_SEND_1800, 18 ),
    );
    foreach ( $jobs as [$hook, $hour] ) {
        if ( ! wp_next_scheduled( $hook ) ) {
            wp_schedule_event( newpixel_newsletter_next_run_at( $hour ), 'daily', $hook );
        }
    }

    // Migration: unschedule the old generation cron, which is no longer used,
    // so it can't fire in production.
    $gen = wp_next_scheduled( NEWPIXEL_NEWSLETTER_CRON_GENERATE );
    if ( $gen ) wp_unschedule_event( $gen, NEWPIXEL_NEWSLETTER_CRON_GENERATE );

    // Migration: an even older version used a single
    // 'newpixel_newsletter_send' hook. Clear it too.
    $legacy = wp_next_scheduled( 'newpixel_newsletter_send' );
    if ( $legacy ) wp_unschedule_event( $legacy, 'newpixel_newsletter_send' );
}

/**
 * Calculate the Unix timestamp of the next occurrence of `$hour:00` in
 * the site's local timezone. If we're already past that hour today,
 * returns the same hour tomorrow.
 */
function newpixel_newsletter_next_run_at( $hour ) {
    $tz  = wp_timezone();
    $now = new DateTimeImmutable( 'now', $tz );
    $target = $now->setTime( $hour, 0, 0 );
    if ( $target <= $now ) {
        $target = $target->modify( '+1 day' );
    }
    return $target->getTimestamp();
}

// ============================================================================
// Cron callbacks
// ============================================================================

// Three send windows hit the same callback (only their time differs).
// Three add_actions = three independent cron entries.
add_action( NEWPIXEL_NEWSLETTER_CRON_SEND_0800, 'newpixel_newsletter_cron_send_window' );
add_action( NEWPIXEL_NEWSLETTER_CRON_SEND_1200, 'newpixel_newsletter_cron_send_window' );
add_action( NEWPIXEL_NEWSLETTER_CRON_SEND_1800, 'newpixel_newsletter_cron_send_window' );

/**
 * Send window (Tue/Fri 08/12/18). Manual model:
 *   • Finds ALL issues with `publish` status AND approved meta = '1'.
 *   • Drafts (and unapproved published issues) are fully ignored.
 *   • No date restriction: the editor publishes + approves whenever; the next
 *     window picks them up.
 *   • Each sent issue is moved to the TRASH by newpixel_newsletter_dispatch(),
 *     so later windows don't resend it (the query only sees `publish`).
 */
function newpixel_newsletter_cron_send_window() {
    if ( ! newpixel_newsletter_is_send_day() ) return;

    $issues = get_posts( array(
        'post_type'      => NEWPIXEL_NEWSLETTER_CPT,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_query'     => array(
            array(
                'key'     => NEWPIXEL_NEWSLETTER_META_APPROVED,
                'value'   => '1',
                'compare' => '=',
            ),
        ),
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ) );

    foreach ( $issues as $issue_id ) {
        newpixel_newsletter_dispatch( (int) $issue_id );
    }
}

// ============================================================================
// Helpers
// ============================================================================

/**
 * Is today a newsletter day? (Tuesday or Friday in the site's TZ)
 * Filterable so the user can shift cadence without touching code.
 */
function newpixel_newsletter_is_send_day() {
    $weekday = (int) current_time( 'w' ); // 0=Sun … 6=Sat
    // Default: Tuesday (2) + Friday (5)
    $send_days = apply_filters( 'newpixel_newsletter_send_days', array( 2, 5 ) );
    return in_array( $weekday, (array) $send_days, true );
}

// ============================================================================
// Cleanup
// ============================================================================
//
// Theme activation/deactivation hooks would need wiring in functions.php for
// this to actually fire on theme switch. For now, the schedules are self-
// managing: if the file isn't loaded, WP_CRON just no-ops the callbacks.
register_deactivation_hook( __FILE__, 'newpixel_newsletter_clear_cron' );
function newpixel_newsletter_clear_cron() {
    wp_clear_scheduled_hook( NEWPIXEL_NEWSLETTER_CRON_GENERATE );
    wp_clear_scheduled_hook( NEWPIXEL_NEWSLETTER_CRON_SEND_0800 );
    wp_clear_scheduled_hook( NEWPIXEL_NEWSLETTER_CRON_SEND_1200 );
    wp_clear_scheduled_hook( NEWPIXEL_NEWSLETTER_CRON_SEND_1800 );
    // Legacy single hook (from earlier versions of this file)
    wp_clear_scheduled_hook( 'newpixel_newsletter_send' );
}

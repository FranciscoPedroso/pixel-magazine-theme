<?php
/**
 * ntfy.sh notification and alarm system for Pixel Magazine.
 *
 * Provides centralized error and alarm dispatching via ntfy.sh.
 * Configured via castle.php:
 *   define( 'NTFY_ALARM_TOPIC', 'your-secret-alarm-topic' ); // Preferred
 *   or fallback to:
 *   define( 'NTFY_PUBLISH_TOPIC', 'your-secret-topic' );     // Legacy
 *
 * Features:
 * - Centralized newpixel_ntfy_alert() helper.
 * - Missed schedule watchdog (detects scheduled posts that failed to auto-publish,
 *   dispatches an urgent alarm, and forces publication recovery).
 *
 * @package newpixel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve the ntfy topic from constants.
 * Checks NTFY_ALARM_TOPIC first, falls back to NTFY_PUBLISH_TOPIC.
 *
 * @return string Topic name or empty string if unconfigured.
 */
function newpixel_ntfy_get_topic() {
	if ( defined( 'NTFY_ALARM_TOPIC' ) && NTFY_ALARM_TOPIC ) {
		return (string) NTFY_ALARM_TOPIC;
	}
	if ( defined( 'NTFY_PUBLISH_TOPIC' ) && NTFY_PUBLISH_TOPIC ) {
		return (string) NTFY_PUBLISH_TOPIC;
	}
	return '';
}

/**
 * Dispatch an alarm notification via ntfy.sh.
 *
 * @param string $title    Notification title.
 * @param string $body     Notification body text.
 * @param string $tags     Comma-separated ntfy tags/emojis (e.g. 'warning,alarm_clock').
 * @param string $priority Priority level ('min', 'low', 'default', 'high', 'urgent').
 * @return array|WP_Error|false Response from wp_remote_post, or false if not configured.
 */
function newpixel_ntfy_alert( $title, $body, $tags = 'warning', $priority = 'high' ) {
	$topic = newpixel_ntfy_get_topic();
	if ( ! $topic ) {
		return false;
	}

	return wp_remote_post( 'https://ntfy.sh/' . rawurlencode( $topic ), array(
		'timeout'  => 5,
		'blocking' => false,
		'headers'  => array(
			'Title'    => (string) $title,
			'Tags'     => (string) $tags,
			'Priority' => (string) $priority,
		),
		'body'     => (string) $body,
	) );
}

/**
 * Register custom cron schedule interval (every 10 minutes).
 */
add_filter( 'cron_schedules', 'newpixel_ntfy_cron_schedules' );
function newpixel_ntfy_cron_schedules( $schedules ) {
	if ( ! isset( $schedules['ten_minutes'] ) ) {
		$schedules['ten_minutes'] = array(
			'interval' => 10 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 10 Minutes', 'newpixel' ),
		);
	}
	return $schedules;
}

/**
 * Schedule recurring cron check for missed scheduled posts.
 */
add_action( 'init', 'newpixel_ntfy_schedule_cron' );
function newpixel_ntfy_schedule_cron() {
	if ( ! wp_next_scheduled( 'newpixel_ntfy_check_missed_cron' ) ) {
		wp_schedule_event( time(), 'ten_minutes', 'newpixel_ntfy_check_missed_cron' );
	}
}
add_action( 'newpixel_ntfy_check_missed_cron', 'newpixel_ntfy_check_missed_schedules' );

/**
 * Supplementary check on wp-admin page loads throttled by a 5-minute transient.
 * Ensures timely detection even if WP-Cron ticks are spaced out on low-traffic periods.
 */
add_action( 'admin_init', 'newpixel_ntfy_check_missed_admin_throttle' );
function newpixel_ntfy_check_missed_admin_throttle() {
	if ( false === get_transient( 'newpixel_ntfy_missed_throttle' ) ) {
		set_transient( 'newpixel_ntfy_missed_throttle', 1, 5 * MINUTE_IN_SECONDS );
		newpixel_ntfy_check_missed_schedules();
	}
}

/**
 * Check for posts that missed their scheduled publication time.
 * If found:
 * 1. Alerts via ntfy (only once per scheduled attempt, deduplicated via post meta).
 * 2. Attempts to auto-publish the post to recover immediately.
 */
function newpixel_ntfy_check_missed_schedules() {
	global $wpdb;

	// Grace period: 5 minutes past scheduled GMT time before considering it missed.
	$threshold = gmdate( 'Y-m-d H:i:s', time() - ( 5 * MINUTE_IN_SECONDS ) );

	$missed_post_ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts}
		 WHERE post_status = 'future'
		   AND post_type = 'post'
		   AND post_date_gmt < %s
		   AND post_date_gmt != '0000-00-00 00:00:00'",
		$threshold
	) );

	if ( empty( $missed_post_ids ) ) {
		return;
	}

	foreach ( $missed_post_ids as $post_id ) {
		$post_id = (int) $post_id;

		// Deduplication check: only alert once per missed schedule event.
		if ( get_post_meta( $post_id, '_newpixel_ntfy_missed_alerted', true ) ) {
			continue;
		}

		$post = get_post( $post_id );
		if ( ! $post || 'future' !== $post->post_status ) {
			continue;
		}

		// Mark as alerted immediately to prevent race conditions or spam.
		update_post_meta( $post_id, '_newpixel_ntfy_missed_alerted', time() );

		$title          = html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
		$scheduled_date = get_date_from_gmt( $post->post_date_gmt, 'd/m/Y H:i' );
		$admin_url      = admin_url( 'post.php?post=' . $post_id . '&action=edit' );

		$body = "Artigo: {$title}\nData agendada: {$scheduled_date}\n\nO artigo não foi publicado na hora agendada. A executar tentativa de recuperação...\nPainel: {$admin_url}";

		newpixel_ntfy_alert(
			'🚨 Agendamento Falhado — Pixel Magazine',
			$body,
			'warning,alarm_clock',
			'urgent'
		);

		// Force auto-publish to heal the missed schedule.
		wp_publish_post( $post_id );
	}
}

/**
 * Reset missed schedule alert meta if an author updates a scheduled post.
 */
add_action( 'save_post', 'newpixel_ntfy_reset_missed_alert_on_save', 10, 2 );
function newpixel_ntfy_reset_missed_alert_on_save( $post_id, $post ) {
	if ( $post && 'future' === $post->post_status ) {
		delete_post_meta( $post_id, '_newpixel_ntfy_missed_alerted' );
	}
}

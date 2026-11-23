<?php
/**
 * View counter history: a daily snapshot in a rolling window.
 *
 * `inc/post-views.php` stores a single cumulative number per article, with no
 * record of WHEN each view happened, so "yesterday vs today" is impossible to
 * answer: that's a data limitation, not a code one.
 *
 * Here we store a snapshot of the totals once a day. The difference between two
 * snapshots gives the views gained in that interval, which is where the daily,
 * weekly and monthly comparisons come from.
 *
 * The window is 90 days, not 30. Comparing "last 30 days vs the previous 30"
 * needs snapshots from today, 30 days ago AND 60 days ago. The 90 days provide
 * that margin and also absorb days when cron fails.
 *
 * Cost: ~8 numbers per day × 90 days, a few KB in a non-autoloaded option.
 *
 * @package newpixel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const NEWPIXEL_VIEWS_HISTORY_OPTION = 'newpixel_views_history';
const NEWPIXEL_VIEWS_HISTORY_CRON   = 'newpixel_views_daily_snapshot';

/**
 * How many days of snapshots to keep.
 *
 * @return int
 */
function newpixel_views_history_days() {
	return (int) apply_filters( 'newpixel_views_history_days', 90 );
}

/**
 * Today's date in the site's timezone (snapshots are indexed by local day).
 *
 * @return string Y-m-d
 */
function newpixel_views_history_today() {
	return wp_date( 'Y-m-d' );
}

/**
 * Computes the current totals, i.e. the content of a snapshot.
 *
 * @return array
 */
function newpixel_views_history_measure() {
	$rows     = newpixel_views_dashboard_rows();
	$articles = newpixel_views_dashboard_describe(
		array_values( array_filter( $rows, static function ( $r ) { return 'post' === $r->post_type; } ) )
	);
	$pages    = newpixel_views_dashboard_describe(
		array_values( array_filter( $rows, static function ( $r ) { return 'page' === $r->post_type; } ) )
	);

	return array(
		'total'     => (int) ( $articles['total'] + $pages['total'] ),
		'articles'  => (int) $articles['total'],
		'pages'     => (int) $pages['total'],
		'published' => (int) $articles['count'],
		'viewed'    => (int) $articles['viewed'],
		'average'   => round( (float) $articles['average'], 4 ),
		'median'    => round( (float) $articles['median'], 4 ),
		'coverage'  => round( (float) $articles['coverage'], 4 ),
	);
}

/**
 * Reads the stored history, sorted by date.
 *
 * @return array date => metrics
 */
function newpixel_views_history_get() {
	$history = get_option( NEWPIXEL_VIEWS_HISTORY_OPTION, array() );
	if ( ! is_array( $history ) ) {
		return array();
	}
	ksort( $history );
	return $history;
}

/**
 * Records today's snapshot if missing, and prunes the window.
 *
 * Idempotent: calling it again on the same day does nothing after the first.
 *
 * @param bool $force Rewrite even if the day already exists.
 * @return bool Whether it saved.
 */
function newpixel_views_history_record( $force = false ) {
	$today   = newpixel_views_history_today();
	$history = newpixel_views_history_get();

	if ( ! $force && isset( $history[ $today ] ) ) {
		return false;
	}

	$history[ $today ] = newpixel_views_history_measure();
	ksort( $history );

	// Rolling window: the oldest entry leaves when a new one comes in.
	$max = newpixel_views_history_days();
	if ( count( $history ) > $max ) {
		$history = array_slice( $history, -$max, null, true );
	}

	update_option( NEWPIXEL_VIEWS_HISTORY_OPTION, $history, false );
	return true;
}

// ============================================================================
// Agendamento
// ============================================================================

add_action( NEWPIXEL_VIEWS_HISTORY_CRON, 'newpixel_views_history_record' );

add_action( 'after_setup_theme', function () {
	if ( ! wp_next_scheduled( NEWPIXEL_VIEWS_HISTORY_CRON ) ) {
		// First run right after next local midnight.
		$tomorrow = strtotime( 'tomorrow 00:10', current_time( 'timestamp' ) );
		wp_schedule_event( $tomorrow - ( (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ), 'daily', NEWPIXEL_VIEWS_HISTORY_CRON );
	}
} );

/**
 * Safety net: when the Dashboard opens and today's snapshot is missing, take it
 * on the spot. WP-Cron depends on traffic and can miss entire days; this way,
 * opening the dashboard is enough to keep the history free of gaps.
 */
add_action( 'load-index.php', function () {
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		return;
	}
	newpixel_views_history_record();
} );

// ============================================================================
// Reading and comparison
// ============================================================================

/**
 * Snapshot from N days ago, or the closest one BEFORE that.
 *
 * Tolerates gaps: if cron failed on the exact day, uses the closest earlier
 * snapshot within a margin. A slightly misaligned comparison beats none, and
 * the returned `date` always says which day it refers to.
 *
 * @param int $days_ago How many days back.
 * @param int $tolerance Margin in days.
 * @return array|null
 */
function newpixel_views_history_at( $days_ago, $tolerance = 3 ) {
	$history = newpixel_views_history_get();
	if ( empty( $history ) ) {
		return null;
	}

	$target = strtotime( newpixel_views_history_today() . ' -' . (int) $days_ago . ' days' );
	$best   = null;

	foreach ( $history as $date => $metrics ) {
		$stamp = strtotime( $date );
		if ( false === $stamp || $stamp > $target ) {
			continue;
		}
		if ( ( $target - $stamp ) > $tolerance * DAY_IN_SECONDS ) {
			continue;
		}
		// Sorted by date, so the last one that passes is the closest.
		$best = array( 'date' => $date, 'metrics' => $metrics );
	}

	return $best;
}

/**
 * Trend of a metric over N days.
 *
 * Two treatments, because the metrics differ in nature:
 *
 * - `counter` (total, articles, pages): cumulative values. What matters isn't
 *   the change in the total, which would always be positive and tiny, but how
 *   much was gained IN the period compared with the previous period.
 * - `ratio` (average, median, coverage): compares today's value directly with
 *   the value from N days ago.
 *
 * @param string $metric Metric key.
 * @param int    $days   Period length.
 * @param string $type   'counter' or 'ratio'.
 * @return array
 */
function newpixel_views_trend( $metric, $days, $type = 'counter' ) {
	$none = array( 'available' => false, 'direction' => 'flat', 'label' => '', 'title' => '' );

	$now = newpixel_views_history_measure();
	if ( ! isset( $now[ $metric ] ) ) {
		return $none;
	}

	$past = newpixel_views_history_at( $days );
	if ( null === $past || ! isset( $past['metrics'][ $metric ] ) ) {
		return array(
			'available' => false,
			'direction' => 'flat',
			'label'     => '',
			'title'     => sprintf(
				/* translators: %d: number of days */
				__( 'Ainda sem histórico de %d dias para comparar.', 'newpixel' ),
				$days
			),
		);
	}

	$current = (float) $now[ $metric ];
	$then    = (float) $past['metrics'][ $metric ];

	if ( 'ratio' === $type ) {
		$delta = $current - $then;
		$flat  = abs( $delta ) < 0.05;

		return array(
			'available' => true,
			'direction' => $flat ? 'flat' : ( $delta > 0 ? 'up' : 'down' ),
			'label'     => $flat ? '=' : sprintf( '%+.1f', $delta ),
			'title'     => sprintf(
				/* translators: 1: current value, 2: past value, 3: days, 4: date */
				__( 'Agora %1$s, há %3$d dias era %2$s (fotografia de %4$s).', 'newpixel' ),
				number_format_i18n( $current, 1 ),
				number_format_i18n( $then, 1 ),
				$days,
				$past['date']
			),
		);
	}

	// Counter: gain in the period vs gain in the previous period.
	$gain     = $current - $then;
	$previous = newpixel_views_history_at( $days * 2 );

	if ( null === $previous || ! isset( $previous['metrics'][ $metric ] ) ) {
		return array(
			'available' => true,
			'direction' => 'flat',
			'label'     => sprintf( '%+d', (int) $gain ),
			'title'     => sprintf(
				/* translators: 1: gain, 2: days */
				__( '%1$d nos últimos %2$d dias. Ainda sem período anterior para comparar.', 'newpixel' ),
				(int) $gain,
				$days
			),
		);
	}

	$prev_gain = $then - (float) $previous['metrics'][ $metric ];
	$flat      = abs( $gain - $prev_gain ) < 0.5;

	return array(
		'available' => true,
		'direction' => $flat ? 'flat' : ( $gain > $prev_gain ? 'up' : 'down' ),
		'label'     => sprintf( '%+d', (int) $gain ),
		'title'     => sprintf(
			/* translators: 1: gain, 2: days, 3: previous gain */
			__( '%1$d nos últimos %2$d dias, contra %3$d nos %2$d anteriores.', 'newpixel' ),
			(int) $gain,
			$days,
			(int) $prev_gain
		),
	);
}

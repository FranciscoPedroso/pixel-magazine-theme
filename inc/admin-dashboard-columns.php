<?php
/**
 * Restores the column selector on the Dashboard.
 *
 * WordPress 7.1 stopped registering the `layout_columns` screen option for the
 * Dashboard: neither `wp-admin/index.php` nor `wp-admin/includes/dashboard.php`
 * registers it, and without it `WP_Screen::render_screen_layout()` silently
 * returns. Screen Options is left with only the widget checkboxes, losing the
 * "Layout: 1 2 3 4" control.
 *
 * The mechanics are all still in core: `wp_dashboard()` still writes the
 * `columns-N` class and `get_columns()` still reads the per-user preference.
 * Only the registration is missing, and its filter still exists.
 *
 * With this back, a one-column Dashboard is a two-click choice, which is how
 * the "Pixel — Visualizações" widget gets the full width without custom code.
 *
 * @package newpixel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the layout option on the Dashboard screen again.
 *
 * @param array $columns Maximum columns per screen, keyed by screen ID.
 * @return array
 */
add_filter( 'screen_layout_columns', 'newpixel_dashboard_layout_columns' );
function newpixel_dashboard_layout_columns( $columns ) {
	if ( ! is_array( $columns ) ) {
		$columns = array();
	}

	// Only touch the Dashboard, and only if nothing else has handled this yet.
	if ( empty( $columns['dashboard'] ) ) {
		$columns['dashboard'] = 4;
	}

	return $columns;
}

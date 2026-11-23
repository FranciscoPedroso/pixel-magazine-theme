<?php
/**
 * Selectable light/dark mode for the WordPress administration area.
 *
 * The feature is deliberately self-contained: the compiled stylesheet is
 * enqueued only in wp-admin and switched by a small top-bar control.
 * To disable it, remove the single require_once line from functions.php.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Load the admin theme bundles without affecting the public site or login.
 */
function newpixel_enqueue_admin_dark_mode() {
	$dark_relative_path = '/dist/admin-dark-mode.css';
	$dark_absolute_path = get_theme_file_path( $dark_relative_path );

	if ( file_exists( $dark_absolute_path ) ) {
		wp_enqueue_style(
			'newpixel-admin-dark-mode',
			get_theme_file_uri( $dark_relative_path ),
			array(),
			filemtime( $dark_absolute_path ),
			'(prefers-color-scheme: dark)'
		);
	}

	$switcher_relative_path = '/dist/admin-theme-switcher.css';
	$switcher_absolute_path = get_theme_file_path( $switcher_relative_path );

	if ( file_exists( $switcher_absolute_path ) ) {
		wp_enqueue_style(
			'newpixel-admin-theme-switcher',
			get_theme_file_uri( $switcher_relative_path ),
			array(),
			filemtime( $switcher_absolute_path )
		);
	}

	$script_relative_path = '/dist/admin-theme.js';
	$script_absolute_path = get_theme_file_path( $script_relative_path );

	if ( file_exists( $script_absolute_path ) ) {
		wp_enqueue_script(
			'newpixel-admin-theme',
			get_theme_file_uri( $script_relative_path ),
			array(),
			filemtime( $script_absolute_path ),
			true
		);
	}
}
add_action( 'admin_enqueue_scripts', 'newpixel_enqueue_admin_dark_mode', 20 );

/**
 * Resolve the saved preference before the admin body is painted.
 *
 * The dark stylesheet defaults to the system media query. This small head
 * script only changes its media attribute when a saved choice overrides the
 * system preference, avoiding a flash of the wrong theme between pages.
 */
function newpixel_admin_theme_bootstrap() {
	$bootstrap = <<<'JS'
(function () {
	var storageKey = 'newpixel-admin-theme';
	var savedTheme = null;

	try {
		savedTheme = window.localStorage.getItem(storageKey);
	} catch (error) {
		savedTheme = null;
	}

	if (savedTheme !== 'dark' && savedTheme !== 'light') {
		savedTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
	}

	document.documentElement.dataset.npAdminTheme = savedTheme;

	var darkStylesheet = document.getElementById('newpixel-admin-dark-mode-css');
	if (darkStylesheet) {
		darkStylesheet.media = savedTheme === 'dark' ? 'all' : 'not all';
	}
}());
JS;

	wp_print_inline_script_tag(
		$bootstrap,
		array( 'id' => 'newpixel-admin-theme-bootstrap' )
	);
}
add_action( 'admin_head', 'newpixel_admin_theme_bootstrap', 0 );

/**
 * Add the theme selector immediately before the account/Howdy menu.
 *
 * @param WP_Admin_Bar $wp_admin_bar WordPress admin bar instance.
 */
function newpixel_admin_theme_admin_bar_selector( $wp_admin_bar ) {
	if ( ! is_admin() || ! is_user_logged_in() ) {
		return;
	}

	$title = '<button id="newpixel-admin-theme-toggle" class="newpixel-admin-theme-toggle" type="button" aria-pressed="false" aria-label="Mudar tema do painel" title="Mudar tema do painel">'
		. '<span class="newpixel-admin-theme-sun" aria-hidden="true">☀</span>'
		. '<span class="newpixel-admin-theme-moon" aria-hidden="true">☾</span>'
		. '</button>';

	$wp_admin_bar->add_node(
		array(
			'id'     => 'newpixel-theme-switcher',
			'parent' => 'top-secondary',
			'title'  => $title,
			'meta'   => array(
				'class' => 'newpixel-admin-theme-switcher',
			),
		)
	);
}
add_action( 'admin_bar_menu', 'newpixel_admin_theme_admin_bar_selector', 9990 );

/**
 * Removes the "Howdy," greeting from the account menu, keeping name and avatar.
 *
 * @param WP_Admin_Bar $wp_admin_bar Admin bar instance.
 */
function newpixel_admin_bar_remove_howdy( $wp_admin_bar ) {
	if ( ! is_admin() || ! is_user_logged_in() ) {
		return;
	}

	$account_node = $wp_admin_bar->get_node( 'my-account' );
	$current_user = wp_get_current_user();

	if ( ! $account_node || ! $current_user->exists() ) {
		return;
	}

	$account_node->title = '<span class="display-name">'
		. esc_html( $current_user->display_name )
		. '</span>'
		. get_avatar( $current_user->ID, 26 );

	$wp_admin_bar->add_node( (array) $account_node );
}
add_action( 'admin_bar_menu', 'newpixel_admin_bar_remove_howdy', 9995 );

/**
 * Load the dark content palette inside TinyMCE's isolated editor iframe.
 *
 * Using TinyMCE's content stylesheet mechanism avoids changing its
 * initialization object or the current Visual/Code mode.
 *
 * @param string $mce_css Comma-separated editor stylesheet URLs.
 * @return string
 */
function newpixel_admin_dark_mode_tinymce_css( $mce_css ) {
	if ( ! is_admin() ) {
		return $mce_css;
	}

	$relative_path = '/dist/admin-editor-dark-mode.css';
	$absolute_path = get_theme_file_path( $relative_path );

	if ( ! file_exists( $absolute_path ) ) {
		return $mce_css;
	}

	$stylesheet_url = add_query_arg(
		'ver',
		filemtime( $absolute_path ),
		get_theme_file_uri( $relative_path )
	);

	return $mce_css ? $mce_css . ',' . $stylesheet_url : $stylesheet_url;
}
add_filter( 'mce_css', 'newpixel_admin_dark_mode_tinymce_css', 20 );

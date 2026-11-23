<?php
/**
 * Google News sitemap — /news-sitemap.xml
 * ================================================================
 *
 * Lists posts published in the LAST 48 HOURS with the Google News
 * `<news:news>` tags (publication name + language, publication date,
 * title). Google News uses this to discover fresh articles quickly.
 *
 * The WordPress core sitemap (wp-sitemap.xml) is generic and can't
 * carry the `news:` namespace, so this is a separate endpoint. Submit
 * the pretty URL in Search Console (Sitemaps → news-sitemap.xml).
 *
 * Served at:
 *   • /news-sitemap.xml           (pretty, via rewrite rule)
 *   • /?newpixel_news_sitemap=1    (always works, even before the flush)
 *
 * @package newpixel
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

const NEWPIXEL_NEWS_SITEMAP_QV = 'newpixel_news_sitemap';

// Expose the query var so WP routes ?newpixel_news_sitemap=1 to us.
add_filter( 'query_vars', function ( $vars ) {
	$vars[] = NEWPIXEL_NEWS_SITEMAP_QV;
	return $vars;
} );

// Register the pretty rewrite + auto-flush ONCE (guarded by an option) so the
// /news-sitemap.xml URL works after a deploy without a manual permalink re-save.
add_action( 'init', 'newpixel_news_sitemap_rewrite' );
function newpixel_news_sitemap_rewrite() {
	add_rewrite_rule( '^news-sitemap\.xml$', 'index.php?' . NEWPIXEL_NEWS_SITEMAP_QV . '=1', 'top' );
	if ( get_option( 'newpixel_news_sitemap_rules_v1' ) !== '1' ) {
		flush_rewrite_rules( false );
		update_option( 'newpixel_news_sitemap_rules_v1', '1', false );
	}
}

// Render the news sitemap and exit. Priority 0 so we run BEFORE
// redirect_canonical (template_redirect, prio 10) — otherwise WP appends a
// trailing slash to /news-sitemap.xml and the rewrite (anchored `$`) no longer
// matches, 301-looping the request.
add_action( 'template_redirect', 'newpixel_render_news_sitemap', 0 );
function newpixel_render_news_sitemap() {
	if ( ! get_query_var( NEWPIXEL_NEWS_SITEMAP_QV ) ) {
		return;
	}

	$posts = get_posts( array(
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'posts_per_page'      => 1000, // Google News sitemap hard cap.
		'orderby'             => 'date',
		'order'               => 'DESC',
		'ignore_sticky_posts' => 1,
		'no_found_rows'       => true,
		// Google News only wants articles from the last 2 days; older ones
		// must be dropped from the news sitemap.
		'date_query'          => array(
			array( 'after' => '48 hours ago', 'column' => 'post_date_gmt', 'inclusive' => true ),
		),
	) );

	$pub_name = apply_filters( 'newpixel_news_publication_name', get_bloginfo( 'name' ) );
	$language = apply_filters( 'newpixel_news_publication_language', 'pt' );

	if ( ! headers_sent() ) {
		header( 'Content-Type: application/xml; charset=UTF-8' );
	}

	echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n";

	foreach ( $posts as $p ) {
		$dt   = get_post_datetime( $p );
		$date = $dt ? $dt->format( 'c' ) : get_post_time( 'c', true, $p );
		printf(
			"  <url>\n    <loc>%s</loc>\n    <news:news>\n      <news:publication>\n        <news:name>%s</news:name>\n        <news:language>%s</news:language>\n      </news:publication>\n      <news:publication_date>%s</news:publication_date>\n      <news:title>%s</news:title>\n    </news:news>\n  </url>\n",
			esc_url( get_permalink( $p ) ),
			esc_html( $pub_name ),
			esc_html( $language ),
			esc_html( $date ),
			esc_html( get_the_title( $p ) )
		);
	}

	echo '</urlset>';
	exit;
}

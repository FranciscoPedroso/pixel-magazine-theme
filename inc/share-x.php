<?php
/**
 * "Share on X" — manual share button (X Web Intent).
 *
 * Opens X's compose window pre-filled with the post's DEK as the tweet text +
 * the permalink, for the editor to review and post with a single click. Uses
 * the official share endpoint (x.com/intent/post) — no API, no cost, no ToS
 * issue.
 *
 * Why the dek (not the title) in the tweet text: X no longer renders the card
 * description — it shows only the cover image with the title overlaid + the
 * domain. So the dek would never be seen if left to the og card; the tweet
 * body is the only place it shows on X. The title still appears, over the
 * image, because og:title is the bare headline (see inc/seo.php).
 *
 * Surfaces (both admin-side, PUBLISHED `post` items only):
 *   1. A "Share" column on the Posts list, right after Date.
 *   2. A side meta box on the post edit screen.
 *
 * @package newpixel
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Build the X Web Intent URL — the compose window pre-filled with the post's
 * title (text) and permalink (url). RFC3986 encoding keeps spaces as %20 so
 * the query is valid.
 */
function newpixel_x_share_url( $post ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return '';
	}

	// Tweet text = the dek (the hook). The X card already shows the cover image
	// with the title over it, so the tweet body leads with the dek — the same
	// description used for og:description (see inc/seo.php). Falls back to the
	// title if there's no dek / excerpt / content.
	$text = '';
	if ( function_exists( 'newpixel_seo_meta_description' ) ) {
		$text = trim( (string) newpixel_seo_meta_description( $post->ID ) );
	}
	if ( $text === '' ) {
		$text = get_the_title( $post );
	}

	return 'https://x.com/intent/post?' . http_build_query(
		array(
			'text' => $text,
			'url'  => get_permalink( $post ),
		),
		'',
		'&',
		PHP_QUERY_RFC3986
	);
}

/**
 * Posts list → add a "Share" column after Date (Date is normally the last
 * column, so appending lands right after it).
 */
add_filter( 'manage_post_posts_columns', 'newpixel_x_share_column' );
function newpixel_x_share_column( $cols ) {
	$cols['newpixel_share_x'] = esc_html__( 'Share', 'newpixel' );
	return $cols;
}

/**
 * Render the column cell: a button on published posts, an em dash otherwise.
 */
add_action( 'manage_post_posts_custom_column', 'newpixel_x_share_column_render', 10, 2 );
function newpixel_x_share_column_render( $column, $post_id ) {
	if ( 'newpixel_share_x' !== $column ) {
		return;
	}

	$post = get_post( $post_id );
	if ( ! $post || 'publish' !== $post->post_status ) {
		echo '&mdash;';
		return;
	}

	echo '<div class="newpixel-share-x-cell">';
	printf(
		'<a href="%s" target="_blank" rel="noopener noreferrer" class="button button-small">%s</a>',
		esc_url( newpixel_x_share_url( $post ) ),
		esc_html__( 'Share on X', 'newpixel' )
	);
	if (
		function_exists( 'newpixel_buffer_x_can_mark_manual' )
		&& newpixel_buffer_x_can_mark_manual( $post_id )
		&& current_user_can( 'edit_post', $post_id )
	) {
		printf(
			'<a href="%1$s" class="button button-small newpixel-mark-x-manual" onclick="return window.confirm(\'%2$s\');">%3$s</a>',
			esc_url( newpixel_buffer_x_manual_mark_url( $post_id ) ),
			esc_js( __( 'Only continue after the post is live on X. Mark it as posted manually?', 'newpixel' ) ),
			esc_html__( 'Mark as posted manually', 'newpixel' )
		);
	}
	if ( function_exists( 'newpixel_buffer_x_admin_status_badge' ) ) {
		echo newpixel_buffer_x_admin_status_badge( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes every value.
	}
	echo '</div>';
}

/**
 * Post edit screen → side meta box with the share button. Registered ONLY for
 * published posts, so it never appears on drafts or scheduled posts.
 */
add_action( 'add_meta_boxes', 'newpixel_x_share_metabox_register', 10, 2 );
function newpixel_x_share_metabox_register( $post_type, $post ) {
	if ( 'post' !== $post_type || ! $post || 'publish' !== $post->post_status ) {
		return;
	}

	add_meta_box(
		'newpixel-share-x',
		__( 'Share on X', 'newpixel' ),
		'newpixel_x_share_metabox_render',
		'post',
		'side',
		'high'
	);
}

function newpixel_x_share_metabox_render( $post ) {
	echo '<div class="newpixel-share-x-actions">';
	printf(
		'<a href="%s" target="_blank" rel="noopener noreferrer" class="button button-primary" style="width:100%%;box-sizing:border-box;text-align:center;">%s</a>',
		esc_url( newpixel_x_share_url( $post ) ),
		esc_html__( 'Share on X →', 'newpixel' )
	);
	if (
		function_exists( 'newpixel_buffer_x_can_mark_manual' )
		&& newpixel_buffer_x_can_mark_manual( $post->ID )
		&& current_user_can( 'edit_post', $post->ID )
	) {
		printf(
			'<a href="%1$s" class="button newpixel-mark-x-manual" onclick="return window.confirm(\'%2$s\');">%3$s</a>',
			esc_url( newpixel_buffer_x_manual_mark_url( $post->ID ) ),
			esc_js( __( 'Only continue after the post is live on X. Mark it as posted manually?', 'newpixel' ) ),
			esc_html__( 'Mark as posted manually', 'newpixel' )
		);
	}
	echo '</div>';

	if ( function_exists( 'newpixel_buffer_x_admin_status_badge' ) ) {
		printf(
			'<div class="newpixel-share-x-buffer-state"><strong>%1$s</strong>%2$s</div>',
			esc_html__( 'Buffer auto-post', 'newpixel' ),
			newpixel_buffer_x_admin_status_badge( $post->ID ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes every value.
		);
	}
}

/**
 * Compact styles for the status label on both admin surfaces.
 */
add_action( 'admin_head', 'newpixel_x_share_admin_styles' );
function newpixel_x_share_admin_styles() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'post' !== $screen->post_type || ! in_array( $screen->base, array( 'edit', 'post' ), true ) ) {
		return;
	}
	?>
	<style>
		.newpixel-share-x-cell {
			display: flex;
			flex-direction: column;
			align-items: flex-start;
			gap: 6px;
		}
		.newpixel-share-x-cell > .button,
		.newpixel-share-x-cell > .newpixel-buffer-x-status {
			margin: 0;
			max-width: 100%;
		}
		.newpixel-share-x-buffer-state {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 8px;
			margin-top: 10px;
			padding-top: 10px;
			border-top: 1px solid #dcdcde;
		}
		.newpixel-share-x-buffer-state strong { font-size: 12px; }
		.newpixel-buffer-x-status {
			display: inline-flex;
			align-items: center;
			gap: 5px;
			min-height: 22px;
			padding: 1px 7px;
			border: 1px solid #c3c4c7;
			border-radius: 999px;
			background: #f6f7f7;
			color: #50575e;
			font-size: 11px;
			font-weight: 600;
			line-height: 1.4;
			white-space: nowrap;
			box-sizing: border-box;
		}
		.newpixel-share-x-actions {
			display: grid;
			gap: 8px;
		}
		.newpixel-share-x-actions .newpixel-mark-x-manual {
			width: 100%;
			box-sizing: border-box;
			text-align: center;
		}
		.newpixel-buffer-x-status::before {
			content: '';
			width: 7px;
			height: 7px;
			border-radius: 50%;
			background: currentColor;
			flex: 0 0 auto;
		}
		.newpixel-buffer-x-status--pending {
			border-color: #dba617;
			background: #fcf9e8;
			color: #8a5d00;
		}
		.newpixel-buffer-x-status--processing {
			border-color: #72aee6;
			background: #f0f6fc;
			color: #135e96;
		}
		.newpixel-buffer-x-status--draft,
		.newpixel-buffer-x-status--sent,
		.newpixel-buffer-x-status--published {
			border-color: #68de7c;
			background: #edfaef;
			color: #1e6b3a;
		}
		.newpixel-buffer-x-status--sending {
			border-color: #72aee6;
			background: #f0f6fc;
			color: #135e96;
		}
		.newpixel-buffer-x-status--manual {
			border-color: #00a0d2;
			background: #f0f8ff;
			color: #006ba1;
		}
		.newpixel-buffer-x-status--failed {
			border-color: #d63638;
			background: #fcf0f1;
			color: #b32d2e;
		}

		/* Posts list: reserve only what the utility columns need and let the
		 * title use all remaining horizontal space. Below WordPress' mobile
		 * breakpoint, the native responsive list-table layout takes over. */
		@media screen and (min-width: 783px) {
			body.post-type-post.edit-php .wp-list-table.fixed {
				table-layout: fixed;
			}
			body.post-type-post.edit-php .wp-list-table tr > .column-title,
			body.post-type-post.edit-php .wp-list-table tr > .column-author,
			body.post-type-post.edit-php .wp-list-table tr > .column-categories,
			body.post-type-post.edit-php .wp-list-table tr > .column-tags,
			body.post-type-post.edit-php .wp-list-table tr > .column-newpixel_views,
			body.post-type-post.edit-php .wp-list-table tr > .column-comments,
			body.post-type-post.edit-php .wp-list-table tr > .column-date,
			body.post-type-post.edit-php .wp-list-table tr > .column-newpixel_share_x {
				border-left: 1px solid #dcdcde;
				box-sizing: border-box;
			}
			body.post-type-post.edit-php .wp-list-table .column-title {
				width: auto;
			}
			body.post-type-post.edit-php .wp-list-table .column-categories {
				width: 8%;
			}
			body.post-type-post.edit-php .wp-list-table .column-tags {
				width: 72px;
				box-sizing: border-box;
			}
			body.post-type-post.edit-php .wp-list-table .column-newpixel_views {
				width: 88px;
				padding-right: 10px;
				box-sizing: border-box;
			}
			body.post-type-post.edit-php .wp-list-table .column-newpixel_share_x {
				width: 180px;
				box-sizing: border-box;
			}
		}

		@media screen and (min-width: 783px) and (max-width: 1100px) {
			body.post-type-post.edit-php .wp-list-table .column-categories {
				width: 7%;
			}
			body.post-type-post.edit-php .wp-list-table .column-tags {
				width: 66px;
			}
			body.post-type-post.edit-php .wp-list-table .column-newpixel_views {
				width: 82px;
			}
			body.post-type-post.edit-php .wp-list-table .column-newpixel_share_x {
				width: 172px;
			}
		}
	</style>
	<?php
}

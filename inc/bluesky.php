<?php
/**
 * Auto-post to Bluesky when an article is published.
 * ================================================================
 *
 * On the publish transition of a post, schedule a NON-BLOCKING background
 * job that posts the article to the Pixel Magazine Bluesky account:
 * the dek as text + an external link card (cover image, title, dek, URL).
 *
 * Triggered by `transition_post_status` → so it also catches scheduled
 * posts (future → publish). The "Publish" click stays instant — the
 * actual API calls run on the next WP-Cron tick (driven by a server
 * cron), within a few minutes.
 *
 * Runs ONLY when configured → effectively prod-only. Credentials live in
 * castle.php (OUTSIDE the web root, same pattern as the Resend WPMS_*):
 *   define( 'BLUESKY_HANDLE', 'pixelmagazine.pt' );        // handle, no @
 *   define( 'BLUESKY_APP_PASSWORD', 'xxxx-xxxx-xxxx-xxxx' ); // App Password, NOT the account password
 *
 * Dedup: post meta `_bluesky_posted` (stores the created record URI) — each
 * post is shared once; posts published before this code existed are never
 * back-posted.
 *
 * @package newpixel
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

const NEWPIXEL_BLUESKY_META = '_bluesky_posted';
const NEWPIXEL_BLUESKY_HOOK = 'newpixel_bluesky_post';
const NEWPIXEL_BLUESKY_PDS  = 'https://bsky.social';

/** Integration configured? (defined only on prod, via castle.php.) */
function newpixel_bluesky_enabled() {
	return defined( 'BLUESKY_HANDLE' ) && defined( 'BLUESKY_APP_PASSWORD' )
		&& BLUESKY_HANDLE && BLUESKY_APP_PASSWORD;
}

// On (first) publish of a post, queue a one-off background job so the editor's
// "Publish" stays instant.
add_action( 'transition_post_status', 'newpixel_bluesky_on_publish', 10, 3 );
function newpixel_bluesky_on_publish( $new_status, $old_status, $post ) {
	if ( ! newpixel_bluesky_enabled() ) return;
	if ( 'publish' !== $new_status || 'publish' === $old_status ) return; // only the first publish
	if ( ! $post || 'post' !== $post->post_type ) return;
	if ( get_post_meta( $post->ID, NEWPIXEL_BLUESKY_META, true ) ) return; // already shared

	if ( ! wp_next_scheduled( NEWPIXEL_BLUESKY_HOOK, array( $post->ID ) ) ) {
		wp_schedule_single_event( time() + 30, NEWPIXEL_BLUESKY_HOOK, array( $post->ID ) );
	}
}

add_action( NEWPIXEL_BLUESKY_HOOK, 'newpixel_bluesky_dispatch' );
function newpixel_bluesky_dispatch( $post_id ) {
	if ( ! newpixel_bluesky_enabled() ) return;
	$post = get_post( $post_id );
	if ( ! $post || 'publish' !== $post->post_status || 'post' !== $post->post_type ) return;
	if ( get_post_meta( $post_id, NEWPIXEL_BLUESKY_META, true ) ) return;

	$result = newpixel_bluesky_share( $post );
	if ( is_wp_error( $result ) ) {
		// Log and leave un-flagged. No auto-retry (rare API hiccups → re-publish
		// or share manually). Avoids spamming on a persistent error.
		$err_message = $result->get_error_message();
		error_log( '[newpixel/bluesky] ' . $err_message );

		if ( function_exists( 'newpixel_ntfy_alert' ) ) {
			$title     = html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
			$admin_url = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
			newpixel_ntfy_alert(
				'⚠️ Falha Bluesky — Pixel Magazine',
				"Não foi possível publicar no Bluesky o artigo:\n\"{$title}\"\n\nErro: {$err_message}\nPainel: {$admin_url}",
				'warning,bluesky',
				'high'
			);
		}
		return;
	}
	update_post_meta( $post_id, NEWPIXEL_BLUESKY_META, $result ); // store the record URI
}

/**
 * Post the article to Bluesky. Returns the created record URI on success,
 * or WP_Error on failure.
 */
function newpixel_bluesky_share( $post ) {
	// 1) Authenticate (handle + app password) → access token + DID.
	$session = wp_remote_post( NEWPIXEL_BLUESKY_PDS . '/xrpc/com.atproto.server.createSession', array(
		'timeout' => 20,
		'headers' => array( 'Content-Type' => 'application/json' ),
		'body'    => wp_json_encode( array(
			'identifier' => BLUESKY_HANDLE,
			'password'   => BLUESKY_APP_PASSWORD,
		) ),
	) );
	if ( is_wp_error( $session ) ) return $session;
	$sdata = json_decode( wp_remote_retrieve_body( $session ), true );
	if ( empty( $sdata['accessJwt'] ) || empty( $sdata['did'] ) ) {
		return new WP_Error( 'bsky_auth', 'createSession falhou: ' . wp_remote_retrieve_body( $session ) );
	}
	$jwt = $sdata['accessJwt'];
	$did = $sdata['did'];

	// 2) Content. Text = dek (same as the X share); card carries title+image+url.
	$title = get_the_title( $post );
	$dek   = function_exists( 'newpixel_seo_meta_description' )
		? newpixel_seo_meta_description( $post->ID )
		: get_the_excerpt( $post );
	$dek   = trim( wp_strip_all_tags( (string) $dek ) );
	if ( '' === $dek ) $dek = $title;
	$text  = apply_filters( 'newpixel_bluesky_text', newpixel_bluesky_truncate( $dek, 300 ), $post );
	$url   = get_permalink( $post );

	// 3) Cover image → Bluesky blob (best-effort: post still goes out without it).
	$thumb = newpixel_bluesky_upload_cover( $post, $jwt );

	// 4) Build the post record with an external link card.
	$external = array(
		'uri'         => $url,
		'title'       => newpixel_bluesky_truncate( $title, 300 ),
		'description' => newpixel_bluesky_truncate( $dek, 1000 ),
	);
	if ( $thumb ) {
		$external['thumb'] = $thumb;
	}

	$record = array(
		'$type'     => 'app.bsky.feed.post',
		'text'      => $text,
		'createdAt' => gmdate( 'Y-m-d\TH:i:s' ) . '.000Z',
		'langs'     => array( 'pt' ),
		'embed'     => array(
			'$type'    => 'app.bsky.embed.external',
			'external' => $external,
		),
	);

	$create = wp_remote_post( NEWPIXEL_BLUESKY_PDS . '/xrpc/com.atproto.repo.createRecord', array(
		'timeout' => 20,
		'headers' => array(
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $jwt,
		),
		'body' => wp_json_encode( array(
			'repo'       => $did,
			'collection' => 'app.bsky.feed.post',
			'record'     => $record,
		) ),
	) );
	if ( is_wp_error( $create ) ) return $create;
	$cdata = json_decode( wp_remote_retrieve_body( $create ), true );
	if ( empty( $cdata['uri'] ) ) {
		return new WP_Error( 'bsky_create', 'createRecord falhou: ' . wp_remote_retrieve_body( $create ) );
	}
	return $cdata['uri'];
}

/**
 * Download the cover (np-16x9-1280, falls back to full) and upload it as a
 * Bluesky blob. Returns the blob ref array, or null (post goes out without it).
 */
function newpixel_bluesky_upload_cover( $post, $jwt ) {
	$tid = get_post_thumbnail_id( $post );
	if ( ! $tid ) return null;

	$src = wp_get_attachment_image_src( $tid, 'np-16x9-1280' );
	if ( ! $src ) $src = wp_get_attachment_image_src( $tid, 'full' );
	if ( ! $src ) return null;

	$img = wp_remote_get( $src[0], array( 'timeout' => 20 ) );
	if ( is_wp_error( $img ) ) return null;
	$bytes = wp_remote_retrieve_body( $img );
	if ( '' === $bytes ) return null;
	// Bluesky blob hard limit is ~1 MB; skip the thumb if the file is bigger.
	if ( strlen( $bytes ) > 976560 ) return null;

	$mime = wp_remote_retrieve_header( $img, 'content-type' );
	if ( ! $mime ) $mime = 'image/jpeg';

	$up = wp_remote_post( NEWPIXEL_BLUESKY_PDS . '/xrpc/com.atproto.repo.uploadBlob', array(
		'timeout' => 30,
		'headers' => array(
			'Content-Type'  => $mime,
			'Authorization' => 'Bearer ' . $jwt,
		),
		'body' => $bytes,
	) );
	if ( is_wp_error( $up ) ) return null;
	$udata = json_decode( wp_remote_retrieve_body( $up ), true );
	return isset( $udata['blob'] ) ? $udata['blob'] : null;
}

/** Trim to N characters (mb-safe), adding an ellipsis if cut. */
function newpixel_bluesky_truncate( $s, $n ) {
	$s = trim( (string) $s );
	if ( mb_strlen( $s ) <= $n ) return $s;
	return rtrim( mb_substr( $s, 0, $n - 1 ) ) . '…';
}

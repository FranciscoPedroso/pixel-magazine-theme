<?php
/**
 * AVIF → JPEG on upload
 * ============================================================================
 *
 * AVIF cover images break social distribution: the og:image is poorly handled
 * by the X / Facebook / WhatsApp / LinkedIn crawlers and by Bluesky's
 * `uploadBlob`, so a shared article can end up with no card image. AVIF reaches
 * the library mainly through the Drafter's og:image fallback path
 * (`pixel-og-*.avif`, used when the source article's Open Graph image happens
 * to be AVIF and AI generation was skipped/failed), and occasionally via a
 * manual upload.
 *
 * This converts any uploaded AVIF to JPEG at upload time — BEFORE WordPress
 * registers the attachment and generates its sub-sizes — so every downstream
 * size (`np-16x9-*`, etc.), the og:image and the Bluesky cover are born JPEG
 * and render everywhere. WebP is intentionally left untouched (it is well
 * supported as an og:image in 2026).
 *
 * Hooks both `wp_handle_upload` (admin uploads) and `wp_handle_sideload`
 * (programmatic / REST uploads — how the n8n Drafter sideloads its cover).
 *
 * Safe by design: it only acts on `image/avif`, and if the server's image
 * library can't read AVIF (or the save fails), it bails and keeps the original
 * untouched. On prod the server reads AVIF fine (WP 7.0 already generates AVIF
 * sub-sizes), so the conversion runs.
 *
 * @package newpixel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'wp_handle_upload', 'newpixel_avif_to_jpeg' );
add_filter( 'wp_handle_sideload', 'newpixel_avif_to_jpeg' );

/**
 * Convert an uploaded AVIF file to JPEG and rewrite the upload descriptor so
 * WordPress registers the JPEG instead of the AVIF.
 *
 * @param array $upload Upload descriptor: { file, url, type }. Returned as-is
 *                      (unchanged) on anything other than a successful AVIF
 *                      conversion, so an upload is never broken by this filter.
 * @return array
 */
function newpixel_avif_to_jpeg( $upload ) {
	if ( ! is_array( $upload ) || empty( $upload['file'] ) || empty( $upload['type'] ) ) {
		return $upload;
	}
	if ( 'image/avif' !== $upload['type'] ) {
		return $upload; // only AVIF — WebP/JPEG/PNG are left alone
	}

	$src = $upload['file'];

	// WordPress's own image editor (Imagick or GD, whichever is available and
	// AVIF-capable). If it can't read the AVIF, keep the original and move on.
	$editor = wp_get_image_editor( $src );
	if ( is_wp_error( $editor ) ) {
		return $upload;
	}

	$editor->set_quality( (int) apply_filters( 'jpeg_quality', 85, 'newpixel_avif_to_jpeg' ) );

	$dest = preg_replace( '/\.avif$/i', '.jpg', $src );
	if ( ! is_string( $dest ) || $dest === $src ) {
		$dest = $src . '.jpg'; // safety net if the name had no .avif extension
	}

	$saved = $editor->save( $dest, 'image/jpeg' );
	if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
		return $upload; // conversion failed — keep the AVIF, don't break the upload
	}

	// Drop the now-redundant AVIF original.
	if ( $saved['path'] !== $src ) {
		@unlink( $src );
	}

	// Rewrite the descriptor so WordPress stores the JPEG as the attachment;
	// from here it generates all sub-sizes (np-16x9-*, …) from the JPEG.
	$new_name       = wp_basename( $saved['path'] );
	$upload['url']  = str_replace( wp_basename( $src ), $new_name, $upload['url'] );
	$upload['file'] = $saved['path'];
	$upload['type'] = 'image/jpeg';

	return $upload;
}

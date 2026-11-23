<?php
/**
 * `tag_names` REST field: assign tags by NAME instead of by ID.
 *
 * Why: the WordPress REST API only accepts `tags` as an array of numeric IDs.
 * The n8n drafter generates tags as text ("android", "segurança"), and
 * converting name → ID on that side would take two HTTP requests per tag (find,
 * create if missing) before even creating the article. With this field the
 * drafter sends names and WordPress does the rest: wp_set_post_terms() accepts
 * strings and creates any missing terms.
 *
 * The field is write-only on purpose: tags are still read through the native
 * `tags` field, which returns IDs.
 *
 * @package newpixel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', function () {
	register_rest_field(
		'post',
		'tag_names',
		array(
			'get_callback'    => null, // write-only: read through the native `tags` field.
			'update_callback' => 'newpixel_rest_set_tag_names',
			'schema'          => array(
				'description' => __( 'Tags por nome. Os termos em falta são criados automaticamente.', 'newpixel' ),
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'context'     => array( 'edit' ),
			),
		)
	);
} );

/**
 * Assigns the post's tags from a list of names.
 *
 * @param mixed   $value Value received in the request.
 * @param WP_Post $post  Post to update.
 * @return true|WP_Error
 */
function newpixel_rest_set_tag_names( $value, $post ) {
	if ( ! is_array( $value ) ) {
		return true; // nothing to do; no reason to fail the request
	}

	if ( ! current_user_can( 'edit_post', $post->ID ) ) {
		return new WP_Error(
			'newpixel_rest_cannot_edit',
			__( 'Sem permissão para editar as tags deste artigo.', 'newpixel' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	// Normalise: text, trimmed, no empties, no repeats
	// (case-insensitive, so "Android" and "android" aren't created separately).
	$names = array();
	$seen  = array();

	foreach ( $value as $raw ) {
		if ( ! is_scalar( $raw ) ) {
			continue;
		}

		$name = trim( wp_strip_all_tags( (string) $raw ) );
		if ( '' === $name || mb_strlen( $name ) > 50 ) {
			continue;
		}

		$key = mb_strtolower( $name );
		if ( isset( $seen[ $key ] ) ) {
			continue;
		}

		$seen[ $key ] = true;
		$names[]      = $name;

		if ( count( $names ) >= 8 ) {
			break; // safety cap against malformed model output
		}
	}

	// An empty array clears the post's tags: deliberate, matching the native
	// `tags` field.
	$result = wp_set_post_terms( $post->ID, $names, 'post_tag', false );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return true;
}

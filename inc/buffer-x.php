<?php
/**
 * X publishing outbox, processed through Buffer and n8n.
 * ==================================================
 *
 * This module does NOT contact Buffer or post to X. When an article first
 * reaches `publish`, it stores a persistent entry on the post itself. An n8n
 * workflow can then:
 *
 *   1. list the pending articles;
 *   2. claim one while processing it;
 *   3. confirm the ID returned by Buffer; or
 *   4. return the article to the queue / mark it as failed.
 *
 * The queue does not depend on time windows. If n8n is offline for a few
 * hours, articles stay pending and are processed when it comes back.
 *
 * Configuration (outside the web root, in castle.php):
 *
 *   define( 'NEWPIXEL_BUFFER_X_TOKEN', 'random-secret-of-32-or-more-characters' );
 *
 * n8n sends the secret in the `X-Pixel-Automation-Key` header (preferred) or in
 * `Authorization: Bearer ...`. Without the constant the endpoints stay closed,
 * but the queue keeps recording new publications.
 *
 * @package newpixel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const NEWPIXEL_BUFFER_X_STATUS_META        = '_newpixel_buffer_x_status';
const NEWPIXEL_BUFFER_X_QUEUED_AT_META     = '_newpixel_buffer_x_queued_at';
const NEWPIXEL_BUFFER_X_ATTEMPTS_META      = '_newpixel_buffer_x_attempts';
const NEWPIXEL_BUFFER_X_LOCK_TOKEN_META    = '_newpixel_buffer_x_lock_token';
const NEWPIXEL_BUFFER_X_LOCKED_AT_META     = '_newpixel_buffer_x_locked_at';
const NEWPIXEL_BUFFER_X_WORKER_META        = '_newpixel_buffer_x_worker';
const NEWPIXEL_BUFFER_X_NEXT_RETRY_META    = '_newpixel_buffer_x_next_retry_at';
const NEWPIXEL_BUFFER_X_LAST_ERROR_META    = '_newpixel_buffer_x_last_error';
const NEWPIXEL_BUFFER_X_LAST_ERROR_AT_META = '_newpixel_buffer_x_last_error_at';
const NEWPIXEL_BUFFER_X_BUFFER_ID_META     = '_newpixel_buffer_x_buffer_post_id';
const NEWPIXEL_BUFFER_X_REMOTE_STATUS_META = '_newpixel_buffer_x_remote_status';
const NEWPIXEL_BUFFER_X_SENT_AT_META       = '_newpixel_buffer_x_sent_at';

const NEWPIXEL_BUFFER_X_CLAIM_TTL   = 30 * MINUTE_IN_SECONDS;
const NEWPIXEL_BUFFER_X_MAX_RETRIES = 5;

/**
 * Gets the Buffer automation status shown in wp-admin.
 *
 * Labels stay in English on purpose. The detail is used as a tooltip and never
 * exposes the claim token or other credentials.
 */
function newpixel_buffer_x_admin_status( $post_id ) {
	$post_id = (int) $post_id;
	$status  = (string) get_post_meta( $post_id, NEWPIXEL_BUFFER_X_STATUS_META, true );

	$data = array(
		'key'         => 'not-queued',
		'label'       => __( 'Not queued', 'newpixel' ),
		'description' => __( 'This article has no Buffer automation entry.', 'newpixel' ),
	);

	switch ( $status ) {
		case 'pending':
			$data = array(
				'key'         => 'pending',
				'label'       => __( 'Pending', 'newpixel' ),
				'description' => __( 'Waiting for the X Autopost workflow to run.', 'newpixel' ),
			);
			break;

		case 'processing':
			$data = array(
				'key'         => 'processing',
				'label'       => __( 'Processing', 'newpixel' ),
				'description' => __( 'The X Autopost workflow is processing this article.', 'newpixel' ),
			);
			break;

		case 'sent':
			$buffer_post_id = (string) get_post_meta( $post_id, NEWPIXEL_BUFFER_X_BUFFER_ID_META, true );
			$remote_status  = strtolower( trim( (string) get_post_meta( $post_id, NEWPIXEL_BUFFER_X_REMOTE_STATUS_META, true ) ) );
			$details        = array_filter(
				array(
					$buffer_post_id ? sprintf( 'Buffer ID: %s', $buffer_post_id ) : '',
					$remote_status ? sprintf( 'Remote status: %s', $remote_status ) : '',
				)
			);

			switch ( $remote_status ) {
				case 'draft':
					$data = array(
						'key'         => 'draft',
						'label'       => __( 'Draft created in Buffer', 'newpixel' ),
						'description' => $details
							? implode( ' · ', $details )
							: __( 'Buffer accepted the article as a draft.', 'newpixel' ),
					);
					break;

				case 'sending':
					$data = array(
						'key'         => 'sending',
						'label'       => __( 'Publishing via Buffer', 'newpixel' ),
						'description' => $details
							? implode( ' · ', $details )
							: __( 'Buffer is publishing this article on X.', 'newpixel' ),
					);
					break;

				case 'sent':
					$data = array(
						'key'         => 'published',
						'label'       => __( 'Published on X', 'newpixel' ),
						'description' => $details
							? implode( ' · ', $details )
							: __( 'Buffer published this article on X.', 'newpixel' ),
					);
					break;

				default:
					$data = array(
						'key'         => 'sent',
						'label'       => __( 'Accepted by Buffer', 'newpixel' ),
						'description' => $details
							? implode( ' · ', $details )
							: __( 'Buffer accepted this article.', 'newpixel' ),
					);
			}
			break;

		case 'manual':
			$data = array(
				'key'         => 'manual',
				'label'       => __( 'Posted manually', 'newpixel' ),
				'description' => __( 'Marked as posted directly on X. Buffer will ignore this article.', 'newpixel' ),
			);
			break;

		case 'failed':
			$last_error = trim( (string) get_post_meta( $post_id, NEWPIXEL_BUFFER_X_LAST_ERROR_META, true ) );
			$data       = array(
				'key'         => 'failed',
				'label'       => __( 'Failed', 'newpixel' ),
				'description' => $last_error
					? $last_error
					: __( 'The last Buffer attempt failed.', 'newpixel' ),
			);
			break;
	}

	return $data;
}

/**
 * Renders a compact, accessible label for the Buffer status.
 */
function newpixel_buffer_x_admin_status_badge( $post_id ) {
	$status = newpixel_buffer_x_admin_status( $post_id );

	return sprintf(
		'<span class="newpixel-buffer-x-status newpixel-buffer-x-status--%1$s" title="%2$s"><span class="screen-reader-text">%3$s: </span>%4$s</span>',
		esc_attr( $status['key'] ),
		esc_attr( $status['description'] ),
		esc_html__( 'Buffer auto-post status', 'newpixel' ),
		esc_html( $status['label'] )
	);
}

/**
 * Whether this status can still be closed as a manual publication.
 *
 * `processing` is never allowed: n8n has already claimed the article at that
 * point, and changing the status in parallel could create a duplicate on X.
 */
function newpixel_buffer_x_can_mark_manual( $post_id ) {
	$post   = get_post( $post_id );
	$status = (string) get_post_meta( (int) $post_id, NEWPIXEL_BUFFER_X_STATUS_META, true );

	return $post
		&& 'post' === $post->post_type
		&& 'publish' === $post->post_status
		&& in_array( $status, array( '', 'pending', 'failed' ), true );
}

/**
 * Nonce-protected URL to confirm a publication made by the editor.
 */
function newpixel_buffer_x_manual_mark_url( $post_id ) {
	$post_id = (int) $post_id;
	$url     = add_query_arg(
		array(
			'action'  => 'newpixel_buffer_x_mark_manual',
			'post_id' => $post_id,
		),
		admin_url( 'admin-post.php' )
	);

	return wp_nonce_url( $url, 'newpixel_buffer_x_mark_manual_' . $post_id );
}

/**
 * Atomically closes the outbox entry when the editor confirms posting on X.
 */
add_action( 'admin_post_newpixel_buffer_x_mark_manual', 'newpixel_buffer_x_mark_manual' );
function newpixel_buffer_x_mark_manual() {
	$post_id = isset( $_GET['post_id'] ) ? absint( wp_unslash( $_GET['post_id'] ) ) : 0;
	check_admin_referer( 'newpixel_buffer_x_mark_manual_' . $post_id );

	if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
		wp_die(
			esc_html__( 'You are not allowed to update this article.', 'newpixel' ),
			'',
			array( 'response' => 403 )
		);
	}

	$post = get_post( $post_id );
	if ( ! $post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
		newpixel_buffer_x_manual_redirect( 'invalid' );
	}

	$status  = (string) get_post_meta( $post_id, NEWPIXEL_BUFFER_X_STATUS_META, true );
	$updated = false;

	if ( 'manual' === $status ) {
		newpixel_buffer_x_manual_redirect( 'success' );
	}

	if ( '' === $status ) {
		$updated = add_post_meta( $post_id, NEWPIXEL_BUFFER_X_STATUS_META, 'manual', true );
	} elseif ( in_array( $status, array( 'pending', 'failed' ), true ) ) {
		// prev_value makes the change atomic: if n8n claims it meanwhile, this fails.
		$updated = update_post_meta( $post_id, NEWPIXEL_BUFFER_X_STATUS_META, 'manual', $status );
	}

	if ( ! $updated ) {
		newpixel_buffer_x_manual_redirect( 'conflict' );
	}

	update_post_meta( $post_id, NEWPIXEL_BUFFER_X_REMOTE_STATUS_META, 'manual' );
	update_post_meta( $post_id, NEWPIXEL_BUFFER_X_SENT_AT_META, time() );
	delete_post_meta( $post_id, NEWPIXEL_BUFFER_X_BUFFER_ID_META );
	delete_post_meta( $post_id, NEWPIXEL_BUFFER_X_LAST_ERROR_META );
	delete_post_meta( $post_id, NEWPIXEL_BUFFER_X_LAST_ERROR_AT_META );
	delete_post_meta( $post_id, NEWPIXEL_BUFFER_X_NEXT_RETRY_META );
	newpixel_buffer_x_clear_claim( $post_id );

	newpixel_buffer_x_manual_redirect( 'success' );
}

/**
 * Returns to the previous page with a short result for the wp-admin notice.
 */
function newpixel_buffer_x_manual_redirect( $result ) {
	$fallback = admin_url( 'edit.php?post_type=post' );
	$referer  = (string) wp_get_referer();
	$redirect = wp_validate_redirect( $referer, $fallback );
	$redirect = remove_query_arg( 'newpixel_x_manual_result', $redirect );
	$redirect = add_query_arg( 'newpixel_x_manual_result', sanitize_key( $result ), $redirect );

	wp_safe_redirect( $redirect );
	exit;
}

/**
 * Feedback for the manual confirmation in wp-admin.
 */
add_action( 'admin_notices', 'newpixel_buffer_x_manual_notice' );
function newpixel_buffer_x_manual_notice() {
	if ( empty( $_GET['newpixel_x_manual_result'] ) ) {
		return;
	}

	$result = sanitize_key( wp_unslash( $_GET['newpixel_x_manual_result'] ) );
	if ( 'success' === $result ) {
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'The article was marked as posted manually. Buffer will ignore it.', 'newpixel' )
		);
		return;
	}

	$message = 'conflict' === $result
		? __( 'The article state changed before it could be marked. Refresh the page and check its current status.', 'newpixel' )
		: __( 'Only published articles can be marked as posted manually.', 'newpixel' );

	printf(
		'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
		esc_html( $message )
	);
}

/**
 * Queues only the first publication of an article.
 *
 * `transition_post_status` includes `future → publish`, used by scheduled
 * articles. An article already sent keeps its status even if it is temporarily
 * unpublished and published again.
 */
add_action( 'transition_post_status', 'newpixel_buffer_x_on_publish', 10, 3 );
function newpixel_buffer_x_on_publish( $new_status, $old_status, $post ) {
	if ( 'publish' !== $new_status || 'publish' === $old_status ) {
		return;
	}
	if ( ! $post || 'post' !== $post->post_type ) {
		return;
	}

	// unique=true prevents two queue rows if two hooks run in parallel.
	$queued = add_post_meta( $post->ID, NEWPIXEL_BUFFER_X_STATUS_META, 'pending', true );
	if ( ! $queued ) {
		return;
	}

	update_post_meta( $post->ID, NEWPIXEL_BUFFER_X_QUEUED_AT_META, time() );
	update_post_meta( $post->ID, NEWPIXEL_BUFFER_X_ATTEMPTS_META, 0 );
}

/**
 * Registers the private API consumed by n8n.
 */
add_action( 'rest_api_init', 'newpixel_buffer_x_register_rest_routes' );
function newpixel_buffer_x_register_rest_routes() {
	$permission = 'newpixel_buffer_x_rest_permission';

	register_rest_route(
		'newpixel/v1',
		'/buffer-x/pending',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'newpixel_buffer_x_rest_pending',
			'permission_callback' => $permission,
			'args'                => array(
				'limit' => array(
					'default'           => 20,
					'sanitize_callback' => 'absint',
					'validate_callback' => function ( $value ) {
						$value = (int) $value;
						return $value >= 1 && $value <= 50;
					},
				),
			),
		)
	);

	register_rest_route(
		'newpixel/v1',
		'/buffer-x/(?P<id>\d+)/claim',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'newpixel_buffer_x_rest_claim',
			'permission_callback' => $permission,
			'args'                => array(
				'id'     => array(
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
				'worker' => array(
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);

	register_rest_route(
		'newpixel/v1',
		'/buffer-x/(?P<id>\d+)/complete',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'newpixel_buffer_x_rest_complete',
			'permission_callback' => $permission,
			'args'                => array(
				'id'             => array(
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
				'claim_token'    => array(
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'buffer_post_id' => array(
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'remote_status'  => array(
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);

	register_rest_route(
		'newpixel/v1',
		'/buffer-x/(?P<id>\d+)/fail',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'newpixel_buffer_x_rest_fail',
			'permission_callback' => $permission,
			'args'                => array(
				'id'          => array(
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
				'claim_token' => array(
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'error'       => array(
					'default'           => 'Erro não especificado.',
					'sanitize_callback' => 'sanitize_textarea_field',
				),
				'retryable'   => array(
					'default'           => true,
					'sanitize_callback' => 'rest_sanitize_boolean',
				),
			),
		)
	);

	register_rest_route(
		'newpixel/v1',
		'/buffer-x/(?P<id>\d+)/retry',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'newpixel_buffer_x_rest_retry',
			'permission_callback' => $permission,
			'args'                => array(
				'id' => array(
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
			),
		)
	);
}

/**
 * Authorises a logged-in administrator or n8n's dedicated key.
 *
 * @return true|WP_Error
 */
function newpixel_buffer_x_rest_permission( $request ) {
	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}

	$expected = defined( 'NEWPIXEL_BUFFER_X_TOKEN' )
		? trim( (string) NEWPIXEL_BUFFER_X_TOKEN )
		: '';
	$expected = (string) apply_filters( 'newpixel_buffer_x_token', $expected );

	if ( strlen( $expected ) < 32 ) {
		return new WP_Error(
			'newpixel_buffer_x_not_configured',
			'A integração Buffer X não está configurada.',
			array( 'status' => 503 )
		);
	}

	$provided = trim( (string) $request->get_header( 'x-pixel-automation-key' ) );
	if ( '' === $provided ) {
		$authorization = trim( (string) $request->get_header( 'authorization' ) );
		if ( preg_match( '/^Bearer\s+(.+)$/i', $authorization, $matches ) ) {
			$provided = trim( $matches[1] );
		}
	}

	if ( '' === $provided || ! hash_equals( $expected, $provided ) ) {
		return new WP_Error(
			'newpixel_buffer_x_unauthorized',
			'Chave de automação inválida.',
			array( 'status' => 401 )
		);
	}

	return true;
}

/**
 * Lists the items that can be claimed now.
 *
 * Includes claims abandoned for over 30 minutes. They only move back to
 * `processing` when the /claim endpoint reserves them, keeping this GET free of
 * side effects.
 */
function newpixel_buffer_x_rest_pending( $request ) {
	$limit = max( 1, min( 50, (int) $request->get_param( 'limit' ) ) );
	$now   = time();

	$query = new WP_Query(
		array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'posts_per_page'         => 100,
			'orderby'                => 'date',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'meta_query'             => array(
				array(
					'key'     => NEWPIXEL_BUFFER_X_STATUS_META,
					'value'   => array( 'pending', 'processing' ),
					'compare' => 'IN',
				),
			),
		)
	);

	$items = array();
	foreach ( $query->posts as $post ) {
		$status      = (string) get_post_meta( $post->ID, NEWPIXEL_BUFFER_X_STATUS_META, true );
		$next_retry  = (int) get_post_meta( $post->ID, NEWPIXEL_BUFFER_X_NEXT_RETRY_META, true );
		$locked_at   = (int) get_post_meta( $post->ID, NEWPIXEL_BUFFER_X_LOCKED_AT_META, true );
		$stale_claim = 'processing' === $status
			&& ( ! $locked_at || $locked_at <= $now - NEWPIXEL_BUFFER_X_CLAIM_TTL );

		if ( 'pending' === $status && $next_retry > $now ) {
			continue;
		}
		if ( 'processing' === $status && ! $stale_claim ) {
			continue;
		}

		$item                = newpixel_buffer_x_post_payload( $post );
		$item['stale_claim'] = $stale_claim;
		$items[]             = $item;

		if ( count( $items ) >= $limit ) {
			break;
		}
	}

	return newpixel_buffer_x_rest_response(
		array(
			'items'      => $items,
			'count'      => count( $items ),
			'checked_at' => gmdate( 'c', $now ),
		)
	);
}

/**
 * Claims an item for one specific n8n execution.
 */
function newpixel_buffer_x_rest_claim( $request ) {
	$post_id = (int) $request->get_param( 'id' );
	$post    = newpixel_buffer_x_get_publishable_post( $post_id );
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$status = (string) get_post_meta( $post_id, NEWPIXEL_BUFFER_X_STATUS_META, true );
	if ( 'processing' === $status ) {
		$locked_at = (int) get_post_meta( $post_id, NEWPIXEL_BUFFER_X_LOCKED_AT_META, true );
		if ( $locked_at && $locked_at > time() - NEWPIXEL_BUFFER_X_CLAIM_TTL ) {
			return new WP_Error(
				'newpixel_buffer_x_already_claimed',
				'O artigo já está a ser processado.',
				array( 'status' => 409 )
			);
		}

		$released = update_post_meta(
			$post_id,
			NEWPIXEL_BUFFER_X_STATUS_META,
			'pending',
			'processing'
		);
		if ( ! $released ) {
			return new WP_Error(
				'newpixel_buffer_x_claim_conflict',
				'O estado do artigo mudou durante a reserva.',
				array( 'status' => 409 )
			);
		}
		$status = 'pending';
	}

	if ( 'pending' !== $status ) {
		return new WP_Error(
			'newpixel_buffer_x_not_pending',
			'O artigo não está disponível para processamento.',
			array( 'status' => 409 )
		);
	}

	$next_retry = (int) get_post_meta( $post_id, NEWPIXEL_BUFFER_X_NEXT_RETRY_META, true );
	if ( $next_retry > time() ) {
		return new WP_Error(
			'newpixel_buffer_x_retry_later',
			'O artigo ainda está em período de espera antes da próxima tentativa.',
			array(
				'status'         => 409,
				'next_retry_at'  => gmdate( 'c', $next_retry ),
				'retry_after_in' => $next_retry - time(),
			)
		);
	}

	$claimed = update_post_meta(
		$post_id,
		NEWPIXEL_BUFFER_X_STATUS_META,
		'processing',
		'pending'
	);
	if ( ! $claimed ) {
		return new WP_Error(
			'newpixel_buffer_x_claim_conflict',
			'Outro processo reservou o artigo primeiro.',
			array( 'status' => 409 )
		);
	}

	$claim_token = wp_generate_uuid4();
	$attempts    = (int) get_post_meta( $post_id, NEWPIXEL_BUFFER_X_ATTEMPTS_META, true ) + 1;
	$worker      = substr( (string) $request->get_param( 'worker' ), 0, 120 );

	update_post_meta( $post_id, NEWPIXEL_BUFFER_X_LOCK_TOKEN_META, $claim_token );
	update_post_meta( $post_id, NEWPIXEL_BUFFER_X_LOCKED_AT_META, time() );
	update_post_meta( $post_id, NEWPIXEL_BUFFER_X_ATTEMPTS_META, $attempts );
	delete_post_meta( $post_id, NEWPIXEL_BUFFER_X_NEXT_RETRY_META );

	if ( '' !== $worker ) {
		update_post_meta( $post_id, NEWPIXEL_BUFFER_X_WORKER_META, $worker );
	} else {
		delete_post_meta( $post_id, NEWPIXEL_BUFFER_X_WORKER_META );
	}

	return newpixel_buffer_x_rest_response(
		array(
			'claim_token' => $claim_token,
			'item'        => newpixel_buffer_x_post_payload( $post ),
		)
	);
}

/**
 * Confirms that Buffer accepted the publication.
 *
 * `sent` means "accepted by Buffer". The remote status returned by the API is
 * stored separately for later reconciliation.
 */
function newpixel_buffer_x_rest_complete( $request ) {
	$post_id = (int) $request->get_param( 'id' );
	$post    = newpixel_buffer_x_validate_claim(
		$post_id,
		(string) $request->get_param( 'claim_token' )
	);
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$buffer_post_id = trim( (string) $request->get_param( 'buffer_post_id' ) );
	if ( '' === $buffer_post_id ) {
		return new WP_Error(
			'newpixel_buffer_x_missing_buffer_id',
			'O Buffer não devolveu um identificador de publicação.',
			array( 'status' => 400 )
		);
	}

	update_post_meta( $post_id, NEWPIXEL_BUFFER_X_BUFFER_ID_META, substr( $buffer_post_id, 0, 255 ) );

	$remote_status = substr( trim( (string) $request->get_param( 'remote_status' ) ), 0, 80 );
	if ( '' !== $remote_status ) {
		update_post_meta( $post_id, NEWPIXEL_BUFFER_X_REMOTE_STATUS_META, $remote_status );
	}

	update_post_meta( $post_id, NEWPIXEL_BUFFER_X_SENT_AT_META, time() );
	delete_post_meta( $post_id, NEWPIXEL_BUFFER_X_LAST_ERROR_META );
	delete_post_meta( $post_id, NEWPIXEL_BUFFER_X_LAST_ERROR_AT_META );
	delete_post_meta( $post_id, NEWPIXEL_BUFFER_X_NEXT_RETRY_META );
	$completed = update_post_meta(
		$post_id,
		NEWPIXEL_BUFFER_X_STATUS_META,
		'sent',
		'processing'
	);
	if ( ! $completed ) {
		return new WP_Error(
			'newpixel_buffer_x_complete_conflict',
			'O estado do artigo mudou durante a confirmação.',
			array( 'status' => 409 )
		);
	}
	newpixel_buffer_x_clear_claim( $post_id );

	return newpixel_buffer_x_rest_response(
		array(
			'ok'             => true,
			'post_id'        => $post_id,
			'status'         => 'sent',
			'buffer_post_id' => $buffer_post_id,
		)
	);
}

/**
 * Records a failure and decides whether it returns to the queue automatically.
 */
function newpixel_buffer_x_rest_fail( $request ) {
	$post_id = (int) $request->get_param( 'id' );
	$post    = newpixel_buffer_x_validate_claim(
		$post_id,
		(string) $request->get_param( 'claim_token' )
	);
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$attempts  = (int) get_post_meta( $post_id, NEWPIXEL_BUFFER_X_ATTEMPTS_META, true );
	$retryable = rest_sanitize_boolean( $request->get_param( 'retryable' ) );
	$error     = trim( (string) $request->get_param( 'error' ) );
	$error     = '' !== $error ? substr( $error, 0, 2000 ) : 'Erro não especificado.';
	$retry     = $retryable && $attempts < NEWPIXEL_BUFFER_X_MAX_RETRIES;

	update_post_meta( $post_id, NEWPIXEL_BUFFER_X_LAST_ERROR_META, $error );
	update_post_meta( $post_id, NEWPIXEL_BUFFER_X_LAST_ERROR_AT_META, time() );

	$next_retry = 0;
	if ( $retry ) {
		$next_retry = time() + newpixel_buffer_x_retry_delay( $attempts );
		update_post_meta( $post_id, NEWPIXEL_BUFFER_X_NEXT_RETRY_META, $next_retry );
	} else {
		delete_post_meta( $post_id, NEWPIXEL_BUFFER_X_NEXT_RETRY_META );
	}

	$updated = update_post_meta(
		$post_id,
		NEWPIXEL_BUFFER_X_STATUS_META,
		$retry ? 'pending' : 'failed',
		'processing'
	);
	if ( ! $updated ) {
		return new WP_Error(
			'newpixel_buffer_x_fail_conflict',
			'O estado do artigo mudou durante o registo da falha.',
			array( 'status' => 409 )
		);
	}
	newpixel_buffer_x_clear_claim( $post_id );

	if ( ! $retry && function_exists( 'newpixel_ntfy_alert' ) ) {
		$post_title = html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' );
		$admin_url  = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
		newpixel_ntfy_alert(
			'⚠️ Falha Buffer / X — Pixel Magazine',
			"Falha definitiva no envio para o X do artigo:\n\"{$post_title}\"\n\nTentativas esgotadas ({$attempts}).\nErro: {$error}\nPainel: {$admin_url}",
			'warning,x',
			'high'
		);
	}

	return newpixel_buffer_x_rest_response(
		array(
			'ok'            => true,
			'post_id'       => $post_id,
			'status'        => $retry ? 'pending' : 'failed',
			'attempts'      => $attempts,
			'next_retry_at' => $next_retry ? gmdate( 'c', $next_retry ) : null,
		)
	);
}

/**
 * Manually reopens a terminal failure.
 */
function newpixel_buffer_x_rest_retry( $request ) {
	$post_id = (int) $request->get_param( 'id' );
	$post    = newpixel_buffer_x_get_publishable_post( $post_id );
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$status = (string) get_post_meta( $post_id, NEWPIXEL_BUFFER_X_STATUS_META, true );
	if ( 'failed' !== $status ) {
		return new WP_Error(
			'newpixel_buffer_x_not_failed',
			'Apenas artigos com falha terminal podem ser reabertos.',
			array( 'status' => 409 )
		);
	}

	delete_post_meta( $post_id, NEWPIXEL_BUFFER_X_NEXT_RETRY_META );
	$updated = update_post_meta(
		$post_id,
		NEWPIXEL_BUFFER_X_STATUS_META,
		'pending',
		'failed'
	);
	if ( ! $updated ) {
		return new WP_Error(
			'newpixel_buffer_x_retry_conflict',
			'O estado do artigo mudou durante a reabertura.',
			array( 'status' => 409 )
		);
	}
	update_post_meta( $post_id, NEWPIXEL_BUFFER_X_ATTEMPTS_META, 0 );
	newpixel_buffer_x_clear_claim( $post_id );

	return newpixel_buffer_x_rest_response(
		array(
			'ok'      => true,
			'post_id' => $post_id,
			'status'  => 'pending',
		)
	);
}

/**
 * Builds the editorial contract handed to n8n.
 */
function newpixel_buffer_x_post_payload( $post ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return array();
	}

	$title = newpixel_buffer_x_plain_text( get_the_title( $post ) );
	$dek   = function_exists( 'newpixel_seo_meta_description' )
		? newpixel_seo_meta_description( $post->ID )
		: get_the_excerpt( $post );
	$dek = newpixel_buffer_x_plain_text( $dek );

	$image         = null;
	$attachment_id = get_post_thumbnail_id( $post );
	if ( $attachment_id ) {
		$source = wp_get_attachment_image_src( $attachment_id, 'np-16x9-1280' );
		if ( ! $source ) {
			$source = wp_get_attachment_image_src( $attachment_id, 'full' );
		}

		if ( $source ) {
			$alt = newpixel_buffer_x_plain_text(
				get_post_meta( $attachment_id, '_wp_attachment_image_alt', true )
			);
			$image = array(
				'attachment_id' => (int) $attachment_id,
				'url'           => esc_url_raw( $source[0] ),
				'mime_type'     => (string) get_post_mime_type( $attachment_id ),
				'width'         => (int) $source[1],
				'height'        => (int) $source[2],
				'alt'           => '' !== $alt ? $alt : $title,
			);
		}
	}

	$categories = array();
	foreach ( get_the_category( $post->ID ) as $category ) {
		$categories[] = array(
			'id'   => (int) $category->term_id,
			'name' => newpixel_buffer_x_plain_text( $category->name ),
			'slug' => (string) $category->slug,
		);
	}

	$queued_at     = (int) get_post_meta( $post->ID, NEWPIXEL_BUFFER_X_QUEUED_AT_META, true );
	$next_retry    = (int) get_post_meta( $post->ID, NEWPIXEL_BUFFER_X_NEXT_RETRY_META, true );
	$last_error_at = (int) get_post_meta( $post->ID, NEWPIXEL_BUFFER_X_LAST_ERROR_AT_META, true );
	$sent_at       = (int) get_post_meta( $post->ID, NEWPIXEL_BUFFER_X_SENT_AT_META, true );

	return array(
		'id'             => (int) $post->ID,
		'dedupe_key'     => 'wordpress:post:' . (int) $post->ID . ':first-publish',
		'status'         => (string) get_post_meta( $post->ID, NEWPIXEL_BUFFER_X_STATUS_META, true ),
		'attempts'       => (int) get_post_meta( $post->ID, NEWPIXEL_BUFFER_X_ATTEMPTS_META, true ),
		'title'          => $title,
		'dek'            => $dek,
		'permalink'      => esc_url_raw( get_permalink( $post ) ),
		'published_at'   => get_post_time( 'c', true, $post ),
		'queued_at'      => $queued_at ? gmdate( 'c', $queued_at ) : null,
		'next_retry_at'  => $next_retry ? gmdate( 'c', $next_retry ) : null,
		'last_error'     => (string) get_post_meta( $post->ID, NEWPIXEL_BUFFER_X_LAST_ERROR_META, true ),
		'last_error_at'  => $last_error_at ? gmdate( 'c', $last_error_at ) : null,
		'buffer_post_id' => (string) get_post_meta( $post->ID, NEWPIXEL_BUFFER_X_BUFFER_ID_META, true ),
		'remote_status'  => (string) get_post_meta( $post->ID, NEWPIXEL_BUFFER_X_REMOTE_STATUS_META, true ),
		'sent_at'        => $sent_at ? gmdate( 'c', $sent_at ) : null,
		'categories'     => $categories,
		'image'          => $image,
	);
}

/**
 * Gets a valid published article for a queue mutation.
 *
 * @return WP_Post|WP_Error
 */
function newpixel_buffer_x_get_publishable_post( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
		return new WP_Error(
			'newpixel_buffer_x_invalid_post',
			'O artigo não existe ou já não está publicado.',
			array( 'status' => 404 )
		);
	}

	return $post;
}

/**
 * Confirms that the completion belongs to the execution that claimed the article.
 *
 * @return WP_Post|WP_Error
 */
function newpixel_buffer_x_validate_claim( $post_id, $provided_token ) {
	$post = newpixel_buffer_x_get_publishable_post( $post_id );
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$status         = (string) get_post_meta( $post_id, NEWPIXEL_BUFFER_X_STATUS_META, true );
	$expected_token = (string) get_post_meta( $post_id, NEWPIXEL_BUFFER_X_LOCK_TOKEN_META, true );
	$provided_token = trim( (string) $provided_token );

	if (
		'processing' !== $status
		|| '' === $expected_token
		|| '' === $provided_token
		|| ! hash_equals( $expected_token, $provided_token )
	) {
		return new WP_Error(
			'newpixel_buffer_x_invalid_claim',
			'A reserva não existe, expirou ou pertence a outra execução.',
			array( 'status' => 409 )
		);
	}

	return $post;
}

/**
 * Clears the ephemeral data of a claim.
 */
function newpixel_buffer_x_clear_claim( $post_id ) {
	delete_post_meta( $post_id, NEWPIXEL_BUFFER_X_LOCK_TOKEN_META );
	delete_post_meta( $post_id, NEWPIXEL_BUFFER_X_LOCKED_AT_META );
	delete_post_meta( $post_id, NEWPIXEL_BUFFER_X_WORKER_META );
}

/**
 * Exponential backoff: 10, 20, 40, 80 minutes, up to 6 hours.
 */
function newpixel_buffer_x_retry_delay( $attempts ) {
	$exponent = max( 0, min( 5, (int) $attempts - 1 ) );
	return min( 6 * HOUR_IN_SECONDS, 10 * MINUTE_IN_SECONDS * ( 2 ** $exponent ) );
}

/**
 * Plain editorial text, with no HTML or entities.
 */
function newpixel_buffer_x_plain_text( $value ) {
	$value = wp_strip_all_tags( (string) $value, true );
	$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
	return trim( preg_replace( '/\s+/u', ' ', $value ) );
}

/**
 * Private response: must never be served by an intermediate cache.
 */
function newpixel_buffer_x_rest_response( $data, $status = 200 ) {
	$response = new WP_REST_Response( $data, $status );
	$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
	return $response;
}

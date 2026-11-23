<?php
/**
 * /perfil/ virtual route + AJAX handlers (update name, toggle
 * newsletter, delete account). Logged-in only — guests bounced home.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// ============================================================================
// /perfil/ — virtual route handled directly via template_redirect (no WP page
// needs to be created). Logged-out users are bounced to the homepage so the
// auth modal can be opened from there.
// ============================================================================
function newpixel_handle_profile_route() {
    $path = trim( parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ), '/' );
    if ( $path !== 'perfil' ) {
        return;
    }
    if ( ! is_user_logged_in() ) {
        wp_safe_redirect( home_url( '/' ) );
        exit;
    }

    // Tell WP this is NOT a 404 even though there is no underlying post.
    global $wp_query;
    $wp_query->is_404      = false;
    $wp_query->is_singular = false;
    $wp_query->is_home     = false;
    status_header( 200 );

    get_header();
    get_template_part( 'template-parts/profile' );
    get_footer();
    exit;
}
add_action( 'template_redirect', 'newpixel_handle_profile_route' );

// ============================================================================
// Profile page — AJAX handlers
// ============================================================================
function pixel_profile_check_request() {
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => __( 'Sessão necessária.', 'newpixel' ) ), 401 );
    }
    if ( empty( $_POST['_nonce'] ) || ! wp_verify_nonce( $_POST['_nonce'], 'pixel_profile' ) ) {
        wp_send_json_error( array( 'message' => __( 'Sessão expirada. Recarrega a página.', 'newpixel' ) ), 403 );
    }
}

// Update display name
function pixel_profile_update_name() {
    pixel_profile_check_request();
    $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
    if ( ! $name ) {
        wp_send_json_error( array( 'message' => __( 'Indica um nome.', 'newpixel' ) ), 400 );
    }
    wp_update_user( array(
        'ID'           => get_current_user_id(),
        'display_name' => $name,
        'first_name'   => $name,
        'nickname'     => $name,
    ) );
    wp_send_json_success( array( 'message' => __( 'Nome atualizado.', 'newpixel' ) ) );
}
add_action( 'wp_ajax_pixel_profile_update_name', 'pixel_profile_update_name' );

// Toggle newsletter (delegates to the existing helper)
function pixel_profile_toggle_newsletter() {
    pixel_profile_check_request();
    $subscribe = ! empty( $_POST['subscribe'] );
    if ( $subscribe ) {
        $email = wp_get_current_user()->user_email;
        // Account emails aren't verified at sign-up, so this confirms too.
        if ( ! newpixel_newsletter_email_is_subscribed( $email ) ) {
            if ( ! newpixel_newsletter_request_confirmation( $email ) ) {
                wp_send_json_error( array( 'message' => __( 'Não foi possível enviar o email de confirmação.', 'newpixel' ) ), 500 );
            }
            wp_send_json_success( array(
                'pending' => true,
                'message' => sprintf(
                    /* translators: %s: account email */
                    __( 'Enviámos um link de confirmação para %s.', 'newpixel' ),
                    $email
                ),
            ) );
        }
        newpixel_newsletter_activate( $email );
    } else {
        do_action( 'newpixel_newsletter_unsubscribe', wp_get_current_user()->user_email );
    }
    newpixel_set_newsletter_subscribed( $subscribe );
    wp_send_json_success();
}
add_action( 'wp_ajax_pixel_profile_toggle_newsletter', 'pixel_profile_toggle_newsletter' );

// Self-delete account (RGPD right-to-be-forgotten). Anonymises any comments
// the user authored so the discussion threads stay readable.
function pixel_profile_delete_account() {
    pixel_profile_check_request();

    $user_id = get_current_user_id();
    $user    = wp_get_current_user();

    // Belt-and-suspenders: never let an account with admin privileges delete
    // itself through this endpoint.
    if ( user_can( $user, 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Contas administrativas não podem ser eliminadas por aqui.', 'newpixel' ) ), 403 );
    }

    require_once ABSPATH . 'wp-admin/includes/user.php';

    // Anonymise comments authored by this user so threads remain readable.
    global $wpdb;
    $wpdb->update(
        $wpdb->comments,
        array(
            'user_id'              => 0,
            'comment_author'       => __( 'Leitor anónimo', 'newpixel' ),
            'comment_author_email' => '',
            'comment_author_url'   => '',
        ),
        array( 'user_id' => $user_id )
    );

    $deleted = wp_delete_user( $user_id );
    if ( ! $deleted ) {
        wp_send_json_error( array( 'message' => __( 'Não foi possível eliminar a conta. Contacta-nos.', 'newpixel' ) ), 500 );
    }

    wp_clear_auth_cookie();
    wp_send_json_success( array( 'redirect' => home_url( '/' ) ) );
}
add_action( 'wp_ajax_pixel_profile_delete_account', 'pixel_profile_delete_account' );

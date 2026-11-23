<?php
/**
 * Hidden login: the secret slug of the "Rename wp-admin login" plugin never
 * reaches the reader.
 * ============================================================================
 *
 * The plugin rewrites EVERY `site_url( 'wp-login.php…' )` to the secret slug.
 * Without this module the slug would appear:
 *   - in the HTML of every page (the header's "Entrar" / "Junta-te" links and
 *     the modal's "Esqueci-me", via wp_login_url / wp_registration_url /
 *     wp_lostpassword_url);
 *   - in the profile's log-out link (wp_logout_url);
 *   - in the password recovery email (the `action=rp` link).
 *
 * What this module does:
 *   1. On the front end, those four URLs point to the home page with a
 *      fragment (`#entrar`, `#criar-conta`, `#recuperar-password`) handled by
 *      the modal's JS. Logout goes through `?pixel-logout=1` + nonce.
 *   2. Password recovery runs entirely over AJAX inside the modal:
 *      request the email → open the link → choose a new password.
 *   3. The recovery email points to `/#redefinir-password?login=…&key=…`.
 *      The key travels in the FRAGMENT: browsers never send it to the server,
 *      so it stays out of logs, caches and the Referer; the JS reads it and
 *      removes it from the address bar.
 *   4. Safety net on `wp_mail`: any email still carrying the slug (e.g. the
 *      "new user" email from wp-admin) is rewritten.
 *
 * Outside the front end (wp-admin and the secret login page itself) the URLs
 * stay as core generates them, since anyone there already knows the slug.
 *
 * @package newpixel
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// ===========================================================================
// Helpers
// ===========================================================================

/**
 * Should login URLs be hidden on this request?
 * Not in wp-admin (it needs the real ones) nor on the secret login page: the
 * plugin sets `$pagenow = 'wp-login.php'` when that page is requested.
 */
function newpixel_mask_login_urls() {
    global $pagenow;
    if ( 'wp-login.php' === $pagenow ) {
        return false;
    }
    if ( is_admin() && ! wp_doing_ajax() ) {
        return false;
    }
    return true;
}

/** Home URL with a fragment handled by the auth modal. */
function newpixel_auth_hash_url( $view ) {
    return home_url( '/' ) . '#' . $view;
}

/** Password reset link (the key travels in the fragment). */
function newpixel_reset_password_url( $key, $user_login ) {
    return newpixel_auth_hash_url( 'redefinir-password' )
        . '?login=' . rawurlencode( $user_login )
        . '&key=' . rawurlencode( $key );
}

/**
 * Fixed-window rate limit, stored in a transient.
 * Returns true if the bucket is already full (the request must be refused).
 */
function newpixel_rate_limited( $bucket, $limit, $window ) {
    $key  = 'np_rl_' . md5( $bucket );
    $now  = time();
    $data = get_transient( $key );

    if ( ! is_array( $data ) || empty( $data['until'] ) || $data['until'] <= $now ) {
        $data = array( 'n' => 0, 'until' => $now + $window );
    }
    if ( $data['n'] >= $limit ) {
        return true;
    }

    $data['n']++;
    set_transient( $key, $data, max( 1, $data['until'] - $now ) );
    return false;
}

/** Is the bucket already full? Unlike the above, this doesn't count the request. */
function newpixel_rate_is_full( $bucket, $limit ) {
    $data = get_transient( 'np_rl_' . md5( $bucket ) );
    return is_array( $data ) && ! empty( $data['until'] ) && $data['until'] > time() && $data['n'] >= $limit;
}

function newpixel_rate_clear( $bucket ) {
    delete_transient( 'np_rl_' . md5( $bucket ) );
}

/**
 * Client IP. REMOTE_ADDR only: X-Forwarded-For headers can be forged by the
 * requester and would bypass the limit.
 */
function newpixel_client_ip() {
    return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
}

function newpixel_auth_require_nonce() {
    if ( empty( $_POST['_nonce'] ) || ! wp_verify_nonce( $_POST['_nonce'], 'pixel_auth' ) ) {
        wp_send_json_error( array( 'message' => __( 'Sessão expirada. Recarrega a página e tenta novamente.', 'newpixel' ) ), 403 );
    }
}

// ===========================================================================
// 1. Login/registration/recovery/logout URLs on the front end
// ===========================================================================

add_filter( 'login_url', 'newpixel_front_login_url', 20 );
function newpixel_front_login_url( $url ) {
    return newpixel_mask_login_urls() ? newpixel_auth_hash_url( 'entrar' ) : $url;
}

add_filter( 'register_url', 'newpixel_front_register_url', 20 );
function newpixel_front_register_url( $url ) {
    return newpixel_mask_login_urls() ? newpixel_auth_hash_url( 'criar-conta' ) : $url;
}

add_filter( 'lostpassword_url', 'newpixel_front_lostpassword_url', 20 );
function newpixel_front_lostpassword_url( $url ) {
    return newpixel_mask_login_urls() ? newpixel_auth_hash_url( 'recuperar-password' ) : $url;
}

add_filter( 'logout_url', 'newpixel_front_logout_url', 20, 2 );
function newpixel_front_logout_url( $url, $redirect ) {
    if ( ! newpixel_mask_login_urls() ) {
        return $url;
    }
    $args = array( 'pixel-logout' => '1' );
    if ( $redirect ) {
        $args['redirect_to'] = rawurlencode( $redirect );
    }
    // Same nonce action name as core ('log-out').
    return wp_nonce_url( add_query_arg( $args, home_url( '/' ) ), 'log-out' );
}

add_action( 'init', 'newpixel_handle_front_logout' );
function newpixel_handle_front_logout() {
    if ( ! isset( $_GET['pixel-logout'] ) ) {
        return;
    }
    $redirect = isset( $_GET['redirect_to'] ) ? wp_unslash( $_GET['redirect_to'] ) : '';
    $redirect = wp_validate_redirect( $redirect, home_url( '/' ) );

    // Without a valid nonce, don't end the session (prevents logout CSRF), but
    // don't show core's confirmation screen either, since it lives on the slug.
    if ( is_user_logged_in()
        && isset( $_GET['_wpnonce'] )
        && wp_verify_nonce( wp_unslash( $_GET['_wpnonce'] ), 'log-out' ) ) {
        wp_logout();
    }

    nocache_headers();
    wp_safe_redirect( $redirect );
    exit;
}

// ===========================================================================
// 2. Password recovery over AJAX
// ===========================================================================

/**
 * Step 1: request the link by email.
 *
 * The response is ALWAYS the same whether or not the account exists, so it
 * can't be used to discover registered emails. Two limits: per IP (against
 * hammering the endpoint) and per account (against using the site to flood
 * someone's inbox); the account limit is silent.
 */
function newpixel_auth_handle_lostpassword() {
    newpixel_auth_require_nonce();

    $generic = __( 'Se existir uma conta com esse email, vais receber uma ligação para escolheres uma nova palavra-passe. Se não aparecer em alguns minutos, vê a pasta de spam.', 'newpixel' );

    // Bots: pretend it worked.
    if ( newpixel_honeypot_tripped() ) {
        wp_send_json_success( array( 'message' => $generic ) );
    }

    $login = isset( $_POST['user_login'] ) ? trim( wp_unslash( $_POST['user_login'] ) ) : '';
    if ( '' === $login ) {
        wp_send_json_error( array( 'message' => __( 'Indica o email da tua conta.', 'newpixel' ) ), 400 );
    }

    if ( newpixel_rate_limited( 'lostpw-ip|' . newpixel_client_ip(), 5, 15 * MINUTE_IN_SECONDS ) ) {
        wp_send_json_error( array( 'message' => __( 'Demasiados pedidos. Tenta outra vez daqui a alguns minutos.', 'newpixel' ) ), 429 );
    }

    $user = is_email( $login )
        ? get_user_by( 'email', $login )
        : get_user_by( 'login', sanitize_user( $login ) );

    if ( $user && ! newpixel_rate_limited( 'lostpw-user|' . $user->ID, 3, HOUR_IN_SECONDS ) ) {
        $result = retrieve_password( $user->user_login );
        if ( is_wp_error( $result ) ) {
            error_log( 'newpixel lostpassword: ' . $result->get_error_code() . ' (user ' . $user->ID . ')' );
        }
    }

    wp_send_json_success( array( 'message' => $generic ) );
}
add_action( 'wp_ajax_nopriv_pixel_auth_lostpassword', 'newpixel_auth_handle_lostpassword' );
add_action( 'wp_ajax_pixel_auth_lostpassword',        'newpixel_auth_handle_lostpassword' );

/** Message for a rejected reset key. */
function newpixel_reset_key_error_message( WP_Error $error ) {
    if ( 'expired_key' === $error->get_error_code() ) {
        return __( 'Esta ligação expirou. Pede uma nova.', 'newpixel' );
    }
    return __( 'Esta ligação não é válida ou já foi usada. Pede uma nova.', 'newpixel' );
}

/** Reads login + key from POST and validates them. Ends the request on failure. */
function newpixel_reset_user_from_request() {
    if ( newpixel_rate_limited( 'resetpw-ip|' . newpixel_client_ip(), 20, 15 * MINUTE_IN_SECONDS ) ) {
        wp_send_json_error( array( 'message' => __( 'Demasiadas tentativas. Tenta outra vez daqui a alguns minutos.', 'newpixel' ) ), 429 );
    }

    $login = isset( $_POST['login'] ) ? wp_unslash( $_POST['login'] ) : '';
    $key   = isset( $_POST['key'] ) ? wp_unslash( $_POST['key'] ) : '';

    if ( '' === $login || '' === $key ) {
        wp_send_json_error( array( 'message' => __( 'Esta ligação não é válida ou já foi usada. Pede uma nova.', 'newpixel' ), 'invalid' => true ), 400 );
    }

    $user = check_password_reset_key( $key, $login );
    if ( is_wp_error( $user ) ) {
        wp_send_json_error( array( 'message' => newpixel_reset_key_error_message( $user ), 'invalid' => true ), 400 );
    }
    return $user;
}

/**
 * Step 2: when the link is opened, check the key right away, to avoid asking
 * for a password that would then be refused.
 */
function newpixel_auth_handle_checkreset() {
    newpixel_auth_require_nonce();
    newpixel_reset_user_from_request();
    wp_send_json_success();
}
add_action( 'wp_ajax_nopriv_pixel_auth_checkreset', 'newpixel_auth_handle_checkreset' );
add_action( 'wp_ajax_pixel_auth_checkreset',        'newpixel_auth_handle_checkreset' );

/**
 * Step 3: save the new password.
 *
 * Doesn't log the user in: with the two-factor plugin active in production, a
 * login here would skip the second factor. Also ends all of the account's open
 * sessions, so anyone else who was in gets signed out.
 */
function newpixel_auth_handle_resetpassword() {
    newpixel_auth_require_nonce();
    $user = newpixel_reset_user_from_request();

    $pass1 = isset( $_POST['pass1'] ) ? wp_unslash( $_POST['pass1'] ) : '';
    $pass2 = isset( $_POST['pass2'] ) ? wp_unslash( $_POST['pass2'] ) : '';

    if ( strlen( $pass1 ) < 8 ) {
        wp_send_json_error( array( 'message' => __( 'A palavra-passe deve ter pelo menos 8 caracteres.', 'newpixel' ) ), 400 );
    }
    if ( $pass1 !== $pass2 ) {
        wp_send_json_error( array( 'message' => __( 'As palavras-passe não coincidem.', 'newpixel' ) ), 400 );
    }

    // Same hook wp-login.php runs, for plugins that validate password strength.
    $errors = new WP_Error();
    do_action( 'validate_password_reset', $errors, $user );
    if ( $errors->has_errors() ) {
        wp_send_json_error( array( 'message' => $errors->get_error_message() ), 400 );
    }

    reset_password( $user, $pass1 );
    WP_Session_Tokens::get_instance( $user->ID )->destroy_all();

    wp_send_json_success( array(
        'message' => __( 'Palavra-passe alterada. Já podes entrar.', 'newpixel' ),
        'view'    => 'login',
    ) );
}
add_action( 'wp_ajax_nopriv_pixel_auth_resetpassword', 'newpixel_auth_handle_resetpassword' );
add_action( 'wp_ajax_pixel_auth_resetpassword',        'newpixel_auth_handle_resetpassword' );

// ===========================================================================
// 3. Recovery email (PT-PT, with the link to the modal)
// ===========================================================================

function newpixel_mail_site_name() {
    return wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
}

add_filter( 'retrieve_password_title', 'newpixel_retrieve_password_title' );
function newpixel_retrieve_password_title() {
    /* translators: %s: site name */
    return sprintf( __( '[%s] Redefinir a palavra-passe', 'newpixel' ), newpixel_mail_site_name() );
}

add_filter( 'retrieve_password_message', 'newpixel_retrieve_password_message', 10, 4 );
function newpixel_retrieve_password_message( $message, $key, $user_login, $user_data ) {
    $name = ( $user_data instanceof WP_User && $user_data->display_name ) ? $user_data->display_name : $user_login;

    return sprintf( __( 'Olá %s,', 'newpixel' ), $name ) . "\r\n\r\n"
        . sprintf( __( 'Recebemos um pedido para redefinir a palavra-passe da tua conta na %s.', 'newpixel' ), newpixel_mail_site_name() ) . "\r\n\r\n"
        . __( 'Para escolheres uma nova, abre esta ligação:', 'newpixel' ) . "\r\n\r\n"
        . newpixel_reset_password_url( $key, $user_login ) . "\r\n\r\n"
        . __( 'A ligação é válida durante 24 horas e só pode ser usada uma vez.', 'newpixel' ) . "\r\n\r\n"
        . __( 'Se não foste tu a pedir, ignora este email: a tua palavra-passe atual mantém-se.', 'newpixel' ) . "\r\n";
}

// ===========================================================================
// 4. Safety net: no email leaves with the slug
// ===========================================================================
//
// Catches what the filter above doesn't cover: the "new user" email when an
// account is created in wp-admin, and any plugin that sends the login URL.
// An `action=rp` link becomes the modal link; anything else becomes `#entrar`.

add_filter( 'wp_mail', 'newpixel_scrub_login_slug_from_mail' );
function newpixel_scrub_login_slug_from_mail( $args ) {
    if ( empty( $args['message'] ) || ! is_string( $args['message'] ) ) {
        return $args;
    }

    // With the plugin active this returns the secret slug; without it, /wp-login.php.
    $path = untrailingslashit( (string) wp_parse_url( site_url( 'wp-login.php', 'login' ), PHP_URL_PATH ) );
    if ( strlen( $path ) < 2 ) {
        return $args;
    }

    $pattern = '#https?://[^/\s"\'<>]+' . preg_quote( $path, '#' ) . '/?(\?[^\s"\'<>]*)?#i';

    $args['message'] = preg_replace_callback( $pattern, function ( $m ) {
        $query = array();
        if ( ! empty( $m[1] ) ) {
            parse_str( html_entity_decode( substr( $m[1], 1 ) ), $query );
        }
        if ( isset( $query['action'], $query['key'], $query['login'] ) && 'rp' === $query['action'] ) {
            return newpixel_reset_password_url( $query['key'], $query['login'] );
        }
        return newpixel_auth_hash_url( 'entrar' );
    }, $args['message'] );

    return $args;
}

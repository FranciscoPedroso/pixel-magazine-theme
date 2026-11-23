<?php
/**
 * AJAX auth — login, register, render the modal, localize nonces.
 * No page reload; success returns a JSON `redirect` URL.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// ============================================================================
// AJAX auth — login + register, no page reload
// ============================================================================
function pixel_auth_handle_login() {
    if ( empty( $_POST['_nonce'] ) || ! wp_verify_nonce( $_POST['_nonce'], 'pixel_auth' ) ) {
        wp_send_json_error( array( 'message' => __( 'Sessão expirada. Recarrega a página e tenta novamente.', 'newpixel' ) ), 403 );
    }

    $log = isset( $_POST['log'] ) ? wp_unslash( $_POST['log'] ) : '';
    $pwd = isset( $_POST['pwd'] ) ? wp_unslash( $_POST['pwd'] ) : '';

    if ( ! $log || ! $pwd ) {
        wp_send_json_error( array( 'message' => __( 'Preenche o email e a palavra-passe.', 'newpixel' ) ), 400 );
    }

    $creds = array(
        'user_login'    => sanitize_user( $log ),
        'user_password' => $pwd,
        'remember'      => ! empty( $_POST['rememberme'] ),
    );

    $user = wp_signon( $creds, is_ssl() );

    if ( is_wp_error( $user ) ) {
        // Don't leak which field is wrong (avoid email enumeration).
        wp_send_json_error( array( 'message' => __( 'Email ou palavra-passe incorretos.', 'newpixel' ) ), 401 );
    }

    $redirect = ! empty( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : home_url( '/' );
    wp_send_json_success( array( 'redirect' => $redirect ) );
}
add_action( 'wp_ajax_nopriv_pixel_auth_login', 'pixel_auth_handle_login' );
add_action( 'wp_ajax_pixel_auth_login',        'pixel_auth_handle_login' );

function pixel_auth_handle_register() {
    if ( empty( $_POST['_nonce'] ) || ! wp_verify_nonce( $_POST['_nonce'], 'pixel_auth' ) ) {
        wp_send_json_error( array( 'message' => __( 'Sessão expirada. Recarrega a página e tenta novamente.', 'newpixel' ) ), 403 );
    }

    // Honeypot — a hidden trap field; bots fill it, humans never see it.
    if ( newpixel_honeypot_tripped() ) {
        wp_send_json_error( array( 'message' => __( 'Não foi possível criar a conta.', 'newpixel' ) ), 400 );
    }

    if ( ! get_option( 'users_can_register' ) ) {
        wp_send_json_error( array( 'message' => __( 'Os registos estão temporariamente desativados.', 'newpixel' ) ), 403 );
    }

    $email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
    $name     = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
    $password = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '';

    if ( ! is_email( $email ) ) {
        wp_send_json_error( array( 'message' => __( 'Email inválido.', 'newpixel' ) ), 400 );
    }
    if ( email_exists( $email ) ) {
        wp_send_json_error( array( 'message' => __( 'Já existe uma conta com este email. Tenta entrar.', 'newpixel' ) ), 400 );
    }
    if ( strlen( $password ) < 8 ) {
        wp_send_json_error( array( 'message' => __( 'A palavra-passe deve ter pelo menos 8 caracteres.', 'newpixel' ) ), 400 );
    }
    if ( ! $name ) {
        wp_send_json_error( array( 'message' => __( 'Indica um nome.', 'newpixel' ) ), 400 );
    }

    // Username derived from the email's local part. If taken, append a short
    // random suffix so registration never fails on a username collision.
    $base = sanitize_user( strstr( $email, '@', true ), true );
    if ( ! $base ) $base = 'leitor';
    $username = $base;
    $i = 0;
    while ( username_exists( $username ) ) {
        $i++;
        $username = $base . '_' . wp_generate_password( 4, false, false );
        if ( $i > 5 ) {
            wp_send_json_error( array( 'message' => __( 'Não foi possível criar a conta. Tenta novamente.', 'newpixel' ) ), 500 );
        }
    }

    $user_id = wp_create_user( $username, $password, $email );
    if ( is_wp_error( $user_id ) ) {
        wp_send_json_error( array( 'message' => __( 'Não foi possível criar a conta.', 'newpixel' ) ), 500 );
    }

    wp_update_user( array(
        'ID'           => $user_id,
        'display_name' => $name,
        'first_name'   => $name,
        'nickname'     => $name,
    ) );

    // Auto-login the new user. Subsequent requests will see them as logged-in.
    wp_set_current_user( $user_id );
    wp_set_auth_cookie( $user_id, true );

    wp_send_json_success( array( 'redirect' => home_url( '/' ) ) );
}
add_action( 'wp_ajax_nopriv_pixel_auth_register', 'pixel_auth_handle_register' );
add_action( 'wp_ajax_pixel_auth_register',        'pixel_auth_handle_register' );

// Output the auth modal in the footer so it's always available for guests.
function pixel_render_auth_modal() {
    if ( is_user_logged_in() ) return;
    get_template_part( 'template-parts/auth-modal' );
}
add_action( 'wp_footer', 'pixel_render_auth_modal' );

// Localise the AJAX URL + per-user nonces on the main script. Available for
// both guests (auth modal, newsletter) and logged-in users (newsletter,
// profile page).
function pixel_auth_localize() {
    if ( ! wp_script_is( 'newpixel-vite-js', 'enqueued' ) ) return;
    wp_localize_script( 'newpixel-vite-js', 'pixelAuth', array(
        'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
        'profileNonce' => is_user_logged_in() ? wp_create_nonce( 'pixel_profile' ) : '',
    ) );
}
add_action( 'wp_enqueue_scripts', 'pixel_auth_localize', 30 );

// ============================================================================
// Non-admin access control
// ============================================================================
//
// Subscribers / authors / editors are not supposed to ever see wp-admin —
// the site is consumer-facing and all user-facing actions (bookmarks,
// profile edits, account deletion, newsletter prefs) happen on the
// front-end via the /perfil/ page and AJAX endpoints.
//
// Two layers:
//   1. login_redirect filter — send non-admins straight to the home page
//      (or whatever they were trying to reach) right after successful
//      login. Cleaner UX than letting them land in wp-admin first.
//   2. admin_init action — kicks any non-admin who somehow ends up in
//      /wp-admin/ (bookmarked URL, direct paste, an old email link).
//
// admin-ajax.php and wp-cron.php both go through admin_init too — we
// whitelist DOING_AJAX and DOING_CRON so the bookmarks endpoint and any
// scheduled tasks keep working for normal logged-in users.

/**
 * Redirect non-admins to the home page after login.
 * The default WP redirect is the admin dashboard, which is not the
 * right destination for our subscriber-style accounts.
 */
add_filter( 'login_redirect', 'newpixel_non_admin_login_redirect', 10, 3 );
function newpixel_non_admin_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
    if ( $user instanceof WP_User && ! user_can( $user, 'manage_options' ) ) {
        return home_url( '/' );
    }
    return $redirect_to;
}

/**
 * Block non-admins from /wp-admin/ entirely. Catches anyone who got past the
 * login redirect (typed the URL by hand, an old bookmark, etc.).
 *
 * Hooked on `auth_redirect`, NOT `admin_init`. Why: inside wp-admin/admin.php
 * the order is auth_redirect() (≈line 104) → require menu.php (≈line 163) →
 * do_action('admin_init') (≈line 180). menu.php is where core throws its own
 * 403 ("Sorry, you are not allowed to access this page.") for a page the user
 * can't reach — and that fires BEFORE admin_init. So an admin_init redirect
 * was too late for a fully authenticated subscriber: the 403 won the race.
 * auth_redirect fires right after the auth check, before menu.php, so we
 * redirect first.
 *
 * auth_redirect only runs on real admin page loads — NOT admin-ajax.php,
 * admin-post.php or cron — so the front-end AJAX endpoints (bookmarks, auth
 * modal, profile) are unaffected. The DOING_AJAX/CRON guards stay as belt
 * and braces.
 */
add_action( 'auth_redirect', 'newpixel_block_non_admins_in_wp_admin' );
function newpixel_block_non_admins_in_wp_admin() {
    if ( defined( 'DOING_AJAX' ) && DOING_AJAX )  return;
    if ( defined( 'DOING_CRON' ) && DOING_CRON )  return;
    if ( current_user_can( 'manage_options' ) )    return;

    wp_safe_redirect( home_url( '/' ) );
    exit;
}

/**
 * Redirect /wp-signup.php and /wp-register.php to the home page.
 * Registration happens via the front-end modal; these WP-native URLs
 * are unused surface area.
 */
add_action( 'init', 'newpixel_block_wp_signup_register' );
function newpixel_block_wp_signup_register() {
    $script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( $_SERVER['SCRIPT_NAME'] ) : '';
    if ( in_array( $script, array( 'wp-signup.php', 'wp-register.php' ), true ) ) {
        wp_safe_redirect( home_url( '/' ), 301 );
        exit;
    }
}

// ============================================================================
// Harden the REST API: restrict the users endpoints to ADMINS only.
//
// `/wp/v2/users` (and `/users/<id>`) expose author usernames/slugs by default —
// a classic user-enumeration vector. We drop those routes for everyone who is
// NOT an administrator (anonymous OR logged-in non-admins, e.g. subscribers),
// so only admins can read them. Safe here: non-admins are bounced out of
// wp-admin entirely (see above), so the only people in the block editor — which
// needs /users for the author panel — are admins. Nothing the front-end or the
// n8n Drafter touches /users (they read /posts, write /media + /posts). Covers
// both /wp-json/wp/v2/users and ?rest_route=/wp/v2/users.
// ============================================================================
add_filter( 'rest_endpoints', 'newpixel_restrict_users_endpoint_to_admins' );
function newpixel_restrict_users_endpoint_to_admins( $endpoints ) {
    if ( current_user_can( 'manage_options' ) ) {
        return $endpoints; // admins keep full access (they're the only ones in the editor)
    }
    foreach ( array( '/wp/v2/users', '/wp/v2/users/(?P<id>[\d]+)' ) as $route ) {
        if ( isset( $endpoints[ $route ] ) ) {
            unset( $endpoints[ $route ] );
        }
    }
    return $endpoints;
}

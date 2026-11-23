<?php
/**
 * Newsletter — recipient list, dispatch, unsubscribe handling
 * ================================================================
 *
 * The "actually send the email" half of the pipeline:
 *   • newpixel_newsletter_get_subscriber_emails()    — single source of truth
 *   • newpixel_newsletter_dispatch()                 — render + send per recipient
 *   • newpixel_newsletter_unsubscribe_url_for()      — HMAC-signed one-click URL
 *   • newpixel_newsletter_handle_unsub_link()        — front-end handler
 *   • newpixel_newsletter_notify_admin_of_draft()    — heads-up email to admin
 *
 * Sending currently uses wp_mail() (lands wherever the host SMTP / WP
 * Mail SMTP plugin sends to). Replacing this with a Resend / Postmark
 * provider API is a one-function change inside dispatch().
 *
 * @package newpixel
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// ============================================================================
// Subscribers — single source of truth for who receives the issue
// ============================================================================

/**
 * All subscriber email addresses (logged-in users with the newsletter
 * meta flag + guest-subscription list). Deduplicated, lowercased.
 *
 * @return string[]
 */
function newpixel_newsletter_get_subscriber_emails() {
    // Logged-in users that have opted in
    $user_query = new WP_User_Query( array(
        'fields'      => array( 'user_email' ),
        'meta_key'    => NEWPIXEL_NEWSLETTER_META,
        'meta_value'  => '1',
        'number'      => -1,
    ) );
    $user_emails = wp_list_pluck( $user_query->get_results(), 'user_email' );

    // Guests (email-only, no account yet)
    $guest_emails = (array) get_option( NEWPIXEL_NEWSLETTER_GUESTS, array() );

    $all = array_map( 'strtolower', array_merge( $user_emails, $guest_emails ) );
    $all = array_filter( $all, 'is_email' );
    return array_values( array_unique( $all ) );
}

// ============================================================================
// Dispatch — actually send the email
// ============================================================================

/**
 * Send the issue to all subscribers.
 *
 * Currently stubbed to wp_mail() for testing — works for ~50 subscribers
 * via the host's SMTP. Replace the inner loop with a Resend / Postmark /
 * Mailgun API call once the newsletter@pixelmagazine.pt mailbox is set
 * up. The rest of the function (rendering, recipient list, status flip)
 * stays the same.
 *
 * @param int $issue_id Newsletter CPT post ID
 * @return array { sent: int, failed: int }
 */
function newpixel_newsletter_dispatch( $issue_id ) {
    $issue = get_post( $issue_id );
    if ( ! $issue || $issue->post_type !== NEWPIXEL_NEWSLETTER_CPT ) {
        return array( 'sent' => 0, 'failed' => 0 );
    }

    // Never resend something already sent. After sending, the issue goes to the
    // trash (see the end of this function), but this guard still protects against
    // double clicks on "Enviar agora" before the trash settles.
    if ( get_post_meta( $issue_id, NEWPIXEL_NEWSLETTER_META_SENT_AT, true ) ) {
        return array( 'sent' => 0, 'failed' => 0, 'reason' => 'already_sent' );
    }

    $recipients = newpixel_newsletter_get_subscriber_emails();
    if ( empty( $recipients ) ) {
        return array( 'sent' => 0, 'failed' => 0, 'reason' => 'no_subscribers' );
    }

    $subject = sprintf( '[Pixel] %s', $issue->post_title );

    $from_email = apply_filters( 'newpixel_newsletter_from_email', 'newsletter@' . wp_parse_url( home_url(), PHP_URL_HOST ) );
    $from_name  = apply_filters( 'newpixel_newsletter_from_name', 'Pixel Magazine' );

    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        sprintf( 'From: %s <%s>', $from_name, $from_email ),
        sprintf( 'Reply-To: %s', $from_email ),
    );

    $sent = 0;
    $failed = 0;
    foreach ( $recipients as $email ) {
        // Each recipient gets their own personalised render: unique
        // unsubscribe URL, and (where available) the date they originally
        // subscribed. The date is RGPD-relevant proof of consent for the
        // recipient — it's stamped at subscribe time by inc/newsletter.php.
        $html = newpixel_newsletter_render_issue( $issue_id, array(
            'unsubscribe_url' => newpixel_newsletter_unsubscribe_url_for( $email ),
            'browser_url'     => get_permalink( $issue_id ),
            'subscribed_date' => function_exists( 'newpixel_get_subscription_date' )
                ? newpixel_get_subscription_date( $email )
                : '',
        ) );

        $ok = wp_mail( $email, $subject, $html, $headers );
        if ( $ok ) { $sent++; } else { $failed++; }

        // Light throttle — 50 emails/min ≈ 1.2s between sends — well below
        // any host's rate limit, and gentle on a shared SMTP. Will be
        // removed once we move to a real provider that handles throttling.
        if ( ( $sent + $failed ) % 10 === 0 ) {
            usleep( 1000 * 1000 ); // 1 second pause every 10 sends
        }
    }

    // Record the send (date + count + issue number) and move the issue to the TRASH.
    // The issue number only increments if the newsletter was actually sent ($sent > 0).
    if ( $sent > 0 ) {
        $edition_num = 0;
        if ( preg_match( '/#(\d+)/', $issue->post_title, $m ) ) {
            $edition_num = (int) $m[1];
        } elseif ( function_exists( 'newpixel_newsletter_get_next_edition_number' ) ) {
            $edition_num = newpixel_newsletter_get_next_edition_number();
        } else {
            $edition_num = 23;
        }

        if ( defined( 'NEWPIXEL_NEWSLETTER_META_EDITION_NUMBER' ) ) {
            update_post_meta( $issue_id, NEWPIXEL_NEWSLETTER_META_EDITION_NUMBER, $edition_num );
        }
        update_post_meta( $issue_id, NEWPIXEL_NEWSLETTER_META_SENT_AT, current_time( 'mysql' ) );
        update_post_meta( $issue_id, NEWPIXEL_NEWSLETTER_META_SENT_COUNT, $sent );

        if ( function_exists( 'newpixel_newsletter_increment_edition_number' ) ) {
            newpixel_newsletter_increment_edition_number( $edition_num );
        }

        wp_trash_post( $issue_id );
    }

    return array( 'sent' => $sent, 'failed' => $failed );
}

// ============================================================================
// Unsubscribe — HMAC-signed one-click link + handler
// ============================================================================

/**
 * Build a one-click unsubscribe URL for a given email address.
 * Uses an HMAC of the email so the link can't be forged or used to
 * unsubscribe arbitrary users.
 *
 * Routes through the home URL with a custom query var (`pixel_unsub`),
 * intercepted on `template_redirect` below. We avoid `admin-post.php`
 * because some auth/redirect plugins block /wp-admin/* for anonymous
 * visitors, and the unsubscribe link MUST work for any recipient
 * (logged-in or not, even after their account is gone).
 */
function newpixel_newsletter_unsubscribe_url_for( $email ) {
    $email = strtolower( sanitize_email( $email ) );
    $token = substr( hash_hmac( 'sha256', $email, wp_salt() ), 0, 16 );
    return add_query_arg( array(
        'pixel_unsub' => '1',
        'email'       => rawurlencode( $email ),
        'token'       => $token,
    ), home_url( '/' ) );
}

// Handler — fires on every front-end request. Bails immediately if the
// `pixel_unsub` query var isn't present, so the overhead for normal
// requests is one isset() call. Runs before WP renders the page so we
// can output our own HTML and exit.
add_action( 'template_redirect', 'newpixel_newsletter_handle_unsub_link' );
function newpixel_newsletter_handle_unsub_link() {
    if ( empty( $_GET['pixel_unsub'] ) ) return;

    $email = isset( $_GET['email'] ) ? strtolower( sanitize_email( wp_unslash( $_GET['email'] ) ) ) : '';
    $token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

    $expected = substr( hash_hmac( 'sha256', $email, wp_salt() ), 0, 16 );
    if ( ! is_email( $email ) || ! hash_equals( $expected, $token ) ) {
        wp_die( __( 'Link inválido ou expirado.', 'newpixel' ), 400 );
    }

    // Remove from guest list (also clears the parallel date entry inside
    // newpixel_remove_guest_subscription).
    newpixel_remove_guest_subscription( $email );

    // If they have an account, flip BOTH the flag AND the consent date off.
    // Per RGPD art. 7.º/3 a withdrawn consent stops being valid, so if the
    // user ever re-subscribes the date should reflect THAT new consent
    // moment — not the old, already-revoked one.
    $user = get_user_by( 'email', $email );
    if ( $user ) {
        delete_user_meta( $user->ID, NEWPIXEL_NEWSLETTER_META );
        delete_user_meta( $user->ID, NEWPIXEL_NEWSLETTER_DATE_META );
    }

    // Minimal confirmation page — no header/footer, just the message.
    // (Could route to a proper /unsubscribed/ page later for branding.)
    status_header( 200 );
    header( 'Content-Type: text/html; charset=utf-8' );
    ?>
    <!doctype html><html lang="pt"><head>
        <meta charset="utf-8">
        <title>Subscrição cancelada · Pixel Magazine</title>
        <style>
            body { background:#fafaf7; color:#0a0a0a; font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; padding:32px; }
            .card { max-width:480px; text-align:center; }
            h1 { font-size:24px; margin:0 0 12px; font-weight:800; letter-spacing:-0.02em; }
            p { color:#525252; line-height:1.55; margin:0 0 16px; font-size:15px; }
            a { color:#0a0a0a; text-decoration:underline; text-underline-offset:3px; }
            .dot { color:#a78bfa; }
        </style>
    </head><body>
        <div class="card">
            <p style="font-weight:800;font-size:18px;color:#0a0a0a;margin-bottom:24px;">PIXEL<span class="dot">·</span></p>
            <h1>Subscrição cancelada.</h1>
            <p>Não vais receber mais a newsletter neste endereço. Se mudares de ideias, podes <a href="<?php echo esc_url( home_url( '/' ) ); ?>">voltar a subscrever no site</a> quando quiseres.</p>
            <p style="color:#a3a3a3;font-family:Georgia,serif;"><?php echo newpixel_manifesto(); ?>.</p>
        </div>
    </body></html>
    <?php
    exit;
}

// ============================================================================
// Admin notification — heads-up email when a draft is generated
// ============================================================================

/**
 * Send a quick heads-up email to the site admin so they know there's a
 * fresh draft to (optionally) tweak before the 08:00 auto-send.
 */
function newpixel_newsletter_notify_admin_of_draft( $issue_id ) {
    $admin_email = get_option( 'admin_email' );
    if ( ! $admin_email ) return;

    $issue = get_post( $issue_id );
    if ( ! $issue ) return;

    $edit_url = admin_url( 'post.php?post=' . $issue_id . '&action=edit' );
    $subject  = sprintf( '[Pixel] %s — rascunho pronto', $issue->post_title );

    $message = sprintf(
        "Olá,\n\n" .
        "Foi criado um rascunho da newsletter:\n\n" .
        "    %s\n\n" .
        "Para enviar, abre o rascunho e marca 'Aprovar para envio automático'.\n" .
        "A próxima janela de envio (08:00, 12:00 ou 18:00) entrega-o aos subscritores.\n" .
        "Se não aprovares até às 18:00, esta edição é saltada.\n\n" .
        "Antes de aprovar podes (opcionalmente) editar o texto da headline.\n\n" .
        "Abrir rascunho: %s\n\n" .
        "Pixel Magazine",
        $issue->post_title,
        $edit_url
    );

    wp_mail( $admin_email, $subject, $message );
}

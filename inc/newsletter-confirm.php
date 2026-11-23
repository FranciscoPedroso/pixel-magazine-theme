<?php
/**
 * Newsletter — email confirmation for subscriptions (double opt-in).
 * ================================================================
 *
 * An address only joins the list once its owner confirms it. Without this,
 * anyone (or a bot) could enrol other people's emails, and the next issue
 * would go to people who never asked for it.
 *
 *   - The link is signed (HMAC of email + date) and expires after 7 days.
 *     Nothing is stored before confirmation, so an unconfirmed request leaves
 *     no personal data in the database.
 *   - Opening the link shows a button; only the POST activates. Mail filters
 *     open links on their own, and a GET with side effects would confirm
 *     fake subscriptions.
 *   - The consent date is the confirmation date.
 *
 * @package newpixel
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

const NEWPIXEL_NEWSLETTER_CONFIRM_TTL = 7 * DAY_IN_SECONDS;

function newpixel_newsletter_confirm_token( $email, $ts ) {
    return hash_hmac( 'sha256', 'newsletter-confirm|' . $email . '|' . $ts, wp_salt() );
}

function newpixel_newsletter_confirm_url( $email ) {
    $ts = time();
    return add_query_arg( array(
        'pixel_confirm' => '1',
        'email'         => rawurlencode( $email ),
        'ts'            => $ts,
        'token'         => newpixel_newsletter_confirm_token( $email, $ts ),
    ), home_url( '/' ) );
}

function newpixel_newsletter_email_is_subscribed( $email ) {
    return in_array( strtolower( $email ), newpixel_newsletter_get_subscriber_emails(), true );
}

/**
 * Sends the confirmation email.
 *
 * Also returns true when deliberately sending nothing (already subscribed, or
 * daily limit reached), so the response can't reveal who is on the list.
 * Only returns false if sending actually fails.
 *
 * @param string $email Address to confirm.
 * @return bool
 */
function newpixel_newsletter_request_confirmation( $email ) {
    $email = strtolower( sanitize_email( $email ) );
    if ( ! is_email( $email ) ) {
        return false;
    }
    if ( newpixel_newsletter_email_is_subscribed( $email ) ) {
        return true;
    }
    // Stops the form from being used to flood someone's inbox.
    if ( newpixel_rate_limited( 'nl-confirm|' . $email, 3, DAY_IN_SECONDS ) ) {
        return true;
    }

    $url  = newpixel_newsletter_confirm_url( $email );
    $html = '<!doctype html><html lang="pt"><body style="margin:0;padding:32px 16px;background:#fafaf7;color:#0a0a0a;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;">'
        . '<div style="max-width:480px;margin:0 auto;">'
        . '<p style="font-weight:800;font-size:18px;margin:0 0 24px;">PIXEL<span style="color:#a78bfa;">·</span></p>'
        . '<h1 style="font-size:24px;line-height:1.2;margin:0 0 12px;">Confirma a tua subscrição</h1>'
        . '<p style="color:#525252;font-size:15px;line-height:1.55;margin:0 0 24px;">Recebemos um pedido para enviar a newsletter da Pixel Magazine para este email. Se foste tu, confirma no botão abaixo.</p>'
        . '<p style="margin:0 0 24px;"><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:12px 22px;border-radius:999px;background:#0a0a0a;color:#ffffff;font-weight:600;text-decoration:none;">Confirmar subscrição</a></p>'
        . '<p style="color:#737373;font-size:13px;line-height:1.55;margin:0;">O link é válido durante 7 dias. Se não foste tu, ignora este email: não vais receber mais nada.</p>'
        . '</div></body></html>';

    return (bool) wp_mail(
        $email,
        'Confirma a tua subscrição da Pixel Magazine',
        $html,
        array( 'Content-Type: text/html; charset=UTF-8' )
    );
}

/**
 * Activates the subscription of a confirmed email.
 *
 * @param string $email Confirmed address.
 */
function newpixel_newsletter_activate( $email ) {
    $email = strtolower( sanitize_email( $email ) );
    $user  = get_user_by( 'email', $email );

    if ( $user ) {
        update_user_meta( $user->ID, NEWPIXEL_NEWSLETTER_META, '1' );
        if ( ! get_user_meta( $user->ID, NEWPIXEL_NEWSLETTER_DATE_META, true ) ) {
            update_user_meta( $user->ID, NEWPIXEL_NEWSLETTER_DATE_META, current_time( 'mysql' ) );
        }
        newpixel_remove_guest_subscription( $email );
    } else {
        newpixel_record_guest_subscription( $email );
    }
    do_action( 'newpixel_newsletter_subscribe', $email );
}

add_action( 'template_redirect', 'newpixel_newsletter_handle_confirm_link' );
function newpixel_newsletter_handle_confirm_link() {
    $is_post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
    $input   = $is_post ? $_POST : $_GET;
    if ( empty( $input['pixel_confirm'] ) ) return;

    nocache_headers();

    $email = isset( $input['email'] ) ? strtolower( sanitize_email( wp_unslash( $input['email'] ) ) ) : '';
    $ts    = isset( $input['ts'] ) ? absint( $input['ts'] ) : 0;
    $token = isset( $input['token'] ) ? sanitize_text_field( wp_unslash( $input['token'] ) ) : '';
    $now   = time();

    $valid = is_email( $email )
        && $ts
        && $ts <= $now + 5 * MINUTE_IN_SECONDS
        && $now - $ts <= NEWPIXEL_NEWSLETTER_CONFIRM_TTL
        && hash_equals( newpixel_newsletter_confirm_token( $email, $ts ), $token );

    if ( ! $valid ) {
        newpixel_newsletter_confirm_page(
            400,
            'Link inválido ou expirado.',
            'Volta a subscrever a newsletter no site para receberes um link novo.'
        );
    }

    if ( ! $is_post ) {
        newpixel_newsletter_confirm_page(
            200,
            'Confirma a tua subscrição.',
            'Vais passar a receber a newsletter da Pixel Magazine neste endereço: ' . $email . '.',
            array( 'email' => $email, 'ts' => $ts, 'token' => $token )
        );
    }

    newpixel_newsletter_activate( $email );
    // The cookie belongs to this browser: don't mark a different logged-in account.
    if ( ! is_user_logged_in() || strtolower( wp_get_current_user()->user_email ) === $email ) {
        newpixel_set_newsletter_subscribed( true );
    }
    newpixel_newsletter_confirm_page(
        200,
        'Subscrição confirmada.',
        'Obrigado! Vais receber a próxima edição neste endereço. Podes cancelar em qualquer email.'
    );
}

/**
 * Minimal response page, matching the unsubscribe page.
 *
 * @param int        $status HTTP status.
 * @param string     $title  Title.
 * @param string     $text   Text.
 * @param array|null $form   Confirmation form fields, if any.
 */
function newpixel_newsletter_confirm_page( $status, $title, $text, $form = null ) {
    status_header( $status );
    header( 'Content-Type: text/html; charset=utf-8' );
    ?>
    <!doctype html><html lang="pt"><head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <meta name="robots" content="noindex,nofollow">
        <title><?php echo esc_html( $title ); ?> · Pixel Magazine</title>
        <style>
            body { background:#fafaf7; color:#0a0a0a; font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; padding:24px; box-sizing:border-box; }
            .card { max-width:480px; text-align:center; overflow-wrap:anywhere; }
            h1 { font-size:24px; margin:0 0 12px; font-weight:800; letter-spacing:-0.02em; }
            p { color:#525252; line-height:1.55; margin:0 0 16px; font-size:15px; }
            a { color:#0a0a0a; text-decoration:underline; text-underline-offset:3px; }
            button { border:0; border-radius:999px; background:#0a0a0a; color:#fff; font:600 15px/1 inherit; padding:14px 24px; cursor:pointer; }
            .dot { color:#a78bfa; }
        </style>
    </head><body>
        <div class="card">
            <p style="font-weight:800;font-size:18px;color:#0a0a0a;margin-bottom:24px;">PIXEL<span class="dot">·</span></p>
            <h1><?php echo esc_html( $title ); ?></h1>
            <p><?php echo esc_html( $text ); ?></p>
            <?php if ( $form ) : ?>
                <form method="post" action="<?php echo esc_url( home_url( '/' ) ); ?>">
                    <input type="hidden" name="pixel_confirm" value="1">
                    <?php foreach ( $form as $name => $value ) : ?>
                        <input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
                    <?php endforeach; ?>
                    <button type="submit">Confirmar subscrição</button>
                </form>
            <?php else : ?>
                <p><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Voltar à Pixel Magazine</a></p>
            <?php endif; ?>
        </div>
    </body></html>
    <?php
    exit;
}

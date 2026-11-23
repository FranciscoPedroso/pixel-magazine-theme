<?php
/**
 * Newsletter — subscription state per user (user_meta) and per guest
 * (cookie + an option list of guest emails so a later signup with the
 * same email auto-promotes to a real user_meta flag).
 *
 * Also wires the WP-admin user list with a Newsletter column, a filter
 * dropdown, and a section on the user-edit screen.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// ============================================================================
// Helper — PT-PT date formatting that NEVER falls back to English
//
// WP's mysql2date()/date_i18n()/wp_date() rely on the locale being loaded,
// which is flaky in cron, REST, AJAX and email-dispatch contexts. This
// helper bypasses the locale system and hardcodes the PT-PT month/weekday
// names. Format string is otherwise compatible with PHP date()/DateTime.
//
//   newpixel_date_pt( 'j M Y', '2026-05-25 14:30:00' )  → '25 Mai 2026'
//   newpixel_date_pt( 'l, j \d\e F' )                   → 'Segunda-feira, 25 de Maio'
// ============================================================================
function newpixel_date_pt( $format, $date = null ) {
    static $months_short_pt   = array( 1 => 'Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez' );
    static $months_full_pt    = array( 1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro' );
    static $weekdays_full_pt  = array( 0 => 'Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado' );
    static $weekdays_short_pt = array( 0 => 'Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb' );

    $tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'Europe/Lisbon' );

    if ( $date === null ) {
        $dt = new DateTimeImmutable( 'now', $tz );
    } elseif ( $date instanceof DateTimeInterface ) {
        $dt = $date;
    } elseif ( is_numeric( $date ) ) {
        $dt = ( new DateTimeImmutable( '@' . (int) $date ) )->setTimezone( $tz );
    } else {
        try { $dt = new DateTimeImmutable( $date, $tz ); }
        catch ( Exception $e ) { $dt = new DateTimeImmutable( 'now', $tz ); }
    }

    $month_num   = (int) $dt->format( 'n' );
    $day_of_week = (int) $dt->format( 'w' );

    // Walk the format string char-by-char so we can swap locale-dependent
    // tokens (M, F, l, D) for the hardcoded PT versions while passing every
    // other char through to PHP's DateTime::format().
    $out = '';
    $len = strlen( $format );
    for ( $i = 0; $i < $len; $i++ ) {
        $c = $format[ $i ];
        if ( $c === '\\' && $i + 1 < $len ) {
            $out .= $format[ ++$i ];           // escaped literal
        } elseif ( $c === 'M' ) {
            $out .= $months_short_pt[ $month_num ];
        } elseif ( $c === 'F' ) {
            $out .= $months_full_pt[ $month_num ];
        } elseif ( $c === 'l' ) {
            $out .= $weekdays_full_pt[ $day_of_week ];
        } elseif ( $c === 'D' ) {
            $out .= $weekdays_short_pt[ $day_of_week ];
        } else {
            $out .= $dt->format( $c );
        }
    }
    return $out;
}

// ============================================================================
// Newsletter — track subscription state per user (or anonymous via cookie)
// so the form is hidden on subsequent visits once the user has signed up.
//
// Storage:
//   • Logged-in users: user meta `newpixel_newsletter_subscribed` = '1'
//   • Guests: cookie `pixel_newsletter_subscribed` = '1' (1 year)
// ============================================================================
const NEWPIXEL_NEWSLETTER_META    = 'newpixel_newsletter_subscribed';
const NEWPIXEL_NEWSLETTER_COOKIE  = 'pixel_newsletter_subscribed';
// Guest emails that subscribed before having an account. Used to auto-promote
// the subscription to a real user_meta flag the moment a matching account is
// created — see newpixel_promote_guest_subscription().
const NEWPIXEL_NEWSLETTER_GUESTS  = 'newpixel_newsletter_guest_emails';

// Subscription timestamp tracking — captures WHEN each subscriber opted in,
// kept separate from the main meta/option so the existing boolean checks
// stay simple. Used by the newsletter dispatcher to print a "Recebes este
// email porque subscreveste a Pixel a {DATA}" line, and also serves as
// proof of consent if ever audited under RGPD (art. 7.º/1).
//
//   • Logged-in users: user_meta 'newpixel_newsletter_subscribed_at' (MySQL datetime)
//   • Guests: option 'newpixel_newsletter_guest_dates' (assoc array email => datetime)
const NEWPIXEL_NEWSLETTER_DATE_META   = 'newpixel_newsletter_subscribed_at';
const NEWPIXEL_NEWSLETTER_GUEST_DATES = 'newpixel_newsletter_guest_dates';

function newpixel_is_newsletter_subscribed() {
    if ( is_user_logged_in() ) {
        $meta = get_user_meta( get_current_user_id(), NEWPIXEL_NEWSLETTER_META, true );
        if ( $meta ) return true;
    }
    return ! empty( $_COOKIE[ NEWPIXEL_NEWSLETTER_COOKIE ] );
}

function newpixel_set_newsletter_subscribed( $subscribed = true ) {
    if ( is_user_logged_in() ) {
        if ( $subscribed ) {
            update_user_meta( get_current_user_id(), NEWPIXEL_NEWSLETTER_META, '1' );
            // Stamp the timestamp ONLY when there isn't one yet, so re-clicking
            // subscribe (or the meta being re-saved by some other flow) doesn't
            // overwrite the original consent date.
            if ( ! get_user_meta( get_current_user_id(), NEWPIXEL_NEWSLETTER_DATE_META, true ) ) {
                update_user_meta( get_current_user_id(), NEWPIXEL_NEWSLETTER_DATE_META, current_time( 'mysql' ) );
            }
        } else {
            delete_user_meta( get_current_user_id(), NEWPIXEL_NEWSLETTER_META );
            delete_user_meta( get_current_user_id(), NEWPIXEL_NEWSLETTER_DATE_META );
        }
    }
    $expires = $subscribed ? time() + YEAR_IN_SECONDS : time() - HOUR_IN_SECONDS;
    setcookie( NEWPIXEL_NEWSLETTER_COOKIE, $subscribed ? '1' : '', array(
        'expires'  => $expires,
        'path'     => COOKIEPATH ?: '/',
        'domain'   => COOKIE_DOMAIN,
        'secure'   => is_ssl(),
        'httponly' => false, // readable by JS as a safety belt for the rail
        'samesite' => 'Lax',
    ) );
    if ( $subscribed ) {
        $_COOKIE[ NEWPIXEL_NEWSLETTER_COOKIE ] = '1';
    } else {
        unset( $_COOKIE[ NEWPIXEL_NEWSLETTER_COOKIE ] );
    }
}

function newpixel_handle_newsletter_subscribe() {
    if ( empty( $_POST['_nonce'] ) || ! wp_verify_nonce( $_POST['_nonce'], 'pixel_newsletter' ) ) {
        wp_send_json_error( array( 'message' => __( 'Sessão expirada. Recarrega a página.', 'newpixel' ) ), 403 );
    }
    // Honeypot — bots fill the hidden field; reject silently.
    if ( newpixel_honeypot_tripped() ) {
        wp_send_json_error( array( 'message' => __( 'Não foi possível subscrever.', 'newpixel' ) ), 400 );
    }
    // When logged in, only the account itself can be subscribed.
    $email = is_user_logged_in()
        ? wp_get_current_user()->user_email
        : ( isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '' );
    if ( ! is_email( $email ) ) {
        wp_send_json_error( array( 'message' => __( 'Email inválido.', 'newpixel' ) ), 400 );
    }
    if ( newpixel_rate_limited( 'nl-ip|' . newpixel_client_ip(), 20, HOUR_IN_SECONDS ) ) {
        wp_send_json_error( array( 'message' => __( 'Demasiados pedidos. Tenta novamente mais tarde.', 'newpixel' ) ), 429 );
    }

    // The subscription only becomes active once the email's owner confirms it
    // (inc/newsletter-confirm.php).
    if ( ! newpixel_newsletter_request_confirmation( $email ) ) {
        wp_send_json_error( array( 'message' => __( 'Não foi possível enviar o email de confirmação. Tenta novamente mais tarde.', 'newpixel' ) ), 500 );
    }

    wp_send_json_success( array(
        'message' => sprintf(
            /* translators: %s: subscriber email */
            __( 'Quase lá! Enviámos um link de confirmação para %s.', 'newpixel' ),
            $email
        ),
    ) );
}
add_action( 'wp_ajax_pixel_newsletter_subscribe',        'newpixel_handle_newsletter_subscribe' );
add_action( 'wp_ajax_nopriv_pixel_newsletter_subscribe', 'newpixel_handle_newsletter_subscribe' );

// ----------------------------------------------------------------------------
// Guest-email subscription bridge
// ----------------------------------------------------------------------------
// When a non-logged-in visitor subscribes, we have no user_meta to write to,
// so we keep the email address in an option. On user_register we look up the
// new account's email — if it matches a guest entry, we promote the
// subscription onto the user (and remove the guest record).
//
// All operations are case-insensitive (emails are stored lowercased) so a
// later mixed-case registration still matches.
// ----------------------------------------------------------------------------
function newpixel_record_guest_subscription( $email ) {
    $email = strtolower( sanitize_email( $email ) );
    if ( ! is_email( $email ) ) return;
    $list = (array) get_option( NEWPIXEL_NEWSLETTER_GUESTS, array() );
    if ( ! in_array( $email, $list, true ) ) {
        $list[] = $email;
        update_option( NEWPIXEL_NEWSLETTER_GUESTS, $list, false ); // no autoload
    }
    // Stamp the consent timestamp in the parallel option (assoc array
    // email => datetime). Only set on first subscription; re-subscribing
    // doesn't reset the date.
    $dates = (array) get_option( NEWPIXEL_NEWSLETTER_GUEST_DATES, array() );
    if ( empty( $dates[ $email ] ) ) {
        $dates[ $email ] = current_time( 'mysql' );
        update_option( NEWPIXEL_NEWSLETTER_GUEST_DATES, $dates, false );
    }
}

function newpixel_remove_guest_subscription( $email ) {
    $email = strtolower( sanitize_email( $email ) );
    if ( ! is_email( $email ) ) return;
    $list = (array) get_option( NEWPIXEL_NEWSLETTER_GUESTS, array() );
    if ( in_array( $email, $list, true ) ) {
        $list = array_values( array_diff( $list, array( $email ) ) );
        update_option( NEWPIXEL_NEWSLETTER_GUESTS, $list, false );
    }
    // Drop the parallel date entry too
    $dates = (array) get_option( NEWPIXEL_NEWSLETTER_GUEST_DATES, array() );
    if ( isset( $dates[ $email ] ) ) {
        unset( $dates[ $email ] );
        update_option( NEWPIXEL_NEWSLETTER_GUEST_DATES, $dates, false );
    }
}

/**
 * Look up the subscription date for a given email — checks user_meta
 * first (account exists), falls back to the guest dates option, returns
 * empty string if neither has a record.
 *
 * @param  string $email Lowercase email
 * @return string Formatted date "DD/MM/YYYY" or empty string if unknown
 */
function newpixel_get_subscription_date( $email ) {
    $email = strtolower( sanitize_email( $email ) );
    $iso = '';

    $user = get_user_by( 'email', $email );
    if ( $user ) {
        $iso = (string) get_user_meta( $user->ID, NEWPIXEL_NEWSLETTER_DATE_META, true );
    }
    if ( ! $iso ) {
        $dates = (array) get_option( NEWPIXEL_NEWSLETTER_GUEST_DATES, array() );
        $iso = (string) ( $dates[ $email ] ?? '' );
    }

    return $iso ? mysql2date( 'd/m/Y', $iso ) : '';
}

// One-shot backfill: stamps "now" on every existing subscriber that
// doesn't have a consent timestamp yet. Runs once via the option flag
// `newpixel_newsletter_dates_backfilled`. Without this, the very first
// newsletter sends after this code lands wouldn't include a date for
// the people who subscribed before the tracking existed.
add_action( 'admin_init', 'newpixel_newsletter_backfill_dates' );
function newpixel_newsletter_backfill_dates() {
    if ( get_option( 'newpixel_newsletter_dates_backfilled' ) === '1' ) return;
    if ( wp_doing_ajax() || wp_doing_cron() ) return;

    $now = current_time( 'mysql' );

    // Logged-in users with the flag but no date
    $users = get_users( array(
        'meta_query' => array(
            'relation' => 'AND',
            array( 'key' => NEWPIXEL_NEWSLETTER_META,      'value' => '1' ),
            array( 'key' => NEWPIXEL_NEWSLETTER_DATE_META, 'compare' => 'NOT EXISTS' ),
        ),
        'fields' => array( 'ID' ),
    ) );
    foreach ( $users as $u ) {
        update_user_meta( $u->ID, NEWPIXEL_NEWSLETTER_DATE_META, $now );
    }

    // Guests: any email in the list without a date in the parallel map
    $list  = (array) get_option( NEWPIXEL_NEWSLETTER_GUESTS, array() );
    $dates = (array) get_option( NEWPIXEL_NEWSLETTER_GUEST_DATES, array() );
    foreach ( $list as $email ) {
        if ( empty( $dates[ $email ] ) ) {
            $dates[ $email ] = $now;
        }
    }
    update_option( NEWPIXEL_NEWSLETTER_GUEST_DATES, $dates, false );
    update_option( 'newpixel_newsletter_dates_backfilled', '1' );
}

// On any new user creation (front-end registration, social login, manual admin
// add, WP-CLI), check whether the email had previously subscribed as a guest
// and inherit that subscription. Removes the guest record so the option
// doesn't grow forever.
function newpixel_promote_guest_subscription( $user_id ) {
    $user = get_userdata( $user_id );
    if ( ! $user || ! is_email( $user->user_email ) ) return;

    $email = strtolower( $user->user_email );
    $list  = (array) get_option( NEWPIXEL_NEWSLETTER_GUESTS, array() );

    if ( in_array( $email, $list, true ) ) {
        update_user_meta( $user_id, NEWPIXEL_NEWSLETTER_META, '1' );
        do_action( 'newpixel_newsletter_subscribe', $user->user_email );
        newpixel_remove_guest_subscription( $email );
    }
}
add_action( 'user_register', 'newpixel_promote_guest_subscription' );

// ---- Admin: surface the per-user fields in wp-admin -----------------------
//
// Without these hooks the newsletter flag and bookmarks live in user_meta
// invisible to the admin. The hooks below add:
//   • A "Newsletter" column on Users → All Users (with a filter dropdown).
//   • A read/write panel inside the user-edit screen with newsletter status,
//     bookmarks count and a few useful read-only metrics.
function newpixel_user_columns( $columns ) {
    $columns['newpixel_newsletter'] = __( 'Newsletter', 'newpixel' );
    $columns['newpixel_bookmarks']  = __( 'Bookmarks', 'newpixel' );
    return $columns;
}
add_filter( 'manage_users_columns', 'newpixel_user_columns' );

function newpixel_user_columns_content( $value, $column_name, $user_id ) {
    if ( $column_name === 'newpixel_newsletter' ) {
        $sub = (bool) get_user_meta( $user_id, NEWPIXEL_NEWSLETTER_META, true );
        return $sub
            ? '<span style="color:#10b981;font-weight:600;">✓ ' . esc_html__( 'Subscribed', 'newpixel' ) . '</span>'
            : '<span style="color:#a3a3a3;">—</span>';
    }
    if ( $column_name === 'newpixel_bookmarks' ) {
        $bookmarks = get_user_meta( $user_id, 'pixel_bookmarks', true );
        $count     = is_array( $bookmarks ) ? count( $bookmarks ) : 0;
        return $count > 0 ? (string) $count : '—';
    }
    return $value;
}
add_filter( 'manage_users_custom_column', 'newpixel_user_columns_content', 10, 3 );

// "Newsletter: subscribed/all" filter dropdown above the users list
function newpixel_user_filter_dropdown( $which ) {
    if ( $which !== 'top' ) return;
    $current = isset( $_GET['newpixel_newsletter'] ) ? sanitize_text_field( wp_unslash( $_GET['newpixel_newsletter'] ) ) : '';
    ?>
    <label class="screen-reader-text" for="newpixel_newsletter"><?php esc_html_e( 'Filter by newsletter', 'newpixel' ); ?></label>
    <select name="newpixel_newsletter" id="newpixel_newsletter" style="margin: 1px 4px 0 0;">
        <option value=""><?php esc_html_e( 'Newsletter: all', 'newpixel' ); ?></option>
        <option value="yes" <?php selected( $current, 'yes' ); ?>><?php esc_html_e( 'Subscribed', 'newpixel' ); ?></option>
        <option value="no" <?php selected( $current, 'no' ); ?>><?php esc_html_e( 'Not subscribed', 'newpixel' ); ?></option>
    </select>
    <?php submit_button( __( 'Filter', 'newpixel' ), '', 'newpixel_filter_users', false );
}
add_action( 'manage_users_extra_tablenav', 'newpixel_user_filter_dropdown' );

function newpixel_user_filter_query( $query ) {
    if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) return;
    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'users' ) return;

    $filter = isset( $_GET['newpixel_newsletter'] ) ? sanitize_text_field( wp_unslash( $_GET['newpixel_newsletter'] ) ) : '';
    if ( $filter === 'yes' ) {
        $query->set( 'meta_key', NEWPIXEL_NEWSLETTER_META );
        $query->set( 'meta_value', '1' );
    } elseif ( $filter === 'no' ) {
        $query->set( 'meta_query', array(
            array(
                'key'     => NEWPIXEL_NEWSLETTER_META,
                'compare' => 'NOT EXISTS',
            ),
        ) );
    }
}
add_action( 'pre_get_users', 'newpixel_user_filter_query' );

// Profile fields on the user-edit screen (own profile + others)
function newpixel_user_profile_fields( $user ) {
    if ( ! current_user_can( 'edit_user', $user->ID ) ) return;
    $sub        = (bool) get_user_meta( $user->ID, NEWPIXEL_NEWSLETTER_META, true );
    $bookmarks  = get_user_meta( $user->ID, 'pixel_bookmarks', true );
    $book_count = is_array( $bookmarks ) ? count( $bookmarks ) : 0;
    $comment_count = (int) get_comments( array(
        'user_id' => $user->ID,
        'count'   => true,
        'status'  => 'approve',
    ) );
    ?>
    <h2><?php esc_html_e( 'Pixel Magazine — reader', 'newpixel' ); ?></h2>
    <table class="form-table" role="presentation">
        <tr>
            <th><label for="newpixel_newsletter_field"><?php esc_html_e( 'Newsletter', 'newpixel' ); ?></label></th>
            <td>
                <label>
                    <input type="checkbox" id="newpixel_newsletter_field" name="newpixel_newsletter_field" value="1" <?php checked( $sub ); ?> />
                    <?php esc_html_e( 'Subscribed to newsletter', 'newpixel' ); ?>
                </label>
                <p class="description"><?php esc_html_e( 'Toggle this checkbox to subscribe/unsubscribe. The reader can also manage this on /perfil/.', 'newpixel' ); ?></p>
            </td>
        </tr>
        <tr>
            <th><?php esc_html_e( 'Saved articles', 'newpixel' ); ?></th>
            <td><strong><?php echo esc_html( $book_count ); ?></strong> <?php esc_html_e( 'saved by reader', 'newpixel' ); ?></td>
        </tr>
        <tr>
            <th><?php esc_html_e( 'Approved comments', 'newpixel' ); ?></th>
            <td><strong><?php echo esc_html( $comment_count ); ?></strong></td>
        </tr>
    </table>
    <?php
    wp_nonce_field( 'newpixel_user_fields_save_' . $user->ID, 'newpixel_user_fields_nonce' );
}
add_action( 'show_user_profile', 'newpixel_user_profile_fields' );
add_action( 'edit_user_profile', 'newpixel_user_profile_fields' );

function newpixel_user_profile_fields_save( $user_id ) {
    if ( ! current_user_can( 'edit_user', $user_id ) ) return;
    if ( empty( $_POST['newpixel_user_fields_nonce'] )
        || ! wp_verify_nonce( $_POST['newpixel_user_fields_nonce'], 'newpixel_user_fields_save_' . $user_id ) ) {
        return;
    }

    if ( ! empty( $_POST['newpixel_newsletter_field'] ) ) {
        update_user_meta( $user_id, NEWPIXEL_NEWSLETTER_META, '1' );
        // Stamp consent date only if not already set (the user already
        // had an active subscription this same flag toggle didn't change
        // their consent — only opting in from off→on creates new consent).
        if ( ! get_user_meta( $user_id, NEWPIXEL_NEWSLETTER_DATE_META, true ) ) {
            update_user_meta( $user_id, NEWPIXEL_NEWSLETTER_DATE_META, current_time( 'mysql' ) );
        }
    } else {
        // Withdrawing consent — clear BOTH the flag and the date so the
        // next subscribe is treated as a fresh consent act (per RGPD
        // art. 7.º/3: withdrawal must be as easy as giving consent, and
        // a withdrawn consent is no longer valid going forward).
        delete_user_meta( $user_id, NEWPIXEL_NEWSLETTER_META );
        delete_user_meta( $user_id, NEWPIXEL_NEWSLETTER_DATE_META );
    }
}
add_action( 'personal_options_update', 'newpixel_user_profile_fields_save' );
add_action( 'edit_user_profile_update', 'newpixel_user_profile_fields_save' );

// Original handler for AJAX unsubscribe (continues below).
function newpixel_handle_newsletter_unsubscribe() {
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => __( 'Sessão necessária.', 'newpixel' ) ), 401 );
    }
    if ( empty( $_POST['_nonce'] ) || ! wp_verify_nonce( $_POST['_nonce'], 'pixel_profile' ) ) {
        wp_send_json_error( array( 'message' => __( 'Sessão expirada. Recarrega a página.', 'newpixel' ) ), 403 );
    }

    do_action( 'newpixel_newsletter_unsubscribe', wp_get_current_user()->user_email );
    newpixel_set_newsletter_subscribed( false );

    wp_send_json_success();
}
add_action( 'wp_ajax_pixel_newsletter_unsubscribe', 'newpixel_handle_newsletter_unsubscribe' );

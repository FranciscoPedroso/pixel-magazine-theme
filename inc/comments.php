<?php
/**
 * Comments — spam protection (honeypot + min-time), WP-core string
 * overrides, comment walker (kf-comment markup), and the AJAX submit
 * endpoint that lets the comment thread update without a page reload.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// ============================================================================
// Comment spam protection — honeypot + minimum-time check
//
// Two layered defences that don't rely on any third-party service:
//
// 1. Honeypot field — a text input visible to bots in the DOM but hidden
//    from humans and screen-readers via CSS off-screen positioning. Most
//    spam bots fill every input they find. If `np_url` arrives populated,
//    the comment is marked spam.
//
// 2. Timestamp + HMAC — the form embeds the time it was rendered and an
//    HMAC signature. On submission we verify the signature (anti-replay
//    with random values) and reject if elapsed time is < 3s (bot speed)
//    or > 24h (stale form / replay).
//
// Suspicious submissions are SILENTLY marked as spam (not rejected with
// an error) — the bot thinks it succeeded but the comment sits in the
// spam queue, invisible to other users.
// ============================================================================
function newpixel_comment_honeypot_fields() {
    $t    = time();
    $hash = hash_hmac( 'sha256', (string) $t, wp_salt( 'auth' ) );
    ?>
    <div class="np-honey" aria-hidden="true">
        <label>
            <?php esc_html_e( 'Website (deixa este campo em branco)', 'newpixel' ); ?>
            <input type="text" name="np_url" tabindex="-1" autocomplete="off" />
        </label>
    </div>
    <input type="hidden" name="np_t" value="<?php echo esc_attr( $t ); ?>" />
    <input type="hidden" name="np_h" value="<?php echo esc_attr( $hash ); ?>" />
    <?php
}
add_action( 'comment_form_top', 'newpixel_comment_honeypot_fields' );

function newpixel_is_likely_spam_submission() {
    // Honeypot tripped — bot filled the hidden text input.
    if ( ! empty( $_POST['np_url'] ) ) {
        return true;
    }

    $t = isset( $_POST['np_t'] ) ? (int) $_POST['np_t'] : 0;
    $h = isset( $_POST['np_h'] ) ? (string) $_POST['np_h'] : '';

    if ( ! $t || ! $h ) {
        return true;
    }

    $expected = hash_hmac( 'sha256', (string) $t, wp_salt( 'auth' ) );
    if ( ! hash_equals( $expected, $h ) ) {
        return true; // tampered or fabricated timestamp
    }

    $elapsed = time() - $t;
    if ( $elapsed < 3 ) {
        return true; // submitted too fast — bot
    }
    if ( $elapsed > 86400 ) {
        return true; // form older than 24h — likely a replayed payload
    }

    return false;
}

function newpixel_force_spam_status() {
    return 'spam';
}

function newpixel_check_comment_for_spam( $commentdata ) {
    if ( newpixel_is_likely_spam_submission() ) {
        add_filter( 'pre_comment_approved', 'newpixel_force_spam_status', 99 );
    }
    return $commentdata;
}
add_filter( 'preprocess_comment', 'newpixel_check_comment_for_spam' );

// Override selected WP-core comment-submission error strings with PT-PT
// versions. The map below targets `$text` exactly as it appears in the WP
// source (including HTML entities such as &#8217; for the apostrophe).
function newpixel_translate_core_strings( $translation, $text, $domain ) {
    if ( 'default' !== $domain ) {
        return $translation;
    }

    static $map = null;
    if ( null === $map ) {
        $map = array(
            'You are posting comments too quickly. Slow down.'
                => 'Estás a publicar comentários demasiado depressa. Aguarda um momento antes de tentar novamente.',
            'Duplicate comment detected; it looks as though you&#8217;ve already said that!'
                => 'Comentário duplicado: parece que já publicaste isto.',
        );
    }

    return isset( $map[ $text ] ) ? $map[ $text ] : $translation;
}
add_filter( 'gettext', 'newpixel_translate_core_strings', 10, 3 );

// ============================================================================
// Helper: normalise an ACF "pros/cons"-style field into a flat array of
// strings. Accepts either a repeater (array of arrays) or a textarea where
// each line is one item.
// ============================================================================
function newpixel_normalise_list( $raw, $sub_key = 'item' ) {
    if ( empty( $raw ) ) return array();

    // ACF repeater — array of arrays
    if ( is_array( $raw ) ) {
        $out = array();
        foreach ( $raw as $row ) {
            if ( is_array( $row ) ) {
                if ( isset( $row[ $sub_key ] ) ) {
                    $val = trim( (string) $row[ $sub_key ] );
                    if ( $val !== '' ) $out[] = $val;
                } else {
                    // Take first scalar value in row
                    foreach ( $row as $val ) {
                        if ( is_scalar( $val ) ) {
                            $val = trim( (string) $val );
                            if ( $val !== '' ) { $out[] = $val; break; }
                        }
                    }
                }
            } elseif ( is_scalar( $row ) ) {
                $val = trim( (string) $row );
                if ( $val !== '' ) $out[] = $val;
            }
        }
        return $out;
    }

    // String — split by newlines / dashes
    $lines = preg_split( '/\r\n|\r|\n/', (string) $raw );
    $out   = array();
    foreach ( $lines as $line ) {
        $line = trim( ltrim( $line, "-•*\t " ) );
        if ( $line !== '' ) $out[] = $line;
    }
    return $out;
}

// ============================================================================
// Render callback: a single article comment (matches kf-comment markup)
// ============================================================================
function newpixel_render_comment( $comment, $args, $depth ) {
    $tag    = ( 'div' === $args['style'] ) ? 'div' : 'li';
    $author = wp_get_current_commenter();
    $is_op  = (int) $comment->user_id && (int) $comment->user_id === (int) get_post_field( 'post_author', $comment->comment_post_ID );
    $klass  = 'kf-comment';
    if ( $depth > 1 ) $klass .= ' kf-comment--reply';

    // Hash-based avatar color per author (palette mirrors the design mock)
    $palette  = array( '#f97316', '#ec4899', '#14b8a6', '#1e3a8a', '#fb923c', '#a855f7', '#06b6d4', '#84cc16', '#ef4444', '#0ea5e9' );
    $seed     = strtolower( get_comment_author_email( $comment ) ?: get_comment_author( $comment ) );
    $hue_idx  = hexdec( substr( md5( $seed ), 0, 2 ) ) % count( $palette );
    $av_color = $palette[ $hue_idx ];

    // Initials for the solid-color avatar — first letter of the first word
    // plus first letter of the last word ("Francisco Pedroso" → "FP").
    // Single-word names ("Maria") collapse to one letter ("M").
    $author_name = trim( get_comment_author( $comment ) );
    $words = preg_split( '/\s+/', $author_name );
    if ( function_exists( 'mb_substr' ) && function_exists( 'mb_strtoupper' ) ) {
        $first = mb_substr( $words[0] ?? '', 0, 1 );
        $last  = ( count( $words ) > 1 ) ? mb_substr( end( $words ), 0, 1 ) : '';
        $av_initial = mb_strtoupper( $first . $last );
    } else {
        $first = substr( $words[0] ?? '', 0, 1 );
        $last  = ( count( $words ) > 1 ) ? substr( end( $words ), 0, 1 ) : '';
        $av_initial = strtoupper( $first . $last );
    }

    // Gravatar with `d=blank` returns a 1×1 transparent GIF when there's no
    // avatar (instead of a 404 — which would pollute the browser console with
    // red "404 Not Found" entries). We detect the blank case in `onload` by
    // checking naturalWidth: real avatars come back at 64px, blanks at 1px.
    // Requesting 64 keeps it crisp at 2× on retina screens.
    $avatar_url = get_avatar_url( $comment, array( 'default' => 'blank', 'size' => 64 ) );
    ?>
    <<?php echo $tag; ?> id="comment-<?php comment_ID(); ?>" <?php comment_class( $klass, $comment ); ?> style="--c:<?php echo esc_attr( $av_color ); ?>">
        <div class="kf-comment__avatar" aria-hidden="true">
            <span class="kf-comment__avatar-initial"><?php echo esc_html( $av_initial ); ?></span>
            <?php if ( $avatar_url ) : ?>
                <img class="kf-comment__avatar-img" src="<?php echo esc_url( $avatar_url ); ?>" alt="" loading="lazy" decoding="async"
                     onload="if(this.naturalWidth<=1)this.style.display='none'"
                     onerror="this.style.display='none'" />
            <?php endif; ?>
        </div>
        <div class="kf-comment__body">
            <div class="kf-comment__head">
                <span class="kf-comment__name"><?php echo esc_html( get_comment_author( $comment ) ); ?></span>
                <?php if ( $is_op ) : ?>
                    <span class="kf-comment__badge">Autor</span>
                <?php endif; ?>
                <span class="kf-comment__time"><?php
                    $diff = time() - get_comment_date( 'U', $comment );
                    if ( $diff < HOUR_IN_SECONDS ) {
                        $n = max( 1, (int) round( $diff / MINUTE_IN_SECONDS ) );
                        echo 'há ' . $n . ' min';
                    } elseif ( $diff < DAY_IN_SECONDS ) {
                        $n = max( 1, (int) round( $diff / HOUR_IN_SECONDS ) );
                        echo 'há ' . $n . 'h';
                    } else {
                        echo esc_html( get_comment_date( 'd/m/Y', $comment ) );
                    }
                ?></span>
            </div>

            <?php if ( '1' !== (string) $comment->comment_approved ) : ?>
                <p class="kf-comment__pending"><em><?php esc_html_e( 'O teu comentário aguarda moderação.', 'newpixel' ); ?></em></p>
            <?php endif; ?>

            <div class="kf-comment__text"><?php comment_text(); ?></div>

            <div class="kf-comment__actions">
                <?php
                comment_reply_link( array_merge( $args, array(
                    'reply_text'    => __( 'responder', 'newpixel' ),
                    /* translators: %s: comment author name */
                    'reply_to_text' => __( 'A responder a %s', 'newpixel' ),
                    'depth'         => $depth,
                    'max_depth'     => $args['max_depth'],
                    'before'        => '<span class="kf-comment__reply">',
                    'after'         => '</span>',
                ) ) );
                ?>
                <?php if ( $comment->get_children() ) :
                    $children = count( $comment->get_children() ); ?>
                    <span class="kf-comment__replies-count"><?php echo (int) $children; ?> <?php echo $children === 1 ? esc_html__( 'resposta', 'newpixel' ) : esc_html__( 'respostas', 'newpixel' ); ?></span>
                <?php endif; ?>
            </div>
        </div>
    <?php
}


// ============================================================================
// AJAX comment submission
//
// Front-end posts to admin-ajax.php?action=pixel_submit_comment with the
// regular comment form fields plus a `pixel_comment_nonce`. We hand the
// payload to wp_handle_comment_submission so spam filters, moderation, and
// the WP comment lifecycle all run unchanged. The new comment is rendered
// through newpixel_render_comment so the markup matches the rest of the
// thread, then returned as JSON.
// ============================================================================
function newpixel_render_single_comment_html( $the_comment, $depth = 1 ) {
    $args = array(
        'style'      => 'ol',
        'avatar_size'=> 64,
        'short_ping' => true,
        'max_depth'  => (int) get_option( 'thread_comments_depth', 5 ),
    );

    // wp_list_comments sets up the comment globals before calling the
    // callback; we're calling it directly so we have to do the same. Without
    // this, comment_text() / comment_ID() / comment_class() all fall back to
    // the previous request's globals (or empty), which is what was making
    // the AJAX-inserted comment render with an empty body.
    global $comment;
    $prev_comment        = $comment;
    $GLOBALS['comment']  = $the_comment;
    $comment             = $the_comment;

    ob_start();
    newpixel_render_comment( $the_comment, $args, max( 1, (int) $depth ) );
    // newpixel_render_comment opens a tag but doesn't close it (matches WP's
    // comment_callback contract). Close it ourselves.
    echo '</li>';
    $html = trim( ob_get_clean() );

    // Restore the previous global so we don't leak state into the rest of
    // the request (admin-ajax may share lifecycle with other handlers).
    $GLOBALS['comment'] = $prev_comment;
    $comment            = $prev_comment;

    return $html;
}

function newpixel_handle_ajax_comment() {
    // Nonce check
    if ( ! isset( $_POST['pixel_comment_nonce'] )
        || ! wp_verify_nonce( $_POST['pixel_comment_nonce'], 'pixel_comment_submit' ) ) {
        wp_send_json_error( array( 'message' => __( 'Sessão expirada. Recarrega a página e tenta novamente.', 'newpixel' ) ), 403 );
    }

    $post_id = isset( $_POST['comment_post_ID'] ) ? (int) $_POST['comment_post_ID'] : 0;
    if ( ! $post_id || ! comments_open( $post_id ) ) {
        wp_send_json_error( array( 'message' => __( 'Os comentários estão fechados neste artigo.', 'newpixel' ) ), 403 );
    }

    // Hand off to the WP core submission handler — runs validation, spam
    // filters, akismet, comment moderation, etc.
    $comment = wp_handle_comment_submission( wp_unslash( $_POST ) );

    if ( is_wp_error( $comment ) ) {
        $msg = $comment->get_error_message();
        if ( ! $msg ) {
            $msg = __( 'Não foi possível publicar o comentário.', 'newpixel' );
        }
        wp_send_json_error( array( 'message' => wp_strip_all_tags( $msg ) ), 400 );
    }

    // Set commenter cookie so subsequent submissions remember name/email
    $user = wp_get_current_user();
    do_action( 'set_comment_cookies', $comment, $user );

    $depth = 1;
    if ( $comment->comment_parent ) {
        $depth = 2;
        $parent = get_comment( $comment->comment_parent );
        while ( $parent && $parent->comment_parent ) {
            $depth++;
            $parent = get_comment( $parent->comment_parent );
        }
    }

    $html = newpixel_render_single_comment_html( $comment, $depth );

    wp_send_json_success( array(
        'comment_id'     => (int) $comment->comment_ID,
        'parent_id'      => (int) $comment->comment_parent,
        'approved'       => (string) $comment->comment_approved,
        'html'           => $html,
        // Anything other than '1' (approved) gets the moderation badge.
        // We deliberately don't differentiate spam vs. pending so the user
        // doesn't get the feedback that something was caught as spam.
        'pending_notice' => ( '1' !== (string) $comment->comment_approved ),
    ) );
}
add_action( 'wp_ajax_pixel_submit_comment',         'newpixel_handle_ajax_comment' );
add_action( 'wp_ajax_nopriv_pixel_submit_comment',  'newpixel_handle_ajax_comment' );

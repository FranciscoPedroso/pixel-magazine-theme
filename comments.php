<?php
/**
 * Comments template — Pixel Magazine article view
 *
 * Mirrors the design mock: sort tabs, big publish button, threaded
 * comments with avatar dot, badge, vote count, reply links.
 */

if ( post_password_required() ) {
    return;
}

$count = (int) get_comments_number();
?>

<div class="kf-comments__head">
    <h2 class="kf-comments__title">
        <?php esc_html_e( 'Comentários', 'newpixel' ); ?>
        <small>· <?php echo esc_html( number_format_i18n( $count ) ); ?></small>
    </h2>
    <?php
    /* Sort toggle intentionally absent until comments have a voting system. */
    ?>
</div>

<?php // ============== COMMENT FORM ============== ?>
<?php
if ( comments_open() ) {
    $commenter = wp_get_current_commenter();
    $req       = (bool) get_option( 'require_name_email' );
    $aria_req  = $req ? ' aria-required="true" required="required"' : '';

    // Include the AJAX nonce inline in submit_field so it ships with the form.
    // Referer field is suppressed (WP's comment_form already emits one).
    $nonce_html = wp_nonce_field( 'pixel_comment_submit', 'pixel_comment_nonce', false, false );

    // Short, friendly status line replacing WP core's verbose "Logged in as".
    // Switching accounts is handled from the profile page (logout button there)
    // — no need to surface that affordance inside every comment form.
    $logged_in_as = '';
    if ( is_user_logged_in() ) {
        $current_user = wp_get_current_user();
        $first_name   = strtok( $current_user->display_name, ' ' ) ?: $current_user->display_name;
        $logged_in_as = sprintf(
            '<p class="logged-in-as">%s</p>',
            sprintf(
                /* translators: %s: user first name */
                __( 'Comentas como <strong>%s</strong>.', 'newpixel' ),
                esc_html( $first_name )
            )
        );
    }

    comment_form( array(
        'class_form'           => 'kf-comment-form',
        'class_submit'         => 'kf-comment-form__submit',
        'submit_button'        => '<button type="submit" name="%1$s" id="%2$s" class="%3$s" data-default-label="' . esc_attr__( 'Publicar →', 'newpixel' ) . '">%4$s &rarr;</button>',
        'submit_field'         => '<div class="kf-comment-form__foot kf-comment-form__foot--no-hint">%1$s %2$s ' . $nonce_html . '</div><div class="kf-comment-form__error" role="alert" aria-live="polite" hidden></div>',
        'title_reply'          => '',
        'title_reply_to'       => __( 'A responder a %s', 'newpixel' ),
        'cancel_reply_link'    => __( 'cancelar', 'newpixel' ),
        'label_submit'         => __( 'Publicar', 'newpixel' ),
        'logged_in_as'         => $logged_in_as,
        'comment_notes_before' => '',
        'comment_notes_after'  => '',
        'comment_field'        => '<p class="comment-form-comment"><label for="comment" class="screen-reader-text">' . esc_html__( 'Comentário', 'newpixel' ) . '</label><textarea id="comment" name="comment" rows="4" maxlength="65525" required placeholder="' . esc_attr__( 'Partilha a tua opinião.', 'newpixel' ) . '"></textarea></p>',
        'fields'               => array(
            'author' => '<p class="comment-form-author"><label for="author">' . esc_html__( 'Nome', 'newpixel' ) . '</label><input id="author" name="author" type="text" value="' . esc_attr( $commenter['comment_author'] ) . '" size="30"' . $aria_req . ' /></p>',
            'email'  => '<p class="comment-form-email"><label for="email">' . esc_html__( 'Email', 'newpixel' ) . '</label><input id="email" name="email" type="email" value="' . esc_attr( $commenter['comment_author_email'] ) . '" size="30"' . $aria_req . ' /></p>',
        ),
    ) );
}
?>

<?php if ( have_comments() ) : ?>
    <ol class="kf-comment-list">
        <?php
        wp_list_comments( array(
            'style'        => 'ol',
            'avatar_size'  => 64,
            'short_ping'   => true,
            'callback'     => 'newpixel_render_comment',
        ) );
        ?>
    </ol>

    <?php
    the_comments_pagination( array(
        'prev_text' => __( '← Anteriores', 'newpixel' ),
        'next_text' => __( 'Seguintes →', 'newpixel' ),
        'class'     => 'kf-comment-pagination',
    ) );
    ?>
<?php elseif ( ! comments_open() ) : ?>
    <p class="kf-comments__closed"><?php esc_html_e( 'Os comentários estão fechados.', 'newpixel' ); ?></p>
<?php endif; ?>

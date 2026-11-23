<?php
/**
 * Profile page — rendered for /perfil/ via template_redirect.
 * Only ever reached when the user is logged in (route handler enforces it).
 */

$user            = wp_get_current_user();
$display_name    = $user->display_name ?: $user->user_login;
$email           = $user->user_email;
$is_subscribed   = newpixel_is_newsletter_subscribed();
$bookmarks       = function_exists( 'newpixel_get_user_bookmarks' ) ? newpixel_get_user_bookmarks() : array();
$bookmarks_count = count( $bookmarks );
$bookmarks_url   = function_exists( 'newpixel_bookmarks_url' ) ? newpixel_bookmarks_url() : home_url( '/' );

// Recent comments by this user (last 5)
$user_comments = get_comments( array(
    'user_id' => $user->ID,
    'number'  => 5,
    'status'  => 'approve',
) );
?>

<main id="content" class="kf-container kf-profile">

    <a class="kf-profile__back"
       href="<?php echo esc_url( home_url( '/' ) ); ?>"
       data-profile-back
       aria-label="<?php esc_attr_e( 'Voltar à página anterior', 'newpixel' ); ?>">
        <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
        <?php esc_html_e( 'Voltar', 'newpixel' ); ?>
    </a>

    <header class="kf-profile__head">
        <div>
            <div class="kf-profile__eyebrow"><?php esc_html_e( 'A TUA CONTA', 'newpixel' ); ?></div>
            <h1 class="kf-profile__title">
                <?php
                printf(
                    /* translators: %s: user display name */
                    esc_html__( 'Olá, %s', 'newpixel' ),
                    '<em>' . esc_html( $display_name ) . '</em>'
                );
                ?><span class="kf-profile__dot">.</span>
            </h1>
            <p class="kf-profile__copy"><?php esc_html_e( 'A leitura à tua maneira, personalizada e privada.', 'newpixel' ); ?></p>
        </div>
    </header>

    <div class="kf-profile__grid">

        <!-- ============== CONTA ============== -->
        <section class="kf-profile__card" aria-labelledby="kf-profile-account">
            <h2 id="kf-profile-account" class="kf-profile__card-title">
                <span class="material-symbols-outlined" aria-hidden="true">person</span>
                <?php esc_html_e( 'Conta', 'newpixel' ); ?>
            </h2>

            <form class="kf-profile__form" data-profile-form="name">
                <div class="kf-profile__field">
                    <label for="kf-profile-name"><?php esc_html_e( 'NOME', 'newpixel' ); ?></label>
                    <input id="kf-profile-name" type="text" name="name" value="<?php echo esc_attr( $display_name ); ?>" autocomplete="name" required />
                </div>

                <div class="kf-profile__field">
                    <label for="kf-profile-email"><?php esc_html_e( 'EMAIL', 'newpixel' ); ?></label>
                    <input id="kf-profile-email" type="email" value="<?php echo esc_attr( $email ); ?>" disabled readonly />
                    <small class="kf-profile__hint"><?php esc_html_e( 'Para mudar o email, contacta-nos.', 'newpixel' ); ?></small>
                </div>

                <div class="kf-profile__form-msg" role="alert" aria-live="polite" hidden></div>
                <button type="submit" class="kf-profile__btn"><?php esc_html_e( 'Guardar', 'newpixel' ); ?></button>

                <input type="hidden" name="action" value="pixel_profile_update_name" />
            </form>
        </section>

        <!-- ============== NEWSLETTER ============== -->
        <section class="kf-profile__card" aria-labelledby="kf-profile-newsletter">
            <h2 id="kf-profile-newsletter" class="kf-profile__card-title">
                <span class="material-symbols-outlined" aria-hidden="true">mail</span>
                <?php esc_html_e( 'Newsletter', 'newpixel' ); ?>
            </h2>
            <p class="kf-profile__card-copy"><?php esc_html_e( 'Resumos curados, terça e sexta. Cancela quando quiseres.', 'newpixel' ); ?></p>

            <label class="kf-profile__toggle">
                <input type="checkbox" data-profile-newsletter <?php checked( $is_subscribed ); ?> />
                <span class="kf-profile__toggle-track"><span class="kf-profile__toggle-thumb"></span></span>
                <span class="kf-profile__toggle-label">
                    <?php esc_html_e( 'Receber a newsletter', 'newpixel' ); ?>
                </span>
            </label>
            <div class="kf-profile__form-msg" data-profile-newsletter-msg role="status" aria-live="polite" hidden></div>
        </section>

        <!-- ============== TEMA ============== -->
        <section class="kf-profile__card" aria-labelledby="kf-profile-theme">
            <h2 id="kf-profile-theme" class="kf-profile__card-title">
                <span class="material-symbols-outlined" aria-hidden="true">contrast</span>
                <?php esc_html_e( 'Tema', 'newpixel' ); ?>
            </h2>
            <p class="kf-profile__card-copy"><?php esc_html_e( 'Escolhe como o site aparece. "Auto" segue o tema do sistema.', 'newpixel' ); ?></p>

            <div class="kf-profile__radio-group" role="radiogroup" aria-label="<?php esc_attr_e( 'Tema', 'newpixel' ); ?>">
                <label class="kf-profile__radio">
                    <input type="radio" name="kf-profile-theme" value="auto" data-profile-theme />
                    <span><?php esc_html_e( 'Auto', 'newpixel' ); ?></span>
                </label>
                <label class="kf-profile__radio">
                    <input type="radio" name="kf-profile-theme" value="light" data-profile-theme />
                    <span><?php esc_html_e( 'Claro', 'newpixel' ); ?></span>
                </label>
                <label class="kf-profile__radio">
                    <input type="radio" name="kf-profile-theme" value="dark" data-profile-theme />
                    <span><?php esc_html_e( 'Escuro', 'newpixel' ); ?></span>
                </label>
            </div>
        </section>

        <!-- ============== ARTIGOS GUARDADOS ============== -->
        <section class="kf-profile__card" aria-labelledby="kf-profile-saved">
            <h2 id="kf-profile-saved" class="kf-profile__card-title">
                <span class="material-symbols-outlined" aria-hidden="true">bookmark</span>
                <?php esc_html_e( 'Artigos guardados', 'newpixel' ); ?>
            </h2>
            <p class="kf-profile__card-copy">
                <?php
                if ( $bookmarks_count > 0 ) {
                    printf(
                        /* translators: %s: count */
                        esc_html( _n( 'Tens %s artigo guardado para ler depois.', 'Tens %s artigos guardados para ler depois.', $bookmarks_count, 'newpixel' ) ),
                        '<strong>' . esc_html( number_format_i18n( $bookmarks_count ) ) . '</strong>'
                    );
                } else {
                    esc_html_e( 'Ainda não guardaste nenhum artigo.', 'newpixel' );
                }
                ?>
            </p>
            <a class="kf-profile__btn kf-profile__btn--ghost" href="<?php echo esc_url( $bookmarks_url ); ?>">
                <?php esc_html_e( 'Ver guardados', 'newpixel' ); ?> &rarr;
            </a>
        </section>

        <!-- ============== RECENT COMMENTS ============== -->
        <?php if ( $user_comments ) : ?>
            <section class="kf-profile__card kf-profile__card--wide" aria-labelledby="kf-profile-comments">
                <h2 id="kf-profile-comments" class="kf-profile__card-title">
                    <span class="material-symbols-outlined" aria-hidden="true">forum</span>
                    <?php esc_html_e( 'Os teus comentários', 'newpixel' ); ?>
                </h2>
                <ul class="kf-profile__comments">
                    <?php foreach ( $user_comments as $c ) :
                        $post_title = get_the_title( $c->comment_post_ID );
                        $post_url   = get_permalink( $c->comment_post_ID );
                        $excerpt    = wp_trim_words( wp_strip_all_tags( $c->comment_content ), 22, '…' );
                        ?>
                        <li>
                            <a href="<?php echo esc_url( $post_url . '#comment-' . (int) $c->comment_ID ); ?>">
                                <span class="kf-profile__comment-meta"><?php echo esc_html( get_comment_date( '', $c ) ); ?> · <?php echo esc_html( $post_title ); ?></span>
                                <span class="kf-profile__comment-body"><?php echo esc_html( $excerpt ); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <!-- ============== PRIVACIDADE ============== -->
        <section class="kf-profile__card" aria-labelledby="kf-profile-privacy">
            <h2 id="kf-profile-privacy" class="kf-profile__card-title">
                <span class="material-symbols-outlined" aria-hidden="true">shield</span>
                <?php esc_html_e( 'Privacidade', 'newpixel' ); ?>
            </h2>
            <p class="kf-profile__card-copy"><?php esc_html_e( 'Tens direito a saber, exportar e apagar tudo o que temos sobre ti. RGPD.', 'newpixel' ); ?></p>
            <?php if ( get_privacy_policy_url() ) : ?>
                <a class="kf-profile__btn kf-profile__btn--ghost" href="<?php echo esc_url( get_privacy_policy_url() ); ?>">
                    <?php esc_html_e( 'Política de privacidade', 'newpixel' ); ?> &rarr;
                </a>
            <?php endif; ?>
        </section>

        <!-- ============== DANGER ZONE ============== -->
        <section class="kf-profile__card kf-profile__card--danger" aria-labelledby="kf-profile-danger">
            <h2 id="kf-profile-danger" class="kf-profile__card-title">
                <span class="material-symbols-outlined" aria-hidden="true">warning</span>
                <?php esc_html_e( 'Eliminar conta', 'newpixel' ); ?>
            </h2>
            <p class="kf-profile__card-copy"><?php esc_html_e( 'Apaga a tua conta permanentemente. Os teus comentários ficam, mas anónimos. Esta ação é irreversível.', 'newpixel' ); ?></p>
            <button type="button" class="kf-profile__btn kf-profile__btn--danger" data-profile-delete>
                <?php esc_html_e( 'Eliminar a minha conta', 'newpixel' ); ?>
            </button>
            <div class="kf-profile__form-msg" data-profile-delete-msg role="alert" aria-live="polite" hidden></div>
        </section>

        <!-- ============== LOG OUT ============== -->
        <section class="kf-profile__card kf-profile__card--logout">
            <a class="kf-profile__btn kf-profile__btn--block" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">
                <?php esc_html_e( 'Terminar sessão', 'newpixel' ); ?>
            </a>
        </section>

    </div>

</main>

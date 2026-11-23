<?php
/**
 * End-of-article newsletter: the point where readers have just finished and
 * decide whether to come back. Same form and endpoint as the sidebar
 * (`form[data-newsletter]` → pixel_newsletter_subscribe); only the layout changes.
 *
 * Always rendered (the page may come from a full-page cache) and hidden
 * client-side for existing subscribers, like the sidebar block.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<section class="kf-nl-inline" aria-label="<?php esc_attr_e( 'Newsletter', 'newpixel' ); ?>" data-newsletter-block>
    <div class="kf-nl-inline__inner">
        <div class="kf-newsletter kf-newsletter--inline">
            <div class="kf-newsletter__text">
                <div class="kf-newsletter__eyebrow"><?php esc_html_e( 'Newsletter', 'newpixel' ); ?></div>
                <h2 class="kf-newsletter__title"><?php esc_html_e( 'Os artigos que importam, duas vezes por semana.', 'newpixel' ); ?></h2>
                <p class="kf-newsletter__copy"><?php esc_html_e( 'Terça e sexta, cinco minutos de leitura. Sem spam, cancelas quando quiseres.', 'newpixel' ); ?></p>
            </div>
            <?php if ( is_user_logged_in() ) : ?>
                <form class="kf-newsletter__form" action="#" method="post" novalidate data-newsletter data-placement="article">
                    <button class="kf-newsletter__submit" type="submit"><?php esc_html_e( 'Subscrever', 'newpixel' ); ?> &rarr;</button>
                    <?php newpixel_honeypot_field(); ?>
                    <input type="hidden" name="action" value="pixel_newsletter_subscribe" />
                    <input type="hidden" name="email" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" />
                    <input type="hidden" name="_nonce" value="<?php echo esc_attr( wp_create_nonce( 'pixel_newsletter' ) ); ?>" />
                </form>
            <?php else : ?>
                <form class="kf-newsletter__form" action="#" method="post" novalidate data-newsletter data-placement="article">
                    <label for="newsletter-email-article" class="visually-hidden"><?php esc_html_e( 'Email', 'newpixel' ); ?></label>
                    <input id="newsletter-email-article" class="kf-newsletter__input" type="email" name="email" placeholder="o.teu.email@dominio.com" required aria-required="true" autocomplete="email" />
                    <button class="kf-newsletter__submit" type="submit"><?php esc_html_e( 'Subscrever', 'newpixel' ); ?> &rarr;</button>
                    <?php newpixel_honeypot_field(); ?>
                    <input type="hidden" name="action" value="pixel_newsletter_subscribe" />
                    <input type="hidden" name="_nonce" value="<?php echo esc_attr( wp_create_nonce( 'pixel_newsletter' ) ); ?>" />
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>

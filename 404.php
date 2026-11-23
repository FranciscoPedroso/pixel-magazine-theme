<?php
/**
 * 404 — page not found.
 *
 * WordPress has already sent the 404 status header before loading this
 * template, so we don't redirect (a redirect-to-home would be a soft-404 and
 * an SEO/UX anti-pattern). Instead: a branded, on-voice message, a search
 * field, and the latest articles — so a dead link becomes a soft landing.
 *
 * Layout reuses .kf-browse (head + list) and newpixel_browse_row(), the same
 * card markup as the archives; the only bespoke bits (search field, recent
 * sub-heading) live in src/scss/_error404.scss.
 *
 * @package newpixel
 */

get_header();
?>

<main id="content" class="kf-container">
    <section class="kf-browse kf-404" style="--c:#a78bfa">

        <header class="kf-browse__head">
            <div>
                <div class="kf-browse__eyebrow"><?php esc_html_e( 'Erro 404', 'newpixel' ); ?></div>
                <h1 class="kf-browse__title"><?php esc_html_e( 'Este pixel não existe.', 'newpixel' ); ?></h1>
                <p class="kf-browse__copy kf-404__copy">
                    <?php esc_html_e( 'A página que procuravas mudou de sítio ou nunca cá esteve. Tenta a pesquisa, ou segue pelos artigos mais recentes.', 'newpixel' ); ?>
                </p>
            </div>
        </header>

        <?php
        $recent_q = new WP_Query( array(
            'posts_per_page'      => 6,
            'post_status'         => 'publish',
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
        ) );
        ?>
        <?php if ( $recent_q->have_posts() ) : ?>
            <h2 class="kf-404__recent-title"><?php esc_html_e( 'Artigos recentes', 'newpixel' ); ?></h2>
            <ol class="kf-browse__list">
                <?php
                while ( $recent_q->have_posts() ) :
                    $recent_q->the_post();
                    newpixel_browse_row();
                endwhile;
                ?>
            </ol>
        <?php endif; wp_reset_postdata(); ?>

    </section>
</main>

<?php get_footer();

<?php
/**
 * "Artigos guardados" — per-user bookmarks index.
 *
 * Reached via the rewrite rule registered in functions.php
 * (`^guardados/?$` and `^guardados/page/(\d+)/?$`). Anonymous visitors are
 * redirected to wp-login with a redirect_to back here.
 *
 * Layout reuses the `kf-browse` archive look so the page feels native to the
 * site even though the underlying query is `post__in` against the current
 * user's saved IDs.
 */

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( wp_login_url( newpixel_bookmarks_url() ) );
    exit;
}

get_header();

$user_id   = get_current_user_id();
$bookmarks = newpixel_get_user_bookmarks( $user_id );
$per_page  = 12;
$paged     = max( 1, (int) get_query_var( 'paged' ) );
$accent    = '#a78bfa'; // brand accent — same fallback the archive uses

// Build the query. WP_Query handles the slice/sort for us when we pass the
// full ID list and `orderby => post__in` to preserve our most-recent-first
// ordering from user_meta.
$args = array(
    'post_type'           => 'post',
    'post_status'         => 'publish',
    'ignore_sticky_posts' => 1,
    'posts_per_page'      => $per_page,
    'paged'               => $paged,
    'no_found_rows'       => false,
);

if ( ! empty( $bookmarks ) ) {
    $args['post__in'] = $bookmarks;
    $args['orderby']  = 'post__in';
} else {
    // Force an empty result set without hitting the DB hard.
    $args['post__in'] = array( 0 );
}

$saved_query = new WP_Query( $args );
$total       = (int) $saved_query->found_posts;
?>

<main id="content" class="kf-container">
    <section class="kf-browse" style="--c:<?php echo esc_attr( $accent ); ?>">

        <header class="kf-browse__head">
            <div>
                <div class="kf-browse__eyebrow"><?php esc_html_e( 'A tua lista', 'newpixel' ); ?></div>
                <h1 class="kf-browse__title"><?php esc_html_e( 'Artigos guardados', 'newpixel' ); ?></h1>
                <p class="kf-browse__copy">
                    <?php esc_html_e( 'Tudo o que marcaste para ler depois — ordenado pelo mais recente.', 'newpixel' ); ?>
                </p>
            </div>
            <?php if ( $total > 0 ) : ?>
                <div class="kf-browse__count">
                    <?php
                    printf(
                        /* translators: %s: number of saved posts */
                        esc_html( _n( '%s artigo', '%s artigos', $total, 'newpixel' ) ),
                        '<strong>' . esc_html( number_format_i18n( $total ) ) . '</strong>'
                    );
                    ?>
                </div>
            <?php endif; ?>
        </header>

        <?php if ( $saved_query->have_posts() ) : ?>

            <ol class="kf-browse__list">
                <?php while ( $saved_query->have_posts() ) : $saved_query->the_post();
                    newpixel_browse_row();
                endwhile; ?>
            </ol>

            <?php
            // Custom paginator: our query isn't the main query, so we hand it
            // explicitly along with the /guardados/ base URL — the helper
            // appends `page/%#%/` itself.
            newpixel_pagination( array(
                'query'    => $saved_query,
                'current'  => $paged,
                'base_url' => newpixel_bookmarks_url(),
            ) );
            ?>

        <?php else : ?>
            <div class="kf-browse__empty">
                <h2><?php esc_html_e( 'Nada guardado ainda', 'newpixel' ); ?></h2>
                <p>
                    <?php
                    printf(
                        /* translators: %s: bookmark icon name (kept literal so it renders if placed in markup) */
                        esc_html__( 'Quando estiveres a ler um artigo, carrega no ícone %s no topo para o adicionares à tua lista.', 'newpixel' ),
                        '<span class="material-symbols-outlined" aria-hidden="true" style="font-size:18px;vertical-align:-3px;">bookmark</span>'
                    );
                    ?>
                </p>
                <p>
                    <a class="kf-browse__cta" href="<?php echo esc_url( home_url( '/' ) ); ?>">
                        <?php esc_html_e( 'Voltar à edição', 'newpixel' ); ?> &rarr;
                    </a>
                </p>
            </div>
        <?php endif; ?>

        <?php wp_reset_postdata(); ?>

    </section>
</main>

<?php get_footer(); ?>

<?php
/**
 * Single Post — Article template
 * Pixel Magazine · NewPixel theme
 *
 * Layout matches the editorial article mock (Pixel-Article.html):
 *  ┌────────────────────────────────────────────┐
 *  │  Eyebrow (Category · Kicker · Reading time) │
 *  │  Title                                      │
 *  │  Dek                                        │
 *  │  Byline (avatar · name · meta · actions)    │
 *  │  Hero image + caption                       │
 *  │  Verdict score box   (optional, ACF)        │
 *  │  Pros / Cons grid    (optional, ACF)        │
 *  │  Body prose (the_content with drop cap)     │
 *  │  Tags                                       │
 *  │  Share toolbar                              │
 *  │  Comments                                   │
 *  └────────────────────────────────────────────┘
 */

get_header();

while ( have_posts() ) : the_post();

    $post_id    = get_the_ID();
    $cat_term   = newpixel_get_primary_category( $post_id );
    $cat_name   = $cat_term ? $cat_term->name : 'Pixel';
    $cat_color  = newpixel_get_post_category_color( $post_id );
    $cat_slug   = newpixel_get_post_category_slug( $post_id );
    $dek        = function_exists( 'get_field' ) ? (string) get_field( 'dek' )  : '';
    if ( ! $dek ) { $dek = get_the_excerpt(); }

    // Optional review-block fields
    $score      = function_exists( 'get_field' ) ? get_field( 'verdict_score' )  : '';
    $verdict    = function_exists( 'get_field' ) ? get_field( 'verdict_text' )   : '';
    $pros_raw   = function_exists( 'get_field' ) ? get_field( 'pros' )           : '';
    $cons_raw   = function_exists( 'get_field' ) ? get_field( 'cons' )           : '';

    // Pros / Cons may be ACF repeaters (array of rows) OR plain textareas (one item per line).
    $pros = newpixel_normalise_list( $pros_raw, 'item' );
    $cons = newpixel_normalise_list( $cons_raw, 'item' );

    // Hero caption (ACF or attachment caption)
    $hero_caption = function_exists( 'get_field' ) ? get_field( 'hero_caption' ) : '';
    if ( ! $hero_caption && has_post_thumbnail() ) {
        $thumb_id = get_post_thumbnail_id( $post_id );
        $hero_caption = wp_get_attachment_caption( $thumb_id );
    }

    // Optional audio/video version of the article — when set, a "Ouvir artigo"
    // button is rendered just above the body. opens in a new tab.
    $listen_url = function_exists( 'get_field' ) ? trim( (string) get_field( 'listen_url' ) ) : '';

    $reading_time = newpixel_reading_time( $post_id );
    $share_url    = get_permalink( $post_id );
    $share_title  = wp_strip_all_tags( get_the_title() );
?>

<!-- Reading progress bar (script populates --fill width) -->
<div class="kf-progress" aria-hidden="true"><div class="kf-progress__fill" data-progress-fill></div></div>

<main id="content" class="kf-single" data-cat="<?php echo esc_attr( $cat_slug ); ?>" style="--c:<?php echo esc_attr( $cat_color ); ?>">

    <article id="post-<?php the_ID(); ?>" <?php post_class( 'kf-article' ); ?>>

        <!-- ============== Eyebrow ============== -->
        <div class="kf-article__head">
            <?php newpixel_category_pill( $cat_term ); ?>
            <span class="kf-article__meta-mono"><?php echo (int) $reading_time; ?> min de leitura</span>
        </div>

        <!-- ============== Title + Dek ============== -->
        <h1 class="kf-article__title"><?php the_title(); ?></h1>

        <?php if ( $dek ) : ?>
            <p class="kf-article__dek"><?php echo esc_html( $dek ); ?></p>
        <?php endif; ?>

        <!-- ============== Byline ============== -->
        <?php
        /* Minimal byline: this is a single-author site, so no avatar, name or role.
           Shows only "publicado há X" and, when relevant, "atualizado há Y". */
        ?>
        <div class="kf-article__byline kf-article__byline--minimal">
            <div class="kf-article__byline__info">
                <div class="author-role">
                    publicado <?php newpixel_time_ago( $post_id ); ?>
                    <?php
                    $modified = get_post_modified_time( 'U', true, $post_id );
                    $posted   = get_post_time( 'U', true, $post_id );
                    if ( $modified && ( $modified - $posted ) > MINUTE_IN_SECONDS * 5 ) :
                        ?>
                        &middot; atualizado <?php
                        $diff = time() - $modified;
                        if ( $diff < HOUR_IN_SECONDS ) {
                            echo 'há ' . max( 1, (int) round( $diff / MINUTE_IN_SECONDS ) ) . ' min';
                        } elseif ( $diff < DAY_IN_SECONDS ) {
                            echo 'há ' . (int) round( $diff / HOUR_IN_SECONDS ) . 'h';
                        } else {
                            echo wp_date( 'd/m/Y', $modified );
                        }
                        ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="kf-article-actions" role="group" aria-label="<?php esc_attr_e( 'Ações do artigo', 'newpixel' ); ?>">
                <?php if ( current_user_can( 'edit_post', $post_id ) ) : ?>
                    <a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>" aria-label="<?php esc_attr_e( 'Editar', 'newpixel' ); ?>" title="<?php esc_attr_e( 'Editar', 'newpixel' ); ?>">
                        <span class="material-symbols-outlined" aria-hidden="true">edit</span>
                    </a>
                <?php endif; ?>

                <?php // Share on X (admin): same one-click share as the backend (dek in the body + permalink). Admins only (manage_options, as in inc/auth.php) and published posts only. ?>
                <?php if ( current_user_can( 'manage_options' ) && 'publish' === get_post_status( $post_id ) && function_exists( 'newpixel_x_share_url' ) ) : ?>
                    <a href="<?php echo esc_url( newpixel_x_share_url( $post_id ) ); ?>"
                       class="kf-x-share"
                       target="_blank"
                       rel="noopener noreferrer"
                       aria-label="<?php esc_attr_e( 'Partilhar no X', 'newpixel' ); ?>"
                       title="<?php esc_attr_e( 'Partilhar no X', 'newpixel' ); ?>">
                        <svg viewBox="0 0 1200 1227" aria-hidden="true" focusable="false"><path fill="currentColor" d="M714.163 519.284 1160.89 0h-105.86L667.137 450.887 357.328 0H0l468.492 681.821L0 1226.37h105.866l409.625-476.152 327.181 476.152H1200L714.137 519.284h.026ZM569.165 687.828l-47.468-67.894-377.686-540.24h162.604l304.797 435.991 47.468 67.894 396.2 566.721H892.476L569.165 687.854v-.026Z"/></svg>
                    </a>
                <?php endif; ?>

                <?php // Bookmark: only shown to authenticated readers; state is per-user, persisted in user_meta. ?>
                <?php if ( is_user_logged_in() ) :
                    $is_saved = newpixel_is_post_saved( $post_id, get_current_user_id() );
                    ?>
                    <button type="button"
                            data-action="bookmark"
                            data-post-id="<?php echo (int) $post_id; ?>"
                            <?php echo $is_saved ? 'data-on="1"' : ''; ?>
                            aria-pressed="<?php echo $is_saved ? 'true' : 'false'; ?>"
                            aria-label="<?php esc_attr_e( 'Guardar artigo', 'newpixel' ); ?>"
                            title="<?php esc_attr_e( 'Guardar artigo', 'newpixel' ); ?>">
                        <span class="material-symbols-outlined" aria-hidden="true">bookmark</span>
                    </button>
                <?php endif; ?>

                <?php // Share: hidden by default; main.js reveals it only when navigator.share is supported. ?>
                <button type="button"
                        data-action="share"
                        class="kf-share-btn"
                        hidden
                        aria-label="<?php esc_attr_e( 'Partilhar', 'newpixel' ); ?>"
                        title="<?php esc_attr_e( 'Partilhar', 'newpixel' ); ?>">
                    <span class="material-symbols-outlined" aria-hidden="true">share</span>
                </button>
            </div>
        </div>
    </article>

    <!-- ============== Hero (breaks out wider than the column) ============== -->
    <figure class="kf-article__hero">
        <div class="kf-imgwrap kf-imgwrap--hero">
            <?php newpixel_image_or_placeholder( array(
                'post_id'   => $post_id,
                'context'   => 'hero-16x9',
                'eager'     => true,
                'label'     => 'HERO · ' . strtoupper( $cat_name ),
                'cat_color' => $cat_color,
            ) ); ?>
        </div>
        <?php if ( $hero_caption ) : ?>
            <figcaption><?php echo esc_html( $hero_caption ); ?></figcaption>
        <?php endif; ?>
    </figure>

    <article class="kf-article kf-article--body">

        <!-- ============== Listen-to-article button (optional) ============== -->
        <?php if ( $listen_url ) : ?>
            <a class="kf-listen-btn" href="<?php echo esc_url( $listen_url ); ?>" target="_blank" rel="noopener">
                <span class="material-symbols-outlined" aria-hidden="true">headphones</span>
                <span><?php esc_html_e( 'Ouvir artigo', 'newpixel' ); ?></span>
                <span class="kf-listen-btn__arrow" aria-hidden="true">&rarr;</span>
            </a>
        <?php endif; ?>

        <!-- ============== Verdict score ============== -->
        <?php if ( $score && $verdict ) : ?>
            <aside class="kf-score" aria-label="<?php esc_attr_e( 'Veredicto', 'newpixel' ); ?>">
                <div class="kf-score__big">
                    <div class="kf-score__num"><?php echo esc_html( $score ); ?></div>
                    <div class="kf-score__scale">DE 10</div>
                </div>
                <div>
                    <div class="kf-score__verdict"><?php esc_html_e( 'Veredicto', 'newpixel' ); ?></div>
                    <p class="kf-score__copy"><?php echo esc_html( $verdict ); ?></p>
                </div>
            </aside>
        <?php endif; ?>

        <!-- ============== Pros / Cons ============== -->
        <?php if ( ! empty( $pros ) || ! empty( $cons ) ) : ?>
            <section class="kf-proscons" aria-label="<?php esc_attr_e( 'Pros e Contras', 'newpixel' ); ?>">
                <?php if ( ! empty( $pros ) ) : ?>
                    <div class="kf-proscons__col kf-proscons__col--pros">
                        <h3 class="kf-proscons__title">Pros</h3>
                        <ul class="kf-proscons__list">
                            <?php foreach ( $pros as $item ) : ?>
                                <li><span><?php echo esc_html( $item ); ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <?php if ( ! empty( $cons ) ) : ?>
                    <div class="kf-proscons__col kf-proscons__col--cons">
                        <h3 class="kf-proscons__title">Contras</h3>
                        <ul class="kf-proscons__list">
                            <?php foreach ( $cons as $item ) : ?>
                                <li><span><?php echo esc_html( $item ); ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <!-- ============== Body ============== -->
        <div class="prose">
            <?php
            the_content();

            wp_link_pages( array(
                'before' => '<nav class="kf-pagination"><span>' . __( 'Páginas:', 'newpixel' ) . '</span>',
                'after'  => '</nav>',
            ) );
            ?>
        </div>

        <!-- ============== Tags ============== -->
        <?php
        $tags = get_the_tags( $post_id );
        if ( $tags ) : ?>
            <nav class="kf-tags" aria-label="<?php esc_attr_e( 'Etiquetas', 'newpixel' ); ?>">
                <?php foreach ( $tags as $tag ) : ?>
                    <a href="<?php echo esc_url( get_tag_link( $tag ) ); ?>">#<?php echo esc_html( $tag->name ); ?></a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>

        <!-- ============== Share ============== -->
        <aside class="kf-share" aria-label="<?php esc_attr_e( 'Partilhar', 'newpixel' ); ?>">
            <div class="kf-share__copy"><?php esc_html_e( 'Partilha:', 'newpixel' ); ?></div>
            <div class="kf-share__list">
                <a href="https://twitter.com/intent/tweet?url=<?php echo rawurlencode( $share_url ); ?>&text=<?php echo rawurlencode( $share_title ); ?>" target="_blank" rel="noopener" aria-label="<?php esc_attr_e( 'Partilhar no X', 'newpixel' ); ?>">
                    <svg class="kf-share__icon" viewBox="0 0 1200 1227" aria-hidden="true" focusable="false"><path fill="currentColor" d="M714.163 519.284 1160.89 0h-105.86L667.137 450.887 357.328 0H0l468.492 681.821L0 1226.37h105.866l409.625-476.152 327.181 476.152H1200L714.137 519.284h.026ZM569.165 687.828l-47.468-67.894-377.686-540.24h162.604l304.797 435.991 47.468 67.894 396.2 566.721H892.476L569.165 687.854v-.026Z"/></svg>
                </a>
                <a href="https://bsky.app/intent/compose?text=<?php echo rawurlencode( $share_title . ' ' . $share_url ); ?>" target="_blank" rel="noopener">Bluesky</a>
                <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo rawurlencode( $share_url ); ?>" target="_blank" rel="noopener">Facebook</a>
                <a href="https://api.whatsapp.com/send?text=<?php echo rawurlencode( $share_title . ' ' . $share_url ); ?>" target="_blank" rel="noopener">WhatsApp</a>
                <a href="mailto:?subject=<?php echo rawurlencode( $share_title ); ?>&body=<?php echo rawurlencode( $share_url ); ?>">Email</a>
                <a href="#" data-action="copy-link" data-url="<?php echo esc_attr( $share_url ); ?>"><?php esc_html_e( 'Copiar link', 'newpixel' ); ?></a>
            </div>
        </aside>

    </article>

    <!-- ============== Newsletter ============== -->
    <?php get_template_part( 'template-parts/newsletter-inline' ); ?>

    <!-- ============== Relacionados ============== -->
    <?php get_template_part( 'template-parts/related' ); ?>

    <!-- ============== Comments ============== -->
    <?php if ( comments_open() || get_comments_number() ) : ?>
        <section class="kf-comments" aria-label="<?php esc_attr_e( 'Comentários', 'newpixel' ); ?>">
            <div class="kf-comments__inner">
                <?php comments_template(); ?>
            </div>
        </section>
    <?php endif; ?>

</main>

<?php endwhile; ?>

<?php get_footer(); ?>

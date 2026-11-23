<?php
/**
 * Front Page — magazine homepage
 *
 * • Page 1   → magazine layout (hero, lead, feature pair, opinion,
 *              three-up, "Mais artigos") + numbered pagination.
 * • Page 2+  → flowing kf-browse list with full pagination.
 *
 * Data preparation lives in inc/frontpage-data.php — see
 * `newpixel_frontpage_paged_state()`, `newpixel_frontpage_archive_query()`,
 * `newpixel_frontpage_more_query()`, and `newpixel_frontpage_pagination_totals()`.
 * Curated slot selection (lead, feature pair, opinion, three-up) lives
 * in inc/frontpage-layout.php — see `newpixel_frontpage_layout()` /
 * `newpixel_frontpage_post()`.
 *
 * This template stays focused on markup + data flow.
 */
get_header();

$page_state = newpixel_frontpage_paged_state();

// ============================================================================
// PAGE 2+ : flowing list of all posts with pagination
// ============================================================================
if ( $page_state['is_archive_page'] ) :
    $archive       = newpixel_frontpage_archive_query( $page_state['paged'] );
    $browse_query  = $archive['query'];
    $page_post_ids = $archive['page_post_ids'];
    ?>

    <main id="content" class="kf-container">

        <?php get_template_part( 'template-parts/home-hero' ); ?>
        <?php get_template_part( 'template-parts/home-cat-scroller' ); ?>

        <div class="kf-home-grid">
            <?php get_template_part( 'template-parts/home-rail-left' ); ?>

            <div class="kf-feed">
                <section class="kf-browse">
                    <?php if ( $browse_query->have_posts() ) : ?>
                        <ol class="kf-browse__list">
                            <?php while ( $browse_query->have_posts() ) : $browse_query->the_post();
                                newpixel_browse_row();
                            endwhile; ?>
                        </ol>

                        <?php
                        // Use a separate fake query for the pager so it counts
                        // the magazine as page 1 (matching the page-1 pager).
                        // The kf-browse__count above keeps using $browse_query's
                        // archive-only totals.
                        $pager_query                = new WP_Query();
                        $pager_query->max_num_pages = $archive['max_pages'] + 1;
                        $pager_query->found_posts   = $archive['total_published'];
                        newpixel_pagination( array(
                            'query'    => $pager_query,
                            'current'  => $archive['paged'],
                            'base_url' => home_url( '/' ),
                        ) );
                        ?>

                    <?php else : ?>
                        <div class="kf-browse__empty">
                            <h2><?php esc_html_e( 'Não há mais artigos por aqui', 'newpixel' ); ?></h2>
                            <p><?php esc_html_e( 'Volta à frontpage para veres o que há de novo.', 'newpixel' ); ?></p>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <?php get_template_part( 'template-parts/home-rail-right', null, array( 'exclude' => $page_post_ids ) ); ?>
        </div>

    </main>

    <?php
    wp_reset_postdata();
    get_footer();
    return;
endif;

// ============================================================================
// PAGE 1 : magazine layout
// ============================================================================

// Resolve the curated frontpage slots (lead, feature pair, opinion, three-up).
// Editors pick each slot in Settings → Frontpage Layout; empty slots auto-fill
// from the most-recent published posts. See inc/frontpage-layout.php.
$layout       = newpixel_frontpage_layout();
$rendered_ids = $layout['used_ids']; // seed: every curated slot's post ID

?>

<main id="content" class="kf-container">

    <?php get_template_part( 'template-parts/home-hero' ); ?>
    <?php get_template_part( 'template-parts/home-cat-scroller' ); ?>

    <!-- ============================================================ -->
    <!-- HOME GRID — left rail / feed / right rail                    -->
    <!-- ============================================================ -->
    <div class="kf-home-grid">

        <?php get_template_part( 'template-parts/home-rail-left' ); ?>

        <!-- ====================================================== -->
        <!-- FEED                                                    -->
        <!-- ====================================================== -->
        <div class="kf-feed">

            <?php // ============== LEAD ============== ?>
            <?php
            $lead = newpixel_frontpage_post( 'lead' );
            if ( $lead ) :
                setup_postdata( $GLOBALS['post'] = $lead );
                $cat_color = newpixel_get_post_category_color();
                $cat_slug  = newpixel_get_post_category_slug();
                ?>
                <article class="kf-card kf-card--lead" data-cat="<?php echo esc_attr( $cat_slug ); ?>" style="--c:<?php echo esc_attr( $cat_color ); ?>">
                    <div class="kf-imgwrap">
                        <?php newpixel_image_or_placeholder( array(
                            'context'   => 'lead-16x9',
                            'eager'     => true,
                            'label'     => 'LEAD · ' . get_the_title(),
                            'cat_color' => $cat_color,
                        ) ); ?>
                    </div>
                    <div class="kf-card__pill-tl">
                        <?php newpixel_category_pill( newpixel_get_primary_category() ); ?>
                    </div>
                    <div class="kf-card__body">
                        <h2 class="kf-card__title">
                            <a class="kf-headline" href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                        </h2>
                        <p class="kf-card__dek">
                            <?php echo newpixel_card_dek( 35 ); ?>
                        </p>
                        <?php newpixel_card_meta(); ?>
                    </div>
                </article>
                <?php wp_reset_postdata(); ?>
            <?php endif; ?>

            <?php // ============== FEATURE PAIR (positions 2 + 3) ============== ?>
            <?php
            $feature_1 = newpixel_frontpage_post( 'feature_1' );
            $feature_2 = newpixel_frontpage_post( 'feature_2' );
            if ( $feature_1 || $feature_2 ) : ?>
                <div class="kf-feature-pair">

                    <?php // Feature 1 — image card (1.4fr) ?>
                    <?php if ( $feature_1 ) :
                        setup_postdata( $GLOBALS['post'] = $feature_1 );
                        $cat_color = newpixel_get_post_category_color();
                        $cat_slug  = newpixel_get_post_category_slug();
                        ?>
                        <article class="kf-card" data-cat="<?php echo esc_attr( $cat_slug ); ?>" style="--c:<?php echo esc_attr( $cat_color ); ?>">
                            <div class="kf-imgwrap">
                                <?php newpixel_image_or_placeholder( array(
                                    'context'   => 'card-16x10',
                                    'label'     => strtoupper( newpixel_get_primary_category_name() ),
                                    'cat_color' => $cat_color,
                                ) ); ?>
                            </div>
                            <div class="kf-card__body">
                                <div class="kf-card__pill"><?php newpixel_category_pill(); ?></div>
                                <h2 class="kf-card__title">
                                    <a class="kf-headline" href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                                </h2>
                                <p class="kf-card__dek">
                                    <?php echo newpixel_card_dek( 28 ); ?>
                                </p>
                                <div class="kf-card__meta">
                                    <span><?php newpixel_time_ago(); ?></span>
                                    <span aria-hidden="true">·</span>
                                    <?php newpixel_meta_comments(); ?>
                                </div>
                            </div>
                        </article>
                    <?php endif; ?>

                    <?php // Feature 2 — text card with big colored kicker (1fr) ?>
                    <?php if ( $feature_2 ) :
                        setup_postdata( $GLOBALS['post'] = $feature_2 );
                        $cat_color  = newpixel_get_post_category_color();
                        $cat_slug   = newpixel_get_post_category_slug();
                        $big_kicker = function_exists( 'get_field' ) ? get_field( 'big_kicker' ) : '';
                        if ( ! $big_kicker ) {
                            // Pull the first 1-2 word punchline from the title
                            $words = explode( ' ', get_the_title() );
                            $big_kicker = isset( $words[0] ) ? $words[0] : strtoupper( newpixel_get_primary_category_name() );
                        }
                        ?>
                        <article class="kf-card kf-card--text" data-cat="<?php echo esc_attr( $cat_slug ); ?>" style="--c:<?php echo esc_attr( $cat_color ); ?>">
                            <div class="kf-card__pill"><?php newpixel_category_pill(); ?></div>
                            <div class="kf-card__big-kicker"><?php echo esc_html( $big_kicker ); ?></div>
                            <h2 class="kf-card__title">
                                <a class="kf-headline" href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                            </h2>
                            <p class="kf-card__dek kf-card__dek--long">
                                <?php echo newpixel_card_dek( 50 ); ?>
                            </p>
                            <div class="kf-card__meta">
                                <span><?php newpixel_time_ago(); ?></span>
                                <span aria-hidden="true">·</span>
                                <?php newpixel_meta_comments(); ?>
                            </div>
                        </article>
                    <?php endif; ?>

                </div>
                <?php wp_reset_postdata(); ?>
            <?php endif; ?>

            <?php // ============== OPINION CARD (purple block) ============== ?>
            <?php
            $opinion = newpixel_frontpage_post( 'opinion' );
            if ( $opinion ) :
                setup_postdata( $GLOBALS['post'] = $opinion );
                $opinion_quote = function_exists( 'get_field' ) ? get_field( 'opinion_quote' ) : '';
                if ( ! $opinion_quote ) {
                    $opinion_quote = '"' . get_the_title() . '"';
                }
                ?>
                <a class="kf-opinion" href="<?php the_permalink(); ?>">
                    <div class="kf-opinion__eyebrow">OPINIÃO</div>
                    <div class="kf-opinion__quote"><?php echo wp_kses_post( $opinion_quote ); ?></div>
                    <div class="kf-opinion__byline">
                        <div>
                            <div class="author-role">
                                Coluna semanal · <?php newpixel_time_ago(); ?>
                            </div>
                        </div>
                        <div class="stats"><?php newpixel_meta_comments(); ?></div>
                    </div>
                </a>
                <?php wp_reset_postdata(); ?>
            <?php endif; ?>

            <?php // ============== THREE-UP GRID ============== ?>
            <?php
            $three_up_posts = array_filter( array(
                newpixel_frontpage_post( 'three_up_1' ),
                newpixel_frontpage_post( 'three_up_2' ),
                newpixel_frontpage_post( 'three_up_3' ),
            ) );
            if ( ! empty( $three_up_posts ) ) : ?>
                <div class="kf-three-up">
                    <?php foreach ( $three_up_posts as $tu_post ) :
                        setup_postdata( $GLOBALS['post'] = $tu_post );
                        $cat_color = newpixel_get_post_category_color();
                        $cat_slug  = newpixel_get_post_category_slug();
                        ?>
                        <article class="kf-card" data-cat="<?php echo esc_attr( $cat_slug ); ?>" style="--c:<?php echo esc_attr( $cat_color ); ?>">
                            <div class="kf-imgwrap">
                                <?php newpixel_image_or_placeholder( array(
                                    'context'   => 'card-4x3',
                                    'label'     => strtoupper( newpixel_get_primary_category_name() ),
                                    'cat_color' => $cat_color,
                                ) ); ?>
                            </div>
                            <div class="kf-card__body">
                                <div class="kf-card__pill"><?php newpixel_category_pill(); ?></div>
                                <h3 class="kf-card__title">
                                    <a class="kf-headline" href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                                </h3>
                                <div class="kf-card__meta">
                                    <span><?php newpixel_time_ago(); ?></span>
                                    <span aria-hidden="true">·</span>
                                    <?php newpixel_meta_comments(); ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; wp_reset_postdata(); ?>
                </div>
            <?php endif; ?>

            <?php // ============== MAIS ARTIGOS — compact list, 4 rows ============== ?>
            <?php
            $more_query = newpixel_frontpage_more_query( $rendered_ids );

            if ( $more_query->have_posts() ) :
                ?>
                <section class="kf-more" aria-labelledby="kf-more-title">
                    <header class="kf-more__head">
                        <h3 id="kf-more-title" class="kf-more__title">Mais artigos</h3>
                    </header>
                    <ol class="kf-more__list">
                        <?php
                        while ( $more_query->have_posts() ) : $more_query->the_post();
                            $rendered_ids[] = get_the_ID();
                            $cat_color = newpixel_get_post_category_color();
                            $cat_slug  = newpixel_get_post_category_slug();
                            $cat_name  = newpixel_get_primary_category_name();
                            $primary_cat = newpixel_get_primary_category();
                            $cat_url     = $primary_cat ? get_term_link( $primary_cat ) : '';
                            ?>
                            <li class="kf-more__row" data-cat="<?php echo esc_attr( $cat_slug ); ?>" style="--c:<?php echo esc_attr( $cat_color ); ?>">
                                <div class="kf-more__thumb">
                                    <?php newpixel_image_or_placeholder( array(
                                        'context'   => 'thumb-1x1',
                                        'label'     => strtoupper( $cat_name ),
                                        'cat_color' => $cat_color,
                                    ) ); ?>
                                </div>
                                <div class="kf-more__body">
                                    <div class="kf-more__meta-top">
                                        <?php if ( $cat_url && ! is_wp_error( $cat_url ) ) : ?>
                                            <a class="kf-more__cat" href="<?php echo esc_url( $cat_url ); ?>"><?php echo esc_html( strtoupper( $cat_name ) ); ?></a>
                                        <?php else : ?>
                                            <span class="kf-more__cat"><?php echo esc_html( strtoupper( $cat_name ) ); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <h4 class="kf-more__heading">
                                        <a class="kf-more__title-link" href="<?php the_permalink(); ?>">
                                            <span class="kf-headline"><?php the_title(); ?></span>
                                        </a>
                                    </h4>
                                    <div class="kf-more__meta-bot">
                                        <span><?php newpixel_time_ago(); ?></span>
                                        <span aria-hidden="true">·</span>
                                        <?php newpixel_meta_comments(); ?>
                                    </div>
                                </div>
                            </li>
                        <?php endwhile; ?>
                    </ol>
                </section>
            <?php endif; wp_reset_postdata(); ?>

            <?php
            // Numbered pagination — page 1 of N for the magazine archive.
            // Lives inside .kf-feed (same as the page 2+ pager) so the
            // visual alignment is consistent: the pager always sits within
            // the feed column, never breaking out to the full container.
            $totals = newpixel_frontpage_pagination_totals( $rendered_ids );

            if ( $totals['total_pages'] > 1 ) :
                $fake_query                = new WP_Query();
                $fake_query->max_num_pages = $totals['total_pages'];
                $fake_query->found_posts   = $totals['total_posts'];
                newpixel_pagination( array(
                    'query'    => $fake_query,
                    'current'  => 1,
                    'base_url' => home_url( '/' ),
                ) );
            endif; ?>

        </div>

        <?php get_template_part( 'template-parts/home-rail-right', null, array( 'exclude' => $rendered_ids ) ); ?>

    </div>

</main>

<?php get_footer(); ?>

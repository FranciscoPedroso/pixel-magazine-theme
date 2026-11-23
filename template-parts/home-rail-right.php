<?php
/**
 * Right rail — Trending (top 5), Da semana, Newsletter.
 *
 * Optional args:
 *   - exclude: array of post IDs to exclude from the rail queries
 *              (so trending/week don't repeat what's in the main feed).
 */
$exclude_ids = ( isset( $args['exclude'] ) && is_array( $args['exclude'] ) ) ? $args['exclude'] : array();
?>
<aside class="kf-rail-right">

    <?php
    // ============================================================================
    // EM ALTA (trending) — resolved upfront so the section can be hidden
    // entirely when there's nothing to show. Avoids rendering a lonely
    // "Em alta" header above an empty container.
    //
    // "Em alta" = trending right now — limited to posts published in the
    // last N days so the list rotates with new content instead of being
    // dominated forever by old hits.
    //
    // Ranking cascade (all scoped to the same time window):
    //   1) Recent posts with view-tracking data — ordered by views DESC
    //   2) If too few: recent posts by comment_count DESC
    //   3) If still too few: recent posts by date DESC
    //
    // Once views accumulate, branch 1 dominates and branches 2/3 become
    // invisible.
    //
    // Tune the window via filter `newpixel_trending_window_days`.
    // ============================================================================
    $window_days = (int) apply_filters( 'newpixel_trending_window_days', 30 );
    $date_query  = array( array( 'after' => '-' . max( 1, $window_days ) . ' days' ) );

    $trending = new WP_Query( array(
        'post_type'           => 'post',
        'posts_per_page'      => 5,
        'meta_key'            => NEWPIXEL_VIEWS_META_KEY,
        'orderby'             => 'meta_value_num',
        'order'               => 'DESC',
        'date_query'          => $date_query,
        'ignore_sticky_posts' => 1,
        'post__not_in'        => $exclude_ids,
        'no_found_rows'       => true,
    ) );

    if ( $trending->post_count < 5 ) {
        $have_ids = array();
        foreach ( $trending->posts as $p ) { $have_ids[] = (int) $p->ID; }

        $needed  = 5 - $trending->post_count;
        $fillers = new WP_Query( array(
            'post_type'           => 'post',
            'posts_per_page'      => $needed,
            'orderby'             => 'comment_count',
            'order'               => 'DESC',
            'date_query'          => $date_query,
            'ignore_sticky_posts' => 1,
            'post__not_in'        => array_merge( $exclude_ids, $have_ids ),
            'no_found_rows'       => true,
        ) );

        if ( ! $fillers->have_posts() ) {
            $fillers = new WP_Query( array(
                'post_type'           => 'post',
                'posts_per_page'      => $needed,
                'orderby'             => 'date',
                'order'               => 'DESC',
                'date_query'          => $date_query,
                'ignore_sticky_posts' => 1,
                'post__not_in'        => array_merge( $exclude_ids, $have_ids ),
                'no_found_rows'       => true,
            ) );
        }

        $merged_posts = array_merge( $trending->posts, $fillers->posts );
        $trending     = new WP_Query();
        $trending->posts      = $merged_posts;
        $trending->post_count = count( $merged_posts );
    }
    ?>

    <?php if ( $trending->post_count > 0 ) : ?>
    <div class="kf-rail-block">
        <div class="kf-rail-block__head">
            <h3 class="kf-rail-block__title">Em alta</h3>
        </div>
        <div class="kf-trending">
            <?php
            $rank = 1;
            foreach ( $trending->posts as $tr_post ) :
                setup_postdata( $GLOBALS['post'] = $tr_post );
                $cat_color = newpixel_get_post_category_color();
                $cat_name  = newpixel_get_primary_category_name();
                ?>
                <a class="kf-trending-row" href="<?php the_permalink(); ?>" style="--c:<?php echo esc_attr( $cat_color ); ?>">
                    <span class="kf-trending-row__rank"><?php echo (int) $rank; ?></span>
                    <div>
                        <h4 class="kf-trending-row__title"><?php the_title(); ?></h4>
                        <div class="kf-trending-row__meta">
                            <?php echo esc_html( strtoupper( $cat_name ) ); ?>
                        </div>
                    </div>
                </a>
            <?php $rank++; endforeach; wp_reset_postdata(); ?>
        </div>
    </div>
    <?php endif; // /Em alta only when there's content to show ?>

    <?php // ============== DA SEMANA (most read this week) ============== ?>
    <div class="kf-rail-block">
        <div class="kf-rail-block__head">
            <h3 class="kf-rail-block__title">Da semana</h3>
        </div>
        <div class="kf-week">
            <?php
            $week = new WP_Query( array(
                'post_type'           => 'post',
                'posts_per_page'      => 4,
                'date_query'          => array(
                    array( 'after' => '7 days ago' ),
                ),
                'orderby'             => 'comment_count',
                'order'               => 'DESC',
                'ignore_sticky_posts' => 1,
            ) );

            if ( ! $week->have_posts() ) {
                $week = new WP_Query( array(
                    'post_type'           => 'post',
                    'posts_per_page'      => 4,
                    'orderby'             => 'date',
                    'order'               => 'DESC',
                    'ignore_sticky_posts' => 1,
                ) );
            }

            while ( $week->have_posts() ) : $week->the_post();
                $cat_color = newpixel_get_post_category_color();
                $cat_slug  = newpixel_get_post_category_slug();
                ?>
                <div class="kf-week-row" data-cat="<?php echo esc_attr( $cat_slug ); ?>" style="--c:<?php echo esc_attr( $cat_color ); ?>">
                    <div class="kf-week-row__pill"><?php newpixel_category_pill(); ?></div>
                    <h4 class="kf-week-row__title">
                        <a class="kf-week-row__title-link" href="<?php the_permalink(); ?>">
                            <span class="kf-week-row__title-text"><?php the_title(); ?></span>
                        </a>
                    </h4>
                </div>
            <?php endwhile; wp_reset_postdata(); ?>
        </div>
    </div>

    <?php // ============== NEWSLETTER ============== ?>
    <?php
    // Always rendered so the homepage can be full-page cached. The
    // block is hidden CLIENT-SIDE (inline script below) for visitors who are
    // already subscribed, keyed off the JS-readable `pixel_newsletter_subscribed`
    // cookie / localStorage flag the subscribe handler sets. Server-side gating
    // (newpixel_is_newsletter_subscribed) would bake one visitor's state into
    // the shared cache, so it's intentionally dropped here.
    ?>
        <div class="kf-newsletter" id="newsletter" data-newsletter-block>
            <div class="kf-newsletter__eyebrow">NEWSLETTER</div>
            <h4 class="kf-newsletter__title">5 minutos. Terça e sexta.</h4>
            <p class="kf-newsletter__copy">Sem hype. Sem spam. Cancela quando quiseres.</p>
            <?php if ( is_user_logged_in() ) :
                // Logged-in: account email is already known, so the form
                // collapses to a single Subscrever button.
                $current_user = wp_get_current_user();
                ?>
                <form class="kf-newsletter__form" action="#" method="post" novalidate data-newsletter>
                    <button class="kf-newsletter__submit" type="submit">Subscrever &rarr;</button>
                    <?php newpixel_honeypot_field(); ?>
                    <input type="hidden" name="action" value="pixel_newsletter_subscribe" />
                    <input type="hidden" name="email" value="<?php echo esc_attr( $current_user->user_email ); ?>" />
                    <input type="hidden" name="_nonce" value="<?php echo esc_attr( wp_create_nonce( 'pixel_newsletter' ) ); ?>" />
                </form>
            <?php else : ?>
                <form class="kf-newsletter__form" action="#" method="post" novalidate data-newsletter>
                    <label for="newsletter-email" class="visually-hidden">Email</label>
                    <input id="newsletter-email" class="kf-newsletter__input" type="email" name="email" placeholder="o.teu.email@dominio.com" required aria-required="true" autocomplete="email" />
                    <button class="kf-newsletter__submit" type="submit">Subscrever &rarr;</button>
                    <?php newpixel_honeypot_field(); ?>
                    <input type="hidden" name="action" value="pixel_newsletter_subscribe" />
                    <input type="hidden" name="_nonce" value="<?php echo esc_attr( wp_create_nonce( 'pixel_newsletter' ) ); ?>" />
                </form>
            <?php endif; ?>
        </div>
        <script>
        (function () {
            var el = document.querySelector('[data-newsletter-block]');
            if (!el) return;
            var sub = document.cookie.split('; ').indexOf('pixel_newsletter_subscribed=1') !== -1;
            if (!sub) {
                try { sub = localStorage.getItem('pixel_newsletter_subscribed') === '1'; } catch (e) {}
            }
            if (sub) el.style.display = 'none';
        })();
        </script>

</aside>

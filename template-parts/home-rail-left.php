<?php
/**
 * Left filter rail — top-level pillar categories with aggregated counts.
 *
 * Shows only top-level pillars (IA, Apple, Linux, Gadgets, etc.) — never
 * sub-categories — so the navigation reflects the editorial architecture
 * rather than whatever happens to have the highest raw post count. Each
 * pillar's count is the sum of its own posts plus every descendant's, which
 * matches what the user will see on the archive page after clicking.
 *
 * The pillar list comes from `newpixel_get_pillar_categories()` in
 * functions.php (filter: `newpixel_filter_rail_pillars`).
 */
$pillars = newpixel_get_pillar_categories();
?>
<aside class="kf-rail-left">
    <div class="kf-filter-rail">
        <div class="kf-filter-eyebrow"><?php esc_html_e( 'FILTRAR POR', 'newpixel' ); ?></div>
        <div class="kf-filter-list">
            <?php foreach ( $pillars as $pillar ) :
                $color  = newpixel_get_category_color( $pillar );
                $slug   = sanitize_title( remove_accents( $pillar->slug ) );
                $active = newpixel_is_pillar_active( $pillar->term_id ) ? ' is-active' : '';
                ?>
                <div class="kf-filter-item<?php echo $active; ?>"
                     data-cat="<?php echo esc_attr( $slug ); ?>"
                     style="--c:<?php echo esc_attr( $color ); ?>">
                    <a class="kf-filter-item__name" href="<?php echo esc_url( get_term_link( $pillar ) ); ?>">
                        <span class="kf-filter-item__dot"></span>
                        <?php echo esc_html( $pillar->name ); ?>
                    </a>
                    <span class="kf-filter-item__count"><?php echo (int) $pillar->total_count; ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</aside>

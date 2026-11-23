<?php
/**
 * Mobile horizontal category scroller — replaces the left rail on small
 * screens. Same pillar list as the desktop rail (top-level only, ordered by
 * aggregated count) so the categories the visitor sees are identical
 * regardless of device.
 */
$pillars = newpixel_get_pillar_categories();
?>
<nav class="kf-cat-scroller" aria-label="<?php esc_attr_e( 'Categorias', 'newpixel' ); ?>">
    <a class="kf-cat-scroller__pill<?php echo is_home() || is_front_page() ? ' is-active' : ''; ?>"
       href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Tudo', 'newpixel' ); ?></a>

    <?php foreach ( $pillars as $pillar ) :
        $color  = newpixel_get_category_color( $pillar );
        $slug   = sanitize_title( remove_accents( $pillar->slug ) );
        $active = newpixel_is_pillar_active( $pillar->term_id ) ? ' is-active' : '';
        ?>
        <a class="kf-cat-scroller__pill<?php echo $active; ?>"
           data-cat="<?php echo esc_attr( $slug ); ?>"
           style="--c:<?php echo esc_attr( $color ); ?>"
           href="<?php echo esc_url( get_term_link( $pillar ) ); ?>">
            <?php echo esc_html( $pillar->name ); ?>
        </a>
    <?php endforeach; ?>
</nav>

<?php
/**
 * Related posts block — rendered at the foot of single.php, before comments.
 * Reuses the archive list row (newpixel_browse_row) so it needs no new card CSS.
 */

if ( ! function_exists( 'newpixel_get_related_posts' ) ) return;

$related_limit = (int) apply_filters( 'newpixel_related_count', 4 );
$related_ids   = newpixel_get_related_posts( get_the_ID(), $related_limit );

if ( empty( $related_ids ) ) return;
?>
<section class="kf-related" aria-label="<?php esc_attr_e( 'Artigos relacionados', 'newpixel' ); ?>">
    <div class="kf-related__inner">
        <header class="kf-related__head">
            <span class="kf-related__eyebrow"><?php esc_html_e( 'CONTINUA A LER', 'newpixel' ); ?></span>
            <h2 class="kf-related__title"><?php esc_html_e( 'Se gostaste deste, lê também', 'newpixel' ); ?></h2>
        </header>
        <ol class="kf-browse__list kf-related__list">
            <?php foreach ( $related_ids as $rid ) { newpixel_browse_row( $rid ); } ?>
        </ol>
    </div>
</section>

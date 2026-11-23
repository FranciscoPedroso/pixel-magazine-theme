<?php
/**
 * Home hero — manifesto title + dot.
 * Used on both page 1 and page 2+ of the front page.
 *
 * The slogan rotation is done CLIENT-SIDE (inline script below) so the
 * homepage can be full-page cached without the hero freezing to
 * whatever line the cache captured: we render the whole manifesto pool in a
 * data-attribute + a default (first) line, and JS picks one per visit. With
 * JS off, the default line shows — still valid.
 */

$manifesto_lines   = function_exists( 'newpixel_manifesto_lines' ) ? newpixel_manifesto_lines() : array( newpixel_manifesto() );
$manifesto_default = $manifesto_lines ? $manifesto_lines[0] : newpixel_manifesto();
?>
<section class="kf-hero">
    <h1 class="kf-hero__title">
        <span class="kf-hero__line" data-manifesto-lines="<?php echo esc_attr( wp_json_encode( $manifesto_lines ) ); ?>"><?php echo $manifesto_default; ?></span><span class="kf-hero__dot">.</span>
    </h1>
</section>
<?php if ( count( $manifesto_lines ) > 1 ) : ?>
<script>
(function () {
    var el = document.querySelector('.kf-hero__line[data-manifesto-lines]');
    if (!el) return;
    try {
        var lines = JSON.parse(el.getAttribute('data-manifesto-lines'));
        if (lines && lines.length > 1) {
            el.innerHTML = lines[Math.floor(Math.random() * lines.length)];
        }
    } catch (e) {}
})();
</script>
<?php endif; ?>

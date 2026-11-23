<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<?php // Before the theme script on purpose: without this tag, the Android WebView
// (in-app browsers such as X's) may report "light" for prefers-color-scheme
// even when the app is dark. See developer.android.com/guide/webapps/dark-theme.
// An explicit choice in the theme selector pins color-scheme in _tokens.scss. ?>
<meta name="color-scheme" content="light dark"/>
<link rel="alternate" type="application/rss+xml" title="<?php bloginfo( 'name' ); ?> Feed" href="<?php bloginfo( 'rss2_url' ); ?>"/>
<script>
// Apply theme BEFORE first paint to avoid the FOUC light → dark flash.
// Two attributes are set on <html>:
//   • data-theme       — "light" or "dark", drives the colour tokens
//   • data-theme-mode  — "auto", "light" or "dark", drives the toggle icon
//                        and tells the runtime whether to follow the OS
// Mode "auto" means localStorage is empty and we follow prefers-color-scheme;
// any explicit choice ("light"/"dark") is stored and locks the mode.
(function () {
    try {
        var stored   = localStorage.getItem('pixel-theme');
        var explicit = (stored === 'dark' || stored === 'light');
        // Sweep stale/invalid legacy values so subsequent reads are clean.
        if (!explicit && stored !== null) {
            try { localStorage.removeItem('pixel-theme'); } catch (e) {}
        }
        var systemDark = !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
        var theme      = explicit ? stored : (systemDark ? 'dark' : 'light');
        var mode       = explicit ? stored : 'auto';
        var html       = document.documentElement;
        html.setAttribute('data-theme', theme);
        html.setAttribute('data-theme-mode', mode);
        if (theme === 'dark') html.classList.add('dark');
        // Liquid glass: refraction (url() in backdrop-filter) only exists in
        // Chromium; Safari and Firefox would drop the whole declaration and
        // lose the blur too. "Chrome/" appears in neither Safari nor iOS
        // Chrome (which is WebKit). Visitors asking for less transparency
        // skip it, as do Android devices under 4 GB (deviceMemory only exists
        // on HTTPS; without it, it stays on): the lens costs ~0.4 ms per
        // frame on the GPU, headroom a weak device may not have.
        if (/\bChrome\//.test(navigator.userAgent) &&
            !(navigator.deviceMemory && navigator.deviceMemory < 4) &&
            !(window.matchMedia && window.matchMedia('(prefers-reduced-transparency: reduce)').matches)) {
            html.classList.add('lg-refract');
        }
    } catch (e) { /* localStorage / matchMedia may be blocked */ }
})();
</script>
<?php
/**
 * Preload the two most-impactful body fonts so they begin streaming as soon
 * as the HTML reaches the browser, in parallel with the stylesheet that
 * declares the @font-face. The @font-face declarations live in the compiled
 * CSS (src/scss/_fonts.scss → main.css); these preloads beat the dependency
 * chain "HTML → CSS → @font-face → font download" by ~1 round-trip.
 *
 * We only preload the two upright variable files (Newsreader for body copy,
 * Inter Tight for display/UI). Italic Newsreader and JetBrains Mono are
 * lower priority — they'll be fetched after CSS arrives via standard
 * @font-face resolution. Material Symbols uses `font-display: block` and
 * a JS reveal trigger, so it doesn't need a preload either.
 */
$theme_uri  = get_template_directory_uri();
$fonts_uri  = $theme_uri . '/assets/fonts';
// Latin subset shared by the four body/UI faces (matches the unicode-range
// that used to live in src/scss/_fonts.scss).
$latin_range = 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD';
?>
<link rel="preload" as="font" type="font/woff2" href="<?php echo esc_url( $fonts_uri . '/newsreader-latin.woff2' ); ?>" crossorigin />
<link rel="preload" as="font" type="font/woff2" href="<?php echo esc_url( $fonts_uri . '/intertight-latin.woff2' ); ?>" crossorigin />
<?php
/**
 * Self-hosted @font-face declarations whose `src` is a real URL — emitted
 * here (not in the compiled stylesheet) so the path is resolved dynamically
 * with get_template_directory_uri(). The active theme dir is `newpixel` in
 * dev but `pixelmagazine` in the production build; a slug hardcoded in
 * dist/style.css would 404 on whichever it isn't, and Material Symbols (no
 * text fallback) would then render its raw ligature names. The url-less
 * metric-matched fallback faces stay in src/scss/_fonts.scss.
 */
?>
<style>
@font-face{font-family:'Newsreader';font-style:normal;font-weight:100 900;font-display:swap;src:url('<?php echo esc_url( $fonts_uri . '/newsreader-latin.woff2' ); ?>') format('woff2');unicode-range:<?php echo $latin_range; ?>}
@font-face{font-family:'Newsreader';font-style:italic;font-weight:100 900;font-display:swap;src:url('<?php echo esc_url( $fonts_uri . '/newsreader-latin-italic.woff2' ); ?>') format('woff2');unicode-range:<?php echo $latin_range; ?>}
@font-face{font-family:'Inter Tight';font-style:normal;font-weight:100 900;font-display:swap;src:url('<?php echo esc_url( $fonts_uri . '/intertight-latin.woff2' ); ?>') format('woff2');unicode-range:<?php echo $latin_range; ?>}
@font-face{font-family:'JetBrains Mono';font-style:normal;font-weight:100 900;font-display:swap;src:url('<?php echo esc_url( $fonts_uri . '/jetbrainsmono-latin.woff2' ); ?>') format('woff2');unicode-range:<?php echo $latin_range; ?>}
@font-face{font-family:'Material Symbols Outlined';font-style:normal;font-weight:100 700;font-display:block;src:url('<?php echo esc_url( $fonts_uri . '/material-symbols-outlined.woff2' ); ?>') format('woff2')}
</style>
<style>
/* Hide icon ligature text until the Material Symbols font is ready, so we
   don't flash "person", "arrow_back", etc. on first paint. */
.material-symbols-outlined { visibility: hidden; }
html.icons-ready .material-symbols-outlined { visibility: visible; }
</style>
<script>
(function () {
    var reveal = function () { document.documentElement.classList.add('icons-ready'); };
    if (document.fonts && document.fonts.load) {
        document.fonts.load('1em "Material Symbols Outlined"').then(reveal, reveal);
        // Safety net: never leave icons hidden longer than 1.2s, even if the
        // font request stalls (slow network, blocked CDN, etc).
        setTimeout(reveal, 1200);
    } else {
        reveal();
    }
})();
</script>
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<?php
/**
 * Liquid glass: refraction filters used by backdrop-filter (Chromium only, see
 * _glass.scss). Each displacement map is a ~500-byte SVG made of two gradients:
 * red horizontally, green vertically, neutral (128) in the middle and pulling
 * inwards at the edges, which is what "bends" the content at the border. In
 * objectBoundingBox it stretches to each element's size, hence one map per
 * aspect ratio:
 *   • np-glass       — small pieces (buttons, chips, theme selector);
 *   • np-glass-wide  — wide pills (search): softer side edges, otherwise the
 *                      displacement would exceed 40px at 500px wide;
 *   • np-glass-bar   — the header bar: vertical only. Its side edges are the
 *                      screen's, and at 1440px any red away from neutral would
 *                      drag the content by around a hundred pixels.
 * sRGB is required: in linearRGB, 128 stops being neutral and everything slides.
 */
if ( ! function_exists( 'newpixel_glass_map' ) ) :
function newpixel_glass_map( $x, $y ) {
    $stops = function ( $list, $channel ) {
        $out = '';
        foreach ( $list as $offset => $v ) {
            $rgb  = 'x' === $channel ? "rgb($v,0,0)" : "rgb(0,$v,0)";
            $out .= "<stop offset='$offset' stop-color='$rgb'/>";
        }
        return $out;
    };
    $svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100' preserveAspectRatio='none'>"
        . "<linearGradient id='x'>" . $stops( $x, 'x' ) . '</linearGradient>'
        . "<linearGradient id='y' x2='0' y2='1'>" . $stops( $y, 'y' ) . '</linearGradient>'
        . "<rect width='100' height='100' fill='url(#x)'/><rect width='100' height='100' fill='url(#y)' style='mix-blend-mode:screen'/></svg>";
    return 'data:image/svg+xml,' . rawurlencode( $svg );
}
endif;
$np_glass_y   = array( '0' => 220, '.15' => 160, '.38' => 128, '.62' => 128, '.85' => 96, '1' => 36 );
$np_glass_maps = array(
    'np-glass'      => array( newpixel_glass_map( array( '0' => 190, '.07' => 150, '.22' => 128, '.78' => 128, '.93' => 106, '1' => 66 ), $np_glass_y ), '0.35' ),
    'np-glass-wide' => array( newpixel_glass_map( array( '0' => 146, '.04' => 134, '.1' => 128, '.9' => 128, '.96' => 122, '1' => 110 ), $np_glass_y ), '0.35' ),
    'np-glass-bar'  => array( newpixel_glass_map( array( '0' => 128, '1' => 128 ), array( '0' => 178, '.16' => 140, '.3' => 128, '.58' => 128, '.8' => 104, '.93' => 70, '1' => 40 ) ), '0.3' ),
);
?>
<svg class="np-glass-defs" width="0" height="0" aria-hidden="true" focusable="false">
    <?php foreach ( $np_glass_maps as $np_id => $np_map ) : ?>
    <filter id="<?php echo esc_attr( $np_id ); ?>" x="0" y="0" width="1" height="1" primitiveUnits="objectBoundingBox" color-interpolation-filters="sRGB">
        <feImage result="map" x="0" y="0" width="1" height="1" preserveAspectRatio="none" href="<?php echo esc_attr( $np_map[0] ); ?>"/>
        <feDisplacementMap in="SourceGraphic" in2="map" scale="<?php echo esc_attr( $np_map[1] ); ?>" xChannelSelector="R" yChannelSelector="G"/>
    </filter>
    <?php endforeach; ?>
</svg>

<!-- ============================================================ -->
<!-- TOP NAVIGATION                                                -->
<!-- ============================================================ -->
<header class="site-header">
    <div class="site-header__inner kf-container">

        <!-- Brand -->
        <a class="site-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php esc_attr_e( 'PIXEL Magazine — ir para a página inicial', 'newpixel' ); ?>">
            <?php newpixel_logo_svg( 'kf-logo--header' ); ?>
        </a>

        <!-- Search form: inline pill from md up, full-width layer below that (opened by
             the .site-search-toggle button). Always submits to native WordPress search. -->
        <form class="site-search" id="site-search" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>" data-search-form>
            <span class="site-search__icon material-symbols-outlined" aria-hidden="true">search</span>
            <label class="screen-reader-text" for="site-search-input"><?php esc_html_e( 'Procurar', 'newpixel' ); ?></label>
            <input
                id="site-search-input"
                class="site-search__input"
                type="search"
                name="s"
                value="<?php echo esc_attr( get_search_query() ); ?>"
                placeholder="<?php esc_attr_e( 'Procurar artigos, tópicos…', 'newpixel' ); ?>"
                autocomplete="off"
                data-search-input
            />
            <?php // Real submit button at every size: guarantees Enter in any browser and
            // makes the virtual keyboard show its "Go" key. ?>
            <button class="site-search__submit" type="submit" aria-label="<?php esc_attr_e( 'Procurar', 'newpixel' ); ?>">
                <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
            </button>
            <button class="site-search__close" type="button" aria-label="<?php esc_attr_e( 'Fechar pesquisa', 'newpixel' ); ?>" data-search-close>
                <span class="material-symbols-outlined" aria-hidden="true">close</span>
            </button>
        </form>

        <!-- Right actions -->
        <div class="site-actions">
            <!-- Only exists below md; from there on the inline pill takes over. -->
            <button class="site-search-toggle" type="button"
                    aria-label="<?php esc_attr_e( 'Procurar', 'newpixel' ); ?>"
                    aria-expanded="false" aria-controls="site-search" data-search-toggle>
                <span class="material-symbols-outlined" aria-hidden="true">search</span>
            </button>
            <?php if ( is_user_logged_in() ) : ?>
                <?php
                // Logged-in: replace Entrar/Junta-te with a "Guardados" CTA
                // styled like the subscribe button so the visual rhythm of the
                // header stays consistent for both states.
                $saved_count = count( newpixel_get_user_bookmarks() );
                ?>
                <a class="site-subscribe site-subscribe--saved"
                   href="<?php echo esc_url( newpixel_bookmarks_url() ); ?>"
                   aria-label="<?php esc_attr_e( 'Os meus artigos guardados', 'newpixel' ); ?>">
                    <span class="material-symbols-outlined" aria-hidden="true" style="font-size:18px;vertical-align:-3px;margin-right:4px;">bookmark</span>
                    <?php esc_html_e( 'Guardados', 'newpixel' ); ?>
                    <?php if ( $saved_count > 0 ) : ?>
                        <span class="site-subscribe__badge"><?php echo (int) $saved_count; ?></span>
                    <?php endif; ?>
                </a>
                <a class="site-iconlink" href="<?php echo esc_url( home_url( '/perfil/' ) ); ?>" aria-label="<?php esc_attr_e( 'O meu perfil', 'newpixel' ); ?>" title="<?php esc_attr_e( 'O meu perfil', 'newpixel' ); ?>" data-profile-toggle>
                    <span class="material-symbols-outlined" aria-hidden="true">person</span>
                </a>
            <?php else : ?>
                <?php // Glass capsule with both actions: "Entrar" and "Junta-te" (the latter with
                // a touch of purple). Below md only "Junta-te" remains. ?>
                <div class="site-auth">
                    <a class="site-login" href="<?php echo esc_url( wp_login_url() ); ?>" data-auth-open="login"><?php esc_html_e( 'Entrar', 'newpixel' ); ?></a>
                    <a class="site-join" href="<?php echo esc_url( wp_registration_url() ); ?>" data-auth-open="register"><?php esc_html_e( 'Junta-te', 'newpixel' ); ?> &rarr;</a>
                </div>
            <?php endif; ?>
            <?php // Glass selector (from md up): three positions, with a thumb sliding to the
            // selected one. Below md it becomes a button cycling through the three modes,
            // as the mobile header has no room for both. ?>
            <fieldset class="np-theme-switch" data-theme-switch>
                <legend class="screen-reader-text"><?php esc_html_e( 'Tema', 'newpixel' ); ?></legend>
                <?php
                $np_modes = array(
                    'light' => array( 'light_mode', __( 'Claro', 'newpixel' ) ),
                    'auto'  => array( 'contrast', __( 'Automático (segue o sistema)', 'newpixel' ) ),
                    'dark'  => array( 'dark_mode', __( 'Escuro', 'newpixel' ) ),
                );
                foreach ( $np_modes as $np_value => $np_mode ) : ?>
                    <label class="np-theme-switch__opt" title="<?php echo esc_attr( $np_mode[1] ); ?>">
                        <input class="np-theme-switch__input" type="radio" name="np-theme-switch" value="<?php echo esc_attr( $np_value ); ?>" data-theme-radio />
                        <span class="material-symbols-outlined" aria-hidden="true"><?php echo esc_html( $np_mode[0] ); ?></span>
                        <span class="screen-reader-text"><?php echo esc_html( $np_mode[1] ); ?></span>
                    </label>
                <?php endforeach; ?>
                <span class="np-theme-switch__thumb" aria-hidden="true"></span>
            </fieldset>
            <button class="site-theme-toggle" type="button" aria-label="Alternar tema (auto / claro / escuro)" data-theme-toggle>
                <span class="material-symbols-outlined" aria-hidden="true" data-icon="auto">contrast</span>
                <span class="material-symbols-outlined" aria-hidden="true" data-icon="light">light_mode</span>
                <span class="material-symbols-outlined" aria-hidden="true" data-icon="dark">dark_mode</span>
            </button>
        </div>

    </div>
</header>

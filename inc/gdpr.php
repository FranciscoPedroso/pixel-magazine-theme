<?php
/**
 * ============================================================================
 * GDPR / ePrivacy consent module — geo-aware, GA-aware.
 * ============================================================================
 *
 * For visitors in jurisdictions that require prior consent for analytics
 * cookies (EU + EEA + UK + Switzerland), shows a footer banner before
 * loading Google Analytics. For everyone else (Brazil, US, etc.) loads GA
 * directly.
 *
 * Activation:
 *   In functions.php, define your Google Analytics measurement ID:
 *
 *     define( 'NEWPIXEL_GA_ID', 'G-XXXXXXXXXX' );
 *
 *   Without the constant the module renders nothing — the banner never
 *   appears and no GA snippet is injected. Useful while you're still
 *   setting up.
 *
 * How it works:
 *   1. Server emits banner HTML (hidden) at wp_footer + an inline JS
 *      config block in wp_head with GA ID + the list of countries that
 *      require consent.
 *   2. Client JS runs immediately and:
 *        a) Reads localStorage for a prior decision. If accepted/declined
 *           within the TTL window, applies it and exits.
 *        b) Otherwise resolves the visitor's country (Cloudflare trace
 *           endpoint first, fallback ip-api.com), cached in sessionStorage
 *           for the rest of the session.
 *        c) If country NOT in the consent list → loads GA, keeps banner
 *           hidden. (E.g. Brazil visitors get analytics with no UI noise.)
 *        d) If country IS in the consent list → reveals the banner and
 *           waits for the visitor to click Aceitar / Recusar.
 *   3. On accept → write decision, inject GA, hide banner.
 *   4. On decline → write decision, hide banner, GA stays unloaded.
 *
 * TTLs (also configurable via filter):
 *   - Decline lives for 30 days. After that the banner reappears so the
 *     visitor can reconsider (matches the user's spec).
 *   - Accept lives for 365 days, matching CNIL guidance (max 13 months for
 *     analytics consent).
 *
 * Cache-safe: country resolution happens client-side, so this works
 * correctly even if the HTML is cached by a CDN or page-cache plugin.
 *
 * @package newpixel
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ============================================================================
// Configuration — public functions consumers (and filters) can override.
// ============================================================================

/**
 * Google Analytics measurement ID. Returns empty string when not configured
 * (module is then dormant — banner never renders, GA never loads).
 */
function newpixel_gdpr_ga_id() {
    if ( defined( 'NEWPIXEL_GA_ID' ) && NEWPIXEL_GA_ID ) {
        return (string) NEWPIXEL_GA_ID;
    }
    return (string) apply_filters( 'newpixel_gdpr_ga_id', '' );
}

/**
 * Country codes (ISO 3166-1 alpha-2) where a consent banner must appear
 * before loading analytics. EU 27 + EEA (Iceland, Liechtenstein, Norway)
 * + UK (still applies UK GDPR post-Brexit) + Switzerland (nFADP).
 *
 * Filter `newpixel_gdpr_consent_countries` to add/remove.
 */
function newpixel_gdpr_required_countries() {
    return apply_filters( 'newpixel_gdpr_consent_countries', array(
        // EU 27
        'AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU',
        'IE','IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE',
        // EEA (non-EU)
        'IS','LI','NO',
        // UK + Switzerland
        'GB','CH',
    ) );
}

// ============================================================================
// Frontend output: config + banner HTML + JS bootstrap
// ============================================================================

add_action( 'wp_head', 'newpixel_gdpr_emit_config', 1 );
add_action( 'wp_footer', 'newpixel_gdpr_emit_banner_and_script', 5 );

/**
 * Emit a small JSON config block early in <head> so the banner JS has its
 * data available the moment the DOM is ready.
 */
function newpixel_gdpr_emit_config() {
    if ( is_admin() ) return;
    $ga_id = newpixel_gdpr_ga_id();
    if ( ! $ga_id ) return;

    $config = array(
        'ga_id'              => $ga_id,
        'consent_countries'  => array_values( newpixel_gdpr_required_countries() ),
        'decline_ttl_days'   => (int) apply_filters( 'newpixel_gdpr_decline_ttl_days', 30 ),
        'accept_ttl_days'    => (int) apply_filters( 'newpixel_gdpr_accept_ttl_days', 365 ),
    );

    // wp_print_inline_script_tag rather than a literal "<script>" in a PHP string:
    // the production packager would try to minify that literal (with printf's %s
    // inside) and fail.
    wp_print_inline_script_tag(
        'window.__newpixelGDPR=' . wp_json_encode( $config, JSON_UNESCAPED_SLASHES ) . ';'
    );
}

/**
 * Emit the banner markup + the inline JS that drives the consent flow.
 * Banner is rendered hidden — the JS reveals it only when needed.
 */
function newpixel_gdpr_emit_banner_and_script() {
    if ( is_admin() ) return;
    if ( ! newpixel_gdpr_ga_id() ) return;

    $privacy_url = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '';
    ?>
    <aside class="kf-gdpr" id="kf-gdpr" role="dialog" aria-live="polite" aria-label="<?php esc_attr_e( 'Consentimento de cookies', 'newpixel' ); ?>" hidden>
        <div class="kf-gdpr__inner">
            <div class="kf-gdpr__text">
                <strong class="kf-gdpr__eyebrow">Cookies &amp; Analytics</strong>
                <p class="kf-gdpr__copy"><?php esc_html_e( 'Medimos audiência de forma agregada para perceber como o site é usado. Sem dados pessoais.', 'newpixel' ); ?></p>
                <?php if ( $privacy_url ) : ?>
                    <a class="kf-gdpr__link" href="<?php echo esc_url( $privacy_url ); ?>">
                        <?php esc_html_e( 'Política de Privacidade', 'newpixel' ); ?>
                        <span class="kf-gdpr__link-arrow" aria-hidden="true">&rarr;</span>
                    </a>
                <?php endif; ?>
            </div>
            <div class="kf-gdpr__actions">
                <button type="button" class="kf-gdpr__btn kf-gdpr__btn--reject" data-gdpr="reject"><?php esc_html_e( 'Recusar', 'newpixel' ); ?></button>
                <button type="button" class="kf-gdpr__btn kf-gdpr__btn--accept" data-gdpr="accept"><?php esc_html_e( 'Aceitar', 'newpixel' ); ?></button>
            </div>
        </div>
    </aside>
    <script>
    (function () {
        var CFG = window.__newpixelGDPR;
        if ( ! CFG || ! CFG.ga_id ) return;

        var STORAGE_KEY = 'newpixel_gdpr';
        var SESSION_KEY = 'newpixel_gdpr_cc';
        var DAY_MS      = 24 * 60 * 60 * 1000;
        var declineTTL  = (CFG.decline_ttl_days || 30) * DAY_MS;
        var acceptTTL   = (CFG.accept_ttl_days || 365) * DAY_MS;

        function readDecision () {
            try {
                var raw = localStorage.getItem(STORAGE_KEY);
                if ( ! raw ) return null;
                var d = JSON.parse(raw);
                if ( ! d || ! d.choice || ! d.at ) return null;
                var ttl = d.choice === 'accepted' ? acceptTTL : declineTTL;
                if ( Date.now() - d.at > ttl ) {
                    localStorage.removeItem(STORAGE_KEY);
                    return null;
                }
                return d.choice;
            } catch ( e ) { return null; }
        }

        function writeDecision ( choice ) {
            try {
                localStorage.setItem(STORAGE_KEY, JSON.stringify({ choice: choice, at: Date.now() }));
            } catch ( e ) {}
        }

        function loadGA () {
            if ( window.__newpixelGAloaded ) return;
            window.__newpixelGAloaded = true;
            var s = document.createElement('script');
            s.async = true;
            s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(CFG.ga_id);
            document.head.appendChild(s);
            window.dataLayer = window.dataLayer || [];
            window.gtag = function () { window.dataLayer.push(arguments); };
            window.gtag('js', new Date());
            window.gtag('config', CFG.ga_id, { anonymize_ip: true });
        }

        function showBanner () {
            var el = document.getElementById('kf-gdpr');
            if ( el ) { el.hidden = false; el.classList.add('is-visible'); }
        }
        function hideBanner () {
            var el = document.getElementById('kf-gdpr');
            if ( el ) { el.hidden = true; el.classList.remove('is-visible'); }
        }

        function bindButtons () {
            var el = document.getElementById('kf-gdpr');
            if ( ! el ) return;
            el.addEventListener('click', function ( e ) {
                var b = e.target.closest('[data-gdpr]');
                if ( ! b ) return;
                var action = b.getAttribute('data-gdpr');
                if ( action === 'accept' ) { writeDecision('accepted'); loadGA(); hideBanner(); }
                else if ( action === 'reject' ) { writeDecision('declined'); hideBanner(); }
            });
        }

        // Resolve visitor country. Tries Cloudflare's same-origin trace
        // first (zero external calls, instant when site is on CF), then
        // falls back to ip-api.com. Caches in sessionStorage so we only do
        // this once per browser session.
        function resolveCountry ( callback ) {
            try {
                var cached = sessionStorage.getItem(SESSION_KEY);
                if ( cached ) { callback(cached); return; }
            } catch ( e ) {}

            // Try Cloudflare trace (same-origin, instant when behind CF)
            fetch('/cdn-cgi/trace', { cache: 'no-store' })
                .then(function ( r ) { return r.ok ? r.text() : Promise.reject(); })
                .then(function ( txt ) {
                    var m = txt.match(/^loc=([A-Z]{2})/m);
                    var cc = m ? m[1] : '';
                    if ( cc ) { try { sessionStorage.setItem(SESSION_KEY, cc); } catch ( e ) {} callback(cc); }
                    else { tryIpApi(); }
                })
                .catch(tryIpApi);

            function tryIpApi () {
                fetch('https://ipapi.co/country/', { cache: 'no-store' })
                    .then(function ( r ) { return r.ok ? r.text() : Promise.reject(); })
                    .then(function ( cc ) {
                        cc = (cc || '').trim().toUpperCase();
                        if ( cc && cc.length === 2 ) {
                            try { sessionStorage.setItem(SESSION_KEY, cc); } catch ( e ) {}
                            callback(cc);
                        } else { callback('UNKNOWN'); }
                    })
                    .catch(function () { callback('UNKNOWN'); });
            }
        }

        bindButtons();

        // 1. Honour an existing decision regardless of country.
        var prior = readDecision();
        if ( prior === 'accepted' ) { loadGA(); return; }
        if ( prior === 'declined' ) { return; } // GA stays unloaded

        // 2. No prior decision — resolve country, then decide.
        var consentSet = (CFG.consent_countries || []).reduce(function ( a, c ) { a[c] = true; return a; }, {});
        resolveCountry(function ( cc ) {
            if ( cc === 'UNKNOWN' ) {
                // Unknown geo → err on the safe side, show banner
                showBanner();
                return;
            }
            if ( consentSet[ cc ] ) {
                showBanner();
            } else {
                // Outside the consent zone → load GA without prompting
                loadGA();
            }
        });
    })();
    </script>
    <?php
}

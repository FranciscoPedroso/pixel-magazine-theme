<?php
/**
 * Newsletter — random headline pool.
 * ================================================================
 *
 * Replicates the `manifesto_lines` pattern used on the home hero
 * (template-parts/home-hero.php) for the newsletter draft headline:
 * a pool of editorial intros stored in an ACF repeater, one of which
 * gets randomly selected each time a draft is generated.
 *
 * Schema lives in version control as ACF Local JSON:
 *   • theme/acf-json/ui_options_page_newpixel_newsletter_headlines.json
 *       → registers the "Newsletter → Headlines" admin sub-page
 *   • theme/acf-json/group_newpixel_newsletter_headlines.json
 *       → registers the repeater field `newsletter_headlines` on that page
 *
 * ACF Pro auto-loads both files on init, so the page + field are present
 * with zero PHP registration calls. They show up in the ACF UI under
 * Field Groups / Options Pages exactly like UI-created ones (with
 * "Local JSON: Saved" in the list view).
 *
 * This file is left with just the two runtime pieces ACF can't do alone:
 *   1. A one-shot seeder that populates the repeater with 9 default
 *      headlines on first admin load (only if the pool is empty).
 *   2. The public `newpixel_newsletter_pick_random_headline()` helper
 *      that does the random pick + sanitisation + fallback.
 *
 * @package newpixel
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// ============================================================================
// Auto-sync — import the JSON-loaded field group + options page into the DB
// ============================================================================
//
// ACF Pro loads our `acf-json/` files into memory at runtime, so the random
// pick works — but the admin UI under "Field Groups → All" / "Options Pages
// → All" only shows DB-backed entries. JSON-only registrations sit in the
// "Sync available" tab awaiting a manual click.
//
// On first admin load we import once: copy the JSON definitions into the DB
// so they show up natively in the UI. After this, the JSON file stays as the
// canonical version (ACF auto-rewrites it on every UI save), and the DB row
// is what the UI talks to. Net effect: editor experience identical to a
// field group created via UI, but the schema travels in git.
//
// Marked done via an option flag so we don't keep re-importing on every page
// load. If the editor ever deletes the DB row from ACF UI ("Trash"), they
// can re-run this once-shot by deleting the flag — or just click the Sync
// button in the admin which does the same thing manually.

const NEWPIXEL_NEWSLETTER_ACF_SYNCED = 'newpixel_newsletter_acf_synced';

add_action( 'admin_init', 'newpixel_newsletter_maybe_sync_acf_to_db', 5 );
function newpixel_newsletter_maybe_sync_acf_to_db() {
    if ( get_option( NEWPIXEL_NEWSLETTER_ACF_SYNCED ) === '1' ) return;
    if ( wp_doing_ajax() || wp_doing_cron() ) return;
    if ( ! function_exists( 'acf_import_field_group' ) ) return;
    if ( ! function_exists( 'acf_get_field_group' )    ) return;

    $synced_anything = false;

    // ----- Field group -----
    $fg_key = 'group_newpixel_newsletter_headlines';
    $existing = acf_get_field_group( $fg_key );
    // If `local` is set to 'json', the group only exists in memory (not DB).
    // If it's empty/unset and the post exists, the group is in DB already.
    if ( empty( $existing ) || ! empty( $existing['local'] ) ) {
        $json_path = get_stylesheet_directory() . '/acf-json/' . $fg_key . '.json';
        if ( file_exists( $json_path ) ) {
            $data = json_decode( file_get_contents( $json_path ), true );
            if ( is_array( $data ) ) {
                acf_import_field_group( $data );
                $synced_anything = true;
            }
        }
    }

    // ----- Options page -----
    if ( function_exists( 'acf_update_ui_options_page' ) ) {
        $op_key = 'ui_options_page_newpixel_newsletter_headlines';
        $json_path = get_stylesheet_directory() . '/acf-json/' . $op_key . '.json';
        // Check by menu_slug (ACF stores options pages keyed by slug too).
        $already = false;
        if ( function_exists( 'acf_get_ui_options_pages' ) ) {
            foreach ( acf_get_ui_options_pages() as $page ) {
                if ( ! empty( $page['menu_slug'] ) && $page['menu_slug'] === 'newpixel-newsletter-headlines' && empty( $page['local'] ) ) {
                    $already = true;
                    break;
                }
            }
        }
        if ( ! $already && file_exists( $json_path ) ) {
            $data = json_decode( file_get_contents( $json_path ), true );
            if ( is_array( $data ) ) {
                acf_update_ui_options_page( $data );
                $synced_anything = true;
            }
        }
    }

    if ( $synced_anything ) {
        update_option( NEWPIXEL_NEWSLETTER_ACF_SYNCED, '1' );
    }
}

// ============================================================================
// Seed — populate with 9 defaults on first admin load (one-time)
// ============================================================================

/**
 * Flag stored in wp_options so we know we already seeded. If the editor
 * deletes everything later, we don't auto-re-seed (that would be annoying
 * and override their intentional empty state).
 */
const NEWPIXEL_NEWSLETTER_HEADLINES_SEEDED = 'newpixel_newsletter_headlines_seeded';

add_action( 'admin_init', 'newpixel_newsletter_maybe_seed_headlines' );
function newpixel_newsletter_maybe_seed_headlines() {
    // Don't seed during AJAX or cron — only on real admin page loads
    if ( wp_doing_ajax() || wp_doing_cron() ) return;
    if ( get_option( NEWPIXEL_NEWSLETTER_HEADLINES_SEEDED ) === '1' ) return;
    if ( ! function_exists( 'get_field' ) || ! function_exists( 'update_field' ) ) return;

    // If the editor has already added any headlines manually before our seed
    // had a chance to run, respect that — don't overwrite.
    $existing = get_field( 'newsletter_headlines', 'option' );
    if ( ! empty( $existing ) && is_array( $existing ) ) {
        update_option( NEWPIXEL_NEWSLETTER_HEADLINES_SEEDED, '1' );
        return;
    }

    $seeds = array(
        'Cinco artigos. Cinco minutos. Vamos a isto.',
        'Esta semana na Pixel: cinco histórias que importam.',
        'Algumas coisas aconteceram. Selecionámos o que vale o teu tempo.',
        'Não te lembras de tudo o que se passou esta semana? Nós lembramos.',
        'O essencial. Sem ruído. Como sempre.',
        'Cinco minutos. Hoje incluem isto.',
        'Sinal. Cinco vezes.',
        'Lê isto antes de fechar o browser para o fim-de-semana.',
        'Bom dia. Aqui está o que perdeste.',
    );

    $rows = array();
    foreach ( $seeds as $text ) {
        $rows[] = array( 'headline' => $text );
    }

    update_field( 'newsletter_headlines', $rows, 'option' );
    update_option( NEWPIXEL_NEWSLETTER_HEADLINES_SEEDED, '1' );
}

// ============================================================================
// Public API — random pick with sane fallback
// ============================================================================

/**
 * Return a random headline from the ACF pool, wrapped in <p>...</p>.
 * Falls back to a neutral hardcoded line if the pool is empty (or ACF
 * isn't available for some reason).
 *
 * @return string HTML (always a paragraph at minimum)
 */
function newpixel_newsletter_pick_random_headline() {
    $fallback = '<p>Cinco artigos. Cinco minutos.</p>';

    if ( ! function_exists( 'get_field' ) ) return $fallback;
    $rows = get_field( 'newsletter_headlines', 'option' );
    if ( ! is_array( $rows ) || empty( $rows ) ) return $fallback;

    $pick = $rows[ array_rand( $rows ) ];
    $raw  = isset( $pick['headline'] ) ? trim( (string) $pick['headline'] ) : '';
    if ( $raw === '' ) return $fallback;

    // If the editor wrote multi-paragraph content, keep as is. If just plain
    // text, wrap in a paragraph so the template's prose styling applies.
    if ( strpos( $raw, '<p' ) === false ) {
        $raw = '<p>' . $raw . '</p>';
    }

    // Allow a small set of inline tags (matches the manifesto_lines pattern).
    return wp_kses( $raw, array(
        'p'      => array(),
        'em'     => array(),
        'i'      => array(),
        'strong' => array(),
        'b'      => array(),
        'a'      => array( 'href' => array(), 'title' => array() ),
        'br'     => array(),
    ) );
}

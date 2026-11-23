<?php
/**
 * Pixel Magazine functions and definitions
 */

// ============================================================================
// Feature modules — extracted from this file to keep it focused on theme
// setup, asset enqueue, and shared rendering helpers. Each include is
// self-contained and registers its own hooks.
// ============================================================================
require_once get_theme_file_path( 'inc/auth.php' );
require_once get_theme_file_path( 'inc/hidden-login.php' );
require_once get_theme_file_path( 'inc/security.php' );
require_once get_theme_file_path( 'inc/profile.php' );
require_once get_theme_file_path( 'inc/newsletter.php' );
require_once get_theme_file_path( 'inc/newsletter-issues.php' );
require_once get_theme_file_path( 'inc/newsletter-headlines.php' );
require_once get_theme_file_path( 'inc/comments.php' );
require_once get_theme_file_path( 'inc/bookmarks.php' );
require_once get_theme_file_path( 'inc/content.php' );
require_once get_theme_file_path( 'inc/category-colors.php' );
require_once get_theme_file_path( 'inc/seo.php' );
require_once get_theme_file_path( 'inc/news-sitemap.php' );
require_once get_theme_file_path( 'inc/frontpage-layout.php' );
require_once get_theme_file_path( 'inc/frontpage-data.php' );
require_once get_theme_file_path( 'inc/manifesto.php' );
require_once get_theme_file_path( 'inc/post-views.php' );
require_once get_theme_file_path( 'inc/post-views-history.php' );
require_once get_theme_file_path( 'inc/gdpr.php' );
require_once get_theme_file_path( 'inc/admin-dark-mode.php' );
require_once get_theme_file_path( 'inc/share-x.php' );
require_once get_theme_file_path( 'inc/buffer-x.php' );
require_once get_theme_file_path( 'inc/bluesky.php' );
require_once get_theme_file_path( 'inc/avif-to-jpeg.php' );
require_once get_theme_file_path( 'inc/ntfy.php' );
require_once get_theme_file_path( 'inc/related.php' );
require_once get_theme_file_path( 'inc/deleted-redirects.php' );
require_once get_theme_file_path( 'inc/rest-tag-names.php' );
require_once get_theme_file_path( 'inc/admin-dashboard-columns.php' );

// Google Analytics — banner + GA only inject when this constant is defined.
// Replace the empty string with your GA4 measurement ID (e.g. 'G-ABC1234567').
// Leave empty to keep the module dormant.
if ( ! defined( 'NEWPIXEL_GA_ID' ) ) {
    define( 'NEWPIXEL_GA_ID', '' );
}

// SEO: Twitter / X handle used on twitter:site + twitter:creator meta tags
add_filter( 'newpixel_seo_twitter_handle', function () { return 'pixelmagazinept'; } );

// ============================================================================
// Front-end admin bar
// ============================================================================
// Force-hide the WordPress toolbar on the front end — for everyone, overriding
// each user's "Show Toolbar when viewing site" preference. The site is
// consumer-facing: visitors/subscribers gain nothing from it (and are kept out
// of /wp-admin/ anyway — see inc/auth.php), and admins manage from /wp-admin/
// directly. Only the front-end bar is affected; the toolbar inside wp-admin
// stays intact.
add_filter( 'show_admin_bar', '__return_false' );


// ============================================================================
// Theme setup
// ============================================================================
function newpixel_setup() {
    add_theme_support( 'title-tag' );
    add_theme_support( 'post-thumbnails' );
    add_theme_support( 'html5', array(
        'search-form',
        'comment-form',
        'comment-list',
        'gallery',
        'caption',
        'style',
        'script',
    ) );
    register_nav_menus( array(
        'primary'      => __( 'Primary Menu', 'newpixel' ),
        'footer-links' => __( 'Footer Links', 'newpixel' ),
    ) );

    // ------------------------------------------------------------------
    // Image sizes
    //
    // Each layout context (article hero, lead card, three-up grid, etc.)
    // gets a small ladder of cropped, aspect-locked sizes. WordPress will
    // pick the closest one for the viewport via srcset/sizes — see
    // newpixel_image_contexts() for the per-context "sizes" attribute.
    //
    // NOTE: registering a size only causes it to be generated for *new*
    // uploads. To regenerate existing media, run:
    //     wp media regenerate --yes
    // (or install "Regenerate Thumbnails" and click the bulk action in
    // Media → Library).
    // ------------------------------------------------------------------

    // Legacy uncropped widths — kept so old content (gutenberg image
    // blocks, ACF fields with hard-coded size keys) keeps resolving.
    add_image_size( 'w1920', 1920, 1920, false );
    add_image_size( 'w1024', 1024, 1024, false );
    add_image_size( 'w960',  960,  960,  false );
    add_image_size( 'w480',  480,  480,  false );

    // 21:9 — homepage lead card hero (cinematic crop)
    add_image_size( 'np-21x9-1600', 1600, 686,  true );
    add_image_size( 'np-21x9-960',  960,  411,  true );
    add_image_size( 'np-21x9-560',  560,  240,  true );

    // 16:9 — single article hero (and any 16:9 standalone hero)
    add_image_size( 'np-16x9-2048', 2048, 1152, true );
    add_image_size( 'np-16x9-1600', 1600, 900,  true );   // og:image / Google Discover (≥1600px)
    add_image_size( 'np-16x9-1280', 1280, 720,  true );
    add_image_size( 'np-16x9-1024', 1024, 576,  true );   // in-article hero @ ~1016px desktop slot
    add_image_size( 'np-16x9-768',  768,  432,  true );
    add_image_size( 'np-16x9-480',  480,  270,  true );

    // 16:10 — generic feature card image (feature pair, etc.)
    add_image_size( 'np-16x10-1280', 1280, 800, true );
    add_image_size( 'np-16x10-800',  800,  500, true );
    add_image_size( 'np-16x10-480',  480,  300, true );

    // 4:3 — three-up grid cards and browse-row thumbnails
    add_image_size( 'np-4x3-960', 960, 720, true );
    add_image_size( 'np-4x3-560', 560, 420, true );
    add_image_size( 'np-4x3-320', 320, 240, true );

    // 1:1 — small square thumbs (Mais artigos, sidebar lists)
    add_image_size( 'np-1x1-480', 480, 480, true );
    add_image_size( 'np-1x1-240', 240, 240, true );
    add_image_size( 'np-1x1-120', 120, 120, true );
}
add_action( 'after_setup_theme', 'newpixel_setup' );

// ============================================================================
// Bump JPEG/WebP quality slightly above WP's default (82) — imagery in a
// design-led tech magazine benefits from a touch more fidelity, especially on
// retina hero crops where compression banding is most visible.
// ============================================================================
add_filter( 'jpeg_quality',                function () { return 86; } );
add_filter( 'wp_editor_set_quality',       function () { return 86; } );

// ============================================================================
// Enqueue compiled CSS / JS
// ============================================================================

/**
 * Asset version (`?ver=`): a fingerprint of the file's CONTENT, not its date.
 *
 * `filemtime()` follows the build time, which FTP clients preserve, so a cache
 * in front of the site can store an old file under a new version during a
 * deploy. With long-lived browser caching, visitors would then keep the stale
 * asset. With a hash, a different file always gets a different URL.
 */
function newpixel_asset_ver( $path ) {
    static $cache = array();
    if ( ! isset( $cache[ $path ] ) ) {
        $hash = @md5_file( $path );
        $cache[ $path ] = $hash ? substr( $hash, 0, 12 ) : (string) @filemtime( $path );
    }
    return $cache[ $path ];
}

function newpixel_scripts() {
    $dist_css = get_template_directory_uri() . '/dist/style.css';
    $dist_js  = get_template_directory_uri() . '/dist/main.js';

    if ( file_exists( get_template_directory() . '/dist/style.css' ) ) {
        wp_enqueue_style(
            'newpixel-vite-style',
            $dist_css,
            array(),
            newpixel_asset_ver( get_template_directory() . '/dist/style.css' )
        );
    } else {
        wp_enqueue_style( 'newpixel-style', get_stylesheet_uri(), array(), '1.0.0' );
    }

    if ( file_exists( get_template_directory() . '/dist/main.js' ) ) {
        wp_enqueue_script(
            'newpixel-vite-js',
            $dist_js,
            array(),
            newpixel_asset_ver( get_template_directory() . '/dist/main.js' ),
            true
        );

        // Bookmarks endpoint — only meaningful when the visitor is logged in,
        // but we ship the config either way so JS can no-op cleanly otherwise.
        if ( is_user_logged_in() ) {
            wp_localize_script( 'newpixel-vite-js', 'pixelBookmarks', array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'pixel_bookmark' ),
            ) );
        }
    }

    // ----- AJAX comments (singular posts only) -----
    if ( is_singular() && comments_open() ) {
        // WP's reply-form mover (handles "Responder" → form-relocates-under-parent)
        if ( get_option( 'thread_comments' ) ) {
            wp_enqueue_script( 'comment-reply' );
        }

        $comments_js = get_template_directory() . '/dist/comments.js';
        if ( file_exists( $comments_js ) ) {
            wp_enqueue_script(
                'newpixel-comments-ajax',
                get_template_directory_uri() . '/dist/comments.js',
                array( 'comment-reply' ),
                newpixel_asset_ver( $comments_js ),
                true
            );
            wp_localize_script( 'newpixel-comments-ajax', 'pixelComments', array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'pixel_comment_submit' ),
                'postId'  => (int) get_queried_object_id(),
                'i18n'    => array(
                    'sending'        => __( 'A enviar…', 'newpixel' ),
                    'submit'         => __( 'Publicar →', 'newpixel' ),
                    'errorGeneric'   => __( 'Algo correu mal. Tenta novamente.', 'newpixel' ),
                    'pendingNotice'  => __( 'O teu comentário aguarda moderação.', 'newpixel' ),
                    'replyTo'        => __( 'A responder a %s', 'newpixel' ),
                    'cancelReply'    => __( 'cancelar', 'newpixel' ),
                ),
            ) );
        }
    }
}
add_action( 'wp_enqueue_scripts', 'newpixel_scripts' );

// ============================================================================
// Posts per page — 10 across all main listings (archives, categories, tags,
// author pages, search, date archives). Front-page page 2+ uses its own
// constant defined locally.
// ============================================================================
function newpixel_archive_posts_per_page( $query ) {
    if ( is_admin() || ! $query->is_main_query() ) {
        return;
    }
    if ( $query->is_archive() || $query->is_search() || $query->is_home() ) {
        $query->set( 'posts_per_page', 10 );
    }
}
add_action( 'pre_get_posts', 'newpixel_archive_posts_per_page' );

// ============================================================================
// ACF — local JSON sync
// ============================================================================
function newpixel_acf_json_save_point( $path ) {
    return get_stylesheet_directory() . '/acf-json';
}
add_filter( 'acf/settings/save_json', 'newpixel_acf_json_save_point' );

function newpixel_acf_json_load_point( $paths ) {
    unset( $paths[0] );
    $paths[] = get_stylesheet_directory() . '/acf-json';
    return $paths;
}
add_filter( 'acf/settings/load_json', 'newpixel_acf_json_load_point' );

// ============================================================================
// Image size labels (admin)
// ============================================================================
add_filter( 'image_size_names_choose', function( $sizes ) {
    return array_merge( $sizes, [
        // Legacy uncropped sizes (free aspect ratio)
        'w1920'         => __( '1920px width', 'newpixel' ),
        'w1024'         => __( '1024px width', 'newpixel' ),
        'w960'          => __( '960px width',  'newpixel' ),
        'w480'          => __( '480px width',  'newpixel' ),

        // Cropped contextual sizes (only the largest of each ladder is
        // exposed in the admin dropdown — the smaller ones are picked
        // automatically by srcset and don't make sense as manual choices)
        'np-21x9-1600'  => __( 'Hero 21:9 (1600px)',  'newpixel' ),
        'np-16x9-2048'  => __( 'Hero 16:9 (2048px)',  'newpixel' ),
        'np-16x10-1280' => __( 'Card 16:10 (1280px)', 'newpixel' ),
        'np-4x3-960'    => __( 'Card 4:3 (960px)',    'newpixel' ),
        'np-1x1-480'    => __( 'Thumb 1:1 (480px)',   'newpixel' ),
    ] );
} );

// ============================================================================
// Helper: get the primary category for a post (with fallback)
// ============================================================================
function newpixel_get_primary_category( $post_id = null ) {
    $post_id = $post_id ?: get_the_ID();
    $cats    = get_the_category( $post_id );

    if ( empty( $cats ) ) return null;

    // 1. Yoast primary always wins when explicitly set by the editor.
    $primary_id = get_post_meta( $post_id, '_yoast_wpseo_primary_category', true );
    if ( $primary_id ) {
        foreach ( $cats as $cat ) {
            if ( (int) $cat->term_id === (int) $primary_id ) return $cat;
        }
    }

    // 2. Otherwise pick the DEEPEST category in the hierarchy — the most
    //    specific one is the most informative editorially (e.g. when a post
    //    is in both "Apple" and "iPhone", we want the pill to say "iPhone").
    //    Alphabetical order from get_the_category() resolves ties so the
    //    selection stays deterministic.
    $deepest       = $cats[0];
    $deepest_depth = newpixel_term_depth( $cats[0] );

    for ( $i = 1, $n = count( $cats ); $i < $n; $i++ ) {
        $d = newpixel_term_depth( $cats[ $i ] );
        if ( $d > $deepest_depth ) {
            $deepest       = $cats[ $i ];
            $deepest_depth = $d;
        }
    }

    return $deepest;
}

/**
 * Count how many parents a category term has. Root cats are depth 0, a child
 * of a root is depth 1, etc. The 10-step guard protects against malformed
 * parent chains (corrupt data, cycles).
 */
function newpixel_term_depth( $term ) {
    if ( ! $term || ! isset( $term->parent ) ) return 0;
    $depth  = 0;
    $parent = (int) $term->parent;
    $guard  = 0;
    while ( $parent > 0 && $guard++ < 10 ) {
        $parent_term = get_term( $parent, 'category' );
        if ( ! $parent_term || is_wp_error( $parent_term ) ) break;
        $depth++;
        $parent = (int) $parent_term->parent;
    }
    return $depth;
}

function newpixel_get_primary_category_name( $post_id = null ) {
    $cat = newpixel_get_primary_category( $post_id );
    return $cat ? $cat->name : 'INTEL';
}

// ============================================================================
// Category accent colour map — matches the design tokens
// Slug-keyed, then we also try by name lower-cased.
// To override per-category, set ACF "category_color" (hex) on the term.
// ============================================================================
function newpixel_category_color_map() {
    return apply_filters( 'newpixel_category_color_map', array(
        'ia'         => '#a78bfa',
        'ai'         => '#a78bfa',
        'hardware'   => '#f97316',
        'software'   => '#22d3ee',
        'apple'      => '#ef4444',
        'games'      => '#10b981',
        'gaming'     => '#10b981',
        'wearables'  => '#10b981',
        'reviews'    => '#fbbf24',
        'review'     => '#fbbf24',
        'tutoriais'  => '#ec4899',
        'tutorials'  => '#ec4899',
        'tutorial'   => '#ec4899',
        'audio'      => '#ec4899',
        'opiniao'    => '#94a3b8',
        'opinião'    => '#94a3b8',
        'opinion'    => '#94a3b8',
        'retro-gaming' => '#22d3ee',
        // Google is a root category with its own section; keep this aligned with
        // the term's actual colour.
        'google'     => '#0891b2',
        'amazfit'    => '#10b981',
    ) );
}

function newpixel_get_category_color( $term, $depth = 0 ) {
    if ( ! $term || $depth > 5 ) return '#94a3b8';

    // 1. Term meta `newpixel_color` — set by the admin colour picker. See
    //    inc/category-colors.php for the UI + random-on-create logic.
    $custom = get_term_meta( $term->term_id, 'newpixel_color', true );
    if ( $custom ) return esc_attr( $custom );

    // 2. Legacy ACF field — kept for any term still carrying the old
    //    `category_color` value.
    if ( function_exists( 'get_field' ) ) {
        $custom = get_field( 'category_color', $term );
        if ( $custom ) return esc_attr( $custom );
    }

    // 3. Inherit from the parent: a subcategory without its own colour follows
    //    its pillar's accent (MacBook → Apple red, Distros → Linux orange).
    //    Runs BEFORE the static map on purpose: the map predates the current
    //    taxonomy and still lists slugs that now sit under another parent. For a
    //    child term, the real hierarchy beats a hand-written table. A colour
    //    picked explicitly still wins at step 1.
    //    Recursive, with $depth guarding against malformed chains.
    if ( ! empty( $term->parent ) ) {
        $taxonomy = isset( $term->taxonomy ) ? $term->taxonomy : 'category';
        $parent   = get_term( $term->parent, $taxonomy );
        if ( $parent && ! is_wp_error( $parent ) ) {
            return newpixel_get_category_color( $parent, $depth + 1 );
        }
    }

    // 4. Static map by slug / name / accent-free name, only for root categories,
    //    which have no parent to inherit from.
    $map = newpixel_category_color_map();
    $slug = strtolower( $term->slug );
    if ( isset( $map[ $slug ] ) ) return $map[ $slug ];

    $name = strtolower( $term->name );
    $name_normalised = str_replace( ' ', '-', remove_accents( $name ) );
    if ( isset( $map[ $name ] ) ) return $map[ $name ];
    if ( isset( $map[ $name_normalised ] ) ) return $map[ $name_normalised ];

    // 5. Fallback slate.
    return '#94a3b8';
}

function newpixel_get_post_category_color( $post_id = null ) {
    $cat = newpixel_get_primary_category( $post_id );
    return newpixel_get_category_color( $cat );
}

// data-cat slug used by SCSS [data-cat="..."] selectors
function newpixel_get_post_category_slug( $post_id = null ) {
    $cat = newpixel_get_primary_category( $post_id );
    if ( ! $cat ) return 'fallback';
    return sanitize_title( remove_accents( $cat->slug ) );
}

// ============================================================================
// Pillar categories — the top-level taxonomy used in the homepage filter rail
// and the mobile category scroller.
//
// We surface ONLY top-level categories (no children), each enriched with a
// `total_count` property that sums the pillar's own posts + every descendant.
// This keeps the rail editorially coherent (you see PILLARS, not a mix of
// pillars + sub-categories) and the count honest (clicking "Apple" lands on
// /category/apple/ which — thanks to WP's default include_children — shows
// posts from MacBook and iPhone too, so the aggregated number is what the
// archive will actually display).
//
// Filter `newpixel_filter_rail_pillars` lets you override the array (reorder,
// pin, hide) without touching the template.
// ============================================================================
function newpixel_get_pillar_categories() {
    static $cache = null;
    if ( $cache !== null ) return $cache;

    $pillars = get_categories( array(
        'parent'     => 0,
        'taxonomy'   => 'category',
        'hide_empty' => false,
    ) );

    foreach ( $pillars as $pillar ) {
        $total = (int) $pillar->count;
        $descendant_ids = get_term_children( $pillar->term_id, 'category' );
        if ( ! is_wp_error( $descendant_ids ) && ! empty( $descendant_ids ) ) {
            foreach ( $descendant_ids as $child_id ) {
                $child = get_term( $child_id, 'category' );
                if ( $child && ! is_wp_error( $child ) ) {
                    $total += (int) $child->count;
                }
            }
        }
        $pillar->total_count = $total;
    }

    // Drop the "Uncategorized" default + any pillar with zero posts in the tree.
    $pillars = array_values( array_filter( $pillars, function ( $p ) {
        return $p->slug !== 'uncategorized' && $p->total_count > 0;
    } ) );

    // Sort by aggregated count DESC (busiest pillar first).
    usort( $pillars, function ( $a, $b ) {
        return $b->total_count - $a->total_count;
    } );

    /**
     * Filter the pillar list for the homepage filter rail and mobile scroller.
     *
     * @param WP_Term[] $pillars Top-level category terms with a `total_count`
     *                           property each. Already sorted by count DESC.
     */
    $cache = apply_filters( 'newpixel_filter_rail_pillars', $pillars );
    return $cache;
}

/**
 * Tells whether a given pillar should be highlighted as "active" — true when
 * we're currently viewing that pillar's archive OR any of its sub-category
 * archives (so on /category/apple/macbook/, "Apple" still glows in the rail).
 */
function newpixel_is_pillar_active( $pillar_id ) {
    if ( ! is_category() ) return false;
    $current_id = (int) get_queried_object_id();
    if ( $current_id === (int) $pillar_id ) return true;

    $ancestors = get_ancestors( $current_id, 'category' );
    return in_array( (int) $pillar_id, array_map( 'intval', $ancestors ), true );
}

// ============================================================================
// Helper: featured image URL at a given size (empty string when no image).
// ============================================================================
function newpixel_get_post_image( $size = 'w1024', $post_id = null ) {
    $post_id = $post_id ?: get_the_ID();
    return has_post_thumbnail( $post_id )
        ? (string) get_the_post_thumbnail_url( $post_id, $size )
        : '';
}

// ============================================================================
// Helper: resolve the attachment ID backing a post's hero image.
// Returns 0 when no featured image is set.
// ============================================================================
function newpixel_get_post_image_id( $post_id = null ) {
    return (int) get_post_thumbnail_id( $post_id ?: get_the_ID() );
}

// ============================================================================
// Image contexts
//
// Maps a layout context (where in the design the image appears) to the size
// ladder that should be exposed via srcset, the default `src` size, and the
// `sizes` attribute that describes how wide the image renders at each
// breakpoint. Keep these in sync with _layout.scss / _cards.scss / _more.scss
// so the browser picks the smallest-but-good-enough source.
//
// All sizes for a single context share the same aspect ratio so WordPress's
// automatic srcset assembly groups them together.
// ============================================================================
function newpixel_image_contexts() {
    static $cache = null;
    if ( $cache !== null ) {
        return $cache;
    }

    $cache = array(
        // Homepage lead — 16:9 banner inside the feed column (≈700px at xl,
        // ≈460 at lg, full-width below). 16:9 (was 21:9) keeps more vertical
        // content and stops the top/bottom cropping on the lead.
        'lead-16x9' => array(
            'ratio'      => '16 / 9',
            'default'    => 'np-16x9-1600',
            'width'      => 1600,
            'height'     => 900,
            'sizes_attr' => '(min-width: 1280px) 700px, (min-width: 1024px) 460px, (min-width: 600px) calc(100vw - 48px), calc(100vw - 32px)',
        ),

        // Single-article hero — figure breaks out to a 1080px max wrapper.
        'hero-16x9' => array(
            'ratio'      => '16 / 9',
            'default'    => 'np-16x9-2048',
            'width'      => 2048,
            'height'     => 1152,
            'sizes_attr' => '(min-width: 1080px) 1016px, (min-width: 600px) calc(100vw - 48px), calc(100vw - 32px)',
        ),

        // Feature pair card — 1.4fr of the feed column when paired, full
        // feed width when stacked on small screens.
        'card-16x10' => array(
            'ratio'      => '16 / 10',
            'default'    => 'np-16x10-1280',
            'width'      => 1280,
            'height'     => 800,
            'sizes_attr' => '(min-width: 1280px) 405px, (min-width: 1024px) 265px, (min-width: 600px) calc(50vw - 24px), calc(100vw - 32px)',
        ),

        // Three-up grid card — three columns inside the feed column.
        'card-4x3' => array(
            'ratio'      => '4 / 3',
            'default'    => 'np-4x3-560',
            'width'      => 560,
            'height'     => 420,
            'sizes_attr' => '(min-width: 1280px) 225px, (min-width: 1024px) 145px, (min-width: 600px) 33vw, calc(100vw - 32px)',
        ),

        // Browse list row — fixed-width thumbnail (96 → 160 → 200).
        'browse-4x3' => array(
            'ratio'      => '4 / 3',
            'default'    => 'np-4x3-560',
            'width'      => 560,
            'height'     => 420,
            'sizes_attr' => '(min-width: 768px) 200px, (min-width: 600px) 160px, 96px',
        ),

        // Mais-artigos / sidebar tiny square thumbnail.
        'thumb-1x1' => array(
            'ratio'      => '1 / 1',
            'default'    => 'np-1x1-240',
            'width'      => 240,
            'height'     => 240,
            'sizes_attr' => '120px',
        ),
    );

    return $cache;
}

// ============================================================================
// Helper: render a responsive <img> for a post's featured image, or the
// striped editorial placeholder when no image is available.
//
// Args:
//   - context   (string)  one of the keys in newpixel_image_contexts() —
//                         drives the chosen size, srcset and sizes attr
//   - post_id   (int)     defaults to current post in the loop
//   - label     (string)  text shown on the placeholder (no-image fallback)
//   - ratio     (string)  CSS aspect-ratio for the placeholder; auto-derived
//                         from the context when omitted
//   - cat_color (string)  category accent for placeholder (auto when omitted)
//   - eager     (bool)    true for above-the-fold hero images — emits
//                         loading="eager" + fetchpriority="high"
//   - size      (string)  legacy single-size key (e.g. 'w1024'); only used
//                         when no `context` is given (back-compat)
// ============================================================================
function newpixel_image_or_placeholder( $args = array() ) {
    $args = wp_parse_args( $args, array(
        'post_id'   => null,
        'context'   => '',
        'size'      => '',
        'label'     => '',
        'ratio'     => '',
        'cat_color' => '',
        'eager'     => false,
    ) );

    $contexts = newpixel_image_contexts();
    $ctx      = ( $args['context'] && isset( $contexts[ $args['context'] ] ) )
        ? $contexts[ $args['context'] ]
        : null;

    $post_id = $args['post_id'] ?: get_the_ID();
    $title   = get_the_title( $post_id );
    $color   = $args['cat_color'] ?: newpixel_get_post_category_color( $post_id );
    $ratio   = $args['ratio'] ?: ( $ctx ? $ctx['ratio'] : '16 / 10' );
    $att_id  = newpixel_get_post_image_id( $post_id );

    // ---------------------------------------------------------------
    // Preferred path: attachment-aware. wp_get_attachment_image() emits
    // `srcset` from every registered size that shares the chosen size's
    // aspect ratio, plus our `sizes` attr, plus intrinsic width/height
    // for layout stability.
    // ---------------------------------------------------------------
    if ( $att_id && $ctx ) {
        $img_attrs = array(
            'sizes' => $ctx['sizes_attr'],
            'alt'   => $title,
            'class' => 'kf-img',
        );
        if ( $args['eager'] ) {
            $img_attrs['loading']       = 'eager';
            $img_attrs['fetchpriority'] = 'high';
            $img_attrs['decoding']      = 'sync';
        } else {
            $img_attrs['loading']  = 'lazy';
            $img_attrs['decoding'] = 'async';
        }

        $html = wp_get_attachment_image( $att_id, $ctx['default'], false, $img_attrs );
        if ( $html ) {
            echo $html;
            return;
        }
    }

    // ---------------------------------------------------------------
    // Fallback path: URL only (covers ACF fields holding raw URLs and
    // any legacy callers that still pass `size` instead of `context`).
    // We can't build srcset without an attachment ID, but we can still
    // honour the chosen size, alt text, and loading hints.
    // ---------------------------------------------------------------
    $url = '';
    if ( $att_id ) {
        $size_for_url = $args['size'] ?: ( $ctx ? $ctx['default'] : 'large' );
        $u = wp_get_attachment_image_url( $att_id, $size_for_url );
        if ( $u ) {
            $url = $u;
        }
    } else {
        $size_for_url = $args['size'] ?: ( $ctx ? $ctx['default'] : 'w1024' );
        $url = newpixel_get_post_image( $size_for_url, $post_id );
    }

    if ( $url ) {
        $width  = $ctx ? (int) $ctx['width']  : 0;
        $height = $ctx ? (int) $ctx['height'] : 0;
        printf(
            '<img src="%s" alt="%s"%s%s loading="%s" decoding="%s" />',
            esc_url( $url ),
            esc_attr( $title ),
            $width  ? ' width="'  . $width  . '"' : '',
            $height ? ' height="' . $height . '"' : '',
            $args['eager'] ? 'eager' : 'lazy',
            $args['eager'] ? 'sync'  : 'async'
        );
        return;
    }

    // ---------------------------------------------------------------
    // No image at all — render the striped editorial placeholder.
    // ---------------------------------------------------------------
    printf(
        '<div class="kf-imgwrap__placeholder" style="--ratio:%s">
            <div class="kf-imgwrap__placeholder__accent" style="--c:%s"></div>
            <span class="kf-imgwrap__placeholder__label">%s</span>
        </div>',
        esc_attr( $ratio ),
        esc_attr( $color ),
        esc_html( $args['label'] ?: strtoupper( wp_trim_words( $title, 4, '' ) ) )
    );
}

// ============================================================================
// Helper: portuguese "time ago" label
// ============================================================================
function newpixel_time_ago( $post_id = null ) {
    $post_time = get_post_time( 'U', true, $post_id );
    if ( ! $post_time ) return;

    $diff = time() - $post_time;

    if ( $diff < MINUTE_IN_SECONDS ) {
        echo 'há instantes';
    } elseif ( $diff < HOUR_IN_SECONDS ) {
        $n = (int) round( $diff / MINUTE_IN_SECONDS );
        echo 'há ' . $n . ' min';
    } elseif ( $diff < DAY_IN_SECONDS ) {
        $n = (int) round( $diff / HOUR_IN_SECONDS );
        echo 'há ' . $n . 'h';
    } elseif ( $diff < 7 * DAY_IN_SECONDS ) {
        $n = (int) round( $diff / DAY_IN_SECONDS );
        echo 'há ' . $n . ( $n === 1 ? ' dia' : ' dias' );
    } elseif ( $diff < 4 * WEEK_IN_SECONDS ) {
        $n = (int) round( $diff / WEEK_IN_SECONDS );
        echo 'há ' . $n . ( $n === 1 ? ' semana' : ' semanas' );
    } else {
        echo wp_date( 'd/m/Y', $post_time );
    }
}

// ============================================================================
// Helper: estimated reading time (minutes)
//
// Counts real words across:
//   - post_content   (Gutenberg / classic editor — the primary body)
//   - verdict_text   (ACF textarea — review verdict, when present)
//   - pros / cons    (ACF repeaters of `item` strings)
//
// Adds a per-image scan budget (Medium's algorithm — ~12s on the first
// image, decaying to a 3s floor) and returns whole minutes with a 1-minute
// floor.
//
// Implementation notes:
//   - `do_blocks()` expands Gutenberg blocks so block-internal text counts.
//   - `strip_shortcodes()` keeps `[gallery …]` and friends out of the total.
//   - Word matching uses Unicode `\p{L}` (with the `/u` flag) so accented
//     PT-PT words like "negócio", "ação", "São" count as one word each.
//     `str_word_count()` doesn't handle UTF-8 — that was the old bug.
//   - Reading speed: 220 wpm (typical for tech / long-form prose).
// ============================================================================
function newpixel_reading_time( $post_id = null ) {
    $post_id = $post_id ?: get_the_ID();

    // ---- 1. Gather every chunk of prose for this post ------------------
    $chunks = array();

    $core = (string) get_post_field( 'post_content', $post_id );
    if ( $core !== '' ) {
        $chunks[] = has_blocks( $core ) ? do_blocks( $core ) : $core;
    }

    if ( function_exists( 'get_field' ) ) {
        // Review verdict (when present, adds to the read length)
        $verdict = get_field( 'verdict_text', $post_id );
        if ( is_string( $verdict ) && $verdict !== '' ) {
            $chunks[] = $verdict;
        }

        // Pros / Cons repeaters (each row has an `item` subfield)
        foreach ( array( 'pros', 'cons' ) as $key ) {
            $rows = get_field( $key, $post_id );
            if ( is_array( $rows ) ) {
                foreach ( $rows as $row ) {
                    if ( is_array( $row ) && isset( $row['item'] ) && is_string( $row['item'] ) ) {
                        $chunks[] = $row['item'];
                    } elseif ( is_string( $row ) ) {
                        $chunks[] = $row;
                    }
                }
            }
        }
    }

    if ( empty( $chunks ) ) {
        return 1;
    }

    $content = implode( "\n\n", $chunks );
    $content = strip_shortcodes( $content );

    // ---- 2. Image scan time (seconds) ---------------------------------
    $image_count   = preg_match_all( '/<img\b[^>]*>/i', $content );
    $image_seconds = 0;
    for ( $i = 1; $i <= $image_count; $i++ ) {
        $image_seconds += max( 3, 13 - $i );
    }

    // ---- 3. UTF-8 aware word count ------------------------------------
    $text = wp_strip_all_tags( $content );
    $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    preg_match_all( '/[\p{L}\p{N}][\p{L}\p{N}\'\-]*/u', $text, $matches );
    $words = isset( $matches[0] ) ? count( $matches[0] ) : 0;

    // ---- 4. Convert to minutes ----------------------------------------
    $words_per_min = 220;
    $total_seconds = ( $words / $words_per_min ) * 60 + $image_seconds;

    return max( 1, (int) ceil( $total_seconds / 60 ) );
}

// ============================================================================
// Helper: render a category pill <a class="kf-pill" data-cat="...">
// ============================================================================
function newpixel_category_pill( $term = null, $args = array() ) {
    if ( ! $term ) {
        $term = newpixel_get_primary_category();
    }
    if ( ! $term ) return;

    $args = wp_parse_args( $args, array(
        'link' => true,
        'echo' => true,
    ) );

    $color = newpixel_get_category_color( $term );
    $slug  = sanitize_title( remove_accents( $term->slug ) );
    $label = strtoupper( $term->name );

    $tag   = $args['link'] ? 'a' : 'span';
    $href  = $args['link'] ? sprintf( ' href="%s"', esc_url( get_term_link( $term ) ) ) : '';

    $html  = sprintf(
        '<%1$s class="kf-pill"%2$s data-cat="%3$s" style="--c:%4$s">
            <span class="kf-pill__dot"></span>%5$s
        </%1$s>',
        $tag,
        $href,
        esc_attr( $slug ),
        esc_attr( $color ),
        esc_html( $label )
    );

    if ( $args['echo'] ) {
        echo $html;
    } else {
        return $html;
    }
}

// ============================================================================
// Helper: comment count (with fallback to 0)
// ============================================================================
function newpixel_comment_count( $post_id = null ) {
    return (int) get_comments_number( $post_id );
}

// ============================================================================
// Helper: renders the "💬 N" meta widget with consistent icon-to-number
// spacing. Used inside .kf-card__meta blocks across the site.
// ============================================================================
function newpixel_meta_comments_html( $post_id = null ) {
    return '<span class="kf-meta-comments"><span class="kf-meta-comments__icon" aria-hidden="true">💬</span><span class="kf-meta-comments__count">' . (int) newpixel_comment_count( $post_id ) . '</span></span>';
}
function newpixel_meta_comments( $post_id = null ) {
    echo newpixel_meta_comments_html( $post_id );
}


// ============================================================================
// Brand wordmark — inline SVG so `currentColor` follows the theme tokens.
//
// Renders inline so:
//   • The "PIXEL" letters and "magazine" inherit the surrounding text colour
//     via fill="currentColor" → adapts to light/dark theme automatically.
//   • The accent dot stays a fixed purple.
//   • Sizing is controlled via CSS on `.kf-logo` (width/height), not an inline
//     attribute, so each placement (header, footer, modal) can set its own
//     dimensions.
// ============================================================================
function newpixel_logo_svg_html( $class = '' ) {
    $class_attr = esc_attr( trim( 'kf-logo ' . $class ) );
    $svg_file   = get_template_directory() . '/assets/img/pixel-logo.svg';

    static $cached = null;
    if ( null === $cached && file_exists( $svg_file ) ) {
        // Read once per request and strip the XML prolog — inline SVGs don't
        // need it. Subsequent calls (header, footer, modal) reuse the same
        // string from this static cache.
        $cached = preg_replace( '/^<\?xml[^>]*\?>\s*/', '', file_get_contents( $svg_file ) );
    }
    if ( ! $cached ) {
        return '';
    }

    // Inject the per-placement modifier class (e.g. "kf-logo--header") into
    // the existing class attribute so each call can size the SVG via CSS.
    return preg_replace(
        '/class="kf-logo"/',
        'class="' . $class_attr . '"',
        $cached,
        1
    );
}
function newpixel_logo_svg( $class = '' ) {
    echo newpixel_logo_svg_html( $class );
}

// ============================================================================
// Helper: render a single elaborate "browse" row (used by paginated front
// page and archive/search templates). Assumes the global $post is set —
// either via WP loop or via setup_postdata().
// ============================================================================
function newpixel_browse_row( $post_id = null ) {
    $post_id   = $post_id ?: get_the_ID();
    $cat_term  = newpixel_get_primary_category( $post_id );
    $cat_color = newpixel_get_post_category_color( $post_id );
    $cat_slug  = newpixel_get_post_category_slug( $post_id );
    $cat_name  = $cat_term ? $cat_term->name : 'Pixel';
    $reading   = newpixel_reading_time( $post_id );
    $comments  = (int) get_comments_number( $post_id );
    // Dek: prefer ACF `dek`, fall back to the native excerpt.
    $dek = function_exists( 'get_field' ) ? trim( (string) get_field( 'dek', $post_id ) ) : '';
    if ( $dek === '' ) {
        $dek = trim( wp_strip_all_tags( get_the_excerpt( $post_id ) ) );
    }
    if ( $dek !== '' ) {
        $dek = wp_trim_words( $dek, 28, '…' );
    }
    ?>
    <li class="kf-browse__row" data-cat="<?php echo esc_attr( $cat_slug ); ?>" style="--c:<?php echo esc_attr( $cat_color ); ?>">
        <div class="kf-browse__link">
            <div class="kf-browse__thumb kf-imgwrap">
                <?php newpixel_image_or_placeholder( array(
                    'post_id'   => $post_id,
                    'context'   => 'browse-4x3',
                    'label'     => strtoupper( $cat_name ),
                    'cat_color' => $cat_color,
                ) ); ?>
            </div>
            <div class="kf-browse__body">
                <div class="kf-browse__meta-top">
                    <?php if ( $cat_term ) {
                        // Pill is a real link to the category archive — sits
                        // above the stretched article link via z-index in CSS.
                        echo newpixel_category_pill( $cat_term, array( 'link' => true, 'echo' => false ) );
                    } ?>
                    <span class="sep" aria-hidden="true">·</span>
                    <span><?php echo (int) $reading; ?> min</span>
                </div>
                <h3 class="kf-browse__heading">
                    <a class="kf-headline kf-browse__title-link" href="<?php echo esc_url( get_permalink( $post_id ) ); ?>">
                        <?php echo esc_html( get_the_title( $post_id ) ); ?>
                    </a>
                </h3>
                <?php if ( $dek ) : ?>
                    <p class="kf-browse__dek"><?php echo esc_html( $dek ); ?></p>
                <?php endif; ?>
                <div class="kf-browse__meta-bot">
                    <span><?php newpixel_time_ago( $post_id ); ?></span>
                    <span class="sep" aria-hidden="true">·</span>
                    <?php echo newpixel_meta_comments_html( $post_id ); ?>
                </div>
            </div>
        </div>
    </li>
    <?php
}

// ============================================================================
// Helper: render a styled paginator. Wraps paginate_links() and adds the
// "Página X de Y · 142 artigos" status block on the left.
// ============================================================================
function newpixel_pagination( $args = array() ) {
    global $wp_query;

    $args = wp_parse_args( $args, array(
        'query'        => $wp_query,
        'show_status'  => false,
        'total_label'  => __( 'artigos', 'newpixel' ),
        'current'      => null,   // override (front-page paging quirk)
        'base_url'     => null,   // override (custom URL base for non-main queries)
    ) );

    $query = $args['query'];
    if ( ! $query instanceof WP_Query ) return;

    $total = (int) $query->max_num_pages;
    if ( $total < 2 ) return;

    if ( $args['current'] !== null ) {
        $current = max( 1, (int) $args['current'] );
    } else {
        $current = (int) get_query_var( 'paged' );
        if ( $current < 2 ) { $current = (int) get_query_var( 'page' ); }
        $current = max( 1, $current );
    }

    $paginate_args = array(
        'total'     => $total,
        'current'   => $current,
        'mid_size'  => 1,
        'end_size'  => 1,
        'prev_text' => '<span aria-hidden="true">←</span><span class="screen-reader-text">' . esc_html__( 'Anteriores', 'newpixel' ) . '</span>',
        'next_text' => '<span aria-hidden="true">→</span><span class="screen-reader-text">' . esc_html__( 'Seguintes', 'newpixel' ) . '</span>',
        'type'      => 'array',
    );
    if ( $args['base_url'] ) {
        $paginate_args['base']   = trailingslashit( $args['base_url'] ) . '%_%';
        $paginate_args['format'] = 'page/%#%/';
    }
    $links = paginate_links( $paginate_args );

    if ( empty( $links ) ) return;

    $found = (int) $query->found_posts;
    ?>
    <nav class="kf-pager" aria-label="<?php esc_attr_e( 'Paginação', 'newpixel' ); ?>">
        <?php if ( $args['show_status'] ) : ?>
            <div class="kf-pager__status">
                <?php
                printf(
                    /* translators: 1: current page, 2: total pages, 3: total posts, 4: label */
                    esc_html__( 'Página %1$s de %2$s · %3$s %4$s', 'newpixel' ),
                    '<strong>' . (int) $current . '</strong>',
                    '<strong>' . (int) $total . '</strong>',
                    '<strong>' . esc_html( number_format_i18n( $found ) ) . '</strong>',
                    esc_html( $args['total_label'] )
                );
                ?>
            </div>
        <?php endif; ?>
        <div class="kf-pager__nav">
            <?php
            // Add custom classes to prev / next / dots
            foreach ( $links as $link ) {
                $klass = '';
                if ( strpos( $link, 'prev page-numbers' ) !== false ) $klass = 'prev';
                elseif ( strpos( $link, 'next page-numbers' ) !== false ) $klass = 'next';
                elseif ( strpos( $link, 'dots' ) !== false ) $klass = 'dots';

                if ( $klass !== '' ) {
                    // Inject our class onto whatever wrapping <a> or <span> WP returned
                    $link = preg_replace( '/(class="[^"]*)/', '$1 ' . esc_attr( $klass ) . ' ', $link, 1 );
                }
                echo $link;
            }
            ?>
        </div>
    </nav>
    <?php
}


/**
 * Favicon, apple-touch-icon, PWA manifest and theme-color meta tags.
 *
 * The browser-tab favicon is a single transparent SVG with the purple
 * dot — readable on both light and dark tabs, so no per-theme variant,
 * no @media query inside the SVG, no JS to swap. One file, done.
 *
 * The home-screen / launcher icons (apple-touch, manifest, root .ico)
 * have to be opaque tiles because iOS / Android / Windows pinned sites
 * don't support transparency. Those stay light-tiled.
 *
 * theme-color meta still ships both light/dark via media queries —
 * that one is about the mobile browser chrome (URL bar), not the
 * favicon, and the dual-meta pattern is the standard for that.
 *
 * ?v=mtime busts browser cache when the SVG is regenerated.
 *
 * Priority 5 keeps these near the top of <head>, before stylesheets.
 */
function newpixel_favicons() {
    $base = get_template_directory_uri() . '/assets/icons';
    $dir  = get_template_directory() . '/assets/icons';
    $ver  = @filemtime( $dir . '/favicon.svg' ) ?: time();
    $svg  = esc_url( $base . '/favicon.svg' ) . '?v=' . (int) $ver;
    ?>
    <link rel="icon" type="image/svg+xml" href="<?php echo $svg; ?>">
    <?php // Raster favicons for crawlers that don't use SVG (Google Search shows a PNG/ICO, never the SVG). ?>
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo esc_url( $base . '/favicon-32.png' ); ?>?v=<?php echo (int) $ver; ?>">
    <link rel="icon" type="image/png" sizes="192x192" href="<?php echo esc_url( $base . '/icon-192.png' ); ?>?v=<?php echo (int) $ver; ?>">
    <link rel="mask-icon" href="<?php echo $svg; ?>" color="#a78bfa">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo esc_url( $base . '/apple-touch-icon.png' ); ?>?v=<?php echo (int) $ver; ?>">
    <link rel="manifest" href="<?php echo esc_url( $base . '/manifest.json' ); ?>?v=<?php echo (int) $ver; ?>">
    <meta name="theme-color" content="#fafaf7" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0a0a0a" media="(prefers-color-scheme: dark)">
    <?php
}
add_action( 'wp_head', 'newpixel_favicons', 5 );

// /favicon.ico is served directly by nginx from a physical file at the
// public root (managed by the build script — see assets/icons/build-favicons.py).
// No PHP redirect needed.

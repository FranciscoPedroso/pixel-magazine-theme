<?php
/**
 * ============================================================================
 * Post view counter — anonymous, cache-proof, GDPR-clean.
 * ============================================================================
 *
 * Tracks how many times each single post OR page has been rendered. Used by the
 * "Em alta" block on the homepage right-rail to surface popular reads, and
 * available as `newpixel_get_view_count( $post_id )` for any future UI
 * (e.g. "1.2K leituras" badges on cards).
 *
 * Architecture: AJAX beacon (not server-side hook), so it works under any
 * full-page caching layer (WP Rocket, WP Super Cache, Cloudflare APO,
 * Varnish, etc.). The cached HTML emits a tiny `fetch()` POST to a
 * non-cached admin-ajax endpoint that increments the counter.
 *
 * Privacy: stores a single integer per post in `wp_postmeta` under the
 * key `_newpixel_views`. Zero IP storage, zero cookies, zero fingerprinting.
 * Falls outside GDPR scope per Recital 26 (anonymous data).
 *
 * Bot filtering: server-side user-agent check rejects obvious crawlers.
 * Headless bots that execute JS bypass this, but they're a small minority.
 *
 * Atomicity: uses `UPDATE … SET = + 1` (atomic at row level in InnoDB).
 * Falls back to INSERT only when no row exists yet. The narrow race window
 * between the two queries can produce duplicate meta rows under concurrent
 * first-views; impact is negligible (worst case: +1 spurious view).
 *
 * @package newpixel
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const NEWPIXEL_VIEWS_META_KEY = '_newpixel_views';

// ============================================================================
// Frontend: inline JS beacon on single posts
// ============================================================================
add_action( 'wp_enqueue_scripts', 'newpixel_views_beacon', 20 );

function newpixel_views_beacon() {
    if ( ! is_singular( array( 'post', 'page' ) ) ) return;

    $post_id = get_the_ID();
    if ( ! $post_id ) return;

    // Skip self-views by editors viewing their own admin sessions — keeps the
    // counter honest while you're testing the site.
    if ( is_user_logged_in() && current_user_can( 'edit_post', $post_id ) ) return;

    $payload = array(
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'postId'  => $post_id,
        'nonce'   => wp_create_nonce( 'newpixel_track_view_' . $post_id ),
    );

    // Counts a READ, not a page load: 10 s of visible page, or scrolling past a
    // quarter of the text with at least 3 s visible. Firing on `load` would let
    // bots that execute JavaScript inflate every article equally and turn the
    // trending block into noise. `navigator.webdriver` catches automated browsers
    // that identify themselves.
    $inline = sprintf(
        '(function(){var c=%s;if(navigator.webdriver)return;var sent=false,vis=0,scrolled=false,timer;'
        . 'function send(){if(sent)return;sent=true;clearInterval(timer);try{fetch(c.ajaxUrl,{method:"POST",credentials:"same-origin",keepalive:true,headers:{"Content-Type":"application/x-www-form-urlencoded"},body:"action=newpixel_track_view&post_id="+c.postId+"&_wpnonce="+c.nonce})}catch(e){}}'
        . 'timer=setInterval(function(){if(document.visibilityState!=="visible")return;vis++;if(vis>=10||(scrolled&&vis>=3))send();},1000);'
        . 'addEventListener("scroll",function(){var h=document.documentElement,max=h.scrollHeight-h.clientHeight;if(max>0&&h.scrollTop>max*0.25)scrolled=true;},{passive:true});'
        . '})();',
        wp_json_encode( $payload )
    );

    wp_add_inline_script( 'newpixel-vite-js', $inline, 'after' );
}

// ============================================================================
// AJAX endpoint: receive the beacon, validate, increment
// ============================================================================
add_action( 'wp_ajax_nopriv_newpixel_track_view', 'newpixel_track_view_handler' );
add_action( 'wp_ajax_newpixel_track_view',        'newpixel_track_view_handler' );

function newpixel_track_view_handler() {
    $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
    if ( $post_id <= 0 ) {
        wp_die( '', '', 400 );
    }

    // Per-post nonce ties the increment to the page that emitted the beacon.
    $nonce = isset( $_POST['_wpnonce'] ) ? (string) $_POST['_wpnonce'] : '';
    if ( ! wp_verify_nonce( $nonce, 'newpixel_track_view_' . $post_id ) ) {
        wp_die( '', '', 403 );
    }

    // Only count published posts/pages (drafts/private/trashed don't qualify).
    if ( get_post_status( $post_id ) !== 'publish'
        || ! in_array( get_post_type( $post_id ), array( 'post', 'page' ), true ) ) {
        wp_die( '', '', 400 );
    }

    // The newsroom's own views: same condition as the beacon, but authoritative
    // here. The template's check is decided when the HTML is GENERATED, and that
    // HTML may be served from cache to someone else. Here we know who is actually
    // making the request.
    // 200 rather than 403: it isn't an error, just a visit we deliberately skip.
    if ( is_user_logged_in() && current_user_can( 'edit_post', $post_id ) ) {
        wp_die( '', '', 200 );
    }

    // Server-side bot filter — quick coarse pass. JS-executing bots slip
    // through (they're a tiny minority of crawler traffic).
    $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';
    if ( $ua === '' || preg_match( '/(bot|crawl|spider|slurp|baidu|bingpreview|facebookexternalhit|whatsapp|telegram|linkedin|twitter|discord)/i', $ua ) ) {
        wp_die( '', '', 200 );
    }

    newpixel_increment_view_count( $post_id );

    // 204 No Content — minimum payload back to the browser.
    status_header( 204 );
    wp_die( '', '', 204 );
}

// ============================================================================
// Helpers (also re-usable from templates)
// ============================================================================

/**
 * Atomically increment the view count for a post.
 *
 * Strategy: try UPDATE first. If zero rows affected the meta key doesn't
 * exist yet, fall through to add_post_meta(). The race window between
 * the two queries is microscopic and the worst case is a duplicate row
 * with value 1 — harmless for an aggregate counter.
 */
function newpixel_increment_view_count( $post_id ) {
    global $wpdb;
    $post_id = (int) $post_id;
    if ( $post_id <= 0 ) return;

    $updated = $wpdb->query( $wpdb->prepare(
        "UPDATE {$wpdb->postmeta}
         SET meta_value = CAST(meta_value AS UNSIGNED) + 1
         WHERE post_id = %d AND meta_key = %s",
        $post_id,
        NEWPIXEL_VIEWS_META_KEY
    ) );

    if ( $updated === 0 ) {
        add_post_meta( $post_id, NEWPIXEL_VIEWS_META_KEY, 1, true );
    }

    // Bust WP's object cache for this post's meta so a subsequent read in
    // the same request sees the new value.
    wp_cache_delete( $post_id, 'post_meta' );
}

/**
 * Read the current view count for a post (0 when never tracked).
 */
function newpixel_get_view_count( $post_id = null ) {
    $post_id = $post_id ?: get_the_ID();
    return (int) get_post_meta( $post_id, NEWPIXEL_VIEWS_META_KEY, true );
}

/**
 * Human-friendly view count for display (1234 → "1.2K", 1500000 → "1.5M").
 */
function newpixel_format_view_count( $count ) {
    $count = (int) $count;
    if ( $count >= 1000000 ) {
        return number_format_i18n( $count / 1000000, 1 ) . 'M';
    }
    if ( $count >= 1000 ) {
        return number_format_i18n( $count / 1000, 1 ) . 'K';
    }
    return number_format_i18n( $count );
}

// ============================================================================
// Admin: "Views" column on Posts → All Posts (sortable both directions).
//
// Sorting uses a LEFT JOIN on wp_postmeta instead of the standard
// `meta_key` + `orderby=meta_value_num` pattern. The standard approach is
// an INNER JOIN — it would exclude posts that haven't been viewed yet from
// the list entirely. The LEFT JOIN keeps every post visible and treats
// "no view data" as 0, so newly published posts still show up.
// ============================================================================

// Add the column — slotted right before the comments bubble (or appended if
// the table has no comments column, e.g. on Pages).
function newpixel_views_add_admin_column( $columns ) {
    $new = array();
    foreach ( $columns as $key => $label ) {
        if ( $key === 'comments' ) {
            $new['newpixel_views'] = __( 'Views', 'newpixel' );
        }
        $new[ $key ] = $label;
    }
    if ( ! isset( $new['newpixel_views'] ) ) {
        $new['newpixel_views'] = __( 'Views', 'newpixel' );
    }
    return $new;
}

// Render the column cell.
function newpixel_views_render_admin_column( $column, $post_id ) {
    if ( $column !== 'newpixel_views' ) return;
    $views = newpixel_get_view_count( $post_id );
    if ( $views > 0 ) {
        printf(
            '<strong title="%d %s">%s</strong>',
            $views,
            esc_attr__( 'views', 'newpixel' ),
            esc_html( newpixel_format_view_count( $views ) )
        );
    } else {
        echo '<span style="color:#a0a5aa">&mdash;</span>';
    }
}

// Declare the column sortable (first click = DESC, most-viewed first).
function newpixel_views_sortable_admin_column( $columns ) {
    $columns['newpixel_views'] = array( 'newpixel_views', true );
    return $columns;
}

// Wire the column up for both Posts and Pages (the posts_clauses sort handler
// below isn't post-type specific, so it covers both).
foreach ( array( 'post', 'page' ) as $newpixel_views_pt ) {
    add_filter( "manage_{$newpixel_views_pt}_posts_columns",        'newpixel_views_add_admin_column' );
    add_action( "manage_{$newpixel_views_pt}_posts_custom_column",  'newpixel_views_render_admin_column', 10, 2 );
    add_filter( "manage_edit-{$newpixel_views_pt}_sortable_columns", 'newpixel_views_sortable_admin_column' );
}
unset( $newpixel_views_pt );

// Intercept the admin query and add the LEFT JOIN + ORDER BY clauses.
add_filter( 'posts_clauses', function ( $clauses, $query ) {
    if ( ! is_admin() || ! $query->is_main_query() ) return $clauses;
    if ( $query->get( 'orderby' ) !== 'newpixel_views' ) return $clauses;

    global $wpdb;
    $order = strtoupper( (string) $query->get( 'order' ) ) === 'ASC' ? 'ASC' : 'DESC';

    $clauses['join']    .= $wpdb->prepare(
        " LEFT JOIN {$wpdb->postmeta} AS np_views ON {$wpdb->posts}.ID = np_views.post_id AND np_views.meta_key = %s ",
        NEWPIXEL_VIEWS_META_KEY
    );
    $clauses['orderby']  = "CAST(np_views.meta_value AS UNSIGNED) {$order}, {$wpdb->posts}.post_date DESC";
    $clauses['groupby']  = "{$wpdb->posts}.ID";

    return $clauses;
}, 10, 2 );

// Make the column a sensible width and right-align the numbers.
add_action( 'admin_head-edit.php', function () {
    $screen = get_current_screen();
    if ( ! $screen || ! in_array( $screen->post_type, array( 'post', 'page' ), true ) ) return;
    echo '<style>
        .wp-list-table .column-newpixel_views { width: 80px; text-align: right; padding-right: 16px; }
        .wp-list-table thead .column-newpixel_views { text-align: right; padding-right: 16px; }
    </style>';
} );

// ============================================================================
// Dashboard widget: every useful metric that can be derived honestly from the
// cumulative counter. It does not claim unique visitors or views by date,
// because neither is stored by the current privacy-first data model.
//
// `wp_dashboard_setup` only fires for users who reach the dashboard (non-admins
// are bounced by inc/auth.php), but we still gate on edit_others_posts so the
// site-wide totals stay an editor+/admin view.
// ============================================================================
add_action( 'wp_dashboard_setup', function () {
    if ( ! current_user_can( 'edit_others_posts' ) ) return;
    wp_add_dashboard_widget(
        'newpixel_views_summary',
        __( 'Pixel — Visualizações', 'newpixel' ),
        'newpixel_views_dashboard_widget'
    );
} );

/**
 * Load the widget stylesheet on the Dashboard only.
 */
function newpixel_views_enqueue_dashboard_styles( $hook_suffix ) {
    if ( 'index.php' !== $hook_suffix ) return;

    $relative_path = '/dist/admin-views-widget.css';
    $absolute_path = get_theme_file_path( $relative_path );

    if ( ! file_exists( $absolute_path ) ) return;

    wp_enqueue_style(
        'newpixel-admin-views-widget',
        get_theme_file_uri( $relative_path ),
        array(),
        filemtime( $absolute_path )
    );
}
add_action( 'admin_enqueue_scripts', 'newpixel_views_enqueue_dashboard_styles', 30 );

/**
 * Fetch all published articles and pages with their cumulative counters.
 *
 * One query keeps the Dashboard predictable even when the site grows. SUM is
 * intentional: it also handles the extremely rare duplicate meta row created
 * by two simultaneous first views (see the increment helper above).
 */
function newpixel_views_dashboard_rows() {
    global $wpdb;

    return $wpdb->get_results( $wpdb->prepare(
        "SELECT p.ID,
                p.post_title,
                p.post_type,
                p.post_author,
                p.post_date_gmt,
                COALESCE( SUM( CAST( pm.meta_value AS UNSIGNED ) ), 0 ) AS views
         FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} pm
           ON pm.post_id = p.ID
          AND pm.meta_key = %s
         WHERE p.post_status = 'publish'
           AND p.post_type IN ('post', 'page')
         GROUP BY p.ID, p.post_title, p.post_type, p.post_author, p.post_date_gmt
         ORDER BY p.post_date_gmt DESC",
        NEWPIXEL_VIEWS_META_KEY
    ) );
}

/**
 * Describe a set of content rows, including zero-view items.
 */
function newpixel_views_dashboard_describe( $rows ) {
    $values = array_map(
        static function ( $row ) {
            return (int) $row->views;
        },
        $rows
    );
    sort( $values, SORT_NUMERIC );

    $count        = count( $values );
    $total        = array_sum( $values );
    $viewed       = count( array_filter( $values, static function ( $value ) { return $value > 0; } ) );
    $zero         = $count - $viewed;
    $average      = $count > 0 ? $total / $count : 0;
    $viewed_avg   = $viewed > 0 ? $total / $viewed : 0;
    $median       = 0;

    if ( $count > 0 ) {
        $middle = (int) floor( $count / 2 );
        $median = 1 === $count % 2
            ? $values[ $middle ]
            : ( $values[ $middle - 1 ] + $values[ $middle ] ) / 2;
    }

    rsort( $values, SORT_NUMERIC );
    $top_five_total = array_sum( array_slice( $values, 0, 5 ) );

    return array(
        'count'          => $count,
        'total'          => $total,
        'viewed'         => $viewed,
        'zero'           => $zero,
        'average'        => $average,
        'viewed_average' => $viewed_avg,
        'median'         => $median,
        'maximum'        => $count > 0 ? $values[0] : 0,
        'coverage'       => $count > 0 ? ( $viewed / $count ) * 100 : 0,
        'top_five_share' => $total > 0 ? ( $top_five_total / $total ) * 100 : 0,
    );
}

/**
 * Metrics for articles whose publication date falls inside a recent window.
 * Views remain lifetime totals; the window applies only to publication date.
 */
function newpixel_views_dashboard_publication_cohort( $rows, $days ) {
    $cutoff = time() - ( (int) $days * DAY_IN_SECONDS );
    $cohort = array_filter(
        $rows,
        static function ( $row ) use ( $cutoff ) {
            $published_at = strtotime( $row->post_date_gmt . ' UTC' );
            return false !== $published_at && $published_at >= $cutoff;
        }
    );

    return newpixel_views_dashboard_describe( array_values( $cohort ) );
}

/**
 * Aggregate article performance by every assigned category.
 * Multi-category articles contribute their full count to each category.
 */
function newpixel_views_dashboard_categories( $rows ) {
    if ( empty( $rows ) ) return array();

    $views_by_post = array();
    foreach ( $rows as $row ) {
        $views_by_post[ (int) $row->ID ] = (int) $row->views;
    }

    $terms = wp_get_object_terms(
        array_keys( $views_by_post ),
        'category',
        array( 'fields' => 'all_with_object_id' )
    );
    if ( is_wp_error( $terms ) ) return array();

    $categories = array();
    foreach ( $terms as $term ) {
        $term_id   = (int) $term->term_id;
        $object_id = isset( $term->object_id ) ? (int) $term->object_id : 0;
        if ( ! $object_id || ! isset( $views_by_post[ $object_id ] ) ) continue;

        if ( ! isset( $categories[ $term_id ] ) ) {
            $categories[ $term_id ] = array(
                'name'  => $term->name,
                'count' => 0,
                'views' => 0,
            );
        }

        $categories[ $term_id ]['count']++;
        $categories[ $term_id ]['views'] += $views_by_post[ $object_id ];
    }

    foreach ( $categories as &$category ) {
        $category['average'] = $category['count'] > 0
            ? $category['views'] / $category['count']
            : 0;
    }
    unset( $category );

    uasort(
        $categories,
        static function ( $a, $b ) {
            if ( $a['views'] === $b['views'] ) {
                return strcasecmp( $a['name'], $b['name'] );
            }
            return $b['views'] <=> $a['views'];
        }
    );

    return array_values( $categories );
}

/**
 * Aggregate article totals by author. Only shown when more than one author
 * has published, so a single-author magazine does not get a redundant table.
 */
function newpixel_views_dashboard_authors( $rows ) {
    $authors = array();

    foreach ( $rows as $row ) {
        $author_id = (int) $row->post_author;
        if ( ! isset( $authors[ $author_id ] ) ) {
            $user = get_userdata( $author_id );
            $authors[ $author_id ] = array(
                'name'  => $user ? $user->display_name : __( 'Autor desconhecido', 'newpixel' ),
                'count' => 0,
                'views' => 0,
            );
        }

        $authors[ $author_id ]['count']++;
        $authors[ $author_id ]['views'] += (int) $row->views;
    }

    foreach ( $authors as &$author ) {
        $author['average'] = $author['count'] > 0 ? $author['views'] / $author['count'] : 0;
    }
    unset( $author );

    uasort(
        $authors,
        static function ( $a, $b ) {
            return $b['views'] <=> $a['views'];
        }
    );

    return array_values( $authors );
}

/**
 * Output one summary card.
 */
function newpixel_views_dashboard_stat( $value, $label, $hint = '', $trend = null ) {
    $badge = '';

    // The arrow only appears when there's history to support the comparison. A
    // green indicator with no data behind it is worse than no indicator at all.
    if ( is_array( $trend ) && ! empty( $trend['available'] ) && '' !== $trend['label'] ) {
        $arrow = array( 'up' => '▲', 'down' => '▼', 'flat' => '=' );
        $badge = sprintf(
            '<div class="newpixel-views-stat__trend newpixel-views-stat__trend--%s" title="%s">'
            . '<span aria-hidden="true">%s</span> %s</div>',
            esc_attr( $trend['direction'] ),
            esc_attr( $trend['title'] ),
            esc_html( isset( $arrow[ $trend['direction'] ] ) ? $arrow[ $trend['direction'] ] : '' ),
            esc_html( $trend['label'] )
        );
    } elseif ( is_array( $trend ) && ! empty( $trend['title'] ) ) {
        // Not enough history: a discreet dash, with the explanation in the title.
        $badge = sprintf(
            '<div class="newpixel-views-stat__trend newpixel-views-stat__trend--none" title="%s">—</div>',
            esc_attr( $trend['title'] )
        );
    }

    printf(
        '<div class="newpixel-views-stat">'
        . '<div class="newpixel-views-stat__value">%s</div>'
        . '<div class="newpixel-views-stat__label">%s</div>%s%s'
        . '</div>',
        esc_html( $value ),
        esc_html( $label ),
        $hint ? '<div class="newpixel-views-stat__hint">' . esc_html( $hint ) . '</div>' : '',
        $badge
    );
}

/**
 * Output a compact label/value metric.
 */
function newpixel_views_dashboard_metric( $label, $value ) {
    printf(
        '<div class="newpixel-views-metric"><span>%s</span><strong>%s</strong></div>',
        esc_html( $label ),
        esc_html( $value )
    );
}

/**
 * Output a ranked list of content with bars relative to its leader.
 */
function newpixel_views_dashboard_ranking( $rows, $empty_message ) {
    $ranked = array_values( array_filter(
        $rows,
        static function ( $row ) { return (int) $row->views > 0; }
    ) );

    usort(
        $ranked,
        static function ( $a, $b ) {
            if ( (int) $a->views === (int) $b->views ) {
                return strcmp( $b->post_date_gmt, $a->post_date_gmt );
            }
            return (int) $b->views <=> (int) $a->views;
        }
    );
    $ranked = array_slice( $ranked, 0, 5 );

    if ( empty( $ranked ) ) {
        printf( '<p class="newpixel-views-empty">%s</p>', esc_html( $empty_message ) );
        return;
    }

    $leader = max( 1, (int) $ranked[0]->views );
    echo '<ol class="newpixel-views-ranking">';

    foreach ( $ranked as $index => $row ) {
        $title = $row->post_title ? $row->post_title : __( '(Sem título)', 'newpixel' );
        $url   = get_edit_post_link( (int) $row->ID, 'raw' );
        $width = min( 100, ( (int) $row->views / $leader ) * 100 );

        printf(
            '<li><span class="newpixel-views-ranking__position">%s</span>'
            . '<span class="newpixel-views-ranking__content"><a href="%s">%s</a>'
            . '<span class="newpixel-views-ranking__bar" style="--newpixel-views-bar:%s%%" aria-hidden="true"></span></span>'
            . '<strong>%s</strong></li>',
            esc_html( number_format_i18n( $index + 1 ) ),
            esc_url( $url ),
            esc_html( $title ),
            esc_attr( number_format( $width, 2, '.', '' ) ),
            esc_html( number_format_i18n( (int) $row->views ) )
        );
    }

    echo '</ol>';
}

/**
 * Render the complete dashboard report.
 */
function newpixel_views_dashboard_widget() {
    $rows         = newpixel_views_dashboard_rows();
    $article_rows = array_values( array_filter( $rows, static function ( $row ) { return 'post' === $row->post_type; } ) );
    $page_rows    = array_values( array_filter( $rows, static function ( $row ) { return 'page' === $row->post_type; } ) );
    $articles     = newpixel_views_dashboard_describe( $article_rows );
    $pages        = newpixel_views_dashboard_describe( $page_rows );
    $combined     = $articles['total'] + $pages['total'];
    $categories   = newpixel_views_dashboard_categories( $article_rows );
    $authors      = newpixel_views_dashboard_authors( $article_rows );
    $cohorts      = array(
        7  => newpixel_views_dashboard_publication_cohort( $article_rows, 7 ),
        30 => newpixel_views_dashboard_publication_cohort( $article_rows, 30 ),
        90 => newpixel_views_dashboard_publication_cohort( $article_rows, 90 ),
    );
    ?>
    <div class="newpixel-views-dashboard">
        <div class="newpixel-views-stats">
            <?php
            newpixel_views_dashboard_stat(
                number_format_i18n( $combined ),
                __( 'Total', 'newpixel' ),
                __( 'artigos + páginas', 'newpixel' ),
                newpixel_views_trend( 'total', 7 )
            );
            newpixel_views_dashboard_stat(
                number_format_i18n( $articles['total'] ),
                __( 'Artigos', 'newpixel' ),
                sprintf( __( '%s do total', 'newpixel' ), number_format_i18n( $combined > 0 ? ( $articles['total'] / $combined ) * 100 : 0, 1 ) . '%' ),
                newpixel_views_trend( 'articles', 7 )
            );
            newpixel_views_dashboard_stat(
                number_format_i18n( $pages['total'] ),
                __( 'Páginas', 'newpixel' ),
                sprintf( __( '%s do total', 'newpixel' ), number_format_i18n( $combined > 0 ? ( $pages['total'] / $combined ) * 100 : 0, 1 ) . '%' ),
                newpixel_views_trend( 'pages', 7 )
            );
            newpixel_views_dashboard_stat(
                number_format_i18n( $articles['average'], 1 ),
                __( 'Média/artigo', 'newpixel' ),
                __( 'inclui artigos a zero', 'newpixel' ),
                newpixel_views_trend( 'average', 7, 'ratio' )
            );
            newpixel_views_dashboard_stat(
                number_format_i18n( $articles['median'], 1 ),
                __( 'Mediana/artigo', 'newpixel' ),
                __( 'valor central', 'newpixel' ),
                newpixel_views_trend( 'median', 7, 'ratio' )
            );
            newpixel_views_dashboard_stat(
                number_format_i18n( $articles['coverage'], 1 ) . '%',
                __( 'Cobertura', 'newpixel' ),
                __( 'artigos com visualizações', 'newpixel' ),
                newpixel_views_trend( 'coverage', 7, 'ratio' )
            );
            ?>
        </div>

        <div class="newpixel-views-columns newpixel-views-columns--summary">
            <section class="newpixel-views-section">
                <h3><?php esc_html_e( 'Distribuição dos artigos', 'newpixel' ); ?></h3>
                <div class="newpixel-views-metrics">
                    <?php
                    newpixel_views_dashboard_metric( __( 'Artigos publicados', 'newpixel' ), number_format_i18n( $articles['count'] ) );
                    newpixel_views_dashboard_metric( __( 'Com visualizações', 'newpixel' ), number_format_i18n( $articles['viewed'] ) );
                    newpixel_views_dashboard_metric( __( 'Sem visualizações', 'newpixel' ), number_format_i18n( $articles['zero'] ) );
                    newpixel_views_dashboard_metric( __( 'Média entre artigos vistos', 'newpixel' ), number_format_i18n( $articles['viewed_average'], 1 ) );
                    newpixel_views_dashboard_metric( __( 'Máximo num artigo', 'newpixel' ), number_format_i18n( $articles['maximum'] ) );
                    newpixel_views_dashboard_metric( __( 'Peso dos 5 mais vistos', 'newpixel' ), number_format_i18n( $articles['top_five_share'], 1 ) . '%' );
                    ?>
                </div>
            </section>

            <section class="newpixel-views-section">
                <h3><?php esc_html_e( 'Distribuição das páginas', 'newpixel' ); ?></h3>
                <div class="newpixel-views-metrics">
                    <?php
                    newpixel_views_dashboard_metric( __( 'Páginas publicadas', 'newpixel' ), number_format_i18n( $pages['count'] ) );
                    newpixel_views_dashboard_metric( __( 'Com visualizações', 'newpixel' ), number_format_i18n( $pages['viewed'] ) );
                    newpixel_views_dashboard_metric( __( 'Sem visualizações', 'newpixel' ), number_format_i18n( $pages['zero'] ) );
                    newpixel_views_dashboard_metric( __( 'Média por página', 'newpixel' ), number_format_i18n( $pages['average'], 1 ) );
                    newpixel_views_dashboard_metric( __( 'Mediana por página', 'newpixel' ), number_format_i18n( $pages['median'], 1 ) );
                    newpixel_views_dashboard_metric( __( 'Máximo numa página', 'newpixel' ), number_format_i18n( $pages['maximum'] ) );
                    ?>
                </div>
            </section>
        </div>

        <div class="newpixel-views-columns">
            <section class="newpixel-views-section">
                <h3><?php esc_html_e( 'Artigos mais vistos', 'newpixel' ); ?></h3>
                <?php newpixel_views_dashboard_ranking( $article_rows, __( 'Ainda não existem artigos com visualizações.', 'newpixel' ) ); ?>
            </section>

            <section class="newpixel-views-section">
                <h3><?php esc_html_e( 'Páginas mais vistas', 'newpixel' ); ?></h3>
                <?php newpixel_views_dashboard_ranking( $page_rows, __( 'Ainda não existem páginas com visualizações.', 'newpixel' ) ); ?>
            </section>
        </div>

        <section class="newpixel-views-section">
            <h3><?php esc_html_e( 'Artigos por data de publicação', 'newpixel' ); ?></h3>
            <p class="newpixel-views-section__note"><?php esc_html_e( 'As visualizações são acumuladas; o período refere-se à data de publicação do artigo.', 'newpixel' ); ?></p>
            <div class="newpixel-views-table-wrap">
                <table class="newpixel-views-table">
                    <thead><tr>
                        <th><?php esc_html_e( 'Publicados', 'newpixel' ); ?></th>
                        <th><?php esc_html_e( 'Artigos', 'newpixel' ); ?></th>
                        <th><?php esc_html_e( 'Visualizações', 'newpixel' ); ?></th>
                        <th><?php esc_html_e( 'Média', 'newpixel' ); ?></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ( $cohorts as $days => $cohort ) : ?>
                            <tr>
                                <td><?php echo esc_html( sprintf( __( 'Há até %d dias', 'newpixel' ), $days ) ); ?></td>
                                <td><?php echo esc_html( number_format_i18n( $cohort['count'] ) ); ?></td>
                                <td><?php echo esc_html( number_format_i18n( $cohort['total'] ) ); ?></td>
                                <td><?php echo esc_html( number_format_i18n( $cohort['average'], 1 ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php if ( ! empty( $categories ) ) : ?>
            <section class="newpixel-views-section">
                <h3><?php esc_html_e( 'Desempenho por categoria', 'newpixel' ); ?></h3>
                <p class="newpixel-views-section__note"><?php esc_html_e( 'Um artigo com várias categorias contribui para cada uma delas.', 'newpixel' ); ?></p>
                <div class="newpixel-views-table-wrap">
                    <table class="newpixel-views-table">
                        <thead><tr>
                            <th><?php esc_html_e( 'Categoria', 'newpixel' ); ?></th>
                            <th><?php esc_html_e( 'Artigos', 'newpixel' ); ?></th>
                            <th><?php esc_html_e( 'Visualizações', 'newpixel' ); ?></th>
                            <th><?php esc_html_e( 'Média', 'newpixel' ); ?></th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ( $categories as $category ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $category['name'] ); ?></td>
                                    <td><?php echo esc_html( number_format_i18n( $category['count'] ) ); ?></td>
                                    <td><?php echo esc_html( number_format_i18n( $category['views'] ) ); ?></td>
                                    <td><?php echo esc_html( number_format_i18n( $category['average'], 1 ) ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <?php if ( count( $authors ) > 1 ) : ?>
            <section class="newpixel-views-section">
                <h3><?php esc_html_e( 'Desempenho por autor', 'newpixel' ); ?></h3>
                <div class="newpixel-views-table-wrap">
                    <table class="newpixel-views-table">
                        <thead><tr>
                            <th><?php esc_html_e( 'Autor', 'newpixel' ); ?></th>
                            <th><?php esc_html_e( 'Artigos', 'newpixel' ); ?></th>
                            <th><?php esc_html_e( 'Visualizações', 'newpixel' ); ?></th>
                            <th><?php esc_html_e( 'Média', 'newpixel' ); ?></th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ( $authors as $author ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $author['name'] ); ?></td>
                                    <td><?php echo esc_html( number_format_i18n( $author['count'] ) ); ?></td>
                                    <td><?php echo esc_html( number_format_i18n( $author['views'] ) ); ?></td>
                                    <td><?php echo esc_html( number_format_i18n( $author['average'], 1 ) ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <p class="newpixel-views-footnote"><?php esc_html_e( 'Contadores acumulados e anónimos. Não representam visitantes únicos nem permitem saber em que dia ocorreu cada visualização.', 'newpixel' ); ?></p>
    </div>
    <?php
}

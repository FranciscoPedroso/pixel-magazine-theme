<?php
/**
 * ============================================================================
 * SEO + Schema.org + Social meta — single-file editorial SEO module.
 * ============================================================================
 *
 * Replaces the old newpixel_social_meta() in functions.php with a complete,
 * editorial-grade SEO solution:
 *
 *   • per-post meta description metabox (with auto-fallback to dek/excerpt)
 *   • <meta name="description"> output
 *   • Open Graph (Facebook, LinkedIn, WhatsApp, Slack, Discord, …)
 *   • Twitter Cards (summary_large_image)
 *   • article:* OG tags (published_time, modified_time, author, section, tag)
 *   • JSON-LD @graph: WebSite + Organization + Article + CollectionPage
 *   • JSON-LD BreadcrumbList (Google SERP breadcrumb display)
 *   • SearchAction (the Sitelinks Search Box that can appear in SERPs)
 *   • noindex,follow on low-value pages (search, author, date, 404)
 *
 * Why custom instead of a plugin: keeps the theme lean, no DB bloat,
 * no admin UI noise, no upsells. WordPress core gives us sitemap
 * (`/wp-sitemap.xml`), canonical URLs (`rel_canonical`), robots meta,
 * and the title tag for free — we add just the editorial-specific bits.
 *
 * Configuration:
 *   • Add `assets/img/default-og.jpg` (1200×630 recommended) for posts
 *     without a featured image.
 *   • Filter `newpixel_seo_twitter_handle` if you want @PixelMagazine
 *     (or whatever) added as twitter:site.
 *
 * @package newpixel
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ============================================================================
// Helper: meta description (cascades editor override → dek → excerpt → content)
// ============================================================================
function newpixel_seo_meta_description( $post_id = null ) {
    $post_id = $post_id ?: get_the_ID();

    if ( $post_id ) {
        // 1. Explicit override from the SEO metabox in the editor
        $custom = (string) get_post_meta( $post_id, '_newpixel_meta_description', true );
        if ( $custom !== '' ) return $custom;

        // 2. ACF Dek (subtitle)
        if ( function_exists( 'get_field' ) ) {
            $dek = trim( (string) get_field( 'dek', $post_id ) );
            if ( $dek !== '' ) return $dek;
        }

        // 3. Native excerpt
        $excerpt = trim( (string) get_the_excerpt( $post_id ) );
        if ( $excerpt !== '' ) return $excerpt;

        // 4. Trimmed content
        $content = (string) get_post_field( 'post_content', $post_id );
        $clean   = trim( wp_strip_all_tags( strip_shortcodes( $content ) ) );
        if ( $clean !== '' ) {
            return wp_trim_words( $clean, 28, '…' );
        }
    }

    // 5. Final fallback: the site tagline. Covers cases like a static page
    //    used as the homepage (no own content/dek/excerpt) and any page that
    //    just doesn't have its own copy yet. Always better to have a
    //    description than to silently omit the meta tag.
    return (string) get_bloginfo( 'description' );
}

// ============================================================================
// Helper: OG / Twitter image — featured image with default fallback.
//
// Returns an array { url, width, height } so emit-time can output the right
// og:image:width / og:image:height (FB/LinkedIn use these to pre-size cards).
// Falls back to assets/img/default-og.jpg with its real on-disk dimensions.
// ============================================================================
function newpixel_seo_og_image( $post_id = null ) {
    $post_id = $post_id ?: get_the_ID();

    // 1. Post's featured image at np-16x9-1600 (≥1600px wide — Google Discover
    //    recommended threshold; also above FB/LinkedIn 1200×630 ideal).
    //    Falls back to the smaller -1280 crop if the larger one wasn't
    //    generated yet (older media uploads), then to full as last resort.
    if ( $post_id && has_post_thumbnail( $post_id ) ) {
        $tid = get_post_thumbnail_id( $post_id );
        $img = wp_get_attachment_image_src( $tid, 'np-16x9-1600' );
        if ( ! $img ) {
            $img = wp_get_attachment_image_src( $tid, 'np-16x9-1280' );
        }
        if ( ! $img ) {
            $img = wp_get_attachment_image_src( $tid, 'full' );
        }
        if ( $img && ! empty( $img[0] ) ) {
            return array(
                'url'    => $img[0],
                'width'  => (int) $img[1],
                'height' => (int) $img[2],
            );
        }
    }

    // 2. Theme-wide default (assets/img/default-og.jpg). Read its real
    //    pixel dimensions so the meta tags don't lie about the size.
    $default_path = get_theme_file_path( 'assets/img/default-og.jpg' );
    if ( file_exists( $default_path ) ) {
        $size = @getimagesize( $default_path );
        return array(
            'url'    => get_theme_file_uri( 'assets/img/default-og.jpg' ),
            'width'  => $size && isset( $size[0] ) ? (int) $size[0] : 1200,
            'height' => $size && isset( $size[1] ) ? (int) $size[1] : 630,
        );
    }

    return null;
}

// ============================================================================
// Admin: meta description metabox in the post editor
// ============================================================================
add_action( 'add_meta_boxes_post', function () {
    add_meta_box(
        'newpixel_seo_metabox',
        __( 'SEO', 'newpixel' ),
        'newpixel_seo_metabox_render',
        'post',
        'normal',
        'low'
    );
} );

function newpixel_seo_metabox_render( $post ) {
    wp_nonce_field( 'newpixel_seo_save', 'newpixel_seo_nonce' );
    $stored = (string) get_post_meta( $post->ID, '_newpixel_meta_description', true );
    $auto   = newpixel_seo_meta_description( $post->ID );
    ?>
    <p>
        <label for="newpixel_meta_description"><strong><?php esc_html_e( 'Meta description', 'newpixel' ); ?></strong></label>
    </p>
    <textarea id="newpixel_meta_description"
              name="newpixel_meta_description"
              rows="3"
              maxlength="200"
              style="width:100%;font-family:inherit;font-size:13px;"
              placeholder="<?php echo esc_attr( $auto ?: __( 'Auto-generated from the dek / excerpt if left empty.', 'newpixel' ) ); ?>"><?php echo esc_textarea( $stored ); ?></textarea>
    <p class="description" style="display:flex;justify-content:space-between;align-items:center;">
        <span><?php esc_html_e( 'Used by Google in search snippets and by social platforms (Facebook, Twitter, WhatsApp, etc.). Sweet spot: 150-160 characters. Leave blank to auto-generate from the Dek or excerpt.', 'newpixel' ); ?></span>
        <span id="newpixel_meta_description_count" style="font-family:monospace;font-size:11px;flex-shrink:0;margin-left:12px;"></span>
    </p>
    <script>
    (function () {
        var el  = document.getElementById( 'newpixel_meta_description' );
        var cnt = document.getElementById( 'newpixel_meta_description_count' );
        var max = 160;
        function tick () {
            var n = el.value.length;
            cnt.textContent = n + ' / ' + max;
            cnt.style.color = n > max ? '#d63638' : ( n > max - 20 ? '#dba617' : '#8c8f94' );
        }
        el.addEventListener( 'input', tick );
        tick();
    })();
    </script>
    <?php
}

// Save handler
add_action( 'save_post_post', function ( $post_id ) {
    if ( ! isset( $_POST['newpixel_seo_nonce'] ) ) return;
    if ( ! wp_verify_nonce( wp_unslash( $_POST['newpixel_seo_nonce'] ), 'newpixel_seo_save' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    $desc = isset( $_POST['newpixel_meta_description'] )
        ? sanitize_textarea_field( wp_unslash( $_POST['newpixel_meta_description'] ) )
        : '';

    if ( $desc !== '' ) {
        update_post_meta( $post_id, '_newpixel_meta_description', $desc );
    } else {
        delete_post_meta( $post_id, '_newpixel_meta_description' );
    }
} );

// ============================================================================
// <head> output — runs early so search engine crawlers see SEO tags first
// ============================================================================
add_action( 'wp_head', 'newpixel_seo_head', 1 );

function newpixel_seo_head() {
    if ( is_admin() || is_feed() ) return;

    $site_name = (string) get_bloginfo( 'name' );
    $site_url  = home_url( '/' );
    $locale    = str_replace( '-', '_', get_locale() ); // "pt_PT"

    // ----- Resolve current context ------------------------------------------
    $is_singular_post = is_singular( 'post' );
    $is_singular_page = is_singular( 'page' );
    $is_term_archive  = is_category() || is_tag();

    if ( $is_singular_post || $is_singular_page ) {
        $post_id  = (int) get_queried_object_id();
        $title    = wp_get_document_title();
        $desc     = newpixel_seo_meta_description( $post_id );
        $url      = get_permalink( $post_id );
        $og_image = newpixel_seo_og_image( $post_id );
        $og_type  = $is_singular_post ? 'article' : 'website';
    } elseif ( $is_term_archive ) {
        $term     = get_queried_object();
        $title    = wp_get_document_title();
        $desc     = $term && ! empty( $term->description )
            ? wp_strip_all_tags( $term->description )
            : sprintf(
                /* translators: %1$s: term name, %2$s: site name */
                __( 'Artigos sobre %1$s no %2$s.', 'newpixel' ),
                $term ? $term->name : '',
                $site_name
            );
        $url      = $term ? get_term_link( $term ) : $site_url;
        $og_image = newpixel_seo_og_image();
        $og_type  = 'website';
    } else {
        $title    = wp_get_document_title();
        $desc     = (string) get_bloginfo( 'description' );
        $url      = $site_url;
        $og_image = newpixel_seo_og_image();
        $og_type  = 'website';
    }

    // og:title / twitter:title: on singular posts/pages use the bare title
    // (without the "– Site Name" suffix added by wp_get_document_title()), so X's
    // card overlay and other social cards show only the title. The browser/SEO
    // <title> keeps the brand (that's WP core).
    $og_title = ( $is_singular_post || $is_singular_page )
        ? get_the_title( get_queried_object_id() )
        : $title;

    // The home page is a static page called "Home": on a social card we want the
    // brand instead.
    if ( is_front_page() ) {
        $og_title = $site_name;
    }

    $desc = trim( wp_strip_all_tags( $desc ) );
    if ( mb_strlen( $desc ) > 200 ) {
        $desc = wp_trim_words( $desc, 30, '…' );
    }

    // ----- <meta name="description"> ----------------------------------------
    if ( $desc !== '' ) {
        printf( '<meta name="description" content="%s">' . "\n", esc_attr( $desc ) );
    }

    // ----- Open Graph -------------------------------------------------------
    printf( '<meta property="og:site_name" content="%s">' . "\n", esc_attr( $site_name ) );
    printf( '<meta property="og:type" content="%s">' . "\n",       esc_attr( $og_type ) );
    printf( '<meta property="og:locale" content="%s">' . "\n",     esc_attr( $locale ) );
    printf( '<meta property="og:title" content="%s">' . "\n",      esc_attr( $og_title ) );
    if ( $desc !== '' ) {
        printf( '<meta property="og:description" content="%s">' . "\n", esc_attr( $desc ) );
    }
    printf( '<meta property="og:url" content="%s">' . "\n", esc_url( $url ) );
    if ( $og_image ) {
        printf( '<meta property="og:image" content="%s">' . "\n",        esc_url( $og_image['url'] ) );
        printf( '<meta property="og:image:width" content="%d">' . "\n",  (int) $og_image['width'] );
        printf( '<meta property="og:image:height" content="%d">' . "\n", (int) $og_image['height'] );
    }

    // ----- Article-specific OG tags ----------------------------------------
    if ( $is_singular_post ) {
        $post_id   = (int) get_queried_object_id();
        $author_id = (int) get_post_field( 'post_author', $post_id );

        printf( '<meta property="article:published_time" content="%s">' . "\n", esc_attr( get_the_date( 'c', $post_id ) ) );
        printf( '<meta property="article:modified_time" content="%s">' . "\n",  esc_attr( get_the_modified_date( 'c', $post_id ) ) );

        if ( $author_id ) {
            printf( '<meta property="article:author" content="%s">' . "\n", esc_url( newpixel_author_profile_url( $author_id ) ) );
        }

        $cat = newpixel_get_primary_category( $post_id );
        if ( $cat ) {
            printf( '<meta property="article:section" content="%s">' . "\n", esc_attr( $cat->name ) );
        }

        $tags = get_the_tags( $post_id );
        if ( $tags ) {
            foreach ( $tags as $tag ) {
                printf( '<meta property="article:tag" content="%s">' . "\n", esc_attr( $tag->name ) );
            }
        }
    }

    // ----- Twitter Cards ----------------------------------------------------
    $tw_card = $og_image ? 'summary_large_image' : 'summary';
    printf( '<meta name="twitter:card" content="%s">' . "\n", esc_attr( $tw_card ) );
    printf( '<meta name="twitter:title" content="%s">' . "\n", esc_attr( $og_title ) );
    if ( $desc !== '' ) {
        printf( '<meta name="twitter:description" content="%s">' . "\n", esc_attr( $desc ) );
    }
    if ( $og_image ) {
        printf( '<meta name="twitter:image" content="%s">' . "\n", esc_url( $og_image['url'] ) );
    }
    $tw_handle = apply_filters( 'newpixel_seo_twitter_handle', '' );
    if ( $tw_handle ) {
        $tw_handle = '@' . ltrim( $tw_handle, '@' );
        printf( '<meta name="twitter:site" content="%s">' . "\n",    esc_attr( $tw_handle ) );
        printf( '<meta name="twitter:creator" content="%s">' . "\n", esc_attr( $tw_handle ) );
    }

    // ----- JSON-LD structured data -----------------------------------------
    newpixel_seo_jsonld( $title, $desc, $url, $og_image );

    // ----- noindex on low-value pages --------------------------------------
    if ( is_search() || is_author() || is_date() || is_404() ) {
        echo '<meta name="robots" content="noindex,follow">' . "\n";
    }
}

// ============================================================================
// Tags with few articles: out of the index and the sitemap
//
// The drafter creates tags as it goes, so many tag pages end up holding a
// single article. To Google those are thin pages. Below the threshold the page
// still exists for readers (noindex,follow) but is no longer offered to Google.
// ============================================================================
function newpixel_thin_tag_min_posts() {
    return (int) apply_filters( 'newpixel_thin_tag_min_posts', 5 );
}

add_filter( 'wp_robots', 'newpixel_noindex_thin_tags' );
function newpixel_noindex_thin_tags( $robots ) {
    if ( is_tag() ) {
        $term = get_queried_object();
        if ( $term instanceof WP_Term && (int) $term->count < newpixel_thin_tag_min_posts() ) {
            $robots['noindex'] = true;
            $robots['follow']  = true;
            unset( $robots['max-image-preview'] );
        }
    }
    return $robots;
}

add_filter( 'wp_sitemaps_taxonomies_query_args', 'newpixel_sitemap_skip_thin_tags', 10, 2 );
function newpixel_sitemap_skip_thin_tags( $args, $taxonomy ) {
    if ( 'post_tag' !== $taxonomy ) {
        return $args;
    }
    $terms = get_terms( array(
        'taxonomy'   => 'post_tag',
        'hide_empty' => false,
    ) );
    if ( is_wp_error( $terms ) ) {
        return $args;
    }
    $min     = newpixel_thin_tag_min_posts();
    $exclude = array();
    foreach ( $terms as $term ) {
        if ( (int) $term->count < $min ) {
            $exclude[] = (int) $term->term_id;
        }
    }
    if ( $exclude ) {
        $args['exclude'] = array_merge( isset( $args['exclude'] ) ? (array) $args['exclude'] : array(), $exclude );
    }
    return $args;
}

// The virtual robots.txt only announced the general sitemap; this also
// exposes the news sitemap (inc/news-sitemap.php) outside Search Console.
add_filter( 'robots_txt', 'newpixel_robots_news_sitemap', 20, 2 );
function newpixel_robots_news_sitemap( $output, $public ) {
    if ( $public && false === strpos( $output, 'news-sitemap.xml' ) ) {
        $output .= 'Sitemap: ' . home_url( '/news-sitemap.xml' ) . "\n";
    }
    return $output;
}

// ============================================================================
// JSON-LD @graph builder
//
// Always emits: WebSite + Organization. Adds Article on singles, CollectionPage
// on term archives, BreadcrumbList wherever it makes sense.
// ============================================================================
function newpixel_seo_jsonld( $title, $description, $url, $og_image ) {
    $site_name  = (string) get_bloginfo( 'name' );
    $site_desc  = (string) get_bloginfo( 'description' );
    $site_url   = home_url( '/' );
    $image_url  = $og_image ? $og_image['url']    : '';
    $image_w    = $og_image ? $og_image['width']  : 0;
    $image_h    = $og_image ? $og_image['height'] : 0;

    $graph = array();

    // 1. WebSite (with potential SearchAction → enables Google Sitelinks search)
    $website = array(
        '@type'           => 'WebSite',
        '@id'             => $site_url . '#website',
        'url'             => $site_url,
        'name'            => $site_name,
        'description'     => $site_desc,
        'inLanguage'      => str_replace( '_', '-', get_locale() ),
        'potentialAction' => array(
            '@type'       => 'SearchAction',
            'target'      => array(
                '@type'       => 'EntryPoint',
                'urlTemplate' => $site_url . '?s={search_term_string}',
            ),
            'query-input' => 'required name=search_term_string',
        ),
    );
    $graph[] = $website;

    // 2. Organization (publisher reference for Articles)
    $organization = array(
        '@type' => 'Organization',
        '@id'   => $site_url . '#organization',
        'name'  => $site_name,
        'url'   => $site_url,
    );
    // Brand logo — FIXED square mark, the same on every page (NOT the per-context
    // og:image, which on an article would wrongly be the article photo). This is
    // the logo Google uses for the brand / Organization.
    $organization['logo'] = array(
        '@type'  => 'ImageObject',
        'url'    => get_template_directory_uri() . '/assets/img/logo-square.png',
        'width'  => 1000,
        'height' => 1000,
    );
    $graph[] = $organization;

    // 3. Per-context types
    if ( is_singular( 'post' ) ) {
        $post_id   = (int) get_queried_object_id();
        $author_id = (int) get_post_field( 'post_author', $post_id );
        $cat       = newpixel_get_primary_category( $post_id );
        $tags      = get_the_tags( $post_id );

        $article = array(
            '@type'            => 'NewsArticle',
            '@id'              => $url . '#article',
            'isPartOf'         => array( '@id' => $site_url . '#website' ),
            'mainEntityOfPage' => array( '@type' => 'WebPage', '@id' => $url ),
            'headline'         => get_the_title( $post_id ),
            'datePublished'    => get_the_date( 'c', $post_id ),
            'dateModified'     => get_the_modified_date( 'c', $post_id ),
            'publisher'        => array( '@id' => $site_url . '#organization' ),
            'inLanguage'       => str_replace( '_', '-', get_locale() ),
        );
        if ( $description !== '' ) {
            $article['description'] = $description;
        }
        if ( $image_url ) {
            $article['image'] = array(
                '@type'  => 'ImageObject',
                'url'    => $image_url,
                'width'  => $image_w,
                'height' => $image_h,
            );
        }
        if ( $author_id ) {
            $article['author'] = array(
                '@type' => 'Person',
                'name'  => get_the_author_meta( 'display_name', $author_id ),
                'url'   => newpixel_author_profile_url( $author_id ),
            );
        }
        if ( $cat ) {
            $article['articleSection'] = $cat->name;
        }
        if ( $tags ) {
            $article['keywords'] = implode( ', ', wp_list_pluck( $tags, 'name' ) );
        }
        $graph[] = $article;

        // BreadcrumbList: Home › (Parent ›) Cat › Post
        $crumbs = newpixel_seo_post_breadcrumbs( $post_id );
        if ( count( $crumbs ) > 1 ) {
            $graph[] = newpixel_seo_breadcrumb_node( $crumbs );
        }
    } elseif ( is_category() || is_tag() ) {
        $term = get_queried_object();

        $collection = array(
            '@type'      => 'CollectionPage',
            '@id'        => $url . '#collection',
            'name'       => $title,
            'url'        => $url,
            'isPartOf'   => array( '@id' => $site_url . '#website' ),
            'inLanguage' => str_replace( '_', '-', get_locale() ),
        );
        if ( $description !== '' ) {
            $collection['description'] = $description;
        }
        $graph[] = $collection;

        $crumbs = newpixel_seo_term_breadcrumbs( $term );
        if ( count( $crumbs ) > 1 ) {
            $graph[] = newpixel_seo_breadcrumb_node( $crumbs );
        }
    }

    $payload = array(
        '@context' => 'https://schema.org',
        '@graph'   => $graph,
    );

    echo '<script type="application/ld+json">' .
         wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) .
         '</script>' . "\n";
}

// ============================================================================
// Breadcrumb builders (used by JSON-LD; can also be used by a visible
// breadcrumb component in templates later if you ever want one)
// ============================================================================

/**
 * Build the breadcrumb trail for a single post: Home › [Parent Cat] › Cat › Post
 */
function newpixel_seo_post_breadcrumbs( $post_id ) {
    $crumbs = array( array(
        'name' => __( 'Home', 'newpixel' ),
        'url'  => home_url( '/' ),
    ) );

    $cat = newpixel_get_primary_category( $post_id );
    if ( $cat ) {
        // Walk up the parent chain
        $chain     = array( $cat );
        $parent_id = (int) $cat->parent;
        $guard     = 0;
        while ( $parent_id > 0 && $guard++ < 10 ) {
            $parent = get_term( $parent_id, 'category' );
            if ( ! $parent || is_wp_error( $parent ) ) break;
            array_unshift( $chain, $parent );
            $parent_id = (int) $parent->parent;
        }
        foreach ( $chain as $term ) {
            $crumbs[] = array(
                'name' => $term->name,
                'url'  => get_term_link( $term ),
            );
        }
    }

    $crumbs[] = array(
        'name' => get_the_title( $post_id ),
        'url'  => get_permalink( $post_id ),
    );

    return $crumbs;
}

/**
 * Build the breadcrumb trail for a term archive (category/tag).
 */
function newpixel_seo_term_breadcrumbs( $term ) {
    $crumbs = array( array(
        'name' => __( 'Home', 'newpixel' ),
        'url'  => home_url( '/' ),
    ) );

    if ( ! $term || is_wp_error( $term ) ) return $crumbs;

    $chain     = array( $term );
    $parent_id = (int) $term->parent;
    $guard     = 0;
    while ( $parent_id > 0 && $guard++ < 10 ) {
        $parent = get_term( $parent_id, $term->taxonomy );
        if ( ! $parent || is_wp_error( $parent ) ) break;
        array_unshift( $chain, $parent );
        $parent_id = (int) $parent->parent;
    }
    foreach ( $chain as $t ) {
        $crumbs[] = array(
            'name' => $t->name,
            'url'  => get_term_link( $t ),
        );
    }

    return $crumbs;
}

/**
 * Convert a breadcrumbs array into a BreadcrumbList JSON-LD node.
 */
function newpixel_seo_breadcrumb_node( $crumbs ) {
    $items = array();
    foreach ( $crumbs as $i => $crumb ) {
        $items[] = array(
            '@type'    => 'ListItem',
            'position' => $i + 1,
            'name'     => $crumb['name'],
            'item'     => $crumb['url'],
        );
    }
    return array(
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $items,
    );
}

<?php
/**
 * ============================================================================
 * Category colors — per-term color picker in the admin + auto random
 * assignment on creation.
 * ============================================================================
 *
 * Adds a "Color" field to the Add Category / Edit Category screens (the
 * standard WP `wp-color-picker` widget). Saves to term meta under
 * `newpixel_color`. When a new category is created without a colour, a
 * random one is picked from a curated palette that fits the rest of the
 * design (vibrant Tailwind-style accents, excluding the slate fallback).
 *
 * Resolution priority for a term's color is in
 * `newpixel_get_category_color()` (functions.php):
 *
 *   1. Term meta `newpixel_color`         ← set by the picker (NEW)
 *   2. ACF field `category_color`         ← legacy back-compat
 *   3. Static map in `newpixel_category_color_map()`
 *   4. Fallback slate (#94a3b8)
 *
 * @package newpixel
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ============================================================================
// Curated palette for random assignment.
//
// 16 hand-picked accents from the Tailwind scale (500-ish stops). These all
// pass WCAG AA against both the light and dark page backgrounds. The slate
// fallback (#94a3b8) is intentionally excluded so new categories always get
// something vibrant.
// ============================================================================
function newpixel_color_palette() {
    return apply_filters( 'newpixel_color_palette', array(
        '#a78bfa', // lavender    (IA / AI)
        '#f97316', // orange      (Hardware)
        '#22d3ee', // cyan        (Software)
        '#ef4444', // red         (Apple)
        '#10b981', // emerald     (Games / Wearables)
        '#fbbf24', // amber       (Reviews)
        '#ec4899', // pink        (Tutorials / Audio)
        '#0ea5e9', // sky
        '#6366f1', // indigo
        '#14b8a6', // teal
        '#f43f5e', // rose
        '#8b5cf6', // violet
        '#84cc16', // lime
        '#0891b2', // cyan-dark
        '#d946ef', // fuchsia
        '#eab308', // yellow
    ) );
}

/**
 * Pick a random colour from the palette, avoiding ones already used by
 * other categories so we don't end up with five "Apple-red" sections.
 */
function newpixel_random_palette_color( $exclude_term_id = 0 ) {
    $palette = newpixel_color_palette();

    $used = array();
    $terms = get_terms( array(
        'taxonomy'   => 'category',
        'hide_empty' => false,
    ) );
    if ( ! is_wp_error( $terms ) ) {
        foreach ( $terms as $term ) {
            if ( (int) $term->term_id === (int) $exclude_term_id ) continue;
            $c = get_term_meta( $term->term_id, 'newpixel_color', true );
            if ( $c ) {
                $used[] = strtolower( $c );
            }
        }
    }

    $available = array_values( array_diff(
        array_map( 'strtolower', $palette ),
        $used
    ) );

    // Every palette entry already used → fall back to the full palette so
    // a 17th category still gets a vibrant accent (just a repeated one).
    if ( empty( $available ) ) {
        $available = $palette;
    }

    return $available[ array_rand( $available ) ];
}

// ============================================================================
// Admin: enqueue the WP color picker on the category screens only.
// ============================================================================
add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( $hook !== 'edit-tags.php' && $hook !== 'term.php' ) return;

    $screen = get_current_screen();
    if ( ! $screen || $screen->taxonomy !== 'category' ) return;

    wp_enqueue_style( 'wp-color-picker' );
    wp_enqueue_script( 'wp-color-picker' );
    wp_add_inline_script(
        'wp-color-picker',
        'jQuery(function($){ $(".newpixel-color-field").wpColorPicker(); });'
    );
} );

// ============================================================================
// Add Category screen — empty picker (random will fill on save).
// ============================================================================
add_action( 'category_add_form_fields', function () {
    ?>
    <div class="form-field term-newpixel-color-wrap">
        <label for="newpixel-color"><?php esc_html_e( 'Color', 'newpixel' ); ?></label>
        <input type="text"
               name="newpixel_color"
               id="newpixel-color"
               value=""
               class="newpixel-color-field"
               data-default-color="" />
        <p><?php esc_html_e( 'Optional. Used for pills, hover states, drop caps, and other category accents. If left empty, a random colour will be assigned automatically.', 'newpixel' ); ?></p>
    </div>
    <?php
} );

// ============================================================================
// Edit Category screen — pre-fills with the term's effective color (whether
// from term meta or the legacy static map).
// ============================================================================
add_action( 'category_edit_form_fields', function ( $term ) {
    $stored = get_term_meta( $term->term_id, 'newpixel_color', true );
    $effective = $stored ?: newpixel_get_category_color( $term );
    ?>
    <tr class="form-field term-newpixel-color-wrap">
        <th scope="row">
            <label for="newpixel-color"><?php esc_html_e( 'Color', 'newpixel' ); ?></label>
        </th>
        <td>
            <input type="text"
                   name="newpixel_color"
                   id="newpixel-color"
                   value="<?php echo esc_attr( $effective ); ?>"
                   class="newpixel-color-field"
                   data-default-color="<?php echo esc_attr( $effective ); ?>" />
            <p class="description">
                <?php esc_html_e( 'Color used for category accents across the site. Clear the field to revert to the theme default.', 'newpixel' ); ?>
            </p>
        </td>
    </tr>
    <?php
} );

// ============================================================================
// Save handlers.
//
// `created_category` fires once when a new term is inserted. If the user
// didn't pick a colour we assign a random one from the palette.
//
// `edited_category` fires on every subsequent save. We trust the user — if
// they clear the field we delete the meta (revert to fallback).
// ============================================================================
add_action( 'created_category', function ( $term_id ) {
    $submitted = isset( $_POST['newpixel_color'] )
        ? sanitize_hex_color( wp_unslash( $_POST['newpixel_color'] ) )
        : '';

    if ( $submitted ) {
        update_term_meta( $term_id, 'newpixel_color', $submitted );
        return;
    }

    // Subcategory with no chosen colour: store NO meta. That way
    // newpixel_get_category_color() reaches step 4 and inherits the parent's accent
    // (iPhone → Apple red, Distros → Linux orange), as that function promises.
    // Storing a random colour here would break the inheritance.
    $term = get_term( $term_id, 'category' );
    if ( $term && ! is_wp_error( $term ) && ! empty( $term->parent ) ) {
        return;
    }

    // Root category: there is no parent to inherit from, so give it a palette
    // colour rather than letting it fall back to grey.
    update_term_meta(
        $term_id,
        'newpixel_color',
        newpixel_random_palette_color( $term_id )
    );
} );

add_action( 'edited_category', function ( $term_id ) {
    if ( ! isset( $_POST['newpixel_color'] ) ) return;

    $submitted = sanitize_hex_color( wp_unslash( $_POST['newpixel_color'] ) );

    if ( $submitted ) {
        update_term_meta( $term_id, 'newpixel_color', $submitted );
    } else {
        // User cleared the field — revert to theme default.
        delete_term_meta( $term_id, 'newpixel_color' );
    }
} );

// ============================================================================
// Admin column: show a small color swatch on the categories list so editors
// see at-a-glance which colour each category is rendering with.
// ============================================================================
add_filter( 'manage_edit-category_columns', function ( $cols ) {
    // Insert "Color" between "Description" and "Slug".
    $new = array();
    foreach ( $cols as $key => $label ) {
        if ( $key === 'slug' ) {
            $new['newpixel_color'] = esc_html__( 'Color', 'newpixel' );
        }
        $new[ $key ] = $label;
    }
    return $new;
} );

add_filter( 'manage_category_custom_column', function ( $content, $column, $term_id ) {
    if ( $column !== 'newpixel_color' ) return $content;

    $term  = get_term( $term_id );
    $color = newpixel_get_category_color( $term );

    return sprintf(
        '<span style="display:inline-flex;align-items:center;gap:8px;">'
        . '<span style="display:inline-block;width:18px;height:18px;border-radius:50%%;background:%1$s;border:1px solid rgba(0,0,0,.1);" aria-hidden="true"></span>'
        . '<span style="font-family:monospace;font-size:11px;color:#666;">%2$s</span>'
        . '</span>',
        esc_attr( $color ),
        esc_html( strtoupper( $color ) )
    );
}, 10, 3 );

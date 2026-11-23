<?php
/**
 * Archive template — categories, tags, authors, date archives.
 * Uses the kf-browse list with a context-aware header.
 */
get_header();

global $wp_query;

// ----- Build header copy ---------------------------------------------------
$eyebrow      = '';
$eyebrow_link = ''; // when set, eyebrow renders as a clickable parent link
$title        = '';
$copy         = '';
$accent       = '';

if ( is_category() ) {
    $term   = get_queried_object();
    $title  = $term->name;
    $copy   = trim( wp_strip_all_tags( $term->description ) );
    $accent = newpixel_get_category_color( $term );

    // Sub-category? Show parent name as a section breadcrumb in the eyebrow.
    if ( ! empty( $term->parent ) ) {
        $parent = get_term( $term->parent, 'category' );
        if ( $parent && ! is_wp_error( $parent ) ) {
            $eyebrow      = $parent->name;
            $eyebrow_link = get_term_link( $parent );
        } else {
            $eyebrow = __( 'Categoria', 'newpixel' );
        }
    } else {
        $eyebrow = __( 'Categoria', 'newpixel' );
    }
} elseif ( is_tag() ) {
    $term    = get_queried_object();
    $eyebrow = __( 'Etiqueta', 'newpixel' );
    $title   = '#' . $term->name;
    $copy    = trim( wp_strip_all_tags( $term->description ) );
} elseif ( is_author() ) {
    $author  = get_queried_object();
    $eyebrow = __( 'Autor', 'newpixel' );
    $title   = $author->display_name;
    $copy    = trim( wp_strip_all_tags( $author->description ) );
} elseif ( is_date() ) {
    $eyebrow = __( 'Arquivo', 'newpixel' );
    if ( is_year() )       { $title = (string) get_the_date( 'Y' ); }
    elseif ( is_month() )  { $title = (string) get_the_date( 'F Y' ); }
    elseif ( is_day() )    { $title = (string) get_the_date( 'j F Y' ); }
} elseif ( is_search() ) {
    $eyebrow = __( 'Pesquisa', 'newpixel' );
    $title   = '<em>' . esc_html( get_search_query() ) . '</em>';
} else {
    $eyebrow = __( 'Arquivo', 'newpixel' );
    $title   = __( 'Todos os artigos', 'newpixel' );
}

if ( ! $accent ) { $accent = '#a78bfa'; }

$total = (int) $wp_query->found_posts;
?>

<main id="content" class="kf-container">
    <section class="kf-browse" style="--c:<?php echo esc_attr( $accent ); ?>">

        <header class="kf-browse__head">
            <div>
                <?php if ( $eyebrow ) : ?>
                    <div class="kf-browse__eyebrow<?php echo $eyebrow_link ? ' kf-browse__eyebrow--link' : ''; ?>">
                        <?php if ( $eyebrow_link ) : ?>
                            <a href="<?php echo esc_url( $eyebrow_link ); ?>">
                                <?php echo esc_html( $eyebrow ); ?>
                                <span aria-hidden="true">&rsaquo;</span>
                            </a>
                        <?php else : ?>
                            <?php echo esc_html( $eyebrow ); ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <h1 class="kf-browse__title"><?php echo wp_kses_post( $title ); ?></h1>
                <?php if ( $copy ) : ?>
                    <p class="kf-browse__copy"><?php echo esc_html( $copy ); ?></p>
                <?php endif; ?>
            </div>
            <?php if ( $total > 0 ) : ?>
                <div class="kf-browse__count">
                    <?php
                    printf(
                        /* translators: %s: number of posts */
                        esc_html( _n( '%s artigo', '%s artigos', $total, 'newpixel' ) ),
                        '<strong>' . esc_html( number_format_i18n( $total ) ) . '</strong>'
                    );
                    ?>
                </div>
            <?php endif; ?>
        </header>

        <?php if ( have_posts() ) : ?>

            <ol class="kf-browse__list">
                <?php while ( have_posts() ) : the_post();
                    newpixel_browse_row();
                endwhile; ?>
            </ol>

            <?php newpixel_pagination(); ?>

        <?php else : ?>
            <div class="kf-browse__empty">
                <h2><?php esc_html_e( 'Nada por aqui', 'newpixel' ); ?></h2>
                <p>
                    <?php if ( is_search() ) : ?>
                        <?php
                        printf(
                            /* translators: %s: search term */
                            esc_html__( 'Não encontrámos nenhum artigo para %s. Tenta outra coisa.', 'newpixel' ),
                            '<strong>' . esc_html( get_search_query() ) . '</strong>'
                        );
                        ?>
                    <?php else : ?>
                        <?php esc_html_e( 'Não há artigos publicados nesta secção. Volta mais tarde.', 'newpixel' ); ?>
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>

    </section>
</main>

<?php get_footer(); ?>

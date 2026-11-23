<?php
/**
 * Default page template — used for standalone pages like Política de
 * Privacidade, Sobre, Contactos, Termos, etc.
 *
 * Keeps the editorial typography of `single.php` (same `.kf-article`
 * styles for title + prose) but drops everything article-specific:
 * no category pill, no byline, no hero, no share toolbar, no comments.
 * Just a clean centred column for long-form static content.
 *
 * Width is capped to ~720px via the existing `.kf-article` SCSS rules,
 * matching the comfortable reading measure used across the site.
 *
 * The home page (`page_on_front`) is rendered by `front-page.php`, which
 * takes priority over this template — so this only fires for "normal"
 * standalone pages.
 */

get_header();

while ( have_posts() ) : the_post(); ?>

<main id="content" class="kf-single kf-single--page">

    <article id="post-<?php the_ID(); ?>" <?php post_class( 'kf-article kf-article--page' ); ?>>

        <h1 class="kf-article__title"><?php the_title(); ?></h1>

        <!--
        `.prose` is the same class single.php uses around the_content() —
        it provides Newsreader body, headline spacing, and underlined
        inline links. The drop cap declared on `.prose > p:first-of-type`
        is scoped in _article.scss to skip when the article wrapper has
        `kf-article--page`, so static pages don't get a dramatic capital
        on a short opening paragraph.
        -->
        <div class="prose">
            <?php the_content(); ?>
        </div>

    </article>

</main>

<?php endwhile; ?>

<?php get_footer();

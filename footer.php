<!-- ============================================================ -->
<!-- FOOTER                                                        -->
<!-- ============================================================ -->
<footer class="site-footer">
    <div class="kf-container">

        <div class="site-footer__grid">

            <div class="site-footer__col site-footer__brand">
                <a class="site-brand site-brand--footer" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php esc_attr_e( 'PIXEL Magazine', 'newpixel' ); ?>">
                    <?php newpixel_logo_svg( 'kf-logo--footer' ); ?>
                </a>
                <p><?php echo newpixel_manifesto(); ?>.</p>
            </div>

            <div class="site-footer__col">
                <h5>Navegar</h5>
                <?php
                if ( has_nav_menu( 'footer-links' ) ) {
                    wp_nav_menu( array(
                        'theme_location' => 'footer-links',
                        'container'      => false,
                        'menu_class'     => '',
                        'fallback_cb'    => false,
                        'depth'          => 1,
                    ) );
                } else {
                    ?>
                    <ul>
                        <li><a href="<?php echo esc_url( home_url( '/sobre-nos' ) ); ?>">Sobre nós</a></li>
                        <li><a href="<?php echo esc_url( home_url( '/contacto' ) ); ?>">Contacto</a></li>
                        <li><a href="<?php echo esc_url( home_url( '/politica-de-privacidade' ) ); ?>">Privacidade</a></li>
                        <li><a href="<?php echo esc_url( home_url( '/politica-de-cookies' ) ); ?>">Cookies</a></li>
                    </ul>
                    <?php
                }
                ?>
            </div>

            <div class="site-footer__col">
                <h5>Seguir</h5>
                <ul>
                    <li><a href="https://twitter.com/pixelmagazinept" target="_blank" rel="noopener">X / Twitter</a></li>
                    <li><a href="https://bsky.app/profile/pixelmagazine.pt" target="_blank" rel="noopener">Bluesky</a></li>
                    <li><a href="<?php bloginfo( 'rss2_url' ); ?>">RSS Feed</a></li>
                    <li><a href="mailto:geral@pixelmagazine.pt">Email</a></li>
                </ul>
            </div>

        </div>

        <div class="site-footer__bottom">
            <span>Pixel Magazine &middot; <?php echo esc_html( current_time( 'Y' ) ); ?> &middot; Feito em Portugal</span>
        </div>

    </div>
</footer>
<?php wp_footer(); ?>
</body>
</html>

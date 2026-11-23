<?php
/**
 * Newsletter HTML template — body of the email.
 *
 * NOT loaded directly. Called via `ob_start()` + `include` from
 * `newpixel_newsletter_render_html()` in newsletter-template.php.
 * That function provides `$data` and the various derived locals.
 *
 * @var array $data See newpixel_newsletter_render_html() docblock for shape
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Local pulls from $data for readability inside the template
$issue_number    = (int) $data['issue_number'];
$issue_label     = (string) $data['issue_label'];
$headline_html   = (string) $data['headline_html'];
$posts           = (array) $data['posts'];
$unsubscribe_url = (string) $data['unsubscribe_url'];
$browser_url     = (string) $data['browser_url'];
$subscribed_date = (string) $data['subscribed_date'];

// First post is the featured (with hero image), the rest are plain cards.
$featured = ! empty( $posts ) ? array_shift( $posts ) : null;
$rest     = $posts;
?><!DOCTYPE html>
<html lang="pt" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="format-detection" content="telephone=no">

    <!--
        Opt-out of forced auto-inversion by Gmail/Outlook web clients.
        Combined with the @media (prefers-color-scheme: dark) block below,
        this gives us full control over both modes instead of having the
        client try (and often fail) to invert our palette automatically.
    -->
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">

    <title>Pixel · Edição #<?php echo $issue_number; ?> · <?php echo esc_html( $issue_label ); ?></title>

    <style type="text/css">
        /* Reset a few defaults that desktop Outlook insists on */
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; line-height: 100%; outline: none; text-decoration: none; }
        body { margin: 0 !important; padding: 0 !important; width: 100% !important; }

        /* Dark mode — Apple Mail + clients that respect the media query.
           Inline styles render the light palette by default; these rules
           use class hooks and !important to override only when the user's
           system is in dark mode. */
        @media (prefers-color-scheme: dark) {
            .bg-page      { background:#0a0a0a !important; }
            .bg-surface   { background:#1a1a1a !important; border-color:rgba(255,255,255,0.08) !important; }
            .fg           { color:#fafaf7 !important; }
            .fg-muted     { color:#b3b3b3 !important; }
            .fg-dim       { color:#6b6b6b !important; }
            .rule-bg      { background:rgba(255,255,255,0.12) !important; }
            .rule-soft-bg { background:rgba(255,255,255,0.06) !important; }
            .link         { color:#fafaf7 !important; }
            .link-dim     { color:#b3b3b3 !important; }
        }

        /* Outlook Office Insider — uses [data-ogsc] attributes on elements
           inside dark mode emails, rather than respecting the standard
           media query. We mirror the dark palette here for that audience. */
        [data-ogsc] .bg-page      { background:#0a0a0a !important; }
        [data-ogsc] .bg-surface   { background:#1a1a1a !important; border-color:rgba(255,255,255,0.08) !important; }
        [data-ogsc] .fg           { color:#fafaf7 !important; }
        [data-ogsc] .fg-muted     { color:#b3b3b3 !important; }
        [data-ogsc] .fg-dim       { color:#6b6b6b !important; }
        [data-ogsc] .rule-bg      { background:rgba(255,255,255,0.12) !important; }
        [data-ogsc] .rule-soft-bg { background:rgba(255,255,255,0.06) !important; }
        [data-ogsc] .link         { color:#fafaf7 !important; }
        [data-ogsc] .link-dim     { color:#b3b3b3 !important; }

        /* Phone-narrow tweaks */
        @media only screen and (max-width: 620px) {
            .container { width: 100% !important; max-width: 100% !important; }
            .px        { padding-left: 16px !important; padding-right: 16px !important; }
            .h1-mobile { font-size: 22px !important; line-height: 1.2 !important; }
            .h2-mobile { font-size: 21px !important; line-height: 1.2 !important; }
        }
    </style>
</head>

<body class="bg-page" style="margin:0;padding:0;width:100%;background:#fafaf7;font-family:-apple-system,BlinkMacSystemFont,'Helvetica Neue',Arial,sans-serif;">

    <!-- Pre-header: hidden text that some clients show as a snippet next
         to the subject line. Important not to leave as default. -->
    <div style="display:none;font-size:1px;color:#fafaf7;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">
        Pixel Magazine — Edição #<?php echo $issue_number; ?> · <?php echo esc_html( $issue_label ); ?>. Cinco minutos. <?php echo newpixel_manifesto(); ?>.
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="bg-page" style="background:#fafaf7;">
        <!--
            Outer wrap — the only td.px with vertical padding. Provides the
            20px breathing room above and below the centred email body so it
            doesn't hug the top/bottom edges of the client viewport. All
            other td.px in the template stay at "0px 8px" — vertical rhythm
            inside the body comes from explicit spacer rows.
        -->
        <tbody><tr><td align="center" class="px" style="padding:20px 8px;">

            <table role="presentation" class="container" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;">

                <!-- ============================== HEADER ============================== -->
                <tbody><tr>
                    <!--
                        Header cell has 20px bottom padding (the one exception to the
                        otherwise-uniform "0px 8px" rule on td.px) so the logo + edition
                        meta have breathing room before the header rule beneath them.
                    -->
                    <td class="px" style="padding:0px 8px 20px 8px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tbody><tr>
                                <!--
                                    Wordmark — recreated in HTML+CSS instead of the site's SVG
                                    because Gmail and Outlook strip inline SVG (only Apple Mail
                                    renders it). Two lines stacked: "PIXEL·" in heavy sans,
                                    "magazine" in italic serif below — matches the look of
                                    assets/img/pixel-logo.svg as closely as system fonts allow.
                                    Both lines use the `fg` class so they auto-invert in dark
                                    mode (dark text on light bg → light text on dark bg).
                                -->
                                <td valign="middle" align="left" class="fg" style="font-family:-apple-system,BlinkMacSystemFont,'Helvetica Neue',Arial,sans-serif;color:#0a0a0a;line-height:1;">
                                    <span style="display:block;font-weight:800;font-size:22px;letter-spacing:-0.02em;">PIXEL<span style="color:#a78bfa;">·</span></span>
                                    <span class="fg" style="display:block;font-family:Georgia,'Times New Roman',serif;font-style:italic;font-size:13px;font-weight:400;color:#0a0a0a;margin-top:2px;margin-left:1px;letter-spacing:0;">magazine</span>
                                </td>
                                <td valign="middle" align="right" class="fg-dim" style="font-family:ui-monospace,'SF Mono',Menlo,Monaco,monospace;font-size:11px;font-weight:500;letter-spacing:0.10em;text-transform:uppercase;color:#b3b3b3;">
                                    Edição #<?php echo $issue_number; ?> · <?php echo esc_html( $issue_label ); ?>
                                </td>
                            </tr></tbody>
                        </table>
                    </td>
                </tr>

                <!-- Header rule -->
                <tr>
                    <td class="px" style="padding:0px 8px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tbody><tr><td height="1" class="rule-bg" style="height:1px;line-height:1px;font-size:0;background:rgba(0,0,0,0.10);">&nbsp;</td></tr></tbody>
                        </table>
                    </td>
                </tr>

                <!--
                    Spacer between the header rule and the editorial headline.
                    Replaces the original 36px top padding the headline cell
                    used to have, now that all td.px are uniformly "0px 8px".
                -->
                <tr><td style="height:36px;line-height:36px;font-size:0;">&nbsp;</td></tr>

                <!-- ============================== EDITORIAL INTRO ============================== -->
                <tr>
                    <td class="px" style="padding:0px 8px;">
                        <!--
                            Editorial headline: same sans display family as the article H2s.
                            Large (40px) to act as the cover-line of the issue when the email
                            opens — pulls the eye immediately. .h2-mobile shared rule scales
                            this down for narrow viewports so it doesn't dominate on mobile.
                            Inner <p> tags from wpautop get margin:0 inlined so the headline
                            sits flush against the cell — vertical rhythm is controlled by
                            the surrounding spacer rows, not by the <p>'s default UA margins.
                        -->
                        <div class="fg h2-mobile" style="font-family:-apple-system,BlinkMacSystemFont,'Helvetica Neue',Arial,sans-serif;font-size:40px;line-height:1.15;font-weight:700;letter-spacing:-0.02em;color:#0a0a0a;">
                            <?php echo str_replace( '<p>', '<p style="margin:0;">', wp_kses_post( $headline_html ) ); ?>
                        </div>
                    </td>
                </tr>

                <tr><td style="height:36px;line-height:36px;font-size:0;">&nbsp;</td></tr>

                <?php if ( $featured ) : ?>
                <!-- ============================== FEATURED ARTICLE ============================== -->
                <tr>
                    <td class="px" style="padding:0px 8px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="bg-surface" style="background:#ffffff;border:1px solid rgba(0,0,0,0.06);border-radius:4px;">
                            <?php if ( ! empty( $featured['image_url'] ) ) : ?>
                            <tbody><tr>
                                <td style="font-size:0;line-height:0;">
                                    <img src="<?php echo esc_url( $featured['image_url'] ); ?>" width="584" alt="<?php echo esc_attr( $featured['image_alt'] ); ?>" style="display:block;width:100%;max-width:584px;height:auto;border:0;border-radius:4px 4px 0 0;">
                                </td>
                            </tr>
                            <?php else : ?>
                            <tbody>
                            <?php endif; ?>
                            <tr>
                                <td style="padding:24px 24px 26px 24px;">
                                    <!--
                                        Category pill — anchor (not span) so it's clickable and
                                        deep-links into the category archive on the site.
                                        Asymmetric padding (more top than bottom, slightly more
                                        right than left) optically centres the ● bullet relative
                                        to the uppercase label text; the bullet sits visually
                                        higher than the cap-height baseline, so a hair more top
                                        padding evens it out.
                                    -->
                                    <p style="margin:0 0 18px 0;font-size:0;line-height:0;">
                                        <a href="<?php echo esc_url( $featured['category_url'] ); ?>" style="display:inline-block;padding:14px 10px 12px 9px;background:<?php echo esc_attr( newpixel_newsletter_hex_to_rgba( $featured['category_color'], 0.16 ) ); ?>;color:<?php echo esc_attr( $featured['category_color'] ); ?>;font-family:ui-monospace,'SF Mono',Menlo,Monaco,monospace;font-size:11px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;border-radius:999px;text-decoration:none;">●&nbsp;<?php echo esc_html( $featured['category_name'] ); ?></a>
                                    </p>
                                    <h2 class="fg h2-mobile" style="margin:0 0 12px 0;font-family:-apple-system,BlinkMacSystemFont,'Helvetica Neue',Arial,sans-serif;font-weight:800;font-size:28px;line-height:1.15;letter-spacing:-0.025em;color:#0a0a0a;">
                                        <a href="<?php echo esc_url( $featured['permalink'] ); ?>" class="fg" style="color:inherit;text-decoration:none;"><?php echo esc_html( $featured['title'] ); ?></a>
                                    </h2>
                                    <p class="fg-muted" style="margin:0 0 18px 0;font-family:Georgia,'Times New Roman',serif;font-size:16px;line-height:1.5;color:#525252;">
                                        <?php echo esc_html( $featured['dek'] ); ?>
                                    </p>
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                        <tbody><tr>
                                            <td class="fg-dim" style="font-family:ui-monospace,'SF Mono',Menlo,Monaco,monospace;font-size:11px;font-weight:500;letter-spacing:0.1em;text-transform:uppercase;color:#b3b3b3;">
                                                <?php echo esc_html( $featured['date_label'] ); ?> · <?php echo (int) $featured['reading_time']; ?> min de leitura
                                            </td>
                                            <td align="right" class="fg" style="font-family:-apple-system,BlinkMacSystemFont,'Helvetica Neue',Arial,sans-serif;font-size:14px;font-weight:600;color:#0a0a0a;">
                                                <a href="<?php echo esc_url( $featured['permalink'] ); ?>" class="fg" style="color:inherit;text-decoration:none;">Ler artigo &nbsp;→</a>
                                            </td>
                                        </tr></tbody>
                                    </table>
                                </td>
                            </tr>
                        </tbody></table>
                    </td>
                </tr>

                <tr><td style="height:32px;line-height:32px;font-size:0;">&nbsp;</td></tr>
                <?php endif; ?>

                <?php
                // ============================== ARTICLES 2-5 ==============================
                // Each subsequent article is a flat card (no surface, no image)
                // with a soft rule between them. Loop with index so we know
                // when to skip the trailing rule.
                $count = count( $rest );
                foreach ( $rest as $i => $post_record ) :
                    $pill_bg = newpixel_newsletter_hex_to_rgba( $post_record['category_color'], 0.13 );
                ?>
                <tr>
                    <td class="px" style="padding:0px 8px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tbody><tr>
                                <td>
                                    <p style="margin:0 0 14px 0;font-size:0;line-height:0;">
                                        <a href="<?php echo esc_url( $post_record['category_url'] ); ?>" style="display:inline-block;padding:14px 10px 12px 9px;background:<?php echo esc_attr( $pill_bg ); ?>;color:<?php echo esc_attr( $post_record['category_color'] ); ?>;font-family:ui-monospace,'SF Mono',Menlo,Monaco,monospace;font-size:11px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;border-radius:999px;text-decoration:none;">●&nbsp;<?php echo esc_html( $post_record['category_name'] ); ?></a>
                                    </p>
                                    <h2 class="fg h2-mobile" style="margin:0 0 10px 0;font-family:-apple-system,BlinkMacSystemFont,'Helvetica Neue',Arial,sans-serif;font-weight:700;font-size:23px;line-height:1.2;letter-spacing:-0.02em;color:#0a0a0a;">
                                        <a href="<?php echo esc_url( $post_record['permalink'] ); ?>" class="fg" style="color:inherit;text-decoration:none;"><?php echo esc_html( $post_record['title'] ); ?></a>
                                    </h2>
                                    <p class="fg-muted" style="margin:0 0 14px 0;font-family:Georgia,'Times New Roman',serif;font-size:15px;line-height:1.55;color:#525252;">
                                        <?php echo esc_html( $post_record['dek'] ); ?>
                                    </p>
                                    <p class="fg-dim" style="margin:0;font-family:ui-monospace,'SF Mono',Menlo,Monaco,monospace;font-size:11px;font-weight:500;letter-spacing:0.1em;text-transform:uppercase;color:#b3b3b3;">
                                        <?php echo esc_html( $post_record['date_label'] ); ?> · <?php echo (int) $post_record['reading_time']; ?> min · <a href="<?php echo esc_url( $post_record['permalink'] ); ?>" class="fg" style="color:#0a0a0a;text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:3px;">Ler artigo →</a>
                                    </p>
                                </td>
                            </tr></tbody>
                        </table>
                    </td>
                </tr>

                <?php if ( $i < $count - 1 ) : ?>
                <!-- Soft rule between cards -->
                <tr><td style="height:28px;line-height:28px;font-size:0;">&nbsp;</td></tr>
                <tr><td class="px" style="padding:0px 8px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tbody><tr><td height="1" class="rule-soft-bg" style="height:1px;line-height:1px;font-size:0;background:rgba(0,0,0,0.05);">&nbsp;</td></tr></tbody></table></td></tr>
                <tr><td style="height:28px;line-height:28px;font-size:0;">&nbsp;</td></tr>
                <?php endif; ?>

                <?php endforeach; ?>

                <tr><td style="height:48px;line-height:48px;font-size:0;">&nbsp;</td></tr>

                <!-- ============================== FOOTER ============================== -->
                <tr>
                    <td class="px" style="padding:0px 8px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tbody><tr><td height="1" class="rule-bg" style="height:1px;line-height:1px;font-size:0;background:rgba(0,0,0,0.10);">&nbsp;</td></tr></tbody>
                        </table>
                    </td>
                </tr>

                <!--
                    Spacer between the footer's top rule and the PIXEL wordmark.
                    Originally lived inside the cell as top padding (28px); with
                    all td.px now uniform at "0px 8px", vertical rhythm here is
                    handled by this explicit spacer row instead.
                -->
                <tr><td style="height:28px;line-height:28px;font-size:0;">&nbsp;</td></tr>

                <tr>
                    <td class="px" style="padding:0px 8px;">
                        <p class="fg" style="margin:0 0 2px 0;font-family:-apple-system,BlinkMacSystemFont,'Helvetica Neue',Arial,sans-serif;font-weight:800;font-size:18px;letter-spacing:-0.02em;color:#0a0a0a;line-height:1;">
                            PIXEL<span style="color:#a78bfa;">·</span>
                        </p>
                        <p class="fg" style="margin:0 0 16px 0;font-family:Georgia,'Times New Roman',serif;font-style:italic;font-size:12px;color:#0a0a0a;line-height:1;">
                            magazine
                        </p>
                        <p class="fg-muted" style="margin:0 0 24px 0;font-family:Georgia,'Times New Roman',serif;font-size:15px;line-height:1.5;color:#525252;">
                            <?php echo newpixel_manifesto(); ?>.
                        </p>

                        <p class="fg-muted" style="margin:0 0 18px 0;font-family:-apple-system,BlinkMacSystemFont,'Helvetica Neue',Arial,sans-serif;font-size:13px;line-height:1.55;color:#525252;">
                            Recebes este email porque subscreveste a newsletter da Pixel Magazine<?php if ( $subscribed_date ) echo ' a ' . esc_html( $subscribed_date ); ?> com este endereço. Se já não queres — sem dramas. <a class="link-dim" href="<?php echo esc_url( $unsubscribe_url ); ?>" style="color:#525252;text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:3px;">Cancela subscrição</a>.
                        </p>

                        <!--
                            Each link is wrapped as a non-breaking unit so on
                            narrow mobile widths the line breaks BETWEEN labels
                            (clean) instead of mid-label (e.g. "Política DE
                            Privacidade" splitting across lines). Belt-and-braces:
                            white-space:nowrap on the <a> handles modern clients
                            that respect inline CSS, &nbsp; inside the label
                            handles the Outlook/Gmail-flattened-CSS case.
                        -->
                        <p class="fg-dim" style="margin:0 0 24px 0;font-family:ui-monospace,'SF Mono',Menlo,Monaco,monospace;font-size:11px;font-weight:500;letter-spacing:0.1em;text-transform:uppercase;color:#b3b3b3;">
                            <a href="<?php echo esc_url( $browser_url ); ?>" class="link-dim" style="color:#b3b3b3;text-decoration:none;white-space:nowrap;">Ver&nbsp;no&nbsp;browser</a>
                            &nbsp;·&nbsp;
                            <a href="<?php echo esc_url( home_url( '/politica-de-privacidade/' ) ); ?>" class="link-dim" style="color:#b3b3b3;text-decoration:none;white-space:nowrap;">Política&nbsp;de&nbsp;Privacidade</a>
                            &nbsp;·&nbsp;
                            <a href="<?php echo esc_url( home_url( '/contacto/' ) ); ?>" class="link-dim" style="color:#b3b3b3;text-decoration:none;white-space:nowrap;">Contactos</a>
                            &nbsp;·&nbsp;
                            <a href="<?php echo esc_url( home_url( '/feed/' ) ); ?>" class="link-dim" style="color:#b3b3b3;text-decoration:none;white-space:nowrap;">RSS</a>
                        </p>
                    </td>
                </tr>

                <tr>
                    <td class="px" style="padding:0px 8px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tbody><tr><td height="1" class="rule-bg" style="height:1px;line-height:1px;font-size:0;background:rgba(0,0,0,0.10);">&nbsp;</td></tr></tbody>
                        </table>
                    </td>
                </tr>

                <!--
                    Spacer between the second footer rule and the bottom
                    copyright line. Replaces the original 18px top padding
                    that cell used to have.
                -->
                <tr><td style="height:18px;line-height:18px;font-size:0;">&nbsp;</td></tr>

                <tr>
                    <td class="px" style="padding:0px 8px;">
                        <!--
                            Same mobile-wrapping defence as the link row above:
                            keep "Pixel Magazine" and "Feito em Portugal" as
                            atomic units so the bullets never end up alone on
                            a line.
                        -->
                        <p class="fg-dim" style="margin:0;font-family:ui-monospace,'SF Mono',Menlo,Monaco,monospace;font-size:10px;font-weight:500;letter-spacing:0.18em;text-transform:uppercase;color:#b3b3b3;">
                            <span style="white-space:nowrap;">Pixel&nbsp;Magazine</span> &nbsp;·&nbsp; <?php echo esc_html( current_time( 'Y' ) ); ?> &nbsp;·&nbsp; <span style="white-space:nowrap;">Feito&nbsp;em&nbsp;Portugal</span>
                        </p>
                    </td>
                </tr>

            </tbody></table>

        </td></tr></tbody>
    </table>

</body>
</html>

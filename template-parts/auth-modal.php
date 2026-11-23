<?php
/**
 * Auth modal — login + register, AJAX-driven, no page reload.
 * Output via wp_footer so it's available on every page (for guests only).
 */

if ( is_user_logged_in() ) return;

$auth_nonce  = wp_create_nonce( 'pixel_auth' );
$home        = home_url( '/' );
// Modal fragment, never core's URL, which would carry the secret login slug
// (see inc/hidden-login.php).
$reset_url   = wp_lostpassword_url();
$can_register = (bool) get_option( 'users_can_register' );
?>
<div class="kf-auth" data-auth-modal hidden aria-hidden="true">
    <button class="kf-auth__backdrop" type="button" data-auth-close aria-label="<?php esc_attr_e( 'Fechar', 'newpixel' ); ?>"></button>

    <div class="kf-auth__panel" role="dialog" aria-modal="true" aria-labelledby="kf-auth-title">
        <button class="kf-auth__close" type="button" data-auth-close aria-label="<?php esc_attr_e( 'Fechar', 'newpixel' ); ?>">
            <span class="material-symbols-outlined" aria-hidden="true">close</span>
        </button>

        <div class="kf-auth__split">

            <!-- LEFT: brand panel (always dark) -->
            <aside class="kf-auth__brand" aria-hidden="true">
                <div class="kf-auth__brand-bg-1"></div>
                <div class="kf-auth__brand-bg-2"></div>

                <a class="kf-auth__brand-mark" href="<?php echo esc_url( $home ); ?>" aria-label="<?php esc_attr_e( 'Voltar ao site', 'newpixel' ); ?>">
                    <?php newpixel_logo_svg( 'kf-logo--auth' ); ?>
                </a>

                <div class="kf-auth__brand-body">
                    <div class="kf-auth__brand-eyebrow"><?php esc_html_e( 'ÁREA DO LEITOR', 'newpixel' ); ?></div>
                    <h2 id="kf-auth-title" class="kf-auth__brand-title">
                        <?php esc_html_e( 'A tecnologia com o', 'newpixel' ); ?> <em><?php esc_html_e( 'teu', 'newpixel' ); ?></em> <?php esc_html_e( 'perfil', 'newpixel' ); ?><span class="kf-auth__brand-dot">.</span>
                    </h2>
                    <p class="kf-auth__brand-copy">
                        <?php esc_html_e( 'Conta gratuita, newsletter opcional e sem partilha de dados.', 'newpixel' ); ?>
                    </p>

                    <ul class="kf-auth__features">
                        <li>
                            <span class="kf-auth__feature-icon material-symbols-outlined" aria-hidden="true">bookmark</span>
                            <span><?php esc_html_e( 'Guarda artigos para ler depois', 'newpixel' ); ?></span>
                        </li>
                        <li>
                            <span class="kf-auth__feature-icon material-symbols-outlined" aria-hidden="true">forum</span>
                            <span><?php esc_html_e( 'Comenta e responde a outros leitores', 'newpixel' ); ?></span>
                        </li>
                        <li>
                            <span class="kf-auth__feature-icon material-symbols-outlined" aria-hidden="true">mail</span>
                            <span><?php esc_html_e( 'Personaliza a tua newsletter', 'newpixel' ); ?></span>
                        </li>
                        <li>
                            <span class="kf-auth__feature-icon material-symbols-outlined" aria-hidden="true">contrast</span>
                            <span><?php esc_html_e( 'Modo escuro lembrado em todos os devices', 'newpixel' ); ?></span>
                        </li>
                    </ul>
                </div>

            </aside>

            <!-- RIGHT: form panel (theme-aware) -->
            <div class="kf-auth__form-wrap">

                <div class="kf-auth__tabs" role="tablist">
                    <button type="button" class="kf-auth__tab is-active" data-auth-tab="login" role="tab" aria-selected="true">
                        <?php esc_html_e( 'Entrar', 'newpixel' ); ?>
                    </button>
                    <?php if ( $can_register ) : ?>
                        <button type="button" class="kf-auth__tab" data-auth-tab="register" role="tab" aria-selected="false">
                            <?php esc_html_e( 'Criar conta', 'newpixel' ); ?>
                        </button>
                    <?php endif; ?>
                </div>

                <!-- LOGIN PANEL -->
                <div class="kf-auth__panel-content" data-auth-panel="login">
                    <h2 class="kf-auth__heading">
                        <?php esc_html_e( 'Bem-vindo de volta', 'newpixel' ); ?><span class="kf-auth__brand-dot">.</span>
                    </h2>
                    <p class="kf-auth__sub"><?php esc_html_e( 'Tens artigos guardados à tua espera.', 'newpixel' ); ?></p>

                    <form class="kf-auth-form" data-auth-form="login" novalidate>
                        <div class="kf-auth-form__error" role="alert" aria-live="polite" hidden></div>
                        <div class="kf-auth-form__notice" role="status" aria-live="polite" hidden></div>

                        <div class="kf-auth-form__field">
                            <label for="kf-login-email"><?php esc_html_e( 'EMAIL', 'newpixel' ); ?></label>
                            <input id="kf-login-email" type="email" name="log" autocomplete="username" placeholder="o.teu@email.pt" required />
                        </div>

                        <div class="kf-auth-form__field">
                            <div class="kf-auth-form__field-head">
                                <label for="kf-login-pass"><?php esc_html_e( 'PALAVRA-PASSE', 'newpixel' ); ?></label>
                                <a href="<?php echo esc_url( $reset_url ); ?>" data-auth-open="lost"><?php esc_html_e( 'Esqueci-me', 'newpixel' ); ?></a>
                            </div>
                            <div class="kf-auth-form__pass">
                                <input id="kf-login-pass" type="password" name="pwd" autocomplete="current-password" placeholder="••••••••••••" required />
                                <button type="button" data-toggle-pass aria-label="<?php esc_attr_e( 'Mostrar/ocultar palavra-passe', 'newpixel' ); ?>">
                                    <span class="material-symbols-outlined" aria-hidden="true">visibility</span>
                                </button>
                            </div>
                        </div>

                        <label class="kf-auth-form__remember">
                            <input type="checkbox" name="rememberme" checked />
                            <?php esc_html_e( 'Manter sessão iniciada (14 dias)', 'newpixel' ); ?>
                        </label>

                        <button type="submit" class="kf-auth-form__submit">
                            <span data-default-label><?php esc_html_e( 'Entrar', 'newpixel' ); ?> &rarr;</span>
                            <span data-loading-label hidden><?php esc_html_e( 'A entrar…', 'newpixel' ); ?></span>
                        </button>

                        <input type="hidden" name="action" value="pixel_auth_login" />
                        <input type="hidden" name="_nonce" value="<?php echo esc_attr( $auth_nonce ); ?>" />
                        <input type="hidden" name="redirect_to" value="<?php echo esc_url( $home ); ?>" />
                    </form>
                </div>

                <?php if ( $can_register ) : ?>
                    <!-- REGISTER PANEL -->
                    <div class="kf-auth__panel-content" data-auth-panel="register" hidden>
                        <h2 class="kf-auth__heading">
                            <?php esc_html_e( 'Cria a tua conta', 'newpixel' ); ?><span class="kf-auth__brand-dot">.</span>
                        </h2>
                        <p class="kf-auth__sub"><?php esc_html_e( 'Em 30 segundos. Sem spam.', 'newpixel' ); ?></p>

                        <form class="kf-auth-form" data-auth-form="register" novalidate>
                            <div class="kf-auth-form__error" role="alert" aria-live="polite" hidden></div>

                            <div class="kf-auth-form__field">
                                <label for="kf-reg-name"><?php esc_html_e( 'NOME', 'newpixel' ); ?></label>
                                <input id="kf-reg-name" type="text" name="name" autocomplete="name" placeholder="<?php esc_attr_e( 'Como queres ser tratado', 'newpixel' ); ?>" required />
                            </div>

                            <div class="kf-auth-form__field">
                                <label for="kf-reg-email"><?php esc_html_e( 'EMAIL', 'newpixel' ); ?></label>
                                <input id="kf-reg-email" type="email" name="email" autocomplete="email" placeholder="o.teu@email.pt" required />
                            </div>

                            <div class="kf-auth-form__field">
                                <label for="kf-reg-pass"><?php esc_html_e( 'PALAVRA-PASSE', 'newpixel' ); ?></label>
                                <div class="kf-auth-form__pass">
                                    <input id="kf-reg-pass" type="password" name="password" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Pelo menos 8 caracteres', 'newpixel' ); ?>" minlength="8" required />
                                    <button type="button" data-toggle-pass aria-label="<?php esc_attr_e( 'Mostrar/ocultar palavra-passe', 'newpixel' ); ?>">
                                    <span class="material-symbols-outlined" aria-hidden="true">visibility</span>
                                </button>
                                </div>
                            </div>

                            <button type="submit" class="kf-auth-form__submit">
                                <span data-default-label><?php esc_html_e( 'Criar conta', 'newpixel' ); ?> &rarr;</span>
                                <span data-loading-label hidden><?php esc_html_e( 'A criar…', 'newpixel' ); ?></span>
                            </button>

                            <?php newpixel_honeypot_field(); ?>
                            <input type="hidden" name="action" value="pixel_auth_register" />
                            <input type="hidden" name="_nonce" value="<?php echo esc_attr( $auth_nonce ); ?>" />
                        </form>
                    </div>
                <?php endif; ?>

                <!-- LOST PASSWORD PANEL — requests the link by email -->
                <div class="kf-auth__panel-content" data-auth-panel="lost" hidden>
                    <h2 class="kf-auth__heading">
                        <?php esc_html_e( 'Recupera o acesso', 'newpixel' ); ?><span class="kf-auth__brand-dot">.</span>
                    </h2>
                    <p class="kf-auth__sub"><?php esc_html_e( 'Indica o email da tua conta e enviamos-te uma ligação para escolheres uma nova palavra-passe.', 'newpixel' ); ?></p>

                    <form class="kf-auth-form" data-auth-form="lost" novalidate>
                        <div class="kf-auth-form__error" role="alert" aria-live="polite" hidden></div>
                        <div class="kf-auth-form__notice" role="status" aria-live="polite" hidden></div>

                        <div class="kf-auth-form__field">
                            <label for="kf-lost-email"><?php esc_html_e( 'EMAIL', 'newpixel' ); ?></label>
                            <input id="kf-lost-email" type="email" name="user_login" autocomplete="email" placeholder="o.teu@email.pt" required />
                        </div>

                        <button type="submit" class="kf-auth-form__submit">
                            <span data-default-label><?php esc_html_e( 'Enviar ligação', 'newpixel' ); ?> &rarr;</span>
                            <span data-loading-label hidden><?php esc_html_e( 'A enviar…', 'newpixel' ); ?></span>
                        </button>

                        <a class="kf-auth-form__alt" href="<?php echo esc_url( wp_login_url() ); ?>" data-auth-open="login">&larr; <?php esc_html_e( 'Voltar a entrar', 'newpixel' ); ?></a>

                        <?php newpixel_honeypot_field(); ?>
                        <input type="hidden" name="action" value="pixel_auth_lostpassword" />
                        <input type="hidden" name="_nonce" value="<?php echo esc_attr( $auth_nonce ); ?>" />
                    </form>
                </div>

                <!-- RESET PANEL — opened by the email link (#redefinir-password) -->
                <div class="kf-auth__panel-content" data-auth-panel="reset" hidden>
                    <h2 class="kf-auth__heading">
                        <?php esc_html_e( 'Nova palavra-passe', 'newpixel' ); ?><span class="kf-auth__brand-dot">.</span>
                    </h2>
                    <p class="kf-auth__sub"><?php esc_html_e( 'Escolhe uma palavra-passe com pelo menos 8 caracteres.', 'newpixel' ); ?></p>

                    <form class="kf-auth-form" data-auth-form="reset" novalidate>
                        <div class="kf-auth-form__error" role="alert" aria-live="polite" hidden></div>

                        <div class="kf-auth-form__group" data-reset-fields>
                            <div class="kf-auth-form__field">
                                <label for="kf-reset-pass"><?php esc_html_e( 'NOVA PALAVRA-PASSE', 'newpixel' ); ?></label>
                                <div class="kf-auth-form__pass">
                                    <input id="kf-reset-pass" type="password" name="pass1" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Pelo menos 8 caracteres', 'newpixel' ); ?>" minlength="8" required />
                                    <button type="button" data-toggle-pass aria-label="<?php esc_attr_e( 'Mostrar/ocultar palavra-passe', 'newpixel' ); ?>">
                                        <span class="material-symbols-outlined" aria-hidden="true">visibility</span>
                                    </button>
                                </div>
                            </div>

                            <div class="kf-auth-form__field">
                                <label for="kf-reset-pass2"><?php esc_html_e( 'REPETE A PALAVRA-PASSE', 'newpixel' ); ?></label>
                                <div class="kf-auth-form__pass">
                                    <input id="kf-reset-pass2" type="password" name="pass2" autocomplete="new-password" minlength="8" required />
                                    <button type="button" data-toggle-pass aria-label="<?php esc_attr_e( 'Mostrar/ocultar palavra-passe', 'newpixel' ); ?>">
                                        <span class="material-symbols-outlined" aria-hidden="true">visibility</span>
                                    </button>
                                </div>
                            </div>

                            <button type="submit" class="kf-auth-form__submit">
                                <span data-default-label><?php esc_html_e( 'Guardar palavra-passe', 'newpixel' ); ?> &rarr;</span>
                                <span data-loading-label hidden><?php esc_html_e( 'A guardar…', 'newpixel' ); ?></span>
                            </button>
                        </div>

                        <a class="kf-auth-form__alt" href="<?php echo esc_url( $reset_url ); ?>" data-auth-open="lost" data-reset-invalid hidden><?php esc_html_e( 'Pedir uma ligação nova', 'newpixel' ); ?> &rarr;</a>

                        <input type="hidden" name="action" value="pixel_auth_resetpassword" />
                        <input type="hidden" name="_nonce" value="<?php echo esc_attr( $auth_nonce ); ?>" />
                        <input type="hidden" name="login" value="" />
                        <input type="hidden" name="key" value="" />
                    </form>
                </div>

            </div>

        </div>
    </div>
</div>

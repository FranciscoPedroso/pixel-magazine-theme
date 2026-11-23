// =============================================================================
// Pixel Magazine — client JS
// =============================================================================

(function () {
    'use strict';

    // ============================================================================
    // Shared helpers
    // ============================================================================

    // POST to a WordPress AJAX endpoint and parse the JSON response. Returns
    // a uniform shape so call sites don't have to decide between .ok / .success
    // every time:
    //   { ok: <HTTP 2xx?>, success: <json.success === true?>, json: <parsed body> }
    function postJSON(url, body) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            body: body,
            headers: { 'Accept': 'application/json' },
        })
            .then((res) => res.json().then((json) => ({
                ok: res.ok,
                json: json,
                success: !!(json && json.success),
            })));
    }

    // localStorage with a safety net: private mode / sandboxed iframes throw on
    // read or write. Wrap once so feature code stays a single line.
    const safeStorage = {
        get(key) { try { return localStorage.getItem(key); } catch (e) { return null; } },
        set(key, value) { try { localStorage.setItem(key, value); } catch (e) {} },
        remove(key) { try { localStorage.removeItem(key); } catch (e) {} },
    };

    // ----- Theme system (auto / light / dark) --------------------------------
    // Initial detection runs synchronously in <head> (see header.php) so the
    // first paint already has data-theme + data-theme-mode set.
    //
    // This block exposes a single source of truth (`setThemeMode`) so the
    // header toggle, profile radios, and OS-pref listener all stay in sync.
    // Mode "auto" follows prefers-color-scheme; "light"/"dark" lock the
    // choice in localStorage.
    const root = document.documentElement;
    const STORAGE_KEY = 'pixel-theme';

    function systemPrefersDark() {
        return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    }

    // Visual order of the glass selector in the header (left → right).
    const SWITCH_ORDER = ['light', 'auto', 'dark'];

    const reduceMotion = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;

    // origin (optional): the element the reader pressed. With it, the new theme
    // opens in a circle from that point (View Transitions); without it (the system
    // preference changed) it's a cross-fade. With no support or reduced motion,
    // it switches immediately.
    function setThemeMode(mode, origin) {
        // mode ∈ {'auto', 'light', 'dark'}
        const prev = root.getAttribute('data-theme-mode') || 'auto';
        const theme = (mode === 'auto')
            ? (systemPrefersDark() ? 'dark' : 'light')
            : mode;

        const apply = function () {
            root.setAttribute('data-theme', theme);
            root.setAttribute('data-theme-mode', mode);
            if (theme === 'dark') root.classList.add('dark');
            else root.classList.remove('dark');

            if (mode === 'auto') safeStorage.remove(STORAGE_KEY);
            else safeStorage.set(STORAGE_KEY, mode);

            // Keep the profile-page radio group in sync if it's mounted on this
            // page. Cheap query; no-op when there's no picker.
            document.querySelectorAll('[data-profile-theme]').forEach((r) => {
                r.checked = (r.value === mode);
            });
            syncThemeSwitch(mode, prev);
        };

        // Only animate when colours actually change (auto → light with a light
        // system changes nothing on screen).
        if (theme === root.getAttribute('data-theme')
            || !document.startViewTransition
            || (reduceMotion && reduceMotion.matches)) {
            apply();
            return;
        }

        const rect = origin && origin.getBoundingClientRect ? origin.getBoundingClientRect() : null;
        if (!rect || (!rect.width && !rect.height)) {
            document.startViewTransition(apply);
            return;
        }

        const x = rect.left + rect.width / 2;
        const y = rect.top + rect.height / 2;
        const radius = Math.hypot(Math.max(x, window.innerWidth - x), Math.max(y, window.innerHeight - y));
        // data-theme-reveal turns off the default cross-fade (see _interactions.scss).
        root.setAttribute('data-theme-reveal', '');
        const vt = document.startViewTransition(apply);
        vt.ready.then(function () {
            root.animate(
                { clipPath: ['circle(0px at ' + x + 'px ' + y + 'px)', 'circle(' + radius + 'px at ' + x + 'px ' + y + 'px)'] },
                { duration: 560, easing: 'cubic-bezier(.65, 0, .35, 1)', pseudoElement: '::view-transition-new(root)' }
            );
        }).catch(function () {});
        vt.finished.finally(function () { root.removeAttribute('data-theme-reveal'); });
    }

    // Glass selector: the thumb's position comes from CSS (data-theme-mode); here
    // we only mark the radio and the direction it travels. data-dir is also what
    // enables the "droplet" animation, so nothing animates on page load.
    function syncThemeSwitch(mode, prev) {
        const delta = prev ? SWITCH_ORDER.indexOf(mode) - SWITCH_ORDER.indexOf(prev) : 0;
        document.querySelectorAll('[data-theme-switch]').forEach((sw) => {
            if (delta) sw.setAttribute('data-dir', delta > 0 ? 'right' : 'left');
            sw.querySelectorAll('[data-theme-radio]').forEach((r) => {
                r.checked = (r.value === mode);
            });
        });
    }
    syncThemeSwitch(root.getAttribute('data-theme-mode') || 'auto', null);

    document.addEventListener('change', function (e) {
        const radio = e.target.closest('[data-theme-radio]');
        // The input is invisible: the circle grows from the icon, inside the label.
        if (radio && radio.checked) setThemeMode(radio.value, radio.closest('label') || radio);
    });
    // Make the helper reachable from the profile picker block below.
    window.__pixelSetThemeMode = setThemeMode;

    // Manual toggle in the header — cycles through the three states so the
    // user can always return to "auto" without leaving the page.
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-theme-toggle]');
        if (!btn) return;
        const current = root.getAttribute('data-theme-mode') || 'auto';
        const next = current === 'auto' ? 'light'
                   : current === 'light' ? 'dark'
                   : 'auto';
        setThemeMode(next, btn);
    });

    // Live OS-preference listener — only re-applies while the mode is "auto".
    // A locked choice (light/dark) stays put even when the OS flips.
    if (window.matchMedia) {
        const mq = window.matchMedia('(prefers-color-scheme: dark)');
        const onChange = function () {
            if ((root.getAttribute('data-theme-mode') || 'auto') !== 'auto') return;
            setThemeMode('auto');
        };
        if (mq.addEventListener) mq.addEventListener('change', onChange);
        else if (mq.addListener) mq.addListener(onChange);
    }

    // ----- Header search: ⌘K / Ctrl+K shortcut ---------------------------------
    const isMac = /Mac|iPhone|iPad|iPod/i.test(navigator.platform || navigator.userAgent || '');

    // Below md the search doesn't fit in the header: it lives in a layer opened by
    // the .site-search-toggle button. From md up it's the inline pill and none of
    // this applies.
    const searchToggle = document.querySelector('[data-search-toggle]');
    const searchForm   = document.querySelector('[data-search-form]');
    const searchClose  = document.querySelector('[data-search-close]');
    const searchInput  = document.querySelector('[data-search-input]');
    const overlayMQ    = window.matchMedia('(max-width: 767.98px)');

    function searchIsOpen() {
        return document.documentElement.hasAttribute('data-search-open');
    }

    function openSearch() {
        document.documentElement.setAttribute('data-search-open', '');
        if (searchToggle) searchToggle.setAttribute('aria-expanded', 'true');
        if (searchInput) {
            searchInput.focus();
            searchInput.select();
        }
    }

    // returnFocus only when closing was deliberate (button, Esc): returning focus
    // after a tap outside would be disorienting.
    function closeSearch(returnFocus) {
        if (!searchIsOpen()) return;
        document.documentElement.removeAttribute('data-search-open');
        if (searchToggle) {
            searchToggle.setAttribute('aria-expanded', 'false');
            if (returnFocus) searchToggle.focus();
        }
    }

    if (searchToggle) {
        searchToggle.addEventListener('click', function () {
            if (searchIsOpen()) closeSearch(true);
            else openSearch();
        });
    }

    if (searchClose) {
        searchClose.addEventListener('click', function () { closeSearch(true); });
    }

    // Submitting empty would lead to /?s=, an empty results page with no way
    // forward. With an empty field we stay put and return focus to it (the pill
    // lights its accent border via :focus-within, so there's visible feedback).
    // Also trims surrounding spaces to avoid ?s=%20linux%20.
    if (searchForm) {
        searchForm.addEventListener('submit', function (e) {
            const value = searchInput ? searchInput.value.trim() : '';
            if (!value) {
                e.preventDefault();
                if (overlayMQ.matches && !searchIsOpen()) openSearch();
                else if (searchInput) searchInput.focus();
                return;
            }
            if (searchInput) searchInput.value = value;
        });
    }

    document.addEventListener('click', function (e) {
        if (!searchIsOpen()) return;
        if (searchForm && searchForm.contains(e.target)) return;
        if (searchToggle && searchToggle.contains(e.target)) return;
        closeSearch(false);
    });

    // If the window crosses the breakpoint with the layer open, the inline pill
    // becomes visible and this state no longer makes sense.
    if (overlayMQ.addEventListener) {
        overlayMQ.addEventListener('change', function (e) { if (!e.matches) closeSearch(false); });
    } else if (overlayMQ.addListener) {
        overlayMQ.addListener(function (e) { if (!e.matches) closeSearch(false); });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && searchIsOpen()) {
            closeSearch(true);
            return;
        }
        const cmdK = (isMac ? e.metaKey : e.ctrlKey) && e.key.toLowerCase() === 'k';
        if (!cmdK) return;
        const input = document.querySelector('[data-search-input]');
        if (!input) return;
        e.preventDefault();
        // With a physical keyboard in a narrow window, the shortcut opens the layer:
        // focusing a hidden input would do nothing visible.
        if (overlayMQ.matches) {
            openSearch();
            return;
        }
        input.focus();
        input.select();
    });

    // ----- Reading progress bar (only on article pages) -----------------------
    // The progress should hit 100% when the reader finishes the article body,
    // NOT when they finish scrolling past the comments. So we measure between
    // two anchors:
    //   - .kf-single  → for the START (top of the eyebrow, where reading begins)
    //   - .kf-article--body → for the END (bottom of the body, just before the
    //                         comments slab starts)
    //
    // 0% when the article top reaches the sticky header (~80px from the top).
    // 100% when the body bottom reaches the viewport bottom.
    const progressFill = document.querySelector('.kf-progress__fill');
    const articleTopEl = document.querySelector('.kf-single');
    const articleBodyEl = document.querySelector('.kf-article--body');

    if (progressFill && articleTopEl && articleBodyEl) {
        const updateProgress = () => {
            const topRect  = articleTopEl.getBoundingClientRect();
            const bodyRect = articleBodyEl.getBoundingClientRect();
            const startScroll = (topRect.top + window.scrollY) - 80;
            const endScroll   = (bodyRect.bottom + window.scrollY) - window.innerHeight;
            const range       = endScroll - startScroll;
            const scrolled    = window.scrollY - startScroll;
            const progress    = range > 0
                ? Math.min(100, Math.max(0, (scrolled / range) * 100))
                : 100; // article fits in viewport — call it done
            progressFill.style.width = progress + '%';
        };
        window.addEventListener('scroll', updateProgress, { passive: true });
        updateProgress();
    }

    // ----- Article actions: bookmark / share / copy-link ----------------------
    // Reveal Share button only when the browser supports the Web Share API.
    // (Rendered with `hidden` server-side, so unsupported browsers see nothing.)
    if (navigator.share) {
        document.querySelectorAll('.kf-share-btn[hidden]').forEach((b) => b.removeAttribute('hidden'));
    }

    document.addEventListener('click', function (e) {
        const action = e.target.closest('[data-action]');
        if (!action) return;
        const which = action.getAttribute('data-action');

        if (which === 'copy-link') {
            e.preventDefault();
            const url = action.getAttribute('data-url') || window.location.href;
            navigator.clipboard?.writeText(url).then(() => {
                const original = action.textContent;
                action.textContent = '✓ copiado';
                setTimeout(() => { action.textContent = original; }, 1500);
            });
        }

        if (which === 'share') {
            if (navigator.share) {
                e.preventDefault();
                navigator.share({ title: document.title, url: window.location.href }).catch(() => {});
            }
        }

        if (which === 'bookmark') {
            e.preventDefault();
            const cfg = window.pixelBookmarks;
            if (!cfg || !cfg.ajaxUrl) return; // server didn't render the button → no-op

            const postId = action.getAttribute('data-post-id');
            if (!postId) return;

            // Mirrors the on/off pair in a single helper so we only have to
            // describe the toggle once instead of in three places.
            const setOn = (on) => {
                if (on) {
                    action.setAttribute('data-on', '1');
                    action.setAttribute('aria-pressed', 'true');
                } else {
                    action.removeAttribute('data-on');
                    action.setAttribute('aria-pressed', 'false');
                }
            };

            const wasOn = action.hasAttribute('data-on');
            setOn(!wasOn);  // optimistic flip — revert below on failure
            action.disabled = true;

            const fd = new FormData();
            fd.append('action', 'pixel_toggle_bookmark');
            fd.append('post_id', postId);
            fd.append('nonce', cfg.nonce);

            postJSON(cfg.ajaxUrl, fd)
                .then((r) => {
                    if (!r.ok || !r.success) { setOn(wasOn); return; }
                    const data = r.json.data || {};
                    // Trust the server's `saved` flag — another tab may have
                    // changed state since this click started.
                    setOn(!!data.saved);
                    // Sync the header "Guardados" badge live so the count
                    // reflects the click immediately (no page reload needed).
                    if (typeof data.count === 'number') {
                        updateGuardadosBadge(data.count);
                    }
                })
                .catch(() => { setOn(wasOn); })
                .finally(() => { action.disabled = false; });
        }
    });

    // Updates the "Guardados (N)" badge in the header. The badge is only
    // server-rendered when count > 0, so we may need to create it on first save
    // (count goes 0 → 1) or remove it when the user unsaves their last article.
    function updateGuardadosBadge(count) {
        const link = document.querySelector('.site-subscribe--saved');
        if (!link) return; // only logged-in users have this element

        let badge = link.querySelector('.site-subscribe__badge');
        if (count > 0) {
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'site-subscribe__badge';
                link.appendChild(badge);
            }
            badge.textContent = String(count);
        } else if (badge) {
            badge.remove();
        }
    }

    // GA event: `gtag` only exists if the visitor accepted cookies (inc/gdpr.php).
    // Mark this as a key event in GA to count it as a goal; nothing is sent
    // without consent.
    const trackEvent = (name, params) => {
        try { if (typeof window.gtag === 'function') window.gtag('event', name, params || {}); } catch (e) {}
    };

    // ----- Newsletter form (AJAX) ----------------------------------------------
    // Requests the subscription from the pixel_newsletter_subscribe endpoint,
    // which sends the confirmation email. The cookie is only set on confirmation.
    // Hides the subscribe blocks (home sidebar and end of article) from
    // existing subscribers. Client-side, as the HTML may come from a page cache.
    (function () {
        let sub = document.cookie.split('; ').indexOf('pixel_newsletter_subscribed=1') !== -1;
        if (!sub) sub = safeStorage.get('pixel_newsletter_subscribed') === '1';
        if (sub) document.querySelectorAll('[data-newsletter-block]').forEach((el) => { el.hidden = true; });
    })();

    document.querySelectorAll('form[data-newsletter]').forEach((form) => {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            // Two form variants share this handler: the guest form has a
            // visible <input type="email">, the logged-in form ships the
            // account email in a hidden <input name="email">.
            const visibleInput = form.querySelector('input[type="email"]');
            const hiddenInput  = form.querySelector('input[type="hidden"][name="email"]');
            const email = (visibleInput && visibleInput.value) || (hiddenInput && hiddenInput.value) || '';
            if (!email) return;

            const submit = form.querySelector('button[type="submit"]');
            const message = (text) => {
                const p = document.createElement('p');
                p.style.cssText = 'margin:0;font-size:13px;line-height:1.5;color:inherit;opacity:.85;';
                p.textContent = text;
                return p;
            };
            // Keep the error next to the form so the reader can try again.
            const showError = (text) => {
                let error = form.querySelector('[data-newsletter-error]');
                if (!error) {
                    error = message('');
                    error.setAttribute('data-newsletter-error', '');
                    error.setAttribute('role', 'alert');
                    error.style.flexBasis = '100%';
                    error.style.marginTop = '8px';
                    form.appendChild(error);
                }
                error.textContent = text;
                if (submit) submit.disabled = false;
            };

            const cfg = window.pixelAuth || {};
            if (!cfg.ajaxUrl) {
                showError('Não foi possível subscrever. Recarrega a página.');
                return;
            }

            if (submit) submit.disabled = true;

            postJSON(cfg.ajaxUrl, new FormData(form))
                .then((r) => {
                    const text = r.json && r.json.data && r.json.data.message;
                    if (!r.success) {
                        showError(text || 'Não foi possível subscrever. Tenta novamente.');
                        return;
                    }
                    form.replaceChildren(message(text || 'Enviámos um link de confirmação para ' + email + '.'));
                    trackEvent('generate_lead', { lead_source: 'newsletter', placement: form.dataset.placement || 'home' });
                })
                .catch(() => showError('Erro de rede. Tenta novamente.'));
        });
    });

    // ----- Big kicker auto-fit -------------------------------------------------
    // The featured text card's giant kicker word (.kf-card__big-kicker) must
    // never overflow its card. CSS clamp() scales with the VIEWPORT, so on the
    // desktop→tablet transition (~1215px) the card narrows but the word doesn't,
    // and a long single word gets clipped. Here we measure the actual container
    // width and shrink the font to fit, re-running whenever the card resizes
    // (covers the responsive narrowing and back to full size on mobile).
    (function () {
        const MIN_PX = 24;  // legibility floor
        const REF_PX = 100; // reference size we measure the word width at

        function availWidth(el) {
            // Width comes from the CARD, independent of the kicker's own font —
            // so reading it never reacts to our font-size changes (no RO loop).
            const card = el.closest('.kf-card--text') || el.parentElement;
            if (!card) return 0;
            const cs = getComputedStyle(card);
            return card.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
        }

        function fitKicker(el) {
            const avail = availWidth(el);
            if (avail <= 0 || avail === el._lastAvail) return; // unchanged → skip
            el._lastAvail = avail;

            el.style.fontSize = REF_PX + 'px';
            const textW = el.scrollWidth;            // word width at REF_PX
            el.style.fontSize = '';                  // back to CSS to read the clamp max
            const maxPx = parseFloat(getComputedStyle(el).fontSize) || 49.6;
            if (!textW) return;

            // Size that fits exactly, capped at the design max, floored for legibility.
            let size = (avail / textW) * REF_PX * 0.99;
            size = Math.min(maxPx, Math.max(MIN_PX, size));
            // Only override when we actually need to shrink below the CSS size.
            if (size < maxPx - 0.5) {
                el.style.fontSize = size + 'px';
            }
        }

        const kickers = document.querySelectorAll('.kf-card__big-kicker');
        if (!kickers.length) return;

        const fitAll = () => kickers.forEach(fitKicker);
        fitAll();

        if ('ResizeObserver' in window) {
            let raf = 0;
            const ro = new ResizeObserver(() => {
                cancelAnimationFrame(raf);
                raf = requestAnimationFrame(fitAll);
            });
            kickers.forEach((el) => {
                const card = el.closest('.kf-card--text') || el.parentElement;
                if (card) ro.observe(card); // observe the card width, not the kicker
            });
        } else {
            let t = 0;
            window.addEventListener('resize', () => {
                clearTimeout(t);
                t = setTimeout(fitAll, 120);
            });
        }
    })();

    // ----- Profile page controls --------------------------------------------
    const profile = document.querySelector('.kf-profile');
    if (profile) {
        const cfg = window.pixelAuth || {};

        const showMsg = (target, msg, isError = false) => {
            if (!target) return;
            target.textContent = msg;
            target.hidden = false;
            target.classList.toggle('is-error', isError);
        };

        // Wrapper for profile-page actions — bakes in the action name and the
        // shared nonce so call sites just say what they need.
        const profileAction = (action, extra = {}) =>
            postJSON(cfg.ajaxUrl, new URLSearchParams(Object.assign({
                action,
                _nonce: cfg.profileNonce || '',
            }, extra)));

        // ----- Back link: prefer history.back() when the previous page is on
        // this site, otherwise fall through to the href fallback (home).
        const backLink = profile.querySelector('[data-profile-back]');
        if (backLink) {
            backLink.addEventListener('click', (e) => {
                const ref = document.referrer;
                if (ref && ref.indexOf(window.location.origin) === 0 && ref !== window.location.href) {
                    e.preventDefault();
                    window.history.back();
                }
            });
        }

        // ----- Header user icon as toggle on the profile page.
        // Normally the icon navigates TO /perfil/. While we're already on it,
        // a second click should close/back out instead of re-loading the same
        // URL. This handler only registers here (inside `if (profile)`), so on
        // every other page the icon's default navigation behaviour is intact.
        // Unlike the back link, we ALWAYS preventDefault — falling through to
        // href="/perfil/" would re-navigate to the page we're already on.
        const headerToggle = document.querySelector('[data-profile-toggle]');
        if (headerToggle) {
            headerToggle.addEventListener('click', (e) => {
                e.preventDefault();
                const ref = document.referrer;
                if (ref && ref.indexOf(window.location.origin) === 0 && ref !== window.location.href) {
                    window.history.back();
                } else {
                    window.location.href = '/';
                }
            });
        }

        // ----- Update name
        const nameForm = profile.querySelector('[data-profile-form="name"]');
        if (nameForm) {
            nameForm.addEventListener('submit', (e) => {
                e.preventDefault();
                const msg = nameForm.querySelector('.kf-profile__form-msg');
                msg.hidden = true;
                const fd = new FormData(nameForm);
                fd.append('_nonce', cfg.profileNonce || '');
                postJSON(cfg.ajaxUrl, fd)
                    .then((r) => {
                        const text = (r.json && r.json.data && r.json.data.message)
                            || (r.success ? 'Guardado.' : 'Erro ao guardar.');
                        showMsg(msg, text, !r.success);
                    })
                    .catch(() => showMsg(msg, 'Erro de rede.', true));
            });
        }

        // ----- Newsletter toggle
        const newsletterToggle = profile.querySelector('[data-profile-newsletter]');
        const newsletterMsg = profile.querySelector('[data-profile-newsletter-msg]');
        if (newsletterToggle) {
            newsletterToggle.addEventListener('change', () => {
                const subscribe = newsletterToggle.checked;
                profileAction('pixel_profile_toggle_newsletter', { subscribe: subscribe ? '1' : '' })
                    .then((r) => {
                        if (r.success && r.json.data && r.json.data.pending) {
                            // Only becomes active once the reader confirms by email.
                            newsletterToggle.checked = false;
                            showMsg(newsletterMsg, r.json.data.message, false);
                        } else if (r.success) {
                            showMsg(newsletterMsg, subscribe ? 'Subscrição ativada.' : 'Subscrição cancelada.', false);
                            safeStorage.set('pixel_newsletter_subscribed', subscribe ? '1' : '');
                        } else {
                            newsletterToggle.checked = !subscribe;  // revert UI
                            showMsg(newsletterMsg, (r.json && r.json.data && r.json.data.message) || 'Erro.', true);
                        }
                    })
                    .catch(() => {
                        newsletterToggle.checked = !subscribe;
                        showMsg(newsletterMsg, 'Erro de rede.', true);
                    });
            });
        }

        // ----- Theme picker (Auto / Claro / Escuro)
        // Delegates to the shared setThemeMode helper above so that profile
        // changes, the header toggle, and the OS-pref listener all share a
        // single source of truth — no duplicate localStorage writes, no
        // diverging icon states.
        const setMode = window.__pixelSetThemeMode || function () {};
        const radios = profile.querySelectorAll('[data-profile-theme]');
        const initialVal = document.documentElement.getAttribute('data-theme-mode') || 'auto';
        radios.forEach((r) => { r.checked = (r.value === initialVal); });
        radios.forEach((r) => {
            r.addEventListener('change', () => {
                if (!r.checked) return;
                const group = r.closest('.kf-profile__radio-group');
                if (group) group.setAttribute('data-moved', '');
                setMode(r.value, r.closest('label') || r);
            });
        });

        // ----- Delete account (with double-confirm)
        const deleteBtn = profile.querySelector('[data-profile-delete]');
        const deleteMsg = profile.querySelector('[data-profile-delete-msg]');
        if (deleteBtn) {
            deleteBtn.addEventListener('click', () => {
                const ok1 = window.confirm('Tens a certeza que queres eliminar a tua conta? Esta ação é irreversível.');
                if (!ok1) return;
                const typed = window.prompt('Para confirmar, escreve ELIMINAR (em maiúsculas):');
                if (typed !== 'ELIMINAR') return;

                deleteBtn.disabled = true;
                profileAction('pixel_profile_delete_account')
                    .then((r) => {
                        if (r.success) {
                            window.location.href = (r.json.data && r.json.data.redirect) || '/';
                        } else {
                            deleteBtn.disabled = false;
                            showMsg(deleteMsg, (r.json && r.json.data && r.json.data.message) || 'Erro.', true);
                        }
                    })
                    .catch(() => {
                        deleteBtn.disabled = false;
                        showMsg(deleteMsg, 'Erro de rede.', true);
                    });
            });
        }
    }

    // ----- Auth modal (login + register, AJAX) -------------------------------
    const authModal = document.querySelector('[data-auth-modal]');
    if (authModal) {
        const setBusy = (form, busy) => {
            const submit = form.querySelector('[type="submit"]');
            if (!submit) return;
            submit.disabled = busy;
            const def = submit.querySelector('[data-default-label]');
            const load = submit.querySelector('[data-loading-label]');
            if (def && load) { def.hidden = busy; load.hidden = !busy; }
        };

        const showMessage = (form, selector, msg) => {
            const el = form && form.querySelector(selector);
            if (!el) return;
            el.textContent = msg;
            el.hidden = false;
        };
        const showError = (form, msg) => showMessage(form, '.kf-auth-form__error', msg);
        const showNotice = (form, msg) => showMessage(form, '.kf-auth-form__notice', msg);
        const clearError = (form) => {
            form.querySelectorAll('.kf-auth-form__error, .kf-auth-form__notice').forEach((el) => {
                el.hidden = true;
                el.textContent = '';
            });
        };
        const authForm = (view) => authModal.querySelector('[data-auth-form="' + view + '"]');

        // Panels: login and register have a tab; lost and reset don't (both tabs
        // stay inactive and serve as a way back).
        const showPanel = (view) => {
            if (!authModal.querySelector('[data-auth-panel="' + view + '"]')) view = 'login';
            authModal.querySelectorAll('[data-auth-panel]').forEach((p) => {
                p.hidden = p.getAttribute('data-auth-panel') !== view;
            });
            authModal.querySelectorAll('[data-auth-tab]').forEach((b) => {
                const on = b.getAttribute('data-auth-tab') === view;
                b.classList.toggle('is-active', on);
                b.setAttribute('aria-selected', on ? 'true' : 'false');
            });
        };

        // Focus the first input on next frame so the panel is visible
        const focusFirst = () => {
            requestAnimationFrame(() => {
                const active = authModal.querySelector('.kf-auth__panel-content:not([hidden]) input:not([type="hidden"]):not([type="checkbox"])');
                if (active && active.offsetParent !== null) active.focus();
            });
        };

        // Animated close in progress (see closeAuth): reopening must cancel it.
        let closeTimer = null;

        const openAuth = (view) => {
            clearTimeout(closeTimer);
            authModal.classList.remove('is-closing');
            authModal.hidden = false;
            authModal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            showPanel(view || 'login');
            focusFirst();
        };

        // Recovery email link: opens the new password panel and validates the key
        // right away, to avoid asking for a password that would then be refused.
        const openReset = (login, key) => {
            const form = authForm('reset');
            if (!form) return;
            const fields = form.querySelector('[data-reset-fields]');
            const invalid = form.querySelector('[data-reset-invalid]');
            const fail = (msg) => {
                showError(form, msg || 'Esta ligação não é válida ou já foi usada. Pede uma nova.');
                if (fields) fields.hidden = true;
                if (invalid) invalid.hidden = false;
            };

            form.reset();
            clearError(form);
            if (fields) fields.hidden = false;
            if (invalid) invalid.hidden = true;
            form.elements.login.value = login || '';
            form.elements.key.value = key || '';
            openAuth('reset');

            const cfg = window.pixelAuth || {};
            if (!login || !key || !cfg.ajaxUrl) { fail(); return; }

            const fd = new FormData();
            fd.append('action', 'pixel_auth_checkreset');
            fd.append('_nonce', form.elements._nonce.value);
            fd.append('login', login);
            fd.append('key', key);
            setBusy(form, true);
            postJSON(cfg.ajaxUrl, fd)
                .then((r) => {
                    setBusy(form, false);
                    if (!r.success) fail(r.json && r.json.data && r.json.data.message);
                })
                .catch(() => {
                    setBusy(form, false);
                    showError(form, 'Erro de rede. Tenta novamente.');
                });
        };

        // Fragments that open the modal. The login/registration/recovery links point
        // here (inc/hidden-login.php) rather than to the secret login slug. The reset
        // key arrives in the fragment, which browsers don't send to the server, and is
        // removed from the address bar right after.
        const HASH_VIEWS = {
            'entrar': 'login',
            'criar-conta': 'register',
            'recuperar-password': 'lost',
            'redefinir-password': 'reset',
        };
        const openFromHash = () => {
            const raw = window.location.hash.slice(1);
            const sep = raw.indexOf('?');
            const name = sep === -1 ? raw : raw.slice(0, sep);
            const view = HASH_VIEWS[name];
            if (!view) return;
            history.replaceState(null, '', window.location.pathname + window.location.search);
            if (view === 'reset') {
                const params = new URLSearchParams(sep === -1 ? '' : raw.slice(sep + 1));
                openReset(params.get('login'), params.get('key'));
            } else {
                openAuth(view);
            }
        };

        // The exit animation (.is-closing, _auth-modal.scss) lasts 180ms; `hidden` is
        // only set at the end. A timer rather than animationend: with reduced motion
        // the animation is cancelled and the event may never fire.
        const closeAuth = () => {
            if (authModal.hidden || authModal.classList.contains('is-closing')) return;
            authModal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
            authModal.classList.add('is-closing');
            closeTimer = setTimeout(() => {
                authModal.classList.remove('is-closing');
                authModal.hidden = true;
            }, 180);
        };

        // Open from any element with [data-auth-open]
        document.addEventListener('click', (e) => {
            const opener = e.target.closest('[data-auth-open]');
            if (!opener) return;
            e.preventDefault();
            openAuth(opener.getAttribute('data-auth-open') || 'login');
        });

        // Close: backdrop, close button, ESC
        authModal.addEventListener('click', (e) => {
            if (e.target.closest('[data-auth-close]')) {
                e.preventDefault();
                closeAuth();
            }
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !authModal.hidden) closeAuth();
        });

        // Tab switching
        authModal.querySelectorAll('[data-auth-tab]').forEach((btn) => {
            btn.addEventListener('click', () => {
                // The glass thumb only plays its "droplet" animation after a click.
                btn.parentElement.setAttribute('data-moved', '');
                showPanel(btn.getAttribute('data-auth-tab'));
            });
        });

        openFromHash();
        window.addEventListener('hashchange', openFromHash);

        // Show/hide password — also flips the Material Symbol between
        // "visibility" and "visibility_off" so the icon reflects state.
        authModal.querySelectorAll('[data-toggle-pass]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const input = btn.parentElement.querySelector('input');
                if (!input) return;
                const showing = input.type === 'text';
                input.type = showing ? 'password' : 'text';
                const icon = btn.querySelector('.material-symbols-outlined');
                if (icon) icon.textContent = showing ? 'visibility' : 'visibility_off';
            });
        });

        // Submit handler — login, register, lost & reset. The success response
        // carries `redirect` (login/register) or `message` (+ optional `view`, the
        // panel to go to: reset returns to login with the notice).
        authModal.querySelectorAll('[data-auth-form]').forEach((form) => {
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                clearError(form);
                setBusy(form, true);

                const cfg = window.pixelAuth || {};
                if (!cfg.ajaxUrl) {
                    showError(form, 'Configuração em falta. Recarrega a página.');
                    setBusy(form, false);
                    return;
                }

                postJSON(cfg.ajaxUrl, new FormData(form))
                    .then((r) => {
                        const data = (r.json && r.json.data) || {};
                        if (r.ok && r.success && data.redirect) {
                            const kind = form.getAttribute('data-auth-form');
                            if (kind === 'register') trackEvent('sign_up', { method: 'email' });
                            else if (kind === 'login') trackEvent('login', { method: 'email' });
                            window.location.href = data.redirect;
                        } else if (r.ok && r.success) {
                            setBusy(form, false);
                            form.reset();
                            const next = data.view && authForm(data.view);
                            if (next) {
                                clearError(next);
                                showPanel(data.view);
                                focusFirst();
                            }
                            if (data.message) showNotice(next || form, data.message);
                        } else {
                            const msg = (r.json && r.json.data && r.json.data.message) || 'Não foi possível concluir. Tenta novamente.';
                            showError(form, msg);
                            setBusy(form, false);
                        }
                    })
                    .catch(() => {
                        showError(form, 'Erro de rede. Tenta novamente.');
                        setBusy(form, false);
                    });
            });
        });
    }

})();

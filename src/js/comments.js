/* =============================================================================
 * PIXEL Magazine — AJAX comments
 *
 * Intercepts the native WP comment form and posts via fetch() to the
 * `pixel_submit_comment` admin-ajax endpoint. The endpoint runs the regular
 * WP comment lifecycle (validation, akismet, moderation) and returns the
 * rendered comment HTML, which we splice into the thread without a reload.
 *
 * Replies use WP's built-in `comment_reply_link` machinery: WordPress
 * relocates the form under the parent comment and writes the parent ID into
 * the hidden `comment_parent` field. We just submit whatever's there.
 * ============================================================================ */
(function () {
    'use strict';

    if (typeof window.pixelComments === 'undefined') return;

    const cfg  = window.pixelComments;
    const form = document.getElementById('commentform');
    if (!form) return;

    const submitBtn    = form.querySelector('button[type="submit"], input[type="submit"]');
    const errorBox     = form.querySelector('.kf-comment-form__error');
    const defaultLbl   = (submitBtn && submitBtn.getAttribute('data-default-label')) || 'Publicar →';
    let   listEl       = document.querySelector('.kf-comment-list');
    const commentsInner = document.querySelector('.kf-comments__inner');

    function setSending(on) {
        if (!submitBtn) return;
        submitBtn.disabled = on;
        form.classList.toggle('is-sending', on);
        submitBtn.textContent = on ? cfg.i18n.sending : defaultLbl;
    }

    function showError(msg) {
        if (!errorBox) return;
        errorBox.textContent = msg || cfg.i18n.errorGeneric;
        errorBox.hidden = false;
    }

    function clearError() {
        if (!errorBox) return;
        errorBox.textContent = '';
        errorBox.hidden = true;
    }

    // If this is the first comment on the post the .kf-comment-list <ol>
    // doesn't exist yet — create it so the new comment has a home.
    function ensureList() {
        if (listEl) return listEl;
        if (!commentsInner) return null;
        const ol = document.createElement('ol');
        ol.className = 'kf-comment-list';
        const respond = document.getElementById('respond');
        const anchorEl = respond || form;
        if (anchorEl && anchorEl.parentNode) {
            anchorEl.parentNode.insertBefore(ol, anchorEl.nextSibling);
        } else {
            commentsInner.appendChild(ol);
        }
        listEl = ol;
        return ol;
    }

    function htmlToElement(html) {
        const tpl = document.createElement('template');
        tpl.innerHTML = (html || '').trim();
        return tpl.content.firstElementChild;
    }

    function findParentRepliesList(parentId) {
        const parentEl = document.getElementById('comment-' + parentId);
        if (!parentEl) return null;

        // Look for an existing nested <ol class="children">; otherwise create.
        const existing = parentEl.querySelector(':scope > ol.children, :scope > .children');
        if (existing) return existing;

        const ol = document.createElement('ol');
        ol.className = 'children';
        parentEl.appendChild(ol);
        return ol;
    }

    function insertNewComment(payload) {
        const node = htmlToElement(payload.html);
        if (!node) return null;

        if (payload.parent_id && payload.parent_id > 0) {
            const nest = findParentRepliesList(payload.parent_id);
            if (nest) {
                nest.appendChild(node);
                return node;
            }
        }

        const ol = ensureList();
        if (!ol) return null;

        // Newest top-level comments go to the bottom (asc by date — same as WP).
        ol.appendChild(node);
        return node;
    }

    function resetForm() {
        // WP's addComment relocates the form when "Responder" is clicked. If
        // the user submitted from that relocated state, cancel it so the form
        // returns to its original position.
        if (window.addComment && typeof window.addComment.cancelEvent === 'function') {
            const parentInput = form.querySelector('#comment_parent');
            if (parentInput && parentInput.value && parentInput.value !== '0') {
                try { window.addComment.cancelEvent({ preventDefault() {} }); } catch (e) {}
            }
        }

        const ta = form.querySelector('textarea[name="comment"]');
        if (ta) ta.value = '';
    }

    function flashAndScroll(node, payload) {
        if (!node) return;
        if (payload.pending_notice) node.classList.add('is-pending');
        node.classList.add('is-fresh');
        try { node.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
        catch (e) { node.scrollIntoView(); }
        setTimeout(() => node.classList.remove('is-fresh'), 1800);
    }

    function bumpCommentCount() {
        const titleSmall = document.querySelector('.kf-comments__title small');
        if (!titleSmall) return;
        const match = (titleSmall.textContent || '').match(/(\d+)/);
        if (!match) return;
        const n = parseInt(match[1], 10) + 1;
        titleSmall.textContent = '· ' + n;
    }

    form.addEventListener('submit', function (e) {
        const ta = form.querySelector('textarea[name="comment"]');
        if (!ta || ta.value.trim() === '') return; // let the browser show "required"

        e.preventDefault();
        clearError();
        setSending(true);

        const fd = new FormData(form);
        fd.append('action', 'pixel_submit_comment');
        // Defensive: ensure post id + nonce are present even if a plugin stripped
        // the hidden inputs from the form.
        if (!fd.get('comment_post_ID') && cfg.postId) fd.append('comment_post_ID', String(cfg.postId));
        if (!fd.get('pixel_comment_nonce') && cfg.nonce) fd.append('pixel_comment_nonce', cfg.nonce);

        fetch(cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: fd,
            headers: { 'Accept': 'application/json' },
        })
            .then((res) => res.json().then((json) => ({ ok: res.ok, json })))
            .then((r) => {
                if (!r.ok || !r.json || r.json.success !== true) {
                    const msg = r.json && r.json.data && r.json.data.message;
                    showError(msg || cfg.i18n.errorGeneric);
                    setSending(false);
                    return;
                }

                const payload = r.json.data;
                const node    = insertNewComment(payload);
                resetForm();
                setSending(false);
                flashAndScroll(node, payload);
                bumpCommentCount();
            })
            .catch(() => {
                showError(cfg.i18n.errorGeneric);
                setSending(false);
            });
    });

    // Note: WP's /wp-includes/js/comment-reply.js (enqueued by the theme)
    // handles the "Responder" click → relocate #respond under the parent
    // comment → set #comment_parent. We just submit whatever's in the form.

})();

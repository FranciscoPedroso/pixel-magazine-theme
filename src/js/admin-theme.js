const STORAGE_KEY = 'newpixel-admin-theme';
const DARK_STYLESHEET_ID = 'newpixel-admin-dark-mode-css';
const EDITOR_STYLESHEET_FRAGMENT = 'admin-editor-dark-mode.css';
const systemDarkMode = window.matchMedia('(prefers-color-scheme: dark)');

function getSavedTheme() {
    try {
        const savedTheme = window.localStorage.getItem(STORAGE_KEY);
        return savedTheme === 'dark' || savedTheme === 'light' ? savedTheme : null;
    } catch (error) {
        return null;
    }
}

function getResolvedTheme() {
    return getSavedTheme() || (systemDarkMode.matches ? 'dark' : 'light');
}

function syncEditor(editor, theme) {
    if (!editor || !editor.initialized) {
        return;
    }

    try {
        const editorDocument = editor.getDoc();
        if (!editorDocument) {
            return;
        }

        editorDocument.documentElement.dataset.npAdminTheme = theme;

        const darkEditorStylesheet = editorDocument.querySelector(
            `link[href*="${EDITOR_STYLESHEET_FRAGMENT}"]`
        );

        if (darkEditorStylesheet) {
            darkEditorStylesheet.media = theme === 'dark' ? 'all' : 'not all';
        }
    } catch (error) {
        // TinyMCE may be between teardown and initialization during navigation.
    }
}

function syncEditors(theme) {
    if (!window.tinymce || !Array.isArray(window.tinymce.editors)) {
        return;
    }

    window.tinymce.editors.forEach((editor) => syncEditor(editor, theme));
}

function applyTheme(theme, persist = false) {
    const resolvedTheme = theme === 'dark' ? 'dark' : 'light';
    const darkStylesheet = document.getElementById(DARK_STYLESHEET_ID);
    const toggle = document.getElementById('newpixel-admin-theme-toggle');

    document.documentElement.dataset.npAdminTheme = resolvedTheme;

    if (darkStylesheet) {
        darkStylesheet.media = resolvedTheme === 'dark' ? 'all' : 'not all';
    }

    if (toggle) {
        const isDark = resolvedTheme === 'dark';
        const actionLabel = isDark ? 'Mudar para tema claro' : 'Mudar para tema escuro';

        toggle.dataset.theme = resolvedTheme;
        toggle.setAttribute('aria-pressed', isDark ? 'true' : 'false');
        toggle.setAttribute('aria-label', actionLabel);
        toggle.setAttribute('title', actionLabel);
    }

    if (persist) {
        try {
            window.localStorage.setItem(STORAGE_KEY, resolvedTheme);
        } catch (error) {
            // The theme still changes for this page if storage is unavailable.
        }
    }

    syncEditors(resolvedTheme);
}

function bindThemeToggle() {
    const toggle = document.getElementById('newpixel-admin-theme-toggle');

    if (!toggle) {
        return;
    }

    toggle.addEventListener('click', (event) => {
        event.stopPropagation();
        applyTheme(getResolvedTheme() === 'dark' ? 'light' : 'dark', true);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    applyTheme(getResolvedTheme());
    bindThemeToggle();

    if (window.jQuery) {
        window.jQuery(document).on('tinymce-editor-init.newpixelAdminTheme', (event, editor) => {
            syncEditor(editor, getResolvedTheme());
        });
    }
});

systemDarkMode.addEventListener('change', () => {
    if (!getSavedTheme()) {
        applyTheme(getResolvedTheme());
    }
});

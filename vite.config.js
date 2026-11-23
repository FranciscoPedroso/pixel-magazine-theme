import { defineConfig } from 'vite';
import { resolve } from 'path';

// Pixel Magazine — Vite build config
//
// Outputs predictable files into dist/, consumed by the theme's PHP loaders
// via wp_enqueue_script / wp_enqueue_style with a versioned query string:
//   • dist/main.js      — site-wide JS (theme, header, profile, auth, etc.)
//   • dist/comments.js  — only enqueued on singular posts with comments open
//   • dist/style.css    — full theme stylesheet
//   • dist/admin-dark-mode.css — automatic dark palette for wp-admin only
//   • dist/admin-editor-dark-mode.css — dark content palette inside TinyMCE
//   • dist/admin-theme-switcher.css — persistent top-bar selector styles
//   • dist/admin-views-widget.css — responsive views report on the Dashboard
//   • dist/admin-theme.js — admin theme preference and editor synchronization
//
// Defaults we rely on (Vite v5):
//   • build.minify          → 'esbuild' (fastest, ~98% of terser quality)
//   • build.cssMinify       → 'esbuild'
//   • build.target          → 'modules'  (es2020-ish; modern browsers)
//   • build.sourcemap       → false      (set to true here when debugging)
//   • build.cssCodeSplit    → true       (no-op since we don't import CSS from JS)
export default defineConfig({
    css: {
        preprocessorOptions: {
            scss: { api: 'modern-compiler' },
        },
    },
    build: {
        outDir: 'dist',
        emptyOutDir: true,
        // Stable filenames so PHP can reference dist/main.js etc. by name and
        // version them with a query string instead of hashed filenames.
        rollupOptions: {
            input: {
                main:     resolve(__dirname, 'src/js/main.js'),
                comments: resolve(__dirname, 'src/js/comments.js'),
                'admin-theme': resolve(__dirname, 'src/js/admin-theme.js'),
                style:    resolve(__dirname, 'src/scss/main.scss'),
                'admin-dark-mode': resolve(__dirname, 'src/scss/admin-dark-mode.scss'),
                'admin-editor-dark-mode': resolve(__dirname, 'src/scss/admin-editor-dark-mode.scss'),
                'admin-theme-switcher': resolve(__dirname, 'src/scss/admin-theme-switcher.scss'),
                'admin-views-widget': resolve(__dirname, 'src/scss/admin-views-widget.scss'),
            },
            output: {
                entryFileNames: '[name].js',
                chunkFileNames: '[name].js',
                assetFileNames: '[name].[ext]',
            },
        },
    },
});

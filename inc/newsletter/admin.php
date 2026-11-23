<?php
/**
 * Newsletter — WP-admin UI: meta boxes, action buttons, endpoints
 * ================================================================
 *
 * Everything the editor sees inside wp-admin when working with the
 * `pixel_newsletter` CPT lives here:
 *   • newpixel_newsletter_default_title()       — pre-fills the title on "Add New"
 *   • newpixel_newsletter_register_meta_boxes() — wires up the 3 metaboxes
 *   • newpixel_newsletter_headline_metabox()    — editable headline (the only knob)
 *   • newpixel_newsletter_posts_metabox()       — read-only article preview
 *   • newpixel_newsletter_actions_metabox()     — approval toggle, preview, send-now
 *   • newpixel_newsletter_save_metaboxes()      — persist headline + approval
 *   • newpixel_newsletter_handle_preview()      — opens email HTML in a new tab
 *   • newpixel_newsletter_handle_send_now()     — manual override of the cron
 *   • newpixel_newsletter_handle_seed_now()     — runs the picker on demand
 *   • newpixel_newsletter_admin_notices()       — banner after Send Now redirect
 *
 * @package newpixel
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// ============================================================================
// Default title — pre-fill "Add New" so manual and cron drafts look alike
// ============================================================================

add_filter( 'default_title', 'newpixel_newsletter_default_title', 10, 2 );
function newpixel_newsletter_default_title( $post_title, $post ) {
    if ( $post && $post->post_type === NEWPIXEL_NEWSLETTER_CPT ) {
        return function_exists( 'newpixel_newsletter_generate_title' )
            ? newpixel_newsletter_generate_title()
            : sprintf( 'Edição #%d · %s · %s', 23, newpixel_newsletter_weekday_pt(), newpixel_date_pt( 'j M Y' ) );
    }
    return $post_title;
}

// ============================================================================
// Meta boxes — 3 panels on the edit screen
// ============================================================================

add_action( 'add_meta_boxes', 'newpixel_newsletter_register_meta_boxes' );
function newpixel_newsletter_register_meta_boxes() {
    add_meta_box(
        'newpixel_newsletter_headline',
        __( 'Editorial Headline', 'newpixel' ),
        'newpixel_newsletter_headline_metabox',
        NEWPIXEL_NEWSLETTER_CPT,
        'normal',
        'high'
    );
    add_meta_box(
        'newpixel_newsletter_posts_preview',
        __( 'Selected Articles', 'newpixel' ),
        'newpixel_newsletter_posts_metabox',
        NEWPIXEL_NEWSLETTER_CPT,
        'normal',
        'default'
    );
    add_meta_box(
        'newpixel_newsletter_actions',
        __( 'Actions', 'newpixel' ),
        'newpixel_newsletter_actions_metabox',
        NEWPIXEL_NEWSLETTER_CPT,
        'side',
        'high'
    );
}

/**
 * Headline metabox — the ONE thing the editor can change.
 * A simple textarea with HTML allowed (so paragraphs, <strong>, links).
 */
function newpixel_newsletter_headline_metabox( $post ) {
    wp_nonce_field( 'newpixel_newsletter_headline', 'newpixel_newsletter_headline_nonce' );
    $headline = (string) get_post_meta( $post->ID, NEWPIXEL_NEWSLETTER_META_HEADLINE, true );
    if ( ! $headline ) {
        $headline = function_exists( 'newpixel_newsletter_pick_random_headline' )
            ? newpixel_newsletter_pick_random_headline()
            : '<p>Cinco artigos. Cinco minutos.</p>';
    }
    ?>
    <p class="description" style="margin:0 0 8px;">
        <?php esc_html_e( 'Introductory paragraph(s) displayed before the articles. Supports simple HTML (paragraphs, <em>italic</em>, <strong>bold</strong>, links).', 'newpixel' ); ?>
    </p>
    <textarea name="newpixel_newsletter_headline" rows="6" style="width:100%;font-family:Georgia,serif;font-size:15px;line-height:1.55;"><?php echo esc_textarea( $headline ); ?></textarea>
    <?php
}

/**
 * Helper to render the ordered list of picked posts in the metabox.
 */
function newpixel_newsletter_render_posts_metabox_list( array $post_ids ) {
    $out = '<ol style="margin:0;padding-left:20px;">';
    foreach ( $post_ids as $i => $pid ) {
        $p = get_post( $pid );
        if ( ! $p ) continue;
        $label = $i === 0 ? ' <strong>(' . esc_html__( 'featured', 'newpixel' ) . ')</strong>' : '';
        $views = function_exists( 'newpixel_get_view_count' ) ? newpixel_get_view_count( $pid ) : (int) get_post_meta( $pid, '_newpixel_views', true );
        $out .= sprintf(
            '<li style="margin:6px 0;"><a href="%s" target="_blank">%s</a>%s <span style="opacity:0.7;font-size:12px;">— %s views</span></li>',
            esc_url( get_edit_post_link( $pid ) ),
            esc_html( get_the_title( $pid ) ),
            $label,
            esc_html( function_exists( 'newpixel_format_view_count' ) ? newpixel_format_view_count( $views ) : (string) $views )
        );
    }
    $out .= '</ol>';
    return $out;
}

/**
 * Read-only list of the picked posts so the editor sees what's going out.
 * For newly created drafts, offers a "Generate selection now" button that
 * fetches the articles asynchronously via AJAX (keeping the title input
 * and any typed headline untouched in the form) with a seamless fallback.
 */
function newpixel_newsletter_posts_metabox( $post ) {
    $raw = get_post_meta( $post->ID, NEWPIXEL_NEWSLETTER_META_POST_IDS, true );
    // get_post_meta returns '' for missing meta; cast to array gives ['']
    // which fails the empty() check. Filter to real post IDs only.
    $post_ids = is_array( $raw ) ? array_filter( array_map( 'intval', $raw ) ) : array();

    $generate_url = wp_nonce_url(
        admin_url( 'admin-post.php?action=pixel_newsletter_seed_now&issue=' . $post->ID ),
        'pixel_newsletter_seed_now_' . $post->ID
    );
    $nonce = wp_create_nonce( 'pixel_newsletter_seed_now_' . $post->ID );
    ?>
    <div id="newpixel-newsletter-posts-box" data-issue-id="<?php echo (int) $post->ID; ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
        <?php if ( empty( $post_ids ) ) : ?>
            <div id="newpixel-newsletter-posts-empty">
                <p style="margin:0 0 12px;opacity:0.8;">
                    <?php esc_html_e( 'Empty list. Click "Generate selection now" to fetch the 5 most-viewed posts from the last 4 days (with fallback to recent posts).', 'newpixel' ); ?>
                </p>
                <p style="margin:0;">
                    <a href="<?php echo esc_url( $generate_url ); ?>" id="newpixel-seed-posts-btn" class="button button-secondary" style="display:inline-flex; align-items:center; gap:6px; line-height:1;">
                        <span class="dashicons dashicons-update" style="font-size:16px; width:16px; height:16px; line-height:1;"></span>
                        <span class="btn-label" style="line-height:1.5;"><?php esc_html_e( 'Generate selection now', 'newpixel' ); ?></span>
                    </a>
                    <span id="newpixel-seed-spinner" class="spinner" style="float:none; margin:0 0 0 4px; vertical-align:middle;"></span>
                </p>
                <div id="newpixel-seed-error" style="display:none;margin-top:8px;color:#b32d2e;font-size:13px;"></div>
            </div>
            <div id="newpixel-newsletter-posts-list" style="display:none;"></div>
        <?php else : ?>
            <div id="newpixel-newsletter-posts-list">
                <?php echo newpixel_newsletter_render_posts_metabox_list( $post_ids ); ?>
            </div>
        <?php endif; ?>
    </div>
    <script>
    (function(){
        var btn = document.getElementById('newpixel-seed-posts-btn');
        if (!btn) return;
        btn.addEventListener('click', function(e){
            e.preventDefault();
            var box = document.getElementById('newpixel-newsletter-posts-box');
            var spinner = document.getElementById('newpixel-seed-spinner');
            var errorBox = document.getElementById('newpixel-seed-error');
            var emptyBox = document.getElementById('newpixel-newsletter-posts-empty');
            var listBox = document.getElementById('newpixel-newsletter-posts-list');
            if (!box) return;
            var issueId = box.getAttribute('data-issue-id');
            var nonce = box.getAttribute('data-nonce');

            btn.disabled = true;
            btn.classList.add('disabled');
            if (spinner) spinner.classList.add('is-active');
            if (errorBox) errorBox.style.display = 'none';

            var formData = new FormData();
            formData.append('action', 'pixel_newsletter_seed_now');
            formData.append('issue', issueId);
            formData.append('_wpnonce', nonce);

            fetch(ajaxurl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(function(res){ return res.json(); })
            .then(function(data){
                if (spinner) spinner.classList.remove('is-active');
                if (data && data.success && data.data && data.data.html) {
                    if (emptyBox) emptyBox.style.display = 'none';
                    if (listBox) {
                        listBox.innerHTML = data.data.html;
                        listBox.style.display = 'block';
                    }
                    var headlineTextarea = document.querySelector('textarea[name="newpixel_newsletter_headline"]');
                    if (headlineTextarea && !headlineTextarea.value.trim() && data.data.headline) {
                        headlineTextarea.value = data.data.headline;
                    }
                } else {
                    btn.disabled = false;
                    btn.classList.remove('disabled');
                    var msg = (data && data.data && data.data.message) ? data.data.message : 'Error generating selection.';
                    if (errorBox) {
                        errorBox.textContent = msg;
                        errorBox.style.display = 'block';
                    } else {
                        alert(msg);
                    }
                }
            })
            .catch(function(err){
                if (spinner) spinner.classList.remove('is-active');
                btn.disabled = false;
                btn.classList.remove('disabled');
                if (errorBox) {
                    errorBox.textContent = 'Connection error: ' + (err.message || 'Network failure');
                    errorBox.style.display = 'block';
                }
            });
        });
    })();
    </script>
    <?php
}

// Handler for the "Generate selection now" button (both AJAX and standard POST/GET)
add_action( 'admin_post_pixel_newsletter_seed_now', 'newpixel_newsletter_handle_seed_now' );
add_action( 'wp_ajax_pixel_newsletter_seed_now',       'newpixel_newsletter_handle_seed_now' );
function newpixel_newsletter_handle_seed_now() {
    if ( ! current_user_can( 'edit_posts' ) ) {
        if ( wp_doing_ajax() ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'newpixel' ) ), 403 );
        }
        wp_die( 'forbidden', 403 );
    }
    $issue_id = isset( $_REQUEST['issue'] ) ? (int) $_REQUEST['issue'] : 0;
    check_admin_referer( 'pixel_newsletter_seed_now_' . $issue_id );

    $post_ids = newpixel_newsletter_pick_posts( 5, 4 );
    if ( empty( $post_ids ) ) {
        if ( wp_doing_ajax() ) {
            wp_send_json_error( array( 'message' => __( 'Not enough posts found to generate a selection.', 'newpixel' ) ) );
        }
        wp_safe_redirect( get_edit_post_link( $issue_id, 'redirect' ) );
        exit;
    }

    update_post_meta( $issue_id, NEWPIXEL_NEWSLETTER_META_POST_IDS, $post_ids );

    // Also seed a random headline if there isn't one yet
    $headline = (string) get_post_meta( $issue_id, NEWPIXEL_NEWSLETTER_META_HEADLINE, true );
    if ( ! $headline ) {
        $headline = function_exists( 'newpixel_newsletter_pick_random_headline' )
            ? newpixel_newsletter_pick_random_headline()
            : '<p>Cinco artigos. Cinco minutos.</p>';
        update_post_meta( $issue_id, NEWPIXEL_NEWSLETTER_META_HEADLINE, $headline );
    }

    // Recover / ensure title in DB for auto-drafts (especially when accessed non-AJAX)
    $issue = get_post( $issue_id );
    if ( $issue ) {
        $current_title = trim( $issue->post_title );
        if ( empty( $current_title ) || strcasecmp( $current_title, 'Auto Draft' ) === 0 ) {
            $default_title = function_exists( 'newpixel_newsletter_generate_title' )
                ? newpixel_newsletter_generate_title()
                : sprintf( 'Edição #23 · %s · %s', newpixel_newsletter_weekday_pt(), newpixel_date_pt( 'j M Y' ) );
            wp_update_post( array(
                'ID'          => $issue_id,
                'post_title'  => $default_title,
                'post_status' => 'draft',
            ) );
        }
    }

    if ( wp_doing_ajax() ) {
        $html = newpixel_newsletter_render_posts_metabox_list( $post_ids );
        wp_send_json_success( array(
            'html'     => $html,
            'headline' => $headline,
        ) );
    }

    wp_safe_redirect( get_edit_post_link( $issue_id, 'redirect' ) );
    exit;
}

/**
 * Action buttons: approval toggle, preview, Send Now, sent-status banner.
 *
 * The approval toggle is the gate the editor controls — until it's
 * checked, the 08:00/12:00/18:00 send-window crons skip this draft.
 * "Send Now" is a separate manual override that bypasses approval (so
 * the editor can fire an out-of-schedule edition if they want).
 */
function newpixel_newsletter_actions_metabox( $post ) {
    $sent_at = get_post_meta( $post->ID, NEWPIXEL_NEWSLETTER_META_SENT_AT, true );
    $sent_count = (int) get_post_meta( $post->ID, NEWPIXEL_NEWSLETTER_META_SENT_COUNT, true );

    if ( $sent_at ) {
        printf(
            '<p class="newpixel-newsletter-sent-status" style="padding:8px;background:#e7f5ea;border-left:3px solid #2e7d32;margin:0;">✓ Sent on <strong>%s</strong> to <strong>%d</strong> subscriber(s).</p>',
            esc_html( newpixel_date_pt( 'j M Y · H:i', $sent_at ) ),
            $sent_count
        );
        return;
    }

    $approved = (string) get_post_meta( $post->ID, NEWPIXEL_NEWSLETTER_META_APPROVED, true ) === '1';

    $preview_url = wp_nonce_url(
        admin_url( 'admin-ajax.php?action=pixel_newsletter_preview&issue=' . $post->ID ),
        'pixel_newsletter_preview_' . $post->ID
    );
    $send_url = wp_nonce_url(
        admin_url( 'admin-post.php?action=pixel_newsletter_send_now&issue=' . $post->ID ),
        'pixel_newsletter_send_now_' . $post->ID
    );

    $subscribers = count( newpixel_newsletter_get_subscriber_emails() );

    wp_nonce_field( 'newpixel_newsletter_approval', 'newpixel_newsletter_approval_nonce' );
    ?>

    <!-- ============ Approval gate ============ -->
    <div class="newpixel-newsletter-approval newpixel-newsletter-approval--<?php echo $approved ? 'approved' : 'pending'; ?>" style="padding:12px;border:1px solid <?php echo $approved ? '#2e7d32' : '#dba617'; ?>;background:<?php echo $approved ? '#e7f5ea' : '#fcf9e8'; ?>;border-radius:4px;margin:0 0 14px;">
        <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-weight:600;line-height:1.4;">
            <input type="checkbox" name="newpixel_newsletter_approved" value="1" <?php checked( $approved ); ?> style="margin-top:2px;flex-shrink:0;">
            <span>
                <?php echo $approved ? '✓ Approved for automatic sending' : '○ Approve for automatic sending'; ?>
            </span>
        </label>
        <p class="description" style="margin:8px 0 0;font-weight:400;">
            <?php if ( $approved ) : ?>
                Approved. When the issue is <strong>Published</strong>, it will be dispatched on the next <strong>Tuesday or Friday</strong> (08:00 / 12:00 / 18:00) to <strong><?php echo (int) $subscribers; ?></strong> subscriber(s), and then moved to trash. To stop, uncheck and <em>Update</em>.
            <?php else : ?>
                Check this box <strong>and Publish</strong> the issue. On the next <strong>Tuesday or Friday</strong> (08:00 / 12:00 / 18:00), all <strong>published and approved</strong> issues are dispatched and moved to trash. <strong>Drafts are ignored.</strong>
            <?php endif; ?>
        </p>
    </div>

    <!-- ============ Preview ============ -->
    <p style="margin:0 0 8px;">
        <a href="<?php echo esc_url( $preview_url ); ?>" target="_blank" class="button button-secondary" style="width:100%;text-align:center;">👁 Browser preview</a>
    </p>

    <!-- ============ Manual send (override) ============ -->
    <p style="margin:0 0 8px;">
        <a href="<?php echo esc_url( $send_url ); ?>"
           class="button"
           style="width:100%;text-align:center;"
           onclick="return confirm('Send NOW to <?php echo (int) $subscribers; ?> subscriber(s)? This overrides the automatic send window.');">
            ✈ Send now (override)
        </a>
    </p>
    <p class="description" style="margin:8px 0 0;font-size:11px;">
        Automatic dispatch: <strong>Tuesday and Friday</strong> at <strong>08:00 / 12:00 / 18:00</strong>. Dispatches all <strong>published + approved</strong> issues and moves them to trash. Drafts are ignored.<br>
        Registered next edition: <strong>#<?php echo (int) ( function_exists( 'newpixel_newsletter_get_next_edition_number' ) ? newpixel_newsletter_get_next_edition_number() : 23 ); ?></strong> (automatically incremented by +1 when sent).
    </p>
    <?php
}

// Save both the headline and the approval flag when the draft is updated.
// Two separate nonces so each metabox is independently validated — if
// you ever split them into different screens, neither breaks the other.
add_action( 'save_post_' . NEWPIXEL_NEWSLETTER_CPT, 'newpixel_newsletter_save_metaboxes', 10, 2 );
function newpixel_newsletter_save_metaboxes( $post_id, $post ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    // Headline (textarea)
    if ( isset( $_POST['newpixel_newsletter_headline_nonce'] )
         && wp_verify_nonce( $_POST['newpixel_newsletter_headline_nonce'], 'newpixel_newsletter_headline' )
         && isset( $_POST['newpixel_newsletter_headline'] ) ) {
        $headline = wp_kses_post( wp_unslash( $_POST['newpixel_newsletter_headline'] ) );
        update_post_meta( $post_id, NEWPIXEL_NEWSLETTER_META_HEADLINE, $headline );
    }

    // Approval flag (checkbox). Note: an unchecked checkbox sends NO
    // value at all, so we use the presence of the nonce to know the
    // metabox was rendered — meaning unchecked === explicit revoke.
    if ( isset( $_POST['newpixel_newsletter_approval_nonce'] )
         && wp_verify_nonce( $_POST['newpixel_newsletter_approval_nonce'], 'newpixel_newsletter_approval' ) ) {
        $is_approved = ! empty( $_POST['newpixel_newsletter_approved'] );
        update_post_meta( $post_id, NEWPIXEL_NEWSLETTER_META_APPROVED, $is_approved ? '1' : '' );
    }
}

// ============================================================================
// Admin endpoints — preview + send now
// ============================================================================

// Preview: open the email HTML in a new browser tab for visual review.
add_action( 'wp_ajax_pixel_newsletter_preview', 'newpixel_newsletter_handle_preview' );
function newpixel_newsletter_handle_preview() {
    if ( ! current_user_can( 'edit_posts' ) ) wp_die( 'forbidden', 403 );
    $issue_id = isset( $_GET['issue'] ) ? (int) $_GET['issue'] : 0;
    check_admin_referer( 'pixel_newsletter_preview_' . $issue_id );

    // Preview uses the current admin's real subscription date (if any)
    // so the editor previews the email exactly as it would land in their
    // own inbox. Falls back to today's date for a useful placeholder.
    $current_user = wp_get_current_user();
    $sub_date = $current_user && function_exists( 'newpixel_get_subscription_date' )
        ? newpixel_get_subscription_date( $current_user->user_email )
        : '';
    if ( ! $sub_date ) $sub_date = mysql2date( 'd/m/Y', current_time( 'mysql' ) );

    $html = newpixel_newsletter_render_issue( $issue_id, array(
        'unsubscribe_url' => '#preview-unsubscribe',
        'browser_url'     => get_permalink( $issue_id ) ?: '#preview-browser',
        'subscribed_date' => $sub_date,
    ) );

    header( 'Content-Type: text/html; charset=utf-8' );
    echo $html;
    exit;
}

// Send Now: bypass cron and dispatch immediately.
add_action( 'admin_post_pixel_newsletter_send_now', 'newpixel_newsletter_handle_send_now' );
function newpixel_newsletter_handle_send_now() {
    if ( ! current_user_can( 'edit_posts' ) ) wp_die( 'forbidden', 403 );
    $issue_id = isset( $_GET['issue'] ) ? (int) $_GET['issue'] : 0;
    check_admin_referer( 'pixel_newsletter_send_now_' . $issue_id );

    $result = newpixel_newsletter_dispatch( $issue_id );

    // The sent issue went to the trash (see dispatch). Return to the issues LIST
    // with the result in the URL, since a trashed post's edit screen isn't editable.
    wp_safe_redirect( add_query_arg( array(
        'post_type'         => NEWPIXEL_NEWSLETTER_CPT,
        'newsletter_sent'   => $result['sent'] ?? 0,
        'newsletter_failed' => $result['failed'] ?? 0,
    ), admin_url( 'edit.php' ) ) );
    exit;
}

// ============================================================================
// Admin notices — show after Send Now redirects back to the edit screen
// ============================================================================

add_action( 'admin_notices', 'newpixel_newsletter_admin_notices' );
function newpixel_newsletter_admin_notices() {
    if ( ! isset( $_GET['newsletter_sent'] ) ) return;
    $sent   = (int) $_GET['newsletter_sent'];
    $failed = (int) ( $_GET['newsletter_failed'] ?? 0 );
    if ( $sent ) {
        printf(
            '<div class="notice notice-success is-dismissible"><p>✓ Sent to %d subscriber(s).%s</p></div>',
            $sent,
            $failed ? sprintf( ' <strong>%d failed.</strong>', $failed ) : ''
        );
    } else {
        echo '<div class="notice notice-warning is-dismissible"><p>No emails were sent.</p></div>';
    }
}

// ============================================================================
// Subscribers screen — guest (no-account) emails + registered count + CSV
// ============================================================================

add_action( 'admin_menu', 'newpixel_newsletter_register_admin_pages' );
function newpixel_newsletter_register_admin_pages() {
    add_submenu_page(
        'edit.php?post_type=' . NEWPIXEL_NEWSLETTER_CPT,
        __( 'Subscribers', 'newpixel' ),
        __( 'Subscribers', 'newpixel' ),
        'manage_options',
        'pixel-newsletter-subscribers',
        'newpixel_newsletter_subscribers_page',
        30
    );
    add_submenu_page(
        'edit.php?post_type=' . NEWPIXEL_NEWSLETTER_CPT,
        __( 'Edition Number', 'newpixel' ),
        __( 'Edition Number', 'newpixel' ),
        'manage_options',
        'pixel-newsletter-edition-number',
        'newpixel_newsletter_edition_number_page',
        50
    );
}

function newpixel_newsletter_subscribers_page() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'forbidden', 403 );

    // Convidados (email-only) + datas
    $guests = (array) get_option( NEWPIXEL_NEWSLETTER_GUESTS, array() );
    $guests = array_values( array_filter( array_map( 'strtolower', $guests ), 'is_email' ) );
    $dates  = (array) get_option( NEWPIXEL_NEWSLETTER_GUEST_DATES, array() );

    // Newest first (unknown dates last)
    usort( $guests, function ( $a, $b ) use ( $dates ) {
        return strcmp( (string) ( $dates[ $b ] ?? '' ), (string) ( $dates[ $a ] ?? '' ) );
    } );

    // With an account (users who opted in)
    $reg = new WP_User_Query( array(
        'meta_key'   => NEWPIXEL_NEWSLETTER_META,
        'meta_value' => '1',
        'number'     => -1,
        'fields'     => 'ID',
    ) );
    $reg_count = count( (array) $reg->get_results() );

    $export_url = wp_nonce_url(
        admin_url( 'admin-post.php?action=pixel_newsletter_export_subscribers' ),
        'pixel_newsletter_export_subscribers'
    );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Newsletter Subscribers', 'newpixel' ); ?></h1>
        <?php if ( isset( $_GET['deleted'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p>Subscriber removed from list.</p></div>
        <?php endif; ?>
        <p style="font-size:14px;margin:12px 0 18px;">
            <strong><?php echo count( $guests ); ?></strong> without account
            &nbsp;·&nbsp; <strong><?php echo (int) $reg_count; ?></strong> with account
            &nbsp;·&nbsp; <strong><?php echo count( $guests ) + (int) $reg_count; ?></strong> total
            &nbsp;&nbsp; <a href="<?php echo esc_url( $export_url ); ?>" class="button button-secondary">⤓ Export CSV (all)</a>
            &nbsp; <a href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>">View registered users &rarr;</a>
        </p>

        <h2 style="margin-top:8px;"><?php esc_html_e( 'Without account (guests)', 'newpixel' ); ?></h2>
        <?php if ( empty( $guests ) ) : ?>
            <p style="opacity:0.8;">No guest subscribers yet.</p>
        <?php else : ?>
            <table class="widefat striped" style="max-width:660px;">
                <thead><tr>
                    <th style="width:40px;">#</th>
                    <th>Email</th>
                    <th style="width:150px;">Subscribed on</th>
                    <th style="width:40px;"></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $guests as $i => $email ) :
                    $iso  = (string) ( $dates[ $email ] ?? '' );
                    $when = $iso ? mysql2date( 'd/m/Y H:i', $iso ) : '—';
                    $del  = wp_nonce_url(
                        admin_url( 'admin-post.php?action=pixel_newsletter_delete_subscriber&email=' . rawurlencode( $email ) ),
                        'pixel_newsletter_delete_subscriber_' . $email
                    ); ?>
                    <tr>
                        <td><?php echo (int) $i + 1; ?></td>
                        <td><a href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a></td>
                        <td><?php echo esc_html( $when ); ?></td>
                        <td style="text-align:center;">
                            <a href="<?php echo esc_url( $del ); ?>"
                                title="Delete this subscriber"
                                style="color:#b32d2e;text-decoration:none;"
                                onclick="return confirm('Delete <?php echo esc_js( $email ); ?> from subscriber list?');">
                                <span class="dashicons dashicons-trash" style="font-size:18px;width:18px;height:18px;"></span>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p style="margin-top:16px;font-weight:600;">All emails (click to select and copy):</p>
            <textarea readonly rows="4" style="width:640px;max-width:100%;font-family:monospace;font-size:12px;" onclick="this.select();"><?php echo esc_textarea( implode( ', ', $guests ) ); ?></textarea>
        <?php endif; ?>
    </div>
    <?php
}

// CSV export — guests + account users, with type + consent date.
add_action( 'admin_post_pixel_newsletter_export_subscribers', 'newpixel_newsletter_handle_export_subscribers' );
function newpixel_newsletter_handle_export_subscribers() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'forbidden', 403 );
    check_admin_referer( 'pixel_newsletter_export_subscribers' );

    $rows = array();

    $reg = new WP_User_Query( array(
        'meta_key'   => NEWPIXEL_NEWSLETTER_META,
        'meta_value' => '1',
        'number'     => -1,
        'fields'     => array( 'ID', 'user_email' ),
    ) );
    foreach ( (array) $reg->get_results() as $u ) {
        $iso = (string) get_user_meta( $u->ID, NEWPIXEL_NEWSLETTER_DATE_META, true );
        $rows[] = array( strtolower( $u->user_email ), 'account', $iso );
    }

    $guests = (array) get_option( NEWPIXEL_NEWSLETTER_GUESTS, array() );
    $dates  = (array) get_option( NEWPIXEL_NEWSLETTER_GUEST_DATES, array() );
    foreach ( $guests as $email ) {
        $email = strtolower( $email );
        if ( ! is_email( $email ) ) continue;
        $rows[] = array( $email, 'guest', (string) ( $dates[ $email ] ?? '' ) );
    }

    nocache_headers();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=pixel-subscribers-' . gmdate( 'Y-m-d' ) . '.csv' );
    $out = fopen( 'php://output', 'w' );
    fputcsv( $out, array( 'email', 'type', 'subscribed_at' ) );
    foreach ( $rows as $r ) {
        fputcsv( $out, $r );
    }
    fclose( $out );
    exit;
}

// Delete a guest (no account) subscriber from the list.
add_action( 'admin_post_pixel_newsletter_delete_subscriber', 'newpixel_newsletter_handle_delete_subscriber' );
function newpixel_newsletter_handle_delete_subscriber() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'forbidden', 403 );
    $email = isset( $_GET['email'] ) ? strtolower( sanitize_email( wp_unslash( $_GET['email'] ) ) ) : '';
    check_admin_referer( 'pixel_newsletter_delete_subscriber_' . $email );

    if ( $email && is_email( $email ) && function_exists( 'newpixel_remove_guest_subscription' ) ) {
        newpixel_remove_guest_subscription( $email );
    }

    wp_safe_redirect( add_query_arg( array(
        'post_type' => NEWPIXEL_NEWSLETTER_CPT,
        'page'      => 'pixel-newsletter-subscribers',
        'deleted'   => '1',
    ), admin_url( 'edit.php' ) ) );
    exit;
}

// Update the registered next edition number from the Subscribers page.
add_action( 'admin_post_pixel_newsletter_update_next_edition', 'newpixel_newsletter_handle_update_next_edition' );
function newpixel_newsletter_handle_update_next_edition() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'forbidden', 403 );
    check_admin_referer( 'pixel_newsletter_update_next_edition' );

    $num = isset( $_POST['next_edition_number'] ) ? (int) $_POST['next_edition_number'] : 23;
    if ( function_exists( 'newpixel_newsletter_set_next_edition_number' ) ) {
        newpixel_newsletter_set_next_edition_number( $num );
    }

    wp_safe_redirect( add_query_arg( array(
        'post_type'       => NEWPIXEL_NEWSLETTER_CPT,
        'page'            => 'pixel-newsletter-edition-number',
        'edition_updated' => '1',
    ), admin_url( 'edit.php' ) ) );
    exit;
}

// ============================================================================
// Edition Number Screen
// ============================================================================

function newpixel_newsletter_edition_number_page() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'forbidden', 403 );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Edition Number', 'newpixel' ); ?></h1>
        <?php if ( isset( $_GET['edition_updated'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p>Next edition number updated successfully.</p></div>
        <?php endif; ?>

        <!-- ============ Next Edition Number Setting ============ -->
        <div class="card" style="max-width:660px; margin:16px 0 20px; padding:14px 18px; border-left:4px solid #a78bfa;">
            <h3 style="margin:0 0 6px;font-size:14px;font-weight:700;">Next Newsletter Edition</h3>
            <p style="margin:0 0 10px;font-size:13px;line-height:1.4;">
                This number is automatically assigned when creating new editions and <strong>incremented by +1 whenever an edition is successfully sent</strong>.
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;align-items:center;gap:10px;margin:0;">
                <?php wp_nonce_field( 'pixel_newsletter_update_next_edition' ); ?>
                <input type="hidden" name="action" value="pixel_newsletter_update_next_edition">
                <label for="next_edition_number" style="font-weight:600;font-size:13px;">Next edition:</label>
                <div style="display:flex;align-items:center;gap:4px;">
                    <span style="font-weight:700;font-size:14px;">#</span>
                    <input type="number" id="next_edition_number" name="next_edition_number" value="<?php echo (int) ( function_exists( 'newpixel_newsletter_get_next_edition_number' ) ? newpixel_newsletter_get_next_edition_number() : 23 ); ?>" min="1" style="width:85px;padding:3px 6px;">
                </div>
                <button type="submit" class="button button-secondary">Save number</button>
            </form>
        </div>
    </div>
    <?php
}

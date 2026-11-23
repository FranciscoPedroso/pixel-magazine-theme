<?php
/**
 * Newsletter — issue lifecycle (CPT, scheduling, sending) — orchestrator
 * ================================================================
 *
 * This file is intentionally slim: it defines the shared constants used
 * by the newsletter modules, then loads each module from the `newsletter/`
 * sub-folder. The actual logic lives one level down so each concern can
 * be opened, read and edited without scrolling through 1000+ lines.
 *
 * Module map:
 *   newsletter/cpt.php        CPT + custom post status registration
 *   newsletter/picker.php     Article picker, draft creation, render
 *   newsletter/dispatch.php   Subscribers, send, unsubscribe handler,
 *                             admin-of-draft notification
 *   newsletter/cron.php       Schedule registration + send-window cron +
 *                             send-day check
 *   newsletter/admin.php      Meta boxes, action buttons, preview /
 *                             send-now / seed-now endpoints, notices
 *
 * Lifecycle summary (manual model, with cron used only for sending):
 *
 *   1. The EDITOR creates issues by hand ("Nova edição"). In each one they
 *      can click "Gerar selecção agora" (top 5 most viewed in the last 4
 *      days, falling back to the latest), edit the headline, tick
 *      "Aprovar para envio automático" and PUBLISH. Drafts are NEVER
 *      generated automatically.
 *
 *   2. Tuesday + Friday at 08:00 / 12:00 / 18:00 Lisbon time, cron finds
 *      ALL issues with `publish` status AND approved = '1', sends each
 *      one and moves it to the TRASH. Drafts (and unapproved published
 *      issues) are ignored. No date restriction: publish/approve
 *      whenever, and the next window picks it up.
 *
 *   3. "Enviar agora (override)" on the issue screen sends immediately
 *      (ignoring the windows) and also moves it to the trash afterwards.
 *
 * Sending currently uses wp_mail() (delivered via host SMTP / Resend
 * via WP Mail SMTP plugin). To switch to a provider API directly,
 * change the inner loop of `newpixel_newsletter_dispatch()` in
 * newsletter/dispatch.php — the rest of the pipeline stays untouched.
 *
 * @package newpixel
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// ============================================================================
// Constants — defined here so every module can rely on them without
// caring about load order. Keep additions in this block, not in modules.
// ============================================================================

/** CPT used to store each newsletter issue (drafts + sent). */
const NEWPIXEL_NEWSLETTER_CPT = 'pixel_newsletter';

/** Post-status added to flag a fully-sent issue (distinct from publish). */
const NEWPIXEL_NEWSLETTER_STATUS_SENT = 'newsletter_sent';

/** Post meta: issue's editable headline (the editorial intro HTML). */
const NEWPIXEL_NEWSLETTER_META_HEADLINE = '_newsletter_headline';

/** Post meta: array of post IDs included in this issue, in display order. */
const NEWPIXEL_NEWSLETTER_META_POST_IDS = '_newsletter_post_ids';

/** Post meta: ISO datetime when the issue was actually sent. */
const NEWPIXEL_NEWSLETTER_META_SENT_AT  = '_newsletter_sent_at';

/** Post meta: number of recipients the issue was sent to. */
const NEWPIXEL_NEWSLETTER_META_SENT_COUNT = '_newsletter_sent_count';

/**
 * Post meta: '1' once the editor has approved the draft for auto-send.
 * Required by the 08:00 / 12:00 / 18:00 send-window crons before they
 * actually dispatch. Opt-in by design — newly generated drafts start
 * unapproved, so nothing leaves the site until the editor signs off.
 */
const NEWPIXEL_NEWSLETTER_META_APPROVED = '_newsletter_approved';

/** WP cron hook for generation (runs Tue+Fri 07:00). */
const NEWPIXEL_NEWSLETTER_CRON_GENERATE = 'newpixel_newsletter_generate';

/**
 * Three send-window crons (Tue+Fri at 08:00, 12:00, 18:00). The editor
 * approves the draft at any time; the next window that fires after the
 * approval delivers the issue. If the editor doesn't approve by 18:00,
 * the issue is skipped that day.
 */
const NEWPIXEL_NEWSLETTER_CRON_SEND_0800 = 'newpixel_newsletter_send_0800';
const NEWPIXEL_NEWSLETTER_CRON_SEND_1200 = 'newpixel_newsletter_send_1200';
const NEWPIXEL_NEWSLETTER_CRON_SEND_1800 = 'newpixel_newsletter_send_1800';

/** Option name storing the next newsletter edition number (integer). */
const NEWPIXEL_NEWSLETTER_OPTION_NEXT_EDITION = 'newpixel_newsletter_next_edition_number';

/** Post meta: edition number assigned when this issue is sent. */
const NEWPIXEL_NEWSLETTER_META_EDITION_NUMBER = '_newsletter_edition_number';

// ============================================================================
// Edition Numbering Helpers
// ============================================================================

/**
 * Return the next newsletter edition number from the registered option.
 * Defaults to 23 if not yet set.
 *
 * @return int
 */
function newpixel_newsletter_get_next_edition_number() {
    $val = get_option( NEWPIXEL_NEWSLETTER_OPTION_NEXT_EDITION, 23 );
    return max( 1, (int) $val );
}

/**
 * Update / set the next newsletter edition number in the options table.
 *
 * @param int $number
 * @return bool
 */
function newpixel_newsletter_set_next_edition_number( $number ) {
    $num = max( 1, (int) $number );
    return update_option( NEWPIXEL_NEWSLETTER_OPTION_NEXT_EDITION, $num, false );
}

/**
 * Increment the registered edition number by 1.
 *
 * @param int|null $sent_edition_number If provided and >= current, next will be $sent_edition_number + 1.
 * @return int The new next edition number.
 */
function newpixel_newsletter_increment_edition_number( $sent_edition_number = null ) {
    $current = newpixel_newsletter_get_next_edition_number();
    if ( $sent_edition_number !== null && (int) $sent_edition_number >= $current ) {
        $next = (int) $sent_edition_number + 1;
    } else {
        $next = $current + 1;
    }
    newpixel_newsletter_set_next_edition_number( $next );
    return $next;
}

/**
 * Generate a standard newsletter issue title.
 * E.g. "Edição #23 · Terça · 1 Set 2026"
 *
 * @param int|null $edition_number If null, uses the registered next edition number.
 * @return string
 */
function newpixel_newsletter_generate_title( $edition_number = null ) {
    $number = $edition_number !== null ? (int) $edition_number : newpixel_newsletter_get_next_edition_number();
    return sprintf(
        'Edição #%d · %s · %s',
        $number,
        newpixel_newsletter_weekday_pt(),
        newpixel_date_pt( 'j M Y' )
    );
}

// ============================================================================
// Module loading — order is irrelevant (no inter-file declaration
// dependencies; everything wires up via WP hooks).
// ============================================================================

require_once __DIR__ . '/newsletter/cpt.php';
require_once __DIR__ . '/newsletter/picker.php';
require_once __DIR__ . '/newsletter/dispatch.php';
require_once __DIR__ . '/newsletter/cron.php';
require_once __DIR__ . '/newsletter/admin.php';

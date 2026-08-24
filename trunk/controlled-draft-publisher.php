<?php

/**
 * Plugin Name: Controlled Draft Publisher
 * Description: Publishes draft posts on a configurable interval per post type, with a "Next Up" dashboard preview, a per-post auto-publish exclude option, optional publishing-window/weekend restrictions, email alerts, and logging.
 * Version: 1.7.2
 * Requires at least: 5.0
 * Tested up to: 7.0.2
 * Requires PHP: 8.0
 * Author: TechyGeeksHome
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: controlled-draft-publisher
 * Domain Path: /languages
 */

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// One-time migration: make cdp_log non-autoloading for existing installs
add_action( 'admin_init', function() {
    if ( get_option( 'cdp_log_autoload_fixed' ) ) {
        return;
    }

   // Use native option functions to update autoload without direct SQL
   $existing = get_option( 'cdp_log', false );

   if ( false !== $existing ) {
       // Re-save the option with autoload disabled
       update_option( 'cdp_log', $existing, 'no' );
       update_option( 'cdp_log_autoload_fixed', 1, false );
   }
}, 10 );

/* ---------------------------
   Activation / Deactivation
   --------------------------- */

register_activation_hook( __FILE__, 'cdp_activate' );
register_deactivation_hook( __FILE__, 'cdp_deactivate' );

function cdp_deactivate() {
    wp_clear_scheduled_hook( 'cdp_publish_event' );
}

function cdp_activate() {
    add_option( 'cdp_interval', 75 );
    add_option( 'cdp_post_types', [ 'post' ] );
    add_option( 'cdp_logging', true );
    add_option( 'cdp_log', [], '', 'no' );
    add_option( 'cdp_posts_per_run', 1 );
    add_option( 'cdp_categories', [] );
    add_option( 'cdp_post_type_intervals', [] );
    add_option( 'cdp_post_type_last_run', [] );
    add_option( 'cdp_window_enabled', false );
    add_option( 'cdp_window_start', '09:00' );
    add_option( 'cdp_window_end', '18:00' );
    add_option( 'cdp_skip_weekends', false );
    add_option( 'cdp_email_notify', false );
    add_option( 'cdp_notify_email', get_option( 'admin_email' ) );

    cdp_reschedule_tick();
}

/* ---------------------------
   Cron interval / scheduling
   --------------------------- */

// The "tick" is how often WP-Cron actually fires. Individual post types can have
// their own longer interval on top of this; the tick just needs to be frequent
// enough to catch the shortest configured interval (capped at 5 minutes so we
// don't hammer cron unnecessarily when every interval is long).
function cdp_get_tick_minutes() {
    $interval = max( 1, intval( get_option( 'cdp_interval', 75 ) ) );
    $type_intervals = get_option( 'cdp_post_type_intervals', [] );

    $minutes = [ $interval ];
    if ( is_array( $type_intervals ) ) {
        foreach ( $type_intervals as $m ) {
            $m = intval( $m );
            if ( $m > 0 ) {
                $minutes[] = $m;
            }
        }
    }

    return max( 1, min( 5, min( $minutes ) ) );
}

function cdp_reschedule_tick() {
    wp_clear_scheduled_hook( 'cdp_publish_event' );
    $tick = cdp_get_tick_minutes();
    wp_schedule_event( time() + ( $tick * 60 ), 'cdp_custom_interval', 'cdp_publish_event' );
}

add_filter( 'cron_schedules', function( $schedules ) {
    $tick = cdp_get_tick_minutes();
    $schedules['cdp_custom_interval'] = [
        'interval' => $tick * 60,
        /* translators: %d is the number of minutes */
        'display'  => sprintf( esc_html__( 'Every %d Minutes (Draft Publisher tick)', 'controlled-draft-publisher' ), $tick ),
    ];
    return $schedules;
});

/* ---------------------------
   Publishing window helpers
   --------------------------- */

function cdp_time_to_minutes( $time_str ) {
    if ( ! preg_match( '/^([0-1]?[0-9]|2[0-3]):([0-5][0-9])$/', (string) $time_str, $m ) ) {
        return null;
    }
    return ( (int) $m[1] * 60 ) + (int) $m[2];
}

function cdp_is_outside_window() {
    if ( ! get_option( 'cdp_window_enabled', false ) ) {
        return false;
    }

    $now = current_time( 'timestamp' );

    if ( get_option( 'cdp_skip_weekends', false ) ) {
        $day = (int) date_i18n( 'N', $now ); // 6 = Saturday, 7 = Sunday
        if ( $day >= 6 ) {
            return true;
        }
    }

    $start_minutes = cdp_time_to_minutes( get_option( 'cdp_window_start', '09:00' ) );
    $end_minutes   = cdp_time_to_minutes( get_option( 'cdp_window_end', '18:00' ) );

    if ( null === $start_minutes || null === $end_minutes ) {
        return false;
    }

    $now_minutes = ( (int) date_i18n( 'H', $now ) * 60 ) + (int) date_i18n( 'i', $now );

    if ( $start_minutes <= $end_minutes ) {
        return ( $now_minutes < $start_minutes || $now_minutes > $end_minutes );
    }

    // Window wraps past midnight (e.g. 22:00 - 06:00)
    return ( $now_minutes > $end_minutes && $now_minutes < $start_minutes );
}

/* ---------------------------
   Per-post "exclude from auto-publish" flag
   (mirrors the same "never auto-archive this post" pattern used in
   BackBurner Post Archiver, for a consistent experience across our plugins)
   --------------------------- */

add_action( 'add_meta_boxes', 'cdp_add_exclude_metabox' );
function cdp_add_exclude_metabox() {
    $types = get_option( 'cdp_post_types', [ 'post' ] );
    foreach ( (array) $types as $type ) {
        add_meta_box( 'cdp_exclude', esc_html__( 'Draft Publisher', 'controlled-draft-publisher' ), 'cdp_render_exclude_metabox', $type, 'side', 'low' );
    }
}

function cdp_render_exclude_metabox( $post ) {
    wp_nonce_field( 'cdp_save_exclude_meta', 'cdp_exclude_nonce' );
    $excluded = get_post_meta( $post->ID, '_cdp_exclude', true );
    echo '<label><input type="checkbox" name="cdp_exclude" value="1" ' . checked( $excluded, '1', false ) . ' /> ' . esc_html__( 'Exclude this draft from auto-publish', 'controlled-draft-publisher' ) . '</label>';
    if ( 'draft' === $post->post_status && $excluded ) {
        echo '<p style="margin-top:8px;"><em>' . esc_html__( 'This draft will be skipped by Draft Publisher and left as-is until you untick this or publish it yourself.', 'controlled-draft-publisher' ) . '</em></p>';
    }
}

add_action( 'save_post', 'cdp_save_exclude_meta' );
function cdp_save_exclude_meta( $post_id ) {
    if ( ! isset( $_POST['cdp_exclude_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cdp_exclude_nonce'] ) ), 'cdp_save_exclude_meta' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( isset( $_POST['cdp_exclude'] ) ) {
        update_post_meta( $post_id, '_cdp_exclude', '1' );
    } else {
        delete_post_meta( $post_id, '_cdp_exclude' );
    }
}

/* ---------------------------
   Shared query builder (used by the real publish run and by the
   "Next Up" dashboard preview, so the preview always matches reality)
   --------------------------- */

function cdp_build_queue_query_args( $types, $categories, $posts_per_page ) {
    $query_args = [
        'fields'         => 'ids',
        'post_type'      => $types,
        'post_status'    => 'draft',
        'posts_per_page' => $posts_per_page,
        'orderby'        => 'date',
        'order'          => 'ASC',
        'meta_query'     => [
            [
                'key'     => '_cdp_exclude',
                'compare' => 'NOT EXISTS',
            ],
        ],
    ];

    if ( ! empty( $categories ) ) {
        $query_args['category__in'] = $categories;
    }

    return $query_args;
}

/**
 * Preview of what would be published next, in order, without changing anything.
 * Used by the dashboard "Next Up" panel so you can see what's coming (and
 * exclude anything you don't want going out) before it actually publishes.
 */
function cdp_get_next_up( $limit = 5 ) {
    $types      = get_option( 'cdp_post_types', [ 'post' ] );
    $categories = get_option( 'cdp_categories', [] );

    if ( empty( $types ) ) {
        return [];
    }

    $query_args = cdp_build_queue_query_args( $types, $categories, max( 1, $limit ) );
    $query_args['fields'] = 'all'; // need title/type/date for display, not just IDs

    $query = new WP_Query( $query_args );
    $items = [];
    foreach ( $query->posts as $p ) {
        $items[] = [
            'id'    => $p->ID,
            'title' => get_the_title( $p ),
            'type'  => get_post_type( $p ),
            'date'  => $p->post_date,
        ];
    }
    wp_reset_postdata();

    return $items;
}

/* ---------------------------
   Publish logic + logging
   --------------------------- */

add_action( 'cdp_publish_event', 'cdp_publish_tick' );

function cdp_publish_tick() {
    if ( cdp_is_outside_window() ) {
        return;
    }

    $types = get_option( 'cdp_post_types', [ 'post' ] );
    if ( empty( $types ) ) {
        return;
    }

    $global_interval = max( 1, intval( get_option( 'cdp_interval', 75 ) ) );
    $type_intervals   = get_option( 'cdp_post_type_intervals', [] );
    $last_run         = get_option( 'cdp_post_type_last_run', [] );
    $now              = time();
    $changed          = false;

    foreach ( (array) $types as $type ) {
        $interval = ( isset( $type_intervals[ $type ] ) && intval( $type_intervals[ $type ] ) > 0 )
            ? intval( $type_intervals[ $type ] )
            : $global_interval;

        $type_last_run = isset( $last_run[ $type ] ) ? intval( $last_run[ $type ] ) : 0;

        if ( ( $now - $type_last_run ) >= ( $interval * 60 ) ) {
            cdp_publish_draft( $type );
            $last_run[ $type ] = $now;
            $changed = true;
        }
    }

    if ( $changed ) {
        update_option( 'cdp_post_type_last_run', $last_run, false );
    }
}

function cdp_publish_draft( $forced_type = null ) {
    $types = $forced_type ? [ $forced_type ] : get_option( 'cdp_post_types', [ 'post' ] );
    $posts_per_run = max( 1, intval( get_option( 'cdp_posts_per_run', 1 ) ) );
    $categories = get_option( 'cdp_categories', [] );

    $query_args = cdp_build_queue_query_args( $types, $categories, $posts_per_run );

    $query = new WP_Query( $query_args );
    $published = [];
    $errors = [];

    if ( ! empty( $query->posts ) ) {
        foreach ( $query->posts as $id ) {
            $result = wp_update_post( [ 'ID' => $id, 'post_status' => 'publish' ], true );
            if ( ! is_wp_error( $result ) ) {
                $entry = [
                    'id'    => $id,
                    'time'  => date_i18n( 'Y-m-d H:i:s', current_time( 'timestamp' ) ),
                    'title' => get_the_title( $id ),
                    'url'   => get_permalink( $id ),
                    'type'  => get_post_type( $id )
                ];
                $published[] = $entry;

                if ( get_option( 'cdp_logging' ) ) {
                    $log = get_option( 'cdp_log', [] );
                    $log[] = $entry;
                    $max_log = 1000;
                    if ( count( $log ) > $max_log ) $log = array_slice( $log, -$max_log );
                    update_option( 'cdp_log', $log, false );
                }
            } else {
                $errors[] = $result->get_error_message();
                set_transient( 'cdp_last_publish_error', $result->get_error_message(), 300 );
            }
        }
        wp_reset_postdata();
    }

    if ( get_option( 'cdp_email_notify', false ) ) {
        cdp_send_notification_email( $published, $errors );
    }
}

function cdp_send_notification_email( $published, $errors ) {
    if ( empty( $published ) && empty( $errors ) ) {
        return;
    }

    $to = get_option( 'cdp_notify_email', get_option( 'admin_email' ) );
    if ( empty( $to ) || ! is_email( $to ) ) {
        return;
    }

    $site_name = get_bloginfo( 'name' );
    $lines = [];

    if ( ! empty( $published ) ) {
        /* translators: %d is the number of posts published */
        $lines[] = sprintf( _n( '%d post was published:', '%d posts were published:', count( $published ), 'controlled-draft-publisher' ), count( $published ) );
        foreach ( $published as $entry ) {
            $lines[] = '- ' . $entry['title'] . ' (' . $entry['url'] . ')';
        }
    }

    if ( ! empty( $errors ) ) {
        $lines[] = '';
        $lines[] = esc_html__( 'The following errors occurred:', 'controlled-draft-publisher' );
        foreach ( $errors as $error ) {
            $lines[] = '- ' . $error;
        }
    }

    $subject = empty( $errors )
        ? sprintf(
            /* translators: 1: site name, 2: number of posts published */
            esc_html__( '[%1$s] Draft Publisher: %2$d post(s) published', 'controlled-draft-publisher' ),
            $site_name,
            count( $published )
        )
        : sprintf(
            /* translators: %s is the site name */
            esc_html__( '[%s] Draft Publisher: publish error', 'controlled-draft-publisher' ),
            $site_name
        );

    wp_mail( $to, $subject, implode( "\n", $lines ) );
}

/* ---------------------------
   Admin menu registration
   --------------------------- */

add_action( 'admin_menu', 'cdp_register_menu' );

function cdp_register_menu() {
    // Shared "TGH" top-level menu — registered once no matter how many of our
    // plugins are active at the same time (first one to load wins the
    // registration; every plugin still adds its own submenu page below).
    if ( ! defined( 'TGHHUB_MENU_REGISTERED' ) ) {
        define( 'TGHHUB_MENU_REGISTERED', true );
        add_menu_page(
            'TGH',
            'TGH',
            'manage_options',
            'tghhub',
            'tghhub_render_landing_page',
            'data:image/svg+xml;base64,' . base64_encode( tghhub_menu_icon_svg() ),
            null
        );
    }

    add_submenu_page(
        'tghhub',
        esc_html__( 'Draft Publisher', 'controlled-draft-publisher' ),
        esc_html__( 'Draft Publisher', 'controlled-draft-publisher' ),
        'manage_options',
        'cdp-dashboard',
        'cdp_dashboard_page'
    );
    add_submenu_page(
        'tghhub',
        esc_html__( 'Draft Publisher Settings', 'controlled-draft-publisher' ),
        esc_html__( 'DP Settings', 'controlled-draft-publisher' ),
        'manage_options',
        'cdp-settings',
        'cdp_settings_page'
    );
}

/* ---------------------------
   Shared "TGH" hub landing page (identical copy lives in every TGH plugin;
   function_exists() guards mean whichever plugin loads first "wins" and
   renders it — keep this block in sync across all TGH plugins when the
   plugin/theme/software list changes).
   --------------------------- */

if ( ! function_exists( 'tghhub_menu_icon_svg' ) ) {
    function tghhub_menu_icon_svg() {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><polygon points="10,7 13.46,9 13.46,13 10,15 6.54,13 6.54,9" fill="none" stroke="black" stroke-width="1.6"/><line x1="10" y1="7" x2="10" y2="3" stroke="black" stroke-width="1.4"/><line x1="13.46" y1="13" x2="16.93" y2="15" stroke="black" stroke-width="1.4"/><line x1="6.54" y1="13" x2="3.07" y2="15" stroke="black" stroke-width="1.4"/><circle cx="10" cy="3" r="1.4" fill="black"/><circle cx="16.93" cy="15" r="1.4" fill="black"/><circle cx="3.07" cy="15" r="1.4" fill="black"/></svg>';
    }
}

if ( ! function_exists( 'tghhub_render_landing_page' ) ) {
    function tghhub_render_landing_page() {
        $plugins = apply_filters( 'tghhub_plugins', array(
            array(
                'name'        => __( 'BackBurner Post Archiver', 'controlled-draft-publisher' ),
                'description' => __( 'Moves old posts out of active circulation without deleting them or breaking a URL.', 'controlled-draft-publisher' ),
                'url'         => 'https://wordpress.org/plugins/backburner-post-archiver/',
            ),
            array(
                'name'        => __( 'Controlled Draft Publisher', 'controlled-draft-publisher' ),
                'description' => __( 'Hold posts as controlled drafts and publish them on your own schedule.', 'controlled-draft-publisher' ),
                'url'         => 'https://wordpress.org/plugins/controlled-draft-publisher/',
            ),
            array(
                'name'        => __( 'LinkGather', 'controlled-draft-publisher' ),
                'description' => __( 'Collects and manages links across your site.', 'controlled-draft-publisher' ),
                'url'         => 'https://wordpress.org/plugins/linkgather/',
            ),
        ) );

        $themes = array(
            array(
                'name'        => __( 'NeoDark Free', 'controlled-draft-publisher' ),
                'description' => __( 'A fast, dark-mode WordPress theme for tech blogs, tutorials and reviews.', 'controlled-draft-publisher' ),
                'url'         => 'https://techygeekshome.info/neodark-free/',
                'cta'         => __( 'View Theme', 'controlled-draft-publisher' ),
            ),
            array(
                'name'        => __( 'NeoDark Pro', 'controlled-draft-publisher' ),
                'description' => __( 'Hero slider, three-column layout, review blocks and a mega menu. One-time payment.', 'controlled-draft-publisher' ),
                'url'         => 'https://techygeekshome.info/neodark-pro/',
                'cta'         => __( 'View Theme', 'controlled-draft-publisher' ),
            ),
        );

        $software = array(
            array(
                'name'        => __( 'AppGeek', 'controlled-draft-publisher' ),
                'description' => __( 'Update every application on a Windows PC in one go, using winget.', 'controlled-draft-publisher' ),
                'url'         => 'https://techygeekshome.info/appgeek/',
                'cta'         => __( 'View / Download', 'controlled-draft-publisher' ),
            ),
            array(
                'name'        => __( 'PDFGeek', 'controlled-draft-publisher' ),
                'description' => __( 'Merge, split, compress and convert PDFs entirely offline.', 'controlled-draft-publisher' ),
                'url'         => 'https://techygeekshome.info/pdfgeek/',
                'cta'         => __( 'View / Download', 'controlled-draft-publisher' ),
            ),
            array(
                'name'        => __( 'DiskGeek', 'controlled-draft-publisher' ),
                'description' => __( 'Free disk space analyser for Windows: scan, find duplicates and reclaim space.', 'controlled-draft-publisher' ),
                'url'         => 'https://techygeekshome.info/diskgeek/',
                'cta'         => __( 'View / Download', 'controlled-draft-publisher' ),
            ),
            array(
                'name'        => __( 'Ultimate Settings Panel', 'controlled-draft-publisher' ),
                'description' => __( '250+ Windows settings, tools and commands in one fast, searchable panel.', 'controlled-draft-publisher' ),
                'url'         => 'https://techygeekshome.info/ultimate-settings-panel-online/',
                'cta'         => __( 'View / Download', 'controlled-draft-publisher' ),
            ),
        );
        ?>
        <div class="wrap tghhub-dashboard">
            <h1>TechyGeeksHome</h1>
            <p>A shared home for everything we have built &mdash; our WordPress plugins, our themes, and our standalone software.</p>

            <h2>Our Plugins</h2>
            <div style="display:flex;flex-wrap:wrap;gap:16px;margin-top:12px;">
                <?php foreach ( $plugins as $p ) : ?>
                    <div style="border:1px solid #dcdcde;border-radius:6px;padding:16px;width:280px;background:#fff;">
                        <h3 style="margin-top:0;"><?php echo esc_html( $p['name'] ); ?></h3>
                        <p><?php echo esc_html( $p['description'] ); ?></p>
                        <a href="<?php echo esc_url( $p['url'] ); ?>" class="button button-primary" target="_blank" rel="noopener">View Plugin</a>
                    </div>
                <?php endforeach; ?>
            </div>

            <h2 style="margin-top:32px;">Our Themes</h2>
            <div style="display:flex;flex-wrap:wrap;gap:16px;margin-top:12px;">
                <?php foreach ( $themes as $t ) : ?>
                    <div style="border:1px solid #dcdcde;border-radius:6px;padding:16px;width:280px;background:#fff;">
                        <h3 style="margin-top:0;"><?php echo esc_html( $t['name'] ); ?></h3>
                        <p><?php echo esc_html( $t['description'] ); ?></p>
                        <a href="<?php echo esc_url( $t['url'] ); ?>" class="button" target="_blank" rel="noopener"><?php echo esc_html( $t['cta'] ); ?></a>
                    </div>
                <?php endforeach; ?>
            </div>

            <h2 style="margin-top:32px;">Our Software</h2>
            <div style="display:flex;flex-wrap:wrap;gap:16px;margin-top:12px;">
                <?php foreach ( $software as $s ) : ?>
                    <div style="border:1px solid #dcdcde;border-radius:6px;padding:16px;width:280px;background:#fff;">
                        <h3 style="margin-top:0;"><?php echo esc_html( $s['name'] ); ?></h3>
                        <p><?php echo esc_html( $s['description'] ); ?></p>
                        <a href="<?php echo esc_url( $s['url'] ); ?>" class="button" target="_blank" rel="noopener"><?php echo esc_html( $s['cta'] ); ?></a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
}

/* ---------------------------
   Add settings link to plugins page
   --------------------------- */

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'cdp_plugin_action_links' );

function cdp_plugin_action_links( $links ) {
    $settings_link = '<a href="' . admin_url( 'admin.php?page=cdp-settings' ) . '">' . esc_html__( 'Settings', 'controlled-draft-publisher' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}

/* ---------------------------
   Cross-promotion notice (own admin pages only, dismissible)
   --------------------------- */

add_action( 'admin_notices', 'cdp_cross_promo_notice' );

function cdp_cross_promo_notice() {
    $screen = get_current_screen();
    if ( ! $screen || false === strpos( $screen->id, 'cdp-' ) ) {
        return;
    }

    if ( get_user_meta( get_current_user_id(), 'cdp_promo_dismissed', true ) ) {
        return;
    }

    echo '<div class="notice notice-info is-dismissible cdp-promo-notice"><p>' .
        wp_kses_post( sprintf(
            /* translators: 1: LinkGather plugin link, 2: NeoDark Pro theme link */
            __( 'Also by TechyGeeksHome: %1$s (audit your internal links) and %2$s (a dark-mode WordPress theme built for tech guides and reviews).', 'controlled-draft-publisher' ),
            '<a href="https://wordpress.org/plugins/linkgather/" target="_blank" rel="noopener noreferrer">LinkGather</a>',
            '<a href="https://techygeekshome.info/neodark-pro/" target="_blank" rel="noopener noreferrer">NeoDark Pro</a>'
        ) ) .
        '</p></div>';
    ?>
    <script>
    document.addEventListener( 'DOMContentLoaded', function () {
        var notice = document.querySelector( '.cdp-promo-notice' );
        if ( ! notice ) return;
        notice.addEventListener( 'click', function ( e ) {
            if ( e.target && e.target.classList.contains( 'notice-dismiss' ) ) {
                var xhr = new XMLHttpRequest();
                xhr.open( 'POST', ajaxurl, true );
                xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
                xhr.send( 'action=cdp_dismiss_promo&_wpnonce=<?php echo esc_js( wp_create_nonce( 'cdp_dismiss_promo' ) ); ?>' );
            }
        } );
    } );
    </script>
    <?php
}

add_action( 'wp_ajax_cdp_dismiss_promo', 'cdp_dismiss_promo' );

function cdp_dismiss_promo() {
    check_ajax_referer( 'cdp_dismiss_promo' );
    update_user_meta( get_current_user_id(), 'cdp_promo_dismissed', 1 );
    wp_die();
}

/* ---------------------------
   Dashboard page (single, full)
   --------------------------- */

function cdp_dashboard_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Handle POST actions
    $method = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) );
    if ( $method === 'POST' ) {
        if ( isset( $_POST['cdp_stop'] ) && check_admin_referer( 'cdp_dashboard_action' ) ) {
            wp_clear_scheduled_hook( 'cdp_publish_event' );
            echo '<div class="updated"><p>' . esc_html__( 'Publishing stopped.', 'controlled-draft-publisher' ) . '</p></div>';
        }

        if ( isset( $_POST['cdp_start'] ) && check_admin_referer( 'cdp_dashboard_action' ) ) {
            if ( ! wp_get_schedule( 'cdp_publish_event' ) ) {
                cdp_reschedule_tick();
                echo '<div class="updated"><p>' . esc_html__( 'Publishing started.', 'controlled-draft-publisher' ) . '</p></div>';
            }
        }

        if ( isset( $_POST['cdp_publish_now'] ) && check_admin_referer( 'cdp_dashboard_action' ) ) {
            $type = isset( $_POST['cdp_filter_type'] ) ? sanitize_text_field( wp_unslash( $_POST['cdp_filter_type'] ) ) : '';
            cdp_publish_draft( $type ?: null );
            echo '<div class="updated"><p>' . esc_html__( 'Manual publish triggered.', 'controlled-draft-publisher' ) . '</p></div>';
        }

        if ( isset( $_POST['cdp_refresh'] ) && check_admin_referer( 'cdp_dashboard_action' ) ) {
            echo '<div class="updated"><p>' . esc_html__( 'Dashboard refreshed.', 'controlled-draft-publisher' ) . '</p></div>';
        }

        if ( isset( $_POST['cdp_clear_log'] ) && check_admin_referer( 'cdp_dashboard_action' ) ) {
            update_option( 'cdp_log', [], false );
            echo '<div class="updated"><p>' . esc_html__( 'Log cleared.', 'controlled-draft-publisher' ) . '</p></div>';
        }

        if ( isset( $_POST['cdp_export_csv'] ) && check_admin_referer( 'cdp_dashboard_action' ) ) {
            $log = get_option( 'cdp_log', [] );
            if ( empty( $log ) ) {
                echo '<div class="notice notice-warning"><p>' . esc_html__( 'No log entries to export.', 'controlled-draft-publisher' ) . '</p></div>';
            } else {
                $filename = 'cdp-log-' . date_i18n( 'Ymd-His' ) . '.csv';

                nocache_headers();
                while ( ob_get_level() ) {
                    ob_end_clean();
                }

                header( 'Content-Type: text/csv; charset=UTF-8' );
                header( 'Content-Disposition: attachment; filename="' . esc_attr( $filename ) . '"' );
                header( 'Pragma: public' );
                header( 'Expires: 0' );

                // Output BOM for correct Excel/UTF-8 handling
                echo "\xEF\xBB\xBF";

                echo '"post_id","time","title","url","type"' . "\n";
                foreach ( $log as $row ) {
                    $title_safe = wp_strip_all_tags( $row['title'] ?? '', true );
                    echo sprintf(
                        '"%s","%s","%s","%s","%s"' . "\n",
                        isset( $row['id'] ) ? esc_attr( intval( $row['id'] ) ) : '',
                        isset( $row['time'] ) ? esc_attr( $row['time'] ) : '',
                        esc_attr( $title_safe ),
                        isset( $row['url'] ) ? esc_url( $row['url'] ) : '',
                        isset( $row['type'] ) ? esc_attr( $row['type'] ) : ''
                    );
                }
                exit;
            }
        }
    }

    // Fetch data
    $log = get_option( 'cdp_log', [] );
    $total = count( $log );
    $next = wp_next_scheduled( 'cdp_publish_event' );
    $enabled = wp_get_schedule( 'cdp_publish_event' );
    $interval = get_option( 'cdp_interval', 75 );
    $posts_per_run = get_option( 'cdp_posts_per_run', 1 );
    $post_types_selected = get_option( 'cdp_post_types', [ 'post' ] );
    $categories_selected = get_option( 'cdp_categories', [] );
    $post_types = get_post_types( [ 'public' => true ], 'names' );
    $window_enabled = get_option( 'cdp_window_enabled', false );

    // Get category names
    $category_names = [];
    if ( ! empty( $categories_selected ) ) {
        foreach ( $categories_selected as $cat_id ) {
            $cat = get_term( $cat_id, 'category' );
            if ( $cat && ! is_wp_error( $cat ) ) {
                $category_names[] = $cat->name;
            }
        }
    }

    // Sanitized filter selection
    $selected_filter = isset( $_REQUEST['cdp_filter_type'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['cdp_filter_type'] ) ) : '';

    echo '<div class="wrap"><h1>' . esc_html__( 'Draft Publisher Dashboard', 'controlled-draft-publisher' ) . '</h1>';

    // Status / Interval / Other Settings
    echo '<p><strong>' . esc_html__( 'Status:', 'controlled-draft-publisher' ) . '</strong> ';
    echo ( $enabled
        ? '<span style="color:green;font-weight:bold;">' . esc_html__( 'Active', 'controlled-draft-publisher' ) . '</span>'
        : '<span style="color:red;font-weight:bold;">' . esc_html__( 'Inactive', 'controlled-draft-publisher' ) . '</span>' );
    echo '</p>';
    /* translators: %d is the number of minutes */
    echo '<p><strong>' . esc_html__( 'Default Interval:', 'controlled-draft-publisher' ) . '</strong> ' . sprintf( esc_html__( 'Every %d minutes', 'controlled-draft-publisher' ), intval( $interval ) ) . '</p>';
    echo '<p><strong>' . esc_html__( 'Posts Per Run:', 'controlled-draft-publisher' ) . '</strong> ' . esc_html( $posts_per_run ) . '</p>';
    echo '<p><strong>' . esc_html__( 'Selected Post Types:', 'controlled-draft-publisher' ) . '</strong> ' . esc_html( implode( ', ', (array) $post_types_selected ) ?: 'None' ) . '</p>';
    echo '<p><strong>' . esc_html__( 'Selected Categories:', 'controlled-draft-publisher' ) . '</strong> ' . esc_html( implode( ', ', $category_names ) ?: 'None' ) . '</p>';
    echo '<p><strong>' . esc_html__( 'Publishing Window:', 'controlled-draft-publisher' ) . '</strong> ' . ( $window_enabled
        ? esc_html( get_option( 'cdp_window_start', '09:00' ) . ' - ' . get_option( 'cdp_window_end', '18:00' ) ) . ( get_option( 'cdp_skip_weekends', false ) ? ' (' . esc_html__( 'weekdays only', 'controlled-draft-publisher' ) . ')' : '' )
        : esc_html__( 'Disabled — publishes any time', 'controlled-draft-publisher' ) ) . '</p>';

    // Start/Stop form
    echo '<form method="post" style="display:inline-block;margin-right:1em;">';
    wp_nonce_field( 'cdp_dashboard_action' );
    echo $enabled
        ? '<input type="submit" name="cdp_stop" class="button-secondary" value="' . esc_attr__( 'Stop Publishing', 'controlled-draft-publisher' ) . '">'
        : '<input type="submit" name="cdp_start" class="button-primary" value="' . esc_attr__( 'Start Publishing', 'controlled-draft-publisher' ) . '">';
    echo '</form>';

    // Refresh button
    echo '<form method="post" style="display:inline-block;">';
    wp_nonce_field( 'cdp_dashboard_action' );
    echo '<input type="hidden" name="cdp_refresh" value="1">';
    echo '<input type="submit" class="button" value="' . esc_attr__( 'Refresh', 'controlled-draft-publisher' ) . '">';
    echo '</form>';

    echo '<hr>';

    // "Next Up" preview — shows what will actually publish next, in order,
    // using the exact same query the real run uses, so it's never misleading.
    echo '<h2>' . esc_html__( 'Next Up', 'controlled-draft-publisher' ) . '</h2>';
    $next_up = cdp_get_next_up( 5 );
    if ( empty( $next_up ) ) {
        echo '<p>' . esc_html__( 'Nothing queued — no matching drafts right now.', 'controlled-draft-publisher' ) . '</p>';
    } else {
        echo '<table class="widefat" style="max-width:640px;"><thead><tr><th>' . esc_html__( 'Title', 'controlled-draft-publisher' ) . '</th><th>' . esc_html__( 'Post Type', 'controlled-draft-publisher' ) . '</th><th>' . esc_html__( 'Draft Date', 'controlled-draft-publisher' ) . '</th></tr></thead><tbody>';
        foreach ( $next_up as $item ) {
            echo '<tr><td><a href="' . esc_url( get_edit_post_link( $item['id'] ) ) . '">' . esc_html( $item['title'] !== '' ? $item['title'] : __( '(no title)', 'controlled-draft-publisher' ) ) . '</a></td><td>' . esc_html( $item['type'] ) . '</td><td>' . esc_html( $item['date'] ) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p class="description">' . esc_html__( 'Order they will be published in, respecting your post type/category filters. Tick "Exclude this draft from auto-publish" on any of these (in the post editor sidebar) to skip it.', 'controlled-draft-publisher' ) . '</p>';
    }

    echo '<hr>';

    // Manual publish + filter form (use GET for filter/pagination persistence)
    echo '<form method="get" style="margin-bottom:1em;">';
    echo '<input type="hidden" name="page" value="cdp-dashboard">';
    echo '<label for="cdp_filter_type">' . esc_html__( 'Post Type:', 'controlled-draft-publisher' ) . '</label> ';
    echo '<select name="cdp_filter_type" id="cdp_filter_type">';
    echo '<option value="">' . esc_html__( 'All', 'controlled-draft-publisher' ) . '</option>';
    foreach ( $post_types as $type ) {
        echo '<option value="' . esc_attr( $type ) . '" ' . selected( $selected_filter, $type, false ) . '>' . esc_html( $type ) . '</option>';
    }
    echo '</select> ';
    echo '<input type="submit" class="button" value="' . esc_attr__( 'Filter', 'controlled-draft-publisher' ) . '">';
    echo '</form>';

    // Manual publish (separate POST)
    echo '<form method="post" style="margin-bottom:1em;">';
    wp_nonce_field( 'cdp_dashboard_action' );
    echo '<input type="hidden" name="cdp_filter_type" value="' . esc_attr( $selected_filter ) . '">';
    echo '<input type="submit" name="cdp_publish_now" class="button" value="' . esc_attr__( 'Publish Now', 'controlled-draft-publisher' ) . '">';
    echo '</form>';

    // Export / Clear controls
    echo '<form method="post" style="display:inline-block;margin-right:1em;">';
    wp_nonce_field( 'cdp_dashboard_action' );
    echo '<input type="submit" name="cdp_export_csv" class="button" value="' . esc_attr__( 'Export Log (CSV)', 'controlled-draft-publisher' ) . '">';
    echo '</form>';

    echo '<form method="post" style="display:inline-block;">';
    wp_nonce_field( 'cdp_dashboard_action' );
    echo '<input type="submit" name="cdp_clear_log" class="button" value="' . esc_attr__( 'Clear Log', 'controlled-draft-publisher' ) . '" onclick="return confirm(\'' . esc_js( __( 'Are you sure you want to clear the log?', 'controlled-draft-publisher' ) ) . '\');">';
    echo '</form>';

    echo '<hr>';

    // Basic stats
    echo '<p><strong>' . esc_html__( 'Total Published:', 'controlled-draft-publisher' ) . '</strong> ' . esc_html( $total ) . '</p>';
    if ( ! empty( $log ) ) {
        $last = end( $log );
        /* translators: 1: post ID, 2: time */
        echo '<p><strong>' . esc_html__( 'Last Published:', 'controlled-draft-publisher' ) . '</strong> ' . sprintf( esc_html__( 'Post ID %1$d at %2$s', 'controlled-draft-publisher' ), intval( $last['id'] ), esc_html( $last['time'] ) ) . '</p>';
    }
    echo $next
        ? '<p><strong>' . esc_html__( 'Next Scheduled Tick:', 'controlled-draft-publisher' ) . '</strong> ' . esc_html( date_i18n( 'Y-m-d H:i:s', $next + ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) ) . '</p>'
        : '<p><strong>' . esc_html__( 'Next Scheduled Tick:', 'controlled-draft-publisher' ) . '</strong> <span style="color:red;">' . esc_html__( 'Not scheduled', 'controlled-draft-publisher' ) . '</span></p>';

    echo '<h2>' . esc_html__( 'Activity Stats', 'controlled-draft-publisher' ) . '</h2>';

    // Compute stats for chart: count per post type and per day (last 7 days)
    $counts_by_type = [];
    $counts_by_day = [];
    $now_ts = current_time( 'timestamp' );
    for ( $d = 6; $d >= 0; $d-- ) {
        $day = date_i18n( 'Y-m-d', $now_ts - ( $d * DAY_IN_SECONDS ) );
        $counts_by_day[$day] = 0;
    }
    foreach ( $log as $entry ) {
        $type = $entry['type'] ?? 'unknown';
        if ( ! isset( $counts_by_type[$type] ) ) $counts_by_type[$type] = 0;
        $counts_by_type[$type]++;

        $dt = strtotime( $entry['time'] ?? '' );
        if ( $dt !== false ) {
            $day = date_i18n( 'Y-m-d', $dt );
            if ( isset( $counts_by_day[$day] ) ) $counts_by_day[$day]++;
        }
    }

    // Render counts_by_type table
    echo '<table class="widefat" style="max-width:480px;"><thead><tr><th>' . esc_html__( 'Post Type', 'controlled-draft-publisher' ) . '</th><th>' . esc_html__( 'Count', 'controlled-draft-publisher' ) . '</th></tr></thead><tbody>';
    foreach ( $counts_by_type as $type => $count ) {
        echo '<tr><td>' . esc_html( $type ) . '</td><td>' . esc_html( $count ) . '</td></tr>';
    }
    if ( empty( $counts_by_type ) ) {
        echo '<tr><td colspan="2">' . esc_html__( 'No published items yet', 'controlled-draft-publisher' ) . '</td></tr>';
    }
    echo '</tbody></table>';

    // Simple inline SVG bar chart for last 7 days
    echo '<h3>' . esc_html__( 'Publishes (last 7 days)', 'controlled-draft-publisher' ) . '</h3>';
    $max = max( 1, max( $counts_by_day ) );
    $svg_width = 700;
    $svg_height = 120;
    $bar_width = intval( $svg_width / 8 );
    echo '<svg width="' . esc_attr( $svg_width ) . '" height="' . esc_attr( $svg_height ) . '" role="img" aria-label="' . esc_attr__( 'Publish frequency last 7 days', 'controlled-draft-publisher' ) . '">';
    $i = 0;
    foreach ( $counts_by_day as $day => $count ) {
        $h = intval( ( $count / $max ) * ( $svg_height - 30 ) );
        $x = 10 + ( $i * $bar_width );
        $y = ( $svg_height - $h - 20 );
        echo '<rect x="' . esc_attr( $x ) . '" y="' . esc_attr( $y ) . '" width="' . esc_attr( $bar_width - 10 ) . '" height="' . esc_attr( $h ) . '" style="fill:#2b8be6;"></rect>';
        echo '<text x="' . esc_attr( $x + 2 ) . '" y="' . esc_attr( $svg_height - 4 ) . '" font-size="10" fill="#222">' . esc_html( substr( $day, 5 ) ) . '</text>';
        echo '<text x="' . esc_attr( $x + 2 ) . '" y="' . esc_attr( $y - 4 ) . '" font-size="10" fill="#222">' . esc_html( $count ) . '</text>';
        $i++;
    }
    echo '</svg>';

    echo '<hr>';

    // Recent Activity table with filtering + pagination
    if ( $selected_filter ) {
        $filtered_log = array_values( array_filter( $log, function( $entry ) use ( $selected_filter ) {
            return ( ( $entry['type'] ?? '' ) === $selected_filter );
        } ) );
    } else {
        $filtered_log = $log;
    }

    // Pagination params
    $paged = max( 1, intval( $_GET['paged'] ?? 1 ) );
    $per_page = 50;
    $offset = ( $paged - 1 ) * $per_page;

    // Newest first: reverse filtered log then slice
    $reversed = array_reverse( $filtered_log );
    $paged_log = array_slice( $reversed, $offset, $per_page );

    // Table header
    echo '<h2>' . esc_html__( 'Recent Activity', 'controlled-draft-publisher' ) . '</h2>';
    echo '<table class="widefat"><thead><tr>';
    echo '<th>' . esc_html__( 'Post Title', 'controlled-draft-publisher' ) . '</th><th>' . esc_html__( 'URL', 'controlled-draft-publisher' ) . '</th><th>' . esc_html__( 'Date', 'controlled-draft-publisher' ) . '</th><th>' . esc_html__( 'Time', 'controlled-draft-publisher' ) . '</th><th>' . esc_html__( 'Post Type', 'controlled-draft-publisher' ) . '</th><th>' . esc_html__( 'Post ID', 'controlled-draft-publisher' ) . '</th>';
    echo '</tr></thead><tbody>';

    foreach ( $paged_log as $entry ) {
        $datetime = strtotime( $entry['time'] ?? '' );
        $date = $datetime ? date_i18n( 'Y-m-d', $datetime ) : '';
        $time = $datetime ? date_i18n( 'H:i:s', $datetime ) : '';
        $title_raw = $entry['title'] ?? '';
        $url_raw = $entry['url'] ?? '#';
        $id_raw = $entry['id'] ?? '';
        $type_raw = $entry['type'] ?? '';

        echo '<tr>';
        echo '<td>' . esc_html( $title_raw !== '' ? $title_raw : __( '(no title)', 'controlled-draft-publisher' ) ) . '</td>';
        echo '<td><a href="' . esc_url( $url_raw ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View', 'controlled-draft-publisher' ) . '</a></td>';
        echo '<td>' . esc_html( $date ) . '</td>';
        echo '<td>' . esc_html( $time ) . '</td>';
        echo '<td>' . esc_html( $type_raw ) . '</td>';
        echo '<td>' . esc_html( intval( $id_raw ) ) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<div style="clear:both;height:0;margin:0;padding:0;"></div>';

    // Pagination
    $total_pages = ceil( count( $filtered_log ) / $per_page );
    if ( $total_pages > 1 ) {
        echo '<div style="margin-top:1em;"><strong>' . esc_html__( 'Pages:', 'controlled-draft-publisher' ) . '</strong> ';
        $base_url = add_query_arg( [ 'page' => 'cdp-dashboard', 'cdp_filter_type' => $selected_filter ], admin_url( 'admin.php' ) );
        for ( $i = 1; $i <= $total_pages; $i++ ) {
            $link = esc_url( add_query_arg( 'paged', $i, $base_url ) );
            if ( $i === $paged ) {
                echo '<a href="' . esc_url( $link ) . '" style="font-weight:bold;text-decoration:underline;margin-right:8px;">' . esc_html( $i ) . '</a>';
            } else {
                echo '<a href="' . esc_url( $link ) . '" style="margin-right:8px;">' . esc_html( $i ) . '</a>';
            }
        }
        echo '</div>';
    }

    echo '</div>';
}

/* ---------------------------
   Settings page
   --------------------------- */

function cdp_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $method = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) );
    if ( $method === 'POST' ) {
        check_admin_referer( 'cdp_settings_action' );
        $new_interval = isset( $_POST['cdp_interval'] ) ? max( 1, absint( wp_unslash( $_POST['cdp_interval'] ) ) ) : 5;
        update_option( 'cdp_interval', $new_interval );

        // Read using filter_input to satisfy validated-input checks, then unslash and sanitize
        $raw_post_types = filter_input( INPUT_POST, 'cdp_post_types', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );

        if ( $raw_post_types === null ) {
            $post_types_clean = [];
        } else {
            $post_types_input = wp_unslash( $raw_post_types );
            if ( ! is_array( $post_types_input ) ) {
                $post_types_input = array( $post_types_input );
            }
            $post_types_clean = array_map( 'sanitize_text_field', $post_types_input );
        }

        update_option( 'cdp_post_types', $post_types_clean );

        $new_posts_per_run = isset( $_POST['cdp_posts_per_run'] ) ? max( 1, absint( wp_unslash( $_POST['cdp_posts_per_run'] ) ) ) : 1;
        update_option( 'cdp_posts_per_run', $new_posts_per_run );

        $raw_categories = filter_input( INPUT_POST, 'cdp_categories', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );
        if ( $raw_categories === null ) {
            $categories_clean = [];
        } else {
            $categories_input = wp_unslash( $raw_categories );
            if ( ! is_array( $categories_input ) ) {
                $categories_input = array( $categories_input );
            }
            $categories_clean = array_map( 'absint', $categories_input );
        }
        update_option( 'cdp_categories', $categories_clean );

        // Per-post-type interval overrides
        $raw_type_intervals = filter_input( INPUT_POST, 'cdp_type_interval', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );
        $type_intervals_clean = [];
        if ( is_array( $raw_type_intervals ) ) {
            $raw_type_intervals = wp_unslash( $raw_type_intervals );
            foreach ( $raw_type_intervals as $ptype => $minutes ) {
                $minutes = absint( $minutes );
                if ( $minutes > 0 ) {
                    $type_intervals_clean[ sanitize_text_field( $ptype ) ] = $minutes;
                }
            }
        }
        update_option( 'cdp_post_type_intervals', $type_intervals_clean );

        // Publishing window
        $window_enabled = isset( $_POST['cdp_window_enabled'] );
        update_option( 'cdp_window_enabled', $window_enabled );

        $window_start = isset( $_POST['cdp_window_start'] ) ? sanitize_text_field( wp_unslash( $_POST['cdp_window_start'] ) ) : '09:00';
        $window_end   = isset( $_POST['cdp_window_end'] ) ? sanitize_text_field( wp_unslash( $_POST['cdp_window_end'] ) ) : '18:00';
        update_option( 'cdp_window_start', null !== cdp_time_to_minutes( $window_start ) ? $window_start : '09:00' );
        update_option( 'cdp_window_end', null !== cdp_time_to_minutes( $window_end ) ? $window_end : '18:00' );

        update_option( 'cdp_skip_weekends', isset( $_POST['cdp_skip_weekends'] ) );

        // Email notifications
        update_option( 'cdp_email_notify', isset( $_POST['cdp_email_notify'] ) );
        $notify_email = isset( $_POST['cdp_notify_email'] ) ? sanitize_email( wp_unslash( $_POST['cdp_notify_email'] ) ) : '';
        update_option( 'cdp_notify_email', $notify_email ?: get_option( 'admin_email' ) );

        $logging = isset( $_POST['cdp_logging'] ) ? true : false;
        update_option( 'cdp_logging', $logging );

        // Reschedule using the (possibly changed) intervals
        cdp_reschedule_tick();

        echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'controlled-draft-publisher' ) . '</p></div>';
    }

    $interval = intval( get_option( 'cdp_interval', 75 ) );
    $post_types = get_post_types( [ 'public' => true ], 'names' );
    $selected_types = get_option( 'cdp_post_types', [ 'post' ] );
    $posts_per_run = intval( get_option( 'cdp_posts_per_run', 1 ) );
    $selected_categories = get_option( 'cdp_categories', [] );
    $categories = get_categories( [ 'hide_empty' => false ] );
    $logging = get_option( 'cdp_logging', true );
    $type_intervals = get_option( 'cdp_post_type_intervals', [] );
    $window_enabled = get_option( 'cdp_window_enabled', false );
    $window_start = get_option( 'cdp_window_start', '09:00' );
    $window_end = get_option( 'cdp_window_end', '18:00' );
    $skip_weekends = get_option( 'cdp_skip_weekends', false );
    $email_notify = get_option( 'cdp_email_notify', false );
    $notify_email = get_option( 'cdp_notify_email', get_option( 'admin_email' ) );

    echo '<div class="wrap"><h1>' . esc_html__( 'Draft Publisher Settings', 'controlled-draft-publisher' ) . '</h1><form method="post">';
    wp_nonce_field( 'cdp_settings_action' );
    echo '<table class="form-table">';
    echo '<tr><th scope="row">' . esc_html__( 'Default Interval (minutes)', 'controlled-draft-publisher' ) . '</th><td><input type="number" name="cdp_interval" value="' . esc_attr( $interval ) . '" min="1" /><p class="description">' . esc_html__( 'Used for any selected post type without its own override below.', 'controlled-draft-publisher' ) . '</p></td></tr>';
    echo '<tr><th scope="row">' . esc_html__( 'Posts Per Run', 'controlled-draft-publisher' ) . '</th><td><input type="number" name="cdp_posts_per_run" value="' . esc_attr( $posts_per_run ) . '" min="1" /></td></tr>';
    echo '<tr><th scope="row">' . esc_html__( 'Post Types', 'controlled-draft-publisher' ) . '</th><td>';
    foreach ( $post_types as $type ) {
        $checked = in_array( $type, (array) $selected_types, true ) ? 'checked' : '';
        $type_interval_value = isset( $type_intervals[ $type ] ) ? intval( $type_intervals[ $type ] ) : '';
        echo '<div style="margin-bottom:6px;">';
        echo '<label style="display:inline-block;min-width:160px;"><input type="checkbox" name="cdp_post_types[]" value="' . esc_attr( $type ) . '" ' . checked( $checked, 'checked', false ) . '> ' . esc_html( $type ) . '</label>';
        echo '<label>' . esc_html__( 'Interval override (minutes):', 'controlled-draft-publisher' ) . ' <input type="number" name="cdp_type_interval[' . esc_attr( $type ) . ']" value="' . esc_attr( $type_interval_value ) . '" min="0" placeholder="' . esc_attr__( 'default', 'controlled-draft-publisher' ) . '" style="width:80px;" /></label>';
        echo '</div>';
    }
    echo '</td></tr>';
    echo '<tr><th scope="row">' . esc_html__( 'Categories', 'controlled-draft-publisher' ) . '</th><td>';
    echo '<select name="cdp_categories[]" multiple size="10" style="width: 300px;">';
    foreach ( $categories as $category ) {
        $selected = in_array( $category->term_id, (array) $selected_categories, true ) ? 'selected' : '';
        echo '<option value="' . esc_attr( $category->term_id ) . '" ' . $selected . '>' . esc_html( $category->name ) . '</option>';
    }
    echo '</select>';
    echo '<p class="description">' . esc_html__( 'Hold Ctrl (Cmd on Mac) to select multiple categories.', 'controlled-draft-publisher' ) . '</p>';
    echo '<button type="button" class="button" onclick="document.querySelectorAll(\'[name=\\\'cdp_categories[]\\\'] option\').forEach(opt => opt.selected = false);">' . esc_html__( 'Clear Categories', 'controlled-draft-publisher' ) . '</button>';
    echo '</td></tr>';

    echo '<tr><th scope="row">' . esc_html__( 'Publishing Window', 'controlled-draft-publisher' ) . '</th><td>';
    echo '<label><input type="checkbox" name="cdp_window_enabled" ' . checked( $window_enabled, true, false ) . '> ' . esc_html__( 'Only publish between these times', 'controlled-draft-publisher' ) . '</label><br><br>';
    echo '<label>' . esc_html__( 'Start:', 'controlled-draft-publisher' ) . ' <input type="time" name="cdp_window_start" value="' . esc_attr( $window_start ) . '" /></label> ';
    echo '<label>' . esc_html__( 'End:', 'controlled-draft-publisher' ) . ' <input type="time" name="cdp_window_end" value="' . esc_attr( $window_end ) . '" /></label><br><br>';
    echo '<label><input type="checkbox" name="cdp_skip_weekends" ' . checked( $skip_weekends, true, false ) . '> ' . esc_html__( 'Skip Saturdays and Sundays', 'controlled-draft-publisher' ) . '</label>';
    echo '</td></tr>';

    echo '<tr><th scope="row">' . esc_html__( 'Email Notifications', 'controlled-draft-publisher' ) . '</th><td>';
    echo '<label><input type="checkbox" name="cdp_email_notify" ' . checked( $email_notify, true, false ) . '> ' . esc_html__( 'Email me when a draft is published or a publish attempt fails', 'controlled-draft-publisher' ) . '</label><br><br>';
    echo '<label>' . esc_html__( 'Send to:', 'controlled-draft-publisher' ) . ' <input type="email" name="cdp_notify_email" value="' . esc_attr( $notify_email ) . '" style="width:280px;" /></label>';
    echo '</td></tr>';

    echo '<tr><th scope="row">' . esc_html__( 'Enable Logging', 'controlled-draft-publisher' ) . '</th><td><label><input type="checkbox" name="cdp_logging" ' . checked( $logging, true, false ) . '> ' . esc_html__( 'Yes', 'controlled-draft-publisher' ) . '</label></td></tr>';
    echo '</table>';
    echo '<p><input type="submit" class="button-primary" value="' . esc_attr__( 'Save Settings', 'controlled-draft-publisher' ) . '"></p>';
    echo '</form></div>';
}

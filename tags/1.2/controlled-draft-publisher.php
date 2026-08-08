<?php
/**
 * Plugin Name: Controlled Draft Publisher
 * Description: Publishes one draft post every X minutes. Includes logging, stats, graphs, and admin dashboard with controls.
 * Version: 1.2
 * Requires at least: 5.0
 * Tested up to: 6.8
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

// Safer admin_init handler: do not run during POST (settings save) and limit scope
add_action( 'admin_init', function() {
    if ( ! is_admin() ) {
        return;
    }

    // Avoid running during form submissions (prevents side effects on save)
    $method = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) );
    if ( 'POST' === $method ) {
        return;
    }

    // Only run on the plugin settings page to reduce surface area
    $current_screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    $is_settings_page = $current_screen && isset( $current_screen->id ) && false !== strpos( $current_screen->id, 'controlled_draft_publisher' );

    // If not on settings page, ensure user has appropriate capability before proceeding
    if ( ! $is_settings_page && ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Schedule only if the interval option changed since last scheduled recurrence
    $configured = max( 1, intval( get_option( 'cdp_interval_minutes', 5 ) ) );
    $next = wp_next_scheduled( 'cdp_publish_event' );
    if ( ! $next ) {
        // no schedule at all, create one
        if ( function_exists( 'cdp_schedule_recurring_event_from_option' ) ) {
            cdp_schedule_recurring_event_from_option();
        }
        return;
    }

    // If a schedule exists, check if its interval matches configured minutes
    $crons = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
    $mismatch = false;
    foreach ( (array) $crons as $ts => $items ) {
        if ( isset( $items['cdp_publish_event'] ) ) {
            foreach ( (array) $items['cdp_publish_event'] as $sig => $evt ) {
                if ( ! empty( $evt['schedule'] ) ) {
                    if ( function_exists( 'cdp_get_interval_schedule_name' ) ) {
                        $expected = cdp_get_interval_schedule_name( $configured );
                        if ( $evt['schedule'] !== $expected ) {
                            $mismatch = true;
                            break 2;
                        }
                    }
                } else {
                    // one-off found where recurring expected
                    $mismatch = true;
                    break 2;
                }
            }
        }
    }

    if ( $mismatch && function_exists( 'cdp_schedule_recurring_event_from_option' ) ) {
        cdp_schedule_recurring_event_from_option();
    }
});

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

function cdp_deactivate() {
    wp_clear_scheduled_hook( 'cdp_publish_event' );
}
register_deactivation_hook( __FILE__, 'cdp_deactivate' );

function cdp_activate() {
    add_option( 'cdp_interval', 75 );
    add_option( 'cdp_post_types', [ 'post' ] );
    add_option( 'cdp_logging', true );
    add_option( 'cdp_log', [], '', 'no' );
    $interval = max( 1, intval( get_option( 'cdp_interval', 75 ) ) );
    if ( ! wp_get_schedule( 'cdp_publish_event' ) ) {
        wp_schedule_event( time() + ( $interval * 60 ), 'cdp_custom_interval', 'cdp_publish_event' );
    }
}

/* ---------------------------
   Cron interval
   --------------------------- */

add_filter( 'cron_schedules', function( $schedules ) {
    $interval = intval( get_option( 'cdp_interval', 75 ) );
    if ( $interval <= 0 ) $interval = 75;
	   $schedules['cdp_custom_interval'] = [
		'interval' => $interval * 60,
		/* translators: %d is the number of minutes */
		'display'  => esc_html__( 'Every %d Minutes', 'controlled-draft-publisher' ),
	];
    return $schedules;
});

/* ---------------------------
   Publish logic + logging
   --------------------------- */

add_action( 'cdp_publish_event', 'cdp_publish_draft' );

function cdp_publish_draft( $forced_type = null ) {
    $types = $forced_type ? [ $forced_type ] : get_option( 'cdp_post_types', [ 'post' ] );
    $query = new WP_Query( [
        'fields' => 'ids',
        'post_type' => $types,
        'post_status' => 'draft',
        'posts_per_page' => 1,
        'orderby' => 'date',
        'order' => 'ASC'
    ] );

    if ( ! empty( $query->posts ) ) {
        foreach ( $query->posts as $id ) {
            $result = wp_update_post( [ 'ID' => $id, 'post_status' => 'publish' ], true );
            if ( ! is_wp_error( $result ) ) {
                if ( get_option( 'cdp_logging' ) ) {
                    $log = get_option( 'cdp_log', [] );
                    $log[] = [
                        'id'    => $id,
                        'time'  => date_i18n( 'Y-m-d H:i:s', current_time( 'timestamp' ) ),
                        'title' => get_the_title( $id ),
                        'url'   => get_permalink( $id ),
                        'type'  => get_post_type( $id )
                    ];
                    $max_log = 1000;
                    if ( count( $log ) > $max_log ) $log = array_slice( $log, -$max_log );
                    update_option( 'cdp_log', $log, false );
                }
            } else {
                set_transient( 'cdp_last_publish_error', $result->get_error_message(), 300 );
            }
        }
        wp_reset_postdata();
    }
}

/* ---------------------------
   Admin menu registration
   --------------------------- */

add_action( 'admin_menu', 'cdp_register_menu' );

function cdp_register_menu() {
    add_menu_page(
        esc_html__( 'Draft Publisher', 'controlled-draft-publisher' ),
        esc_html__( 'Draft Publisher', 'controlled-draft-publisher' ),
        'manage_options',
        'cdp-dashboard',
        'cdp_dashboard_page',
        'dashicons-schedule',
        25
    );
    add_submenu_page(
        'cdp-dashboard',
        esc_html__( 'Settings', 'controlled-draft-publisher' ),
        esc_html__( 'Settings', 'controlled-draft-publisher' ),
        'manage_options',
        'cdp-settings',
        'cdp_settings_page'
    );
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
                $interval = max( 1, intval( get_option( 'cdp_interval', 5 ) ) );
                wp_schedule_event( time() + ( $interval * 60 ), 'cdp_custom_interval', 'cdp_publish_event' );
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
                // Prepare filename
                $filename = 'cdp-log-' . date_i18n( 'Ymd-His' ) . '.csv';

                // Initialize WP_Filesystem
                require_once ABSPATH . 'wp-admin/includes/file.php';
                WP_Filesystem();
                global $wp_filesystem;

                // Make sure no WP output is sent before headers
                nocache_headers();
                while ( ob_get_level() ) {
                    ob_end_clean();
                }

                // Send CSV headers
                header( 'Content-Type: text/csv; charset=UTF-8' );
                header( 'Content-Disposition: attachment; filename="' . esc_attr( $filename ) . '"' );
                header( 'Pragma: public' );
                header( 'Expires: 0' );

                // Output BOM for correct Excel/UTF-8 handling
                echo "\xEF\xBB\xBF";

                // Use output buffer to capture CSV content
                ob_start();
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
                $csv_content = ob_get_clean();
                $wp_filesystem->put_contents( 'php://output', $csv_content, FS_CHMOD_FILE );
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
    $post_types = get_post_types( [ 'public' => true ], 'names' );

    // Sanitized filter selection
    $selected_filter = isset( $_REQUEST['cdp_filter_type'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['cdp_filter_type'] ) ) : '';

    echo '<div class="wrap"><h1>' . esc_html__( 'Draft Publisher Dashboard', 'controlled-draft-publisher' ) . '</h1>';

    // Status / Interval
    echo '<p><strong>' . esc_html__( 'Status:', 'controlled-draft-publisher' ) . '</strong> ';
    echo ( $enabled
        ? '<span style="color:green;font-weight:bold;">' . esc_html__( 'Active', 'controlled-draft-publisher' ) . '</span>'
        : '<span style="color:red;font-weight:bold;">' . esc_html__( 'Inactive', 'controlled-draft-publisher' ) . '</span>' );
    echo '</p>';
    /* translators: %d is the number of minutes */
    echo '<p><strong>' . esc_html__( 'Interval:', 'controlled-draft-publisher' ) . '</strong> ' . sprintf( esc_html__( 'Every %d minutes', 'controlled-draft-publisher' ), intval( $interval ) ) . '</p>';

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
        ? '<p><strong>' . esc_html__( 'Next Scheduled Run:', 'controlled-draft-publisher' ) . '</strong> ' . esc_html( date_i18n( 'Y-m-d H:i:s', $next + ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) ) . '</p>'
        : '<p><strong>' . esc_html__( 'Next Scheduled Run:', 'controlled-draft-publisher' ) . '</strong> <span style="color:red;">' . esc_html__( 'Not scheduled', 'controlled-draft-publisher' ) . '</span></p>';

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
    $per_page = 10;
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
     	if ( isset( $_POST['cdp_post_types'] ) ) {
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
        $logging = isset( $_POST['cdp_logging'] ) ? true : false;
        update_option( 'cdp_logging', $logging );
        wp_clear_scheduled_hook( 'cdp_publish_event' );
        if ( ! wp_get_schedule( 'cdp_publish_event' ) ) {
            wp_schedule_event( time() + ( $new_interval * 60 ), 'cdp_custom_interval', 'cdp_publish_event' );
        }
        echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'controlled-draft-publisher' ) . '</p></div>';
    }

    $interval = intval( get_option( 'cdp_interval', 75 ) );
    $post_types = get_post_types( [ 'public' => true ], 'names' );
    $selected_types = get_option( 'cdp_post_types', [ 'post' ] );
    $logging = get_option( 'cdp_logging', true );

    echo '<div class="wrap"><h1>' . esc_html__( 'Draft Publisher Settings', 'controlled-draft-publisher' ) . '</h1><form method="post">';
    wp_nonce_field( 'cdp_settings_action' );
    echo '<table class="form-table">';
    echo '<tr><th scope="row">' . esc_html__( 'Interval (minutes)', 'controlled-draft-publisher' ) . '</th><td><input type="number" name="cdp_interval" value="' . esc_attr( $interval ) . '" min="1" /></td></tr>';
    echo '<tr><th scope="row">' . esc_html__( 'Post Types', 'controlled-draft-publisher' ) . '</th><td>';
    foreach ( $post_types as $type ) {
        $checked = in_array( $type, (array) $selected_types, true ) ? 'checked' : '';
        echo '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="cdp_post_types[]" value="' . esc_attr( $type ) . '" ' . checked( $checked, 'checked', false ) . '> ' . esc_html( $type ) . '</label>';
    }
    echo '</td></tr>';
    echo '<tr><th scope="row">' . esc_html__( 'Enable Logging', 'controlled-draft-publisher' ) . '</th><td><label><input type="checkbox" name="cdp_logging" ' . checked( $logging, true, false ) . '> ' . esc_html__( 'Yes', 'controlled-draft-publisher' ) . '</label></td></tr>';
    echo '</table>';
    echo '<p><input type="submit" class="button-primary" value="' . esc_attr__( 'Save Settings', 'controlled-draft-publisher' ) . '"></p>';
    echo '</form></div>';
	}
}
<?php
// Controlled Draft Publisher — uninstall cleanup
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$cdp_options = [
    'cdp_interval',
    'cdp_post_types',
    'cdp_logging',
    'cdp_log',
    'cdp_posts_per_run',
    'cdp_categories',
    'cdp_post_type_intervals',
    'cdp_post_type_last_run',
    'cdp_window_enabled',
    'cdp_window_start',
    'cdp_window_end',
    'cdp_skip_weekends',
    'cdp_email_notify',
    'cdp_notify_email',
    'cdp_log_autoload_fixed',
];

foreach ( $cdp_options as $option ) {
    delete_option( $option );
}

wp_clear_scheduled_hook( 'cdp_publish_event' );

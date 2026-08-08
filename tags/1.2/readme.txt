=== Controlled Draft Publisher ===
Contributors: techygeekshome
Tags: drafts, scheduler, automation, publishing, cron
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publishes one draft post every configurable interval, with logging and an admin dashboard.

== Description ==
Publishes one draft post every X minutes. Includes logging, stats, and an admin dashboard with start/stop, manual publish, filter, and refresh controls.

**Features:**
- Publish one draft post at a configurable interval.
- Simple start/stop controls and manual publish button.
- Activity log with timestamps, post titles, and permalinks.
- Basic stats: total published and last published entry.
- Works with selected post types.

== Installation ==
1. Upload the plugin folder to the `/wp-content/plugins/` directory, or install via the WordPress admin.
2. Activate the plugin through the 'Plugins' screen in WordPress admin.
3. Go to Draft Publisher → Settings to configure post types, interval, and logging.
4. Use the Draft Publisher dashboard to start/stop the scheduler or manually publish drafts.

== Frequently Asked Questions ==
= Can I control which post types are published? =
Yes, you can select one or more post types in the plugin settings. Default is `post`.

= Does it support custom intervals? =
Yes, set the number of minutes between publishes in the settings page. Minimum safe interval is recommended to avoid accidental bulk publishes.

= Is publishing logged? =
Yes, if logging is enabled the plugin stores a rolling activity log (option `cdp_log`) and updates the last/total counters.

= How does scheduling work? =
The plugin uses WordPress cron (WP-Cron) to schedule publishes. For low-traffic sites, set up a server cron job (e.g., `*/5 * * * * wget -q -O - https://your-site.com/wp-cron.php`) for reliable timing.

= Can I manually publish a draft? =
Yes, use the "Publish Now" button on the dashboard to publish a draft immediately.

== Screenshots ==
1. Dashboard: Controlled Draft Publisher main controls and activity graph.
2. Recent Activity: Recent activity log with all information about posted items.
3. Settings: select post types, interval, and enable/disable logging.

== Changelog ==
= 1.2 =
* Added start/stop controls to the dashboard.
* Fixed timezone display for "Next Scheduled Run" (uses site timezone, e.g., BST).
* Improved scheduling logic for dynamic intervals.

= 1.1 =
* Added CSV export for activity log.
* Added 7-day publish history chart to dashboard.
* Enhanced logging with post type and permalink details.

= 1.0 =
* Initial release.

== Upgrade Notice ==
= 1.2 =
Improved scheduling and timezone handling. Recommended for all users.

= 1.1 =
Added CSV export and visual stats. Upgrade for better reporting.

= 1.0 =
Initial public release.

== Privacy Policy ==
Controlled Draft Publisher stores an activity log (`cdp_log`) in the WordPress database when logging is enabled. The log includes post IDs, titles, timestamps, permalinks, and post types for published drafts. No user data is collected or sent externally. Logs can be cleared or exported via the dashboard.

== Notes ==
- Ensure your site meets the PHP and WordPress version requirements before installing.
- Server cron or WP-Cron behaviour may vary on low-traffic sites; consider using a real cron if reliable timing is required.
- Translation-ready: Includes `controlled-draft-publisher.pot` in the `languages/` folder for translators.

== License ==
This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License version 2, or any later version, as published by the Free Software Foundation.

== License URI ==
https://www.gnu.org/licenses/gpl-2.0.html
=== Controlled Draft Publisher ===
Contributors: techygeekshome
Donate link: https://ko-fi.com/techygeekshome
Tags: drafts, scheduler, publish automation, content workflow, cron
Requires at least: 5.0
Tested up to: 7.0.2
Requires PHP: 8.0
Stable tag: 1.7.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publishes draft posts on a configurable interval per post type, with optional publishing-window/weekend restrictions and email alerts.

== Description ==
Publishes draft posts automatically on a schedule. Includes per-post-type intervals, an optional publishing window (e.g. only publish 9am-6pm on weekdays), email notifications, logging, and an admin dashboard with start/stop, manual publish, filter, and refresh controls.

**Features:**
- Publish drafts at a configurable interval, globally or per post type.
- Optional publishing window and weekend skip, so drafts never go out overnight or on weekends unless you want them to.
- Optional email alert when a draft is published or when a publish attempt fails.
- Simple start/stop controls and manual publish button.
- "Next Up" preview on the dashboard shows exactly which drafts will publish next, in order, before it happens.
- Per-post "Exclude this draft from auto-publish" option, for the odd draft you want to hold back by hand.
- Activity log with timestamps, post titles, and permalinks, exportable to CSV.
- Basic stats: total published, last published entry, 7-day activity chart.
- Works with selected post types and categories.

== Installation ==
1. Upload the plugin folder to the `/wp-content/plugins/` directory, or install via the WordPress admin.
2. Activate the plugin through the 'Plugins' screen in WordPress admin.
3. Go to TGH → DP Settings to configure post types, intervals, publishing window, and email alerts.
4. Use the TGH → Draft Publisher dashboard to start/stop the scheduler or manually publish drafts.

== Frequently Asked Questions ==
= Can I control which post types are published? =
Yes, you can select one or more post types in the plugin settings, and optionally give each one its own interval.

= Can I stop it publishing overnight or on weekends? =
Yes. Enable the publishing window in Settings and set a start/end time, and optionally tick "Skip weekends".

= Does it support custom intervals? =
Yes, set the number of minutes between publishes globally, or override it per post type.

= Can I get an email when something is published? =
Yes, enable email notifications in Settings and set the address to send to (defaults to the site admin email).

= Is publishing logged? =
Yes, if logging is enabled the plugin stores a rolling activity log and updates the last/total counters.

= How does scheduling work? =
The plugin uses WordPress cron (WP-Cron) to schedule publishes. For low-traffic sites, set up a server cron job (e.g., `*/5 * * * * wget -q -O - https://your-site.com/wp-cron.php`) for reliable timing.

= Can I manually publish a draft? =
Yes, use the "Publish Now" button on the dashboard to publish a draft immediately.

= Can I stop one specific draft from being auto-published, without changing my settings? =
Yes. Open the draft in the editor and tick "Exclude this draft from auto-publish" in the Draft Publisher box in the sidebar. It's skipped until you untick it or publish it yourself — your interval and post type settings are unaffected.

= How do I see what's going to publish next? =
The dashboard's "Next Up" panel lists the next few drafts in the order they'll actually go out, using your current post type/category settings.

== Screenshots ==
1. Dashboard: Controlled Draft Publisher main controls and activity graph.
2. Recent Activity: Recent activity log with all information about posted items.
3. Settings: select post types, intervals, publishing window, and email alerts.

== Changelog ==

= 1.7.3 =
* The TechyGeeksHome panel now lists the whole current range of applications rather than four of them, and links to the full list.
* Tidied a few pieces of wording in the admin screens.

= 1.7.2 =
* The TechyGeeksHome hub page now lists everything: BackBurner Post Archiver (newly live on WordPress.org), AppGeek, PDFGeek, Ultimate Settings Panel and NeoDark Pro were all missing. The DiskGeek link pointed at an old announcement post rather than its product page.
* Hub strings are now translatable rather than hard-coded English.


= 1.7.1 =
* Fixed: the shared "Our Plugins" hub page functions/constants were renamed from the generic tgh_hub_ prefix to a unique tghhub_ prefix, avoiding a naming-collision risk raised by WordPress.org's Plugins Team during a companion plugin's (BackBurner Post Archiver) manual review.
* Fixed: the shared hub's admin menu no longer registers at a hardcoded top-level position; it now uses WordPress core's default (safe) placement.

= 1.7 =
* Added a "Next Up" preview on the dashboard, showing exactly which drafts will publish next and in what order.
* Added a per-post "Exclude this draft from auto-publish" option (matches the same exclusion pattern used in our BackBurner Post Archiver plugin).
* Confirmed compatible with WordPress 7.0.2.
* Refreshed the readme's tags for accuracy.

= 1.6 =
* The plugin's admin pages now live under a shared "TGH" menu alongside our other plugins, with a landing page cross-promoting everything we've built.
* Added a Ko-fi donate link.

= 1.5 =
* Added per-post-type interval overrides.
* Added optional publishing window (start/end time) and weekend skip.
* Added optional email notifications on publish and on error.
* Added uninstall cleanup so plugin options are removed when deleted.
* Simplified CSV export (removed unnecessary WP_Filesystem indirection that could fail on some hosts).
* Added a small dismissible notice on the plugin's own admin pages pointing to our other plugin and theme.

= 1.4 =
* Added taxonomy settings to select publishing from categories and tags.

= 1.3 =
* Fixed white screen when loading Settings page.
* Change Recent Activity log view per page from 10 to 50.
* Added Settings link in Plugins page.

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
= 1.7 =
Adds a "Next Up" dashboard preview and a per-post auto-publish exclusion option. Tested with WordPress 7.0.2. No settings changes needed.

= 1.6 =
Settings pages have moved under a shared "TGH" admin menu. Your saved settings and scheduled jobs are unaffected — only the menu location changed.

= 1.5 =
Adds per-post-type intervals, publishing windows, email alerts, and uninstall cleanup. Recommended for all users.

= 1.2 =
Improved scheduling and timezone handling. Recommended for all users.

= 1.1 =
Added CSV export and visual stats. Upgrade for better reporting.

= 1.0 =
Initial public release.

== Privacy Policy ==
Controlled Draft Publisher stores an activity log (`cdp_log`) in the WordPress database when logging is enabled. The log includes post IDs, titles, timestamps, permalinks, and post types for published drafts. If email notifications are enabled, publish summaries are sent to the configured notification address using WordPress's built-in mail function. No user data is collected or sent externally. Logs can be cleared or exported via the dashboard. All plugin options are removed automatically when the plugin is deleted.

== Notes ==
- Ensure your site meets the PHP and WordPress version requirements before installing.
- Server cron or WP-Cron behaviour may vary on low-traffic sites; consider using a real cron if reliable timing is required.
- Translation-ready: Includes `controlled-draft-publisher.pot` in the `languages/` folder for translators.

== License ==
This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License version 2, or any later version, as published by the Free Software Foundation.

== License URI ==
https://www.gnu.org/licenses/gpl-2.0.html

<?php
/**
 * Uninstall routine.
 *
 * Runs when the plugin is deleted from the Plugins screen, never on deactivation, so switching the
 * plugin off temporarily loses nothing at all.
 *
 * **Deleting the plugin keeps the owner's settings by default.** Someone who deletes the plugin by
 * accident, or removes it to install a clean copy, gets their address settings back when they
 * reinstall. Destruction is opt-in, through the "Delete all data" checkbox in the HivePress URLs
 * section of the Permalinks page, and is never a surprise.
 *
 * There is no way to ask at delete time. The confirmation form in wp-admin/plugins.php:400-412 is
 * hard-coded with no do_action or apply_filters inside it, so a checkbox cannot be added to that
 * screen; the setting has to live on our own page. Worse, WordPress prints "(will also delete its
 * data)" on that screen whenever an uninstall.php exists at all (wp-admin/plugins.php:379, WP 7.1),
 * whatever the file actually does, so the setting's own description tells the owner that the core
 * warning does not apply unless they ticked the box.
 *
 * @package Permalinks
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// Exit unless WordPress is genuinely uninstalling this plugin.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/*
 * The option prefix, repeated here rather than read from the main plugin file, because uninstall.php
 * runs on its own and that file is never loaded.
 *
 * It is deliberately NOT "hp_permalinks". HivePress core stores the base slugs a site owner types on
 * the Permalinks page in an option of exactly that name (hivepress/includes/components/
 * class-admin.php:385, core 1.7.31), so a sweep for "hp_permalinks%" would match it and delete every
 * HivePress base slug on the site while claiming to remove only this plugin's data. Every listing,
 * vendor and category address would change at once, with no warning and nothing to restore from.
 * Never widen this prefix.
 */
$hppl_prefix = 'hp_hppl_';

/**
 * Removes this plugin's traces from the site that is current when it is called.
 *
 * Written as a function because on a network it has to run once per site: the
 * uninstaller runs in the context of one site only, and everywhere else would
 * be left holding rewrite rules naming segments that nothing produces any more,
 * so every listing and vendor address would 404 with nothing to explain it.
 *
 * @param string $prefix Option prefix.
 * @return void
 */
function hppl_uninstall_site( $prefix ) {
	global $wpdb;

	// Read the owner's choice first, before anything is touched.
	$settings = (array) get_option( $prefix . 'settings', [] );

	$delete_all = ! empty( $settings['delete_data'] );

	/*
	 * -------------------------------------------------------------------------------------------------
	 * Always cleaned, whichever way the setting is set.
	 * -------------------------------------------------------------------------------------------------
	 */

	/*
	 * The stored rewrite rules carry this plugin's extra address segments, and with the plugin gone
	 * nothing rebuilds them, so every listing and vendor address would answer 404 until something else
	 * happened to flush. Deleting the rules makes WordPress rebuild them cleanly on the next request.
	 * The structure fingerprint is regenerable runtime state that exists only to detect that same
	 * staleness, so it goes with them, whichever way the setting is set.
	 */
	delete_option( 'rewrite_rules' );
	delete_option( $prefix . 'fingerprint' );

	// The updater's cached release lookup. A site transient lives under its own prefix, so neither the
	// option sweep below nor a plain delete_option() would ever reach it.
	delete_site_transient( 'permalinks_for_hivepress_release' );

	/*
	 * The updater's other two site transients and its background job.
	 *
	 * All three are regenerable runtime state belonging to the update check, not the owner's
	 * configuration, so they go unconditionally alongside the release cache above. Core's daily sweep
	 * clears expired site transients within about a day on single-site; on multisite they live in
	 * wp_sitemeta and are only purged when something asks for them, so on a network they simply stay.
	 * The scheduled refresh is worse than debris: it is a job whose callback no longer exists.
	 *
	 * Unscheduled from both places it can be, because the refresh is queued through HivePress's
	 * scheduler (Action Scheduler) when HivePress is present and through WP-Cron when it is not.
	 */
	delete_site_transient( 'permalinks_for_hivepress_release_reason' );
	delete_site_transient( 'permalinks_for_hivepress_release_rate_limit' );

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'permalinks_for_hivepress_release_refresh', [], 'hivepress' );
		as_unschedule_all_actions( 'permalinks_for_hivepress_release_refresh' );
	}

	wp_clear_scheduled_hook( 'permalinks_for_hivepress_release_refresh' );

	// Any ordinary transient the plugin has ever set. Nothing writes one today, but a transient is
	// stored as "_transient_{name}" plus a separate "_transient_timeout_{name}" row, so the prefix sweep
	// used for options below cannot match them: it anchors on the prefix at the start of the name.
	// Leaving a timeout row behind with no value row is the classic orphan.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup of wildcard option names, which no WordPress API can enumerate.
	$transients = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			'_transient_' . $wpdb->esc_like( $prefix ) . '%',
			'_transient_timeout_' . $wpdb->esc_like( $prefix ) . '%'
		)
	);

	foreach ( (array) $transients as $transient_name ) {
		delete_option( $transient_name );
	}

	/*
	 * -------------------------------------------------------------------------------------------------
	 * Everything below happens only when the owner asked for it.
	 * -------------------------------------------------------------------------------------------------
	 */

	if ( $delete_all ) {

		// Delete the options by prefix, so the per-post-type settings are swept without this file having
		// to know which post types the site had. This runs once, while the plugin is being deleted, so
		// there is nothing worth caching.
		//
		// The "delete all data" option itself is excluded here and removed at the very end. If this run
		// fails part-way through, the flag is still set, so a second attempt finishes the job. Sweeping it
		// away first would silently flip the site back to "retain" with half the settings already gone.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup of wildcard option names, which no WordPress API can enumerate.
		$option_names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name != %s",
				$wpdb->esc_like( $prefix ) . '%',
				$prefix . 'delete_data'
			)
		);

		foreach ( (array) $option_names as $option_name ) {

			// Use the options API so persistent object caches are invalidated too.
			delete_option( $option_name );
		}

		// Last, and only once everything above has succeeded.
		delete_option( $prefix . 'delete_data' );
	}
}

/*
 * A network install runs this file once, in one site's context. Every other site on the network has
 * its own rewrite rules and its own settings, so each one is visited in turn. On a single site the
 * loop is skipped entirely and nothing changes.
 */
if ( is_multisite() ) {
	foreach ( get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	) as $hppl_site_id ) {
		switch_to_blog( (int) $hppl_site_id );

		hppl_uninstall_site( $hppl_prefix );

		restore_current_blog();
	}
} else {
	hppl_uninstall_site( $hppl_prefix );
}

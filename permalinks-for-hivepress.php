<?php
/**
 * Plugin Name: Permalinks for HivePress
 * Plugin URI: https://github.com/irapidchris-del/permalinks-for-hivepress
 * Description: Build SEO-friendly web addresses for listings, vendors and requests by adding their category and region, with new options on the Permalinks page.
 * Version: 1.0.0
 * Author: ChrisB @ HivePress Community
 * Author URI: https://community.hivepress.io/u/chrisb/summary
 * Text Domain: permalinks-for-hivepress
 * Domain Path: /languages/
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: hivepress
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/irapidchris-del/permalinks-for-hivepress
 *
 * @package Permalinks
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

define( 'HPPL_VERSION', '1.0.0' );

/**
 * Prefix for every option this plugin stores.
 *
 * It is deliberately NOT "hp_permalinks". HivePress core keeps the base slugs
 * a site owner types on the Permalinks page in an option of exactly that name
 * (hivepress/includes/components/class-admin.php:385, core 1.7.31), so the
 * prefix sweep in uninstall.php - "WHERE option_name LIKE 'hp_permalinks%'" -
 * would have matched it and deleted every HivePress base slug on the site
 * while claiming to remove only this plugin's data. "hp_hppl_" cannot collide
 * with anything core owns. Never widen it.
 */
define( 'HPPL_OPTION_PREFIX', 'hp_hppl_' );

// Set up updates from GitHub releases.
require_once __DIR__ . '/includes/updater.php';

Permalinks\Updater\bootstrap( __FILE__ );

/**
 * Registers the extension.
 *
 * Two registration forms exist and both have a failure mode. HivePress resolves
 * a bare directory path to `{dirname}/{dirname}.php`, so the string form fails
 * silently whenever the installed folder name differs from the main file name
 * (a source zip unpacks to `permalinks-for-hivepress-main`, for instance). The
 * array form always registers, but core's updater probe concatenates every
 * entry as a string, so an array entry makes it log a warning on each request
 * unless the probe has already been satisfied. So: the string form whenever the
 * folder name matches, and only for a renamed folder the array form, with the
 * probe run here first over the string entries so core's loop never reaches
 * the array. The filter is registered late so extensions that bundle the
 * updates package are already listed by the time that probe runs.
 *
 * @param array<string, mixed> $extensions Extension arguments.
 * @return array<string, mixed>
 */
function hppl_register_extension( $extensions ) {
	if ( file_exists( __DIR__ . '/' . basename( __DIR__ ) . '.php' ) ) {
		$extensions[] = __DIR__;

		return $extensions;
	}

	if ( ! isset( $extensions['updates'] ) ) {
		$path = '/vendor/hivepress/hivepress-updates';

		foreach ( $extensions as $dir ) {
			if ( is_string( $dir ) && file_exists( $dir . $path . '/hivepress-updates.php' ) ) {
				$extensions['updates'] = $dir . $path;

				break;
			}
		}

		// Set it even when nothing was found. Core's own probe (class-core.php:245-256) only runs
		// while this key is unset, and it concatenates EVERY entry as a string, so on a site with
		// no premium extension the array entry below would make it warn "Array to string
		// conversion" on every single request. A path that does not exist is harmless: core's
		// string branch drops it at its own file_exists() guard (:277), which is the same outcome
		// as the probe finding nothing, minus the warning. Only ever reached on a renamed folder,
		// where the array entry is the only way this plugin loads at all.
		if ( ! isset( $extensions['updates'] ) ) {
			$extensions['updates'] = __DIR__ . $path;
		}
	}

	$extensions['permalinks_for_hivepress'] = [
		'name'    => 'Permalinks for HivePress',
		'version' => HPPL_VERSION,
		'path'    => __DIR__,
		'url'     => rtrim( plugin_dir_url( __FILE__ ), '/' ),
	];

	return $extensions;
}

add_filter( 'hivepress/v1/extensions', 'hppl_register_extension', 100 );

/**
 * Rebuilds the stored rewrite rules when the plugin switches off or on.
 *
 * The stored rules carry this plugin's extra URL segments. Deactivating without
 * clearing them would leave every listing and vendor address pointing at a rule
 * the site can no longer answer, so the rules are dropped here and WordPress
 * rebuilds them, without the extra segments, on the next request. The structure
 * fingerprint goes too: it is what the component compares against to decide
 * whether the rules are stale, and keeping it across a deactivation would make
 * a later reactivation look like nothing changed when the stored rules were in
 * fact rebuilt without this plugin's segments in between.
 *
 * Two community topics reported addresses that simply 404'd until somebody
 * happened to press Save Changes on the Permalinks page, in both cases because
 * a snippet added rewrite rules without ever flushing. Flushing on activation
 * and deactivation is the floor; the component also repairs the rules by itself
 * whenever the structure it builds stops matching the stored fingerprint.
 *
 * @return void
 */
function hppl_reset_site_rewrite_rules() {
	delete_option( 'rewrite_rules' );
	delete_option( HPPL_OPTION_PREFIX . 'fingerprint' );
}

/**
 * Rebuilds the stored rewrite rules, across a whole network where that applies.
 *
 * On a network-wide activation or deactivation WordPress runs the hook once, in
 * the context of ONE site, so clearing the rules for the current site alone
 * leaves every other site on the network holding rules that name segments
 * nothing will produce any more. Their listing and vendor addresses would then
 * 404 until somebody opened Settings, Permalinks on each site in turn, and
 * nothing would say why. Deactivation is the case that has to be handled here
 * rather than left to repair itself: once the plugin is off, there is no code
 * left to notice.
 *
 * @param bool $network_wide Whether the action applies to the whole network.
 * @return void
 */
function hppl_reset_rewrite_rules( $network_wide = false ) {
	if ( $network_wide && is_multisite() ) {
		foreach ( get_sites(
			[
				'fields' => 'ids',
				'number' => 0,
			]
		) as $hppl_site_id ) {
			switch_to_blog( (int) $hppl_site_id );

			hppl_reset_site_rewrite_rules();

			restore_current_blog();
		}

		return;
	}

	hppl_reset_site_rewrite_rules();
}

register_activation_hook( __FILE__, 'hppl_reset_rewrite_rules' );
register_deactivation_hook( __FILE__, 'hppl_reset_rewrite_rules' );

// Add a settings link on the Plugins screen. The plugin's options live on the
// core Permalinks page rather than a HivePress settings tab, so that is where
// the link goes.
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function( $links ) {
		if ( class_exists( '\HivePress\Core' ) ) {
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-permalink.php' ) ) . '">' . esc_html__( 'Settings', 'permalinks-for-hivepress' ) . '</a>' );
		}

		return $links;
	}
);

// Show a notice if HivePress is not active.
add_action(
	'admin_notices',
	function() {
		if ( ! class_exists( '\HivePress\Core' ) && current_user_can( 'activate_plugins' ) ) {

			// Dismissible, because an undismissable notice on every admin screen is admin hijacking even
			// when the thing it says is true. WordPress only hides it for the current page load, so the
			// warning returns until HivePress is actually activated.
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Permalinks for HivePress requires the HivePress plugin to be installed and activated.', 'permalinks-for-hivepress' ) . '</p></div>';
		}
	}
);

/**
 * The author's support page.
 *
 * One place, so the Plugins row and the View details popup can never drift apart.
 *
 * @return string
 */
function hppl_get_support_url() {
	return 'https://ko-fi.com/chrisbathivepresscommunity';
}

/**
 * Adds a quiet "Donate" link to this plugin's row meta.
 *
 * WordPress fires plugin_row_meta for EVERY plugin on the screen and joins the items with a pipe,
 * so without the basename test the link would appear on every row on the site.
 *
 * The markup is copied verbatim from the house spec in `releasing.md` rather than composed here:
 * every plugin's row has to look identical, and sessions have drifted before. The label is exactly
 * "Donate", which is also the wording WordPress uses in the details popup, and the icon is a
 * Dashicon rather than Font Awesome because Dashicons is the admin's own font and is always loaded
 * there. WordPress joins row-meta items with " | " itself, so this returns a bare anchor.
 *
 * @param array<string> $meta Row meta links.
 * @param string        $plugin_file Plugin file the row belongs to.
 * @return array<string>
 */
function hppl_add_row_meta( $meta, $plugin_file ) {
	if ( plugin_basename( __FILE__ ) === $plugin_file ) {
		$meta[] = '<a href="' . esc_url( hppl_get_support_url() ) . '" target="_blank" rel="noopener noreferrer">'
			. '<span class="dashicons dashicons-star-filled" style="font-size:14px;line-height:1.3;"></span> '
			. esc_html__( 'Donate', 'permalinks-for-hivepress' )
			. '</a>';
	}

	return $meta;
}

add_filter( 'plugin_row_meta', 'hppl_add_row_meta', 10, 2 );

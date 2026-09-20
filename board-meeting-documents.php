<?php
/**
 * Plugin Name:       Board Meeting Documents
 * Plugin URI:        https://ibgolden.com
 * Description:       Manage Board Meetings with Agenda and Minutes PDFs, plus a Document Library of grouped PDF sections (Annual Reports, Audit Reports, Budgets and more). Everything is displayed on the frontend with shortcodes in accessible, year-grouped accordions.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            GraphicZen
 * Author URI:        https://ibgolden.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       board-meeting-documents
 * Domain Path:       /languages
 *
 * @package BoardMeetingDocuments
 */

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * ---------------------------------------------------------------------------
 * Constants
 * ---------------------------------------------------------------------------
 */
define( 'BMD_VERSION', '1.1.0' );
define( 'BMD_PLUGIN_FILE', __FILE__ );
define( 'BMD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BMD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
/*
 * Post type slug. Prefixed with bmd_ so it can never collide with another
 * plugin that also registers a generic "board_meeting" post type.
 * (Versions before 1.1.0 used "board_meeting"; see bmd_maybe_migrate().)
 */
define( 'BMD_POST_TYPE', 'bmd_meeting' );
define( 'BMD_LEGACY_POST_TYPE', 'board_meeting' );
define( 'BMD_DB_VERSION', 2 );

/*
 * ---------------------------------------------------------------------------
 * Includes
 * ---------------------------------------------------------------------------
 */
require_once BMD_PLUGIN_DIR . 'includes/class-bmd-helpers.php';
require_once BMD_PLUGIN_DIR . 'includes/class-bmd-post-type.php';
require_once BMD_PLUGIN_DIR . 'includes/class-bmd-meta-boxes.php';
require_once BMD_PLUGIN_DIR . 'includes/class-bmd-admin.php';
require_once BMD_PLUGIN_DIR . 'includes/class-bmd-shortcodes.php';
require_once BMD_PLUGIN_DIR . 'includes/class-bmd-document-sections.php';

/*
 * ---------------------------------------------------------------------------
 * Activation / deactivation
 * ---------------------------------------------------------------------------
 * Rewrite rules are flushed ONLY here (never on init), as required by the
 * WordPress plugin guidelines. The post type is registered first so that the
 * flush knows about it.
 */

/**
 * Runs on plugin activation.
 *
 * @return void
 */
function bmd_activate(): void {
	BMD_Post_Type::register();
	BMD_Document_Sections::register();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'bmd_activate' );

/**
 * Runs on plugin deactivation.
 *
 * @return void
 */
function bmd_deactivate(): void {
	unregister_post_type( BMD_POST_TYPE );
	unregister_post_type( BMD_Document_Sections::POST_TYPE );
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'bmd_deactivate' );

/*
 * ---------------------------------------------------------------------------
 * Bootstrap
 * ---------------------------------------------------------------------------
 */

/**
 * Instantiates the plugin components.
 *
 * @return void
 */
function bmd_init(): void {
	load_plugin_textdomain( 'board-meeting-documents', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	new BMD_Post_Type();
	new BMD_Meta_Boxes();
	new BMD_Shortcodes();
	new BMD_Document_Sections();

	if ( is_admin() ) {
		new BMD_Admin();
	}
}
add_action( 'plugins_loaded', 'bmd_init' );

/*
 * ---------------------------------------------------------------------------
 * One-time data migration
 * ---------------------------------------------------------------------------
 */

/**
 * Moves posts created by this plugin from the old "board_meeting" slug to
 * "bmd_meeting". Runs once (tracked by the bmd_db_version option).
 *
 * Only posts that carry this plugin's own meta (_bmd_meeting_date or a
 * _bmd_*_documents list) are touched, so a different plugin's "board_meeting"
 * posts are left exactly as they are.
 *
 * @return void
 */
function bmd_maybe_migrate(): void {
	if ( (int) get_option( 'bmd_db_version', 0 ) >= BMD_DB_VERSION ) {
		return;
	}

	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT p.ID
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			 WHERE p.post_type = %s
			   AND m.meta_key IN ( %s, %s, %s )",
			BMD_LEGACY_POST_TYPE,
			BMD_Helpers::META_DATE,
			BMD_Helpers::META_AGENDA,
			BMD_Helpers::META_MINUTES
		)
	);

	if ( ! empty( $ids ) ) {
		$ids          = array_map( 'intval', $ids );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->posts} SET post_type = %s WHERE ID IN ( {$placeholders} )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( BMD_POST_TYPE ), $ids )
			)
		);

		foreach ( $ids as $id ) {
			clean_post_cache( $id );
		}
	}

	update_option( 'bmd_db_version', BMD_DB_VERSION );
}
add_action( 'init', 'bmd_maybe_migrate', 20 );

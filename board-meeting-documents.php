<?php
/**
 * Plugin Name:       Board Meeting Documents
 * Plugin URI:        https://example.com/board-meeting-documents
 * Description:       Manage Board Meetings with Agenda and Minutes PDF documents and display them on the frontend, grouped by year, using shortcodes.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Board Meeting Documents
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
define( 'BMD_VERSION', '1.0.0' );
define( 'BMD_PLUGIN_FILE', __FILE__ );
define( 'BMD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BMD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BMD_POST_TYPE', 'board_meeting' );

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

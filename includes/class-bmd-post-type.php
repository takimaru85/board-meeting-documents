<?php
/**
 * Registers the board_meeting custom post type and its post meta.
 *
 * @package BoardMeetingDocuments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BMD_Post_Type
 */
class BMD_Post_Type {

	/**
	 * Hooks registration into init.
	 */
	public function __construct() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Registers the post type and meta. Static so the activation hook can call
	 * it before flushing rewrite rules.
	 *
	 * The post type is intentionally NOT publicly queryable: meetings have no
	 * single page of their own. All frontend output is produced by shortcodes
	 * and the PDFs stay ordinary Media Library attachments.
	 *
	 * @return void
	 */
	public static function register(): void {
		$labels = array(
			'name'                  => _x( 'Board Meetings', 'post type general name', 'board-meeting-documents' ),
			'singular_name'         => _x( 'Board Meeting', 'post type singular name', 'board-meeting-documents' ),
			'menu_name'             => _x( 'Board Documents', 'admin menu', 'board-meeting-documents' ),
			'name_admin_bar'        => _x( 'Board Meeting', 'add new on admin bar', 'board-meeting-documents' ),
			'add_new'               => __( 'Add New', 'board-meeting-documents' ),
			'add_new_item'          => __( 'Add New Board Meeting', 'board-meeting-documents' ),
			'new_item'              => __( 'New Board Meeting', 'board-meeting-documents' ),
			'edit_item'             => __( 'Edit Board Meeting', 'board-meeting-documents' ),
			'view_item'             => __( 'View Board Meeting', 'board-meeting-documents' ),
			'all_items'             => __( 'Meetings (Agendas & Minutes)', 'board-meeting-documents' ),
			'search_items'          => __( 'Search Board Meetings', 'board-meeting-documents' ),
			'not_found'             => __( 'No board meetings found.', 'board-meeting-documents' ),
			'not_found_in_trash'    => __( 'No board meetings found in Trash.', 'board-meeting-documents' ),
			'item_published'        => __( 'Board meeting published.', 'board-meeting-documents' ),
			'item_updated'          => __( 'Board meeting updated.', 'board-meeting-documents' ),
			'filter_items_list'     => __( 'Filter board meetings list', 'board-meeting-documents' ),
			'items_list_navigation' => __( 'Board meetings list navigation', 'board-meeting-documents' ),
			'items_list'            => __( 'Board meetings list', 'board-meeting-documents' ),
		);

		$args = array(
			'labels'              => $labels,
			'description'         => __( 'Board meetings with agenda and minutes documents.', 'board-meeting-documents' ),
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => true,
			'show_in_rest'        => false, // Classic editor: keeps the meta boxes simple and dependency-free.
			'menu_position'       => 20,
			'menu_icon'           => 'dashicons-media-document',
			'hierarchical'        => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'supports'            => array( 'title' ),
			/*
			 * Uses the standard post capabilities so Administrators and Editors
			 * can manage meetings. Filterable for sites that want it locked to
			 * administrators only, e.g. return 'page' or a custom cap type.
			 */
			'capability_type'     => apply_filters( 'bmd_capability_type', 'post' ),
			'map_meta_cap'        => true,
		);

		register_post_type( BMD_POST_TYPE, $args );

		// Register meta so WordPress knows the expected types. REST exposure is off.
		register_post_meta(
			BMD_POST_TYPE,
			BMD_Helpers::META_DATE,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => array( 'BMD_Helpers', 'sanitize_date' ),
				'auth_callback'     => array( __CLASS__, 'meta_auth' ),
			)
		);

		register_post_meta(
			BMD_POST_TYPE,
			BMD_Helpers::META_STATUS,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => 'normal',
				'show_in_rest'      => false,
				'sanitize_callback' => array( 'BMD_Helpers', 'sanitize_status' ),
				'auth_callback'     => array( __CLASS__, 'meta_auth' ),
			)
		);

		foreach ( array( BMD_Helpers::META_AGENDA, BMD_Helpers::META_MINUTES ) as $key ) {
			register_post_meta(
				BMD_POST_TYPE,
				$key,
				array(
					'type'          => 'array',
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => array( __CLASS__, 'meta_auth' ),
				)
			);
		}
	}

	/**
	 * Meta auth callback: only users who can edit the meeting may edit its meta.
	 *
	 * @param bool   $allowed  Whether the user can edit the meta.
	 * @param string $meta_key Meta key.
	 * @param int    $post_id  Post ID.
	 * @return bool
	 */
	public static function meta_auth( bool $allowed, string $meta_key, int $post_id ): bool {
		return current_user_can( 'edit_post', $post_id );
	}
}

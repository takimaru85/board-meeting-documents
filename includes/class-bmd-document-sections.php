<?php
/**
 * Document Sections: standalone PDF lists such as "Annual Reports",
 * "Audit Reports" or "Budget" that are not tied to a meeting.
 *
 * Each section is a post of type bmd_doc_section. Its title is the section
 * heading and its documents are stored in _bmd_section_documents using the
 * same [ attachment_id, label ] structure as meeting documents. Sections are
 * ordered by the "Order" field (menu_order), then title.
 *
 * Frontend:
 *   [board_documents]                         all sections, each an accordion
 *   [board_documents section="annual-reports"] one section (slug or ID)
 *   [board_documents expand="first"]          all | first | none (default all)
 *
 * @package BoardMeetingDocuments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BMD_Document_Sections
 */
class BMD_Document_Sections {

	const POST_TYPE    = 'bmd_doc_section';
	const META_DOCS    = '_bmd_section_documents';
	const SHORTCODE    = 'board_documents';
	const FIELD_NAME   = 'bmd_section_documents';
	const NONCE_ACTION = 'bmd_save_section';
	const NONCE_NAME   = 'bmd_section_nonce';

	/**
	 * Instance counter for unique accordion IDs.
	 *
	 * @var int
	 */
	private static int $instance = 0;

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'init', array( $this, 'register_shortcode' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save' ), 10, 2 );

		if ( is_admin() ) {
			add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
			add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
			add_action( 'pre_get_posts', array( $this, 'sort_admin_list' ) );
			add_filter( 'enter_title_here', array( $this, 'title_placeholder' ), 10, 2 );
		}
	}

	/**
	 * Registers the post type. Static so activation can call it before the
	 * rewrite flush.
	 *
	 * @return void
	 */
	public static function register(): void {
		$labels = array(
			'name'               => _x( 'Document Sections', 'post type general name', 'board-meeting-documents' ),
			'singular_name'      => _x( 'Document Section', 'post type singular name', 'board-meeting-documents' ),
			'menu_name'          => _x( 'Document Sections', 'admin menu', 'board-meeting-documents' ),
			'add_new'            => __( 'Add New', 'board-meeting-documents' ),
			'add_new_item'       => __( 'Add New Document Section', 'board-meeting-documents' ),
			'new_item'           => __( 'New Document Section', 'board-meeting-documents' ),
			'edit_item'          => __( 'Edit Document Section', 'board-meeting-documents' ),
			'all_items'          => __( 'Document Sections', 'board-meeting-documents' ),
			'search_items'       => __( 'Search Document Sections', 'board-meeting-documents' ),
			'not_found'          => __( 'No document sections found.', 'board-meeting-documents' ),
			'not_found_in_trash' => __( 'No document sections found in Trash.', 'board-meeting-documents' ),
			'item_published'     => __( 'Document section published.', 'board-meeting-documents' ),
			'item_updated'       => __( 'Document section updated.', 'board-meeting-documents' ),
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => $labels,
				'description'         => __( 'Grouped PDF lists such as Annual Reports or Budgets.', 'board-meeting-documents' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => 'edit.php?post_type=' . BMD_POST_TYPE, // Under "Board Meetings".
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'hierarchical'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title', 'page-attributes' ), // page-attributes = "Order" field.
				'capability_type'     => apply_filters( 'bmd_capability_type', 'post' ),
				'map_meta_cap'        => true,
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_DOCS,
			array(
				'type'          => 'array',
				'single'        => true,
				'show_in_rest'  => false,
				'auth_callback' => array( 'BMD_Post_Type', 'meta_auth' ),
			)
		);
	}

	/**
	 * Registers the shortcode.
	 *
	 * @return void
	 */
	public function register_shortcode(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
	}

	/**
	 * Reads a section's documents.
	 *
	 * @param int $post_id Section ID.
	 * @return array<int,array{attachment_id:int,label:string}>
	 */
	public static function get_documents( int $post_id ): array {
		$stored = get_post_meta( $post_id, self::META_DOCS, true );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$docs = array();
		foreach ( $stored as $row ) {
			if ( ! is_array( $row ) || empty( $row['attachment_id'] ) ) {
				continue;
			}
			$docs[] = array(
				'attachment_id' => absint( $row['attachment_id'] ),
				'label'         => isset( $row['label'] ) ? (string) $row['label'] : '',
			);
		}

		return $docs;
	}

	/* ------------------------------------------------------------------ */
	/* Admin                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Registers the Documents meta box.
	 *
	 * @return void
	 */
	public function add_meta_boxes(): void {
		add_meta_box(
			'bmd_section_documents',
			__( 'Documents', 'board-meeting-documents' ),
			array( $this, 'render_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Renders the Documents meta box (re-uses the shared PDF table).
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_meta_box( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION . '_' . $post->ID, self::NONCE_NAME );

		echo '<p class="description">'
			. esc_html__( 'The section title above is the heading shown on the site. Rows are displayed in this order; use the arrows to reorder. A blank label falls back to the file\'s Media Library title.', 'board-meeting-documents' )
			. '</p>';

		BMD_Meta_Boxes::render_documents_table(
			array(
				'type'              => 'section',
				'field_name'        => self::FIELD_NAME,
				'docs'              => self::get_documents( $post->ID ),
				'add_button'        => __( 'Add Document', 'board-meeting-documents' ),
				'empty_message'     => __( 'No documents added yet.', 'board-meeting-documents' ),
				'label_placeholder' => __( 'e.g. 2025 Annual Report', 'board-meeting-documents' ),
				'label_heading'     => __( 'Title shown on the site', 'board-meeting-documents' ),
			)
		);

		$shortcode = $post->post_name ? '[board_documents section="' . $post->post_name . '"]' : '[board_documents]';
		echo '<p class="description">'
			. esc_html__( 'Show all sections with', 'board-meeting-documents' ) . ' <code class="bmd-shortcode">[board_documents]</code> '
			. esc_html__( 'or only this one with', 'board-meeting-documents' ) . ' <code class="bmd-shortcode">' . esc_html( $shortcode ) . '</code>'
			. '</p>';
	}

	/**
	 * Saves the documents list.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save( int $post_id, WP_Post $post ): void {
		if ( empty( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION . '_' . $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized row by row in sanitize_documents().
		$raw  = ( isset( $_POST[ self::FIELD_NAME ] ) && is_array( $_POST[ self::FIELD_NAME ] ) ) ? wp_unslash( $_POST[ self::FIELD_NAME ] ) : array();
		$docs = BMD_Helpers::sanitize_documents( $raw );

		if ( ! empty( $docs ) ) {
			update_post_meta( $post_id, self::META_DOCS, $docs );
		} else {
			delete_post_meta( $post_id, self::META_DOCS );
		}
	}

	/**
	 * Adds a "Documents" count and "Order" column to the list table.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function columns( array $columns ): array {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['bmd_docs']  = __( 'Documents', 'board-meeting-documents' );
				$new['bmd_order'] = __( 'Order', 'board-meeting-documents' );
			}
		}
		return $new;
	}

	/**
	 * Outputs the custom columns.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function column_content( string $column, int $post_id ): void {
		if ( 'bmd_docs' === $column ) {
			$count = count( self::get_documents( $post_id ) );
			/* translators: %s: number of PDF files */
			echo $count ? esc_html( sprintf( _n( '%s file', '%s files', $count, 'board-meeting-documents' ), number_format_i18n( $count ) ) ) : '&mdash;';
		} elseif ( 'bmd_order' === $column ) {
			echo esc_html( (string) get_post_field( 'menu_order', $post_id ) );
		}
	}

	/**
	 * Sorts the admin list the same way the frontend does (Order, then Title).
	 *
	 * @param WP_Query $query Main admin query.
	 * @return void
	 */
	public function sort_admin_list( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || self::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		if ( '' === (string) $query->get( 'orderby' ) ) {
			$query->set(
				'orderby',
				array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				)
			);
		}
	}

	/**
	 * Title placeholder for sections.
	 *
	 * @param string  $placeholder Placeholder.
	 * @param WP_Post $post        Post.
	 * @return string
	 */
	public function title_placeholder( string $placeholder, WP_Post $post ): string {
		if ( self::POST_TYPE === $post->post_type ) {
			return __( 'Section title, e.g. Annual Reports', 'board-meeting-documents' );
		}
		return $placeholder;
	}

	/* ------------------------------------------------------------------ */
	/* Frontend                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Shortcode callback.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'section' => '',
				'expand'  => 'all', // all | first | none
				'title'   => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);

		$expand = in_array( $atts['expand'], array( 'all', 'first', 'none' ), true ) ? $atts['expand'] : 'all';
		$title  = sanitize_text_field( (string) $atts['title'] );

		// Frontend assets (registered by BMD_Shortcodes).
		wp_enqueue_style( 'bmd-frontend' );
		wp_enqueue_script( 'bmd-frontend' );

		$sections = $this->get_sections( (string) $atts['section'] );

		self::$instance++;
		$wrapper_id = 'bmd-docs-' . self::$instance;

		$html  = '<div class="bmd-wrapper bmd-docs" id="' . esc_attr( $wrapper_id ) . '">';
		$html .= '<noscript><style>#' . esc_attr( $wrapper_id ) . ' .bmd-section-content[hidden]{display:block}</style></noscript>';

		if ( '' !== $title ) {
			$html .= '<h2 class="bmd-heading">' . esc_html( $title ) . '</h2>';
		}

		if ( empty( $sections ) ) {
			$html .= '<p class="bmd-empty">' . esc_html__( 'No documents are available at this time.', 'board-meeting-documents' ) . '</p>';
			return $html . '</div>';
		}

		/** This filter is documented in class-bmd-shortcodes.php */
		$heading_tag = apply_filters( 'bmd_year_heading_tag', 'h3' );
		$heading_tag = in_array( $heading_tag, array( 'h2', 'h3', 'h4', 'h5', 'h6', 'div' ), true ) ? $heading_tag : 'h3';

		$first = true;
		foreach ( $sections as $section ) {
			$is_open = ( 'all' === $expand ) || ( 'first' === $expand && $first );
			$first   = false;

			$panel_id  = $wrapper_id . '-' . $section['id'];
			$button_id = $panel_id . '-button';

			$html .= '<section class="bmd-section' . ( $is_open ? ' bmd-open' : '' ) . '" data-bmd-section="' . esc_attr( $section['slug'] ) . '">';
			$html .= '<' . $heading_tag . ' class="bmd-section-heading">';
			$html .= '<button type="button" class="bmd-section-header" id="' . esc_attr( $button_id ) . '"'
				. ' aria-expanded="' . ( $is_open ? 'true' : 'false' ) . '"'
				. ' aria-controls="' . esc_attr( $panel_id ) . '">';
			$html .= '<span class="bmd-section-title">' . esc_html( $section['title'] ) . '</span>';
			$html .= '<span class="bmd-chevron" aria-hidden="true">' . self::icon_chevron() . '</span>';
			$html .= '</button></' . $heading_tag . '>';

			$html .= '<div class="bmd-section-content" id="' . esc_attr( $panel_id ) . '" role="region"'
				. ' aria-labelledby="' . esc_attr( $button_id ) . '"' . ( $is_open ? '' : ' hidden' ) . '>';
			$html .= '<ul class="bmd-doc-list">';

			foreach ( $section['docs'] as $doc ) {
				$html .= '<li class="bmd-doc-item">'
					. '<a class="bmd-doc-card" href="' . esc_url( $doc['url'] ) . '" target="_blank" rel="noopener">'
					. '<span class="bmd-doc-icon" aria-hidden="true">' . self::icon_pdf() . '</span>'
					. '<span class="bmd-card-body">'
					. '<span class="bmd-doc-title">' . esc_html( $doc['label'] ) . '</span>'
					. '<span class="bmd-doc-subtitle">' . esc_html( $doc['meta'] ) . '</span>'
					. '<span class="bmd-sr-only"> ' . esc_html__( '(opens in a new tab)', 'board-meeting-documents' ) . '</span>'
					. '</span>'
					. '<span class="bmd-doc-ext" aria-hidden="true">' . self::icon_external() . '</span>'
					. '</a></li>';
			}

			$html .= '</ul></div></section>';
		}

		return $html . '</div>';
	}

	/**
	 * Loads published sections (optionally one) with resolved document URLs.
	 * Constant query count: 1 sections query + 1 meta prime + 2 attachment primes.
	 *
	 * @param string $only Slug or ID of a single section, or ''.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_sections( string $only ): array {
		$args = array(
			'post_type'              => self::POST_TYPE,
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
			'orderby'                => array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			),
		);

		$only = trim( $only );
		if ( '' !== $only ) {
			if ( ctype_digit( $only ) ) {
				$args['p'] = (int) $only;
			} else {
				$args['name'] = sanitize_title( $only );
			}
		}

		/**
		 * Filters the WP_Query arguments used by [board_documents].
		 *
		 * @param array $args WP_Query args.
		 */
		$args = apply_filters( 'bmd_sections_query_args', $args );

		$query = new WP_Query( $args );
		if ( empty( $query->posts ) ) {
			return array();
		}

		$sections       = array();
		$attachment_ids = array();

		foreach ( $query->posts as $post ) {
			$docs = self::get_documents( $post->ID );
			if ( empty( $docs ) ) {
				continue;
			}
			foreach ( $docs as $doc ) {
				$attachment_ids[] = $doc['attachment_id'];
			}
			$sections[] = array(
				'id'    => $post->ID,
				'slug'  => $post->post_name,
				'title' => get_the_title( $post ),
				'docs'  => $docs,
			);
		}

		if ( ! empty( $attachment_ids ) ) {
			_prime_post_caches( array_unique( $attachment_ids ), false, true );
		}

		foreach ( $sections as $key => $section ) {
			$resolved = array();
			foreach ( $section['docs'] as $doc ) {
				$url = wp_get_attachment_url( $doc['attachment_id'] );
				if ( ! $url ) {
					continue;
				}
				$label = trim( $doc['label'] );
				if ( '' === $label ) {
					$label = get_the_title( $doc['attachment_id'] );
				}
				if ( '' === $label ) {
					$label = wp_basename( $url );
				}

				// Subtitle: "PDF · 1.2 MB". File size comes from attachment metadata
				// (stored by WordPress on upload), so no filesystem access is needed.
				$meta_parts = array( __( 'PDF', 'board-meeting-documents' ) );
				$attachment_meta = wp_get_attachment_metadata( $doc['attachment_id'] );
				if ( is_array( $attachment_meta ) && ! empty( $attachment_meta['filesize'] ) ) {
					$meta_parts[] = size_format( (int) $attachment_meta['filesize'], 1 );
				}

				$resolved[] = array(
					'url'   => $url,
					'label' => $label,
					'meta'  => implode( ' · ', $meta_parts ),
				);
			}

			if ( empty( $resolved ) ) {
				unset( $sections[ $key ] );
				continue;
			}
			$sections[ $key ]['docs'] = $resolved;
		}

		return array_values( $sections );
	}

	/**
	 * Inline SVG: PDF/document icon.
	 *
	 * @return string
	 */
	private static function icon_pdf(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 384 512" width="1em" height="1em" fill="currentColor" focusable="false"><path d="M64 0C28.7 0 0 28.7 0 64v384c0 35.3 28.7 64 64 64h256c35.3 0 64-28.7 64-64V160H256c-17.7 0-32-14.3-32-32V0H64zm192 0v128h128L256 0zM64 224h32c35.3 0 64 28.7 64 64s-28.7 64-64 64H80v32c0 8.8-7.2 16-16 16s-16-7.2-16-16V240c0-8.8 7.2-16 16-16zm32 96c17.7 0 32-14.3 32-32s-14.3-32-32-32H80v64h16zm96-96h32c26.5 0 48 21.5 48 48v64c0 26.5-21.5 48-48 48h-32c-8.8 0-16-7.2-16-16V240c0-8.8 7.2-16 16-16zm32 128c8.8 0 16-7.2 16-16v-64c0-8.8-7.2-16-16-16h-16v96h16zm80-128h48c8.8 0 16 7.2 16 16s-7.2 16-16 16h-32v32h32c8.8 0 16 7.2 16 16s-7.2 16-16 16h-32v48c0 8.8-7.2 16-16 16s-16-7.2-16-16V240c0-8.8 7.2-16 16-16z"/></svg>';
	}

	/**
	 * Inline SVG: external link icon.
	 *
	 * @return string
	 */
	private static function icon_external(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="1em" height="1em" fill="currentColor" focusable="false"><path d="M320 0c-17.7 0-32 14.3-32 32s14.3 32 32 32h82.7L201.4 265.4c-12.5 12.5-12.5 32.8 0 45.3s32.8 12.5 45.3 0L448 109.3V192c0 17.7 14.3 32 32 32s32-14.3 32-32V32c0-17.7-14.3-32-32-32H320zM80 32C35.8 32 0 67.8 0 112v320c0 44.2 35.8 80 80 80h320c44.2 0 80-35.8 80-80V320c0-17.7-14.3-32-32-32s-32 14.3-32 32v112c0 8.8-7.2 16-16 16H80c-8.8 0-16-7.2-16-16V112c0-8.8 7.2-16 16-16h112c17.7 0 32-14.3 32-32s-14.3-32-32-32H80z"/></svg>';
	}

	/**
	 * Inline SVG: chevron (rotated by CSS when open).
	 *
	 * @return string
	 */
	private static function icon_chevron(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" focusable="false"><polyline points="6 9 12 15 18 9"/></svg>';
	}
}

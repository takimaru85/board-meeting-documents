<?php
/**
 * The [bmd_meetings] shortcode: query, grouping by year, and accessible
 * accordion markup.
 *
 * Usage:
 *   [bmd_meetings type="agenda"]                 (alias: [board_meetings])
 *   [bmd_meetings type="minutes" year="2026" order="asc"]
 *
 * @package BoardMeetingDocuments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BMD_Shortcodes
 */
class BMD_Shortcodes {

	/**
	 * Primary shortcode tag (unique prefix, cannot clash with other plugins)
	 * and the legacy alias kept for pages built with earlier versions.
	 */
	const SHORTCODE       = 'bmd_meetings';
	const SHORTCODE_ALIAS = 'board_meetings';

	/**
	 * Counts rendered instances so every accordion on a page gets unique IDs
	 * (needed for aria-controls / aria-labelledby).
	 *
	 * @var int
	 */
	private static int $instance = 0;

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Registers the shortcode.
	 *
	 * @return void
	 */
	public function register_shortcode(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );

		// Legacy alias: only if nothing else has claimed the generic tag.
		if ( ! shortcode_exists( self::SHORTCODE_ALIAS ) ) {
			add_shortcode( self::SHORTCODE_ALIAS, array( $this, 'render' ) );
		}
	}

	/**
	 * Whether a block of content uses any of this plugin's shortcodes.
	 *
	 * @param string $content Post content.
	 * @return bool
	 */
	public static function content_has_shortcodes( string $content ): bool {
		foreach ( array( self::SHORTCODE, self::SHORTCODE_ALIAS, BMD_Document_Sections::SHORTCODE, BMD_Document_Sections::SHORTCODE_ALIAS ) as $tag ) {
			if ( has_shortcode( $content, $tag ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Registers (does not enqueue) the frontend assets. They are enqueued only
	 * when the shortcode actually renders, so no other page pays for them.
	 *
	 * As a bonus, when the current singular post contains the shortcode we
	 * enqueue early so the stylesheet lands in <head> and avoids a flash of
	 * unstyled content.
	 *
	 * @return void
	 */
	public function register_assets(): void {
		wp_register_style(
			'bmd-frontend',
			BMD_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			BMD_VERSION
		);

		wp_register_script(
			'bmd-frontend',
			BMD_PLUGIN_URL . 'assets/js/frontend.js',
			array(), // No jQuery.
			BMD_VERSION,
			true
		);

		if ( is_singular() ) {
			$post = get_post();
			if ( $post instanceof WP_Post && self::content_has_shortcodes( (string) $post->post_content ) ) {
				$this->enqueue_assets();
			}
		}
	}

	/**
	 * Enqueues the frontend assets (safe to call multiple times).
	 *
	 * @return void
	 */
	private function enqueue_assets(): void {
		wp_enqueue_style( 'bmd-frontend' );
		wp_enqueue_script( 'bmd-frontend' );
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'type'   => 'agenda',
				'year'   => '',
				'order'  => 'desc',
				'title'  => '',
				'expand' => 'latest', // latest | all | none
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);

		$types = BMD_Helpers::get_document_types();
		$type  = sanitize_key( (string) $atts['type'] );

		if ( ! isset( $types[ $type ] ) ) {
			// Only editors see the mistake; visitors see nothing.
			if ( current_user_can( 'edit_posts' ) ) {
				return '<p class="bmd-error">' . esc_html__( '[bmd_meetings] error: type must be "agenda" or "minutes".', 'board-meeting-documents' ) . '</p>';
			}
			return '';
		}

		$config = $types[ $type ];
		$order  = 'ASC' === strtoupper( (string) $atts['order'] ) ? 'ASC' : 'DESC';
		$year   = absint( $atts['year'] );
		$year   = ( $year >= 1900 && $year <= 2200 ) ? $year : 0;
		$expand = in_array( $atts['expand'], array( 'latest', 'all', 'none' ), true ) ? $atts['expand'] : 'latest';
		$title  = sanitize_text_field( (string) $atts['title'] );

		$this->enqueue_assets();

		$meetings = $this->get_meetings( $type, $year, $order );
		$groups   = $this->group_by_year( $meetings );

		return $this->render_html( $type, $config, $groups, $expand, $title );
	}

	/**
	 * Retrieves published meetings that have at least one document of $type,
	 * sorted by meeting date, with all needed meta and attachment data primed
	 * in as few queries as possible.
	 *
	 * Query count is constant regardless of the number of meetings:
	 *   1 posts query + 1 post meta prime + 1 attachments prime + 1 attachment meta prime.
	 *
	 * @param string $type  Document type key.
	 * @param int    $year  Year filter (0 = all years).
	 * @param string $order ASC|DESC.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_meetings( string $type, int $year, string $order ): array {
		$config = BMD_Helpers::get_document_types()[ $type ];

		$meta_query = array(
			'relation'    => 'AND',
			// Named clause so we can order by it.
			'date_clause' => array(
				'key'     => BMD_Helpers::META_DATE,
				'compare' => 'EXISTS',
				'type'    => 'DATE',
			),
			array(
				// Empty document lists are deleted on save, so EXISTS == "has at least one".
				'key'     => $config['meta_key'],
				'compare' => 'EXISTS',
			),
		);

		if ( $year > 0 ) {
			$meta_query[] = array(
				'key'     => BMD_Helpers::META_DATE,
				'value'   => array( sprintf( '%04d-01-01', $year ), sprintf( '%04d-12-31', $year ) ),
				'compare' => 'BETWEEN',
				'type'    => 'DATE',
			);
		}

		$args = array(
			'post_type'              => BMD_POST_TYPE,
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
			'meta_query'             => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'orderby'                => array(
				'date_clause' => $order,
				'ID'          => $order,
			),
		);

		/**
		 * Filters the WP_Query arguments used by the shortcode.
		 *
		 * @param array  $args WP_Query args.
		 * @param string $type Document type.
		 */
		$args = apply_filters( 'bmd_query_args', $args, $type );

		$query = new WP_Query( $args );
		$ids   = array_map( 'intval', (array) $query->posts );

		if ( empty( $ids ) ) {
			return array();
		}

		// One query for all meeting meta.
		update_meta_cache( 'post', $ids );

		$meetings       = array();
		$attachment_ids = array();

		foreach ( $ids as $post_id ) {
			$date = BMD_Helpers::get_meeting_date( $post_id );
			if ( '' === $date ) {
				continue;
			}

			$docs = BMD_Helpers::get_documents( $post_id, $type );
			if ( empty( $docs ) ) {
				continue;
			}

			foreach ( $docs as $doc ) {
				$attachment_ids[] = $doc['attachment_id'];
			}

			$meetings[] = array(
				'id'     => $post_id,
				'date'   => $date,
				'status' => BMD_Helpers::get_meeting_status( $post_id ),
				'docs'   => $docs,
			);
		}

		// One query for all attachment posts + one for their meta (_wp_attached_file).
		if ( ! empty( $attachment_ids ) ) {
			_prime_post_caches( array_unique( $attachment_ids ), false, true );
		}

		// Resolve URLs and drop documents whose attachment no longer exists.
		foreach ( $meetings as $key => $meeting ) {
			$resolved = array();

			foreach ( $meeting['docs'] as $doc ) {
				$url = wp_get_attachment_url( $doc['attachment_id'] );
				if ( ! $url ) {
					continue;
				}

				$label = trim( $doc['label'] );

				$resolved[] = array(
					'url'   => $url,
					'label' => '' !== $label ? $label : $config['default_label'],
				);
			}

			if ( empty( $resolved ) ) {
				unset( $meetings[ $key ] );
				continue;
			}

			$meetings[ $key ]['docs']  = $resolved;
			$meetings[ $key ]['title'] = BMD_Helpers::get_meeting_title( $type, $meeting['date'], $meeting['status'] );
		}

		return array_values( $meetings );
	}

	/**
	 * Groups an already-sorted meeting list by year (insertion order kept).
	 *
	 * @param array $meetings Meetings from get_meetings().
	 * @return array<int,array> year => meetings
	 */
	private function group_by_year( array $meetings ): array {
		$groups = array();

		foreach ( $meetings as $meeting ) {
			$year = (int) substr( $meeting['date'], 0, 4 );
			$groups[ $year ][] = $meeting;
		}

		return $groups;
	}

	/**
	 * Builds the accordion HTML.
	 *
	 * @param string $type   Document type key.
	 * @param array  $config Document type config.
	 * @param array  $groups year => meetings.
	 * @param string $expand latest|all|none.
	 * @param string $title  Optional heading.
	 * @return string
	 */
	private function render_html( string $type, array $config, array $groups, string $expand, string $title ): string {
		self::$instance++;
		$wrapper_id = 'bmd-' . self::$instance;

		/**
		 * Filters the heading tag wrapping each year button (accordion pattern).
		 *
		 * @param string $tag Default 'h3'.
		 */
		$heading_tag = apply_filters( 'bmd_year_heading_tag', 'h3' );
		$heading_tag = in_array( $heading_tag, array( 'h2', 'h3', 'h4', 'h5', 'h6', 'div' ), true ) ? $heading_tag : 'h3';

		$html = '<div class="bmd-wrapper bmd-type-' . esc_attr( $type ) . '" id="' . esc_attr( $wrapper_id ) . '" data-bmd-type="' . esc_attr( $type ) . '">';

		// Without JavaScript every year must stay readable, so undo the "hidden" state.
		$html .= '<noscript><style>#' . esc_attr( $wrapper_id ) . ' .bmd-year-content[hidden]{display:block}</style></noscript>';

		if ( '' !== $title ) {
			$html .= '<h2 class="bmd-heading">' . esc_html( $title ) . '</h2>';
		}

		if ( empty( $groups ) ) {
			$html .= '<p class="bmd-empty">' . esc_html( $config['empty_front'] ) . '</p>';
			$html .= '</div>';
			return $html;
		}

		$latest_year = max( array_keys( $groups ) );

		foreach ( $groups as $year => $meetings ) {
			$is_open = ( 'all' === $expand ) || ( 'latest' === $expand && $year === $latest_year );

			$panel_id  = $wrapper_id . '-year-' . $year;
			$button_id = $panel_id . '-button';

			$html .= '<div class="bmd-year' . ( $is_open ? ' bmd-year--open' : '' ) . '" data-bmd-year="' . esc_attr( (string) $year ) . '">';

			$html .= '<' . $heading_tag . ' class="bmd-year-heading">';
			$html .= '<button type="button" class="bmd-year-header" id="' . esc_attr( $button_id ) . '"'
				. ' aria-expanded="' . ( $is_open ? 'true' : 'false' ) . '"'
				. ' aria-controls="' . esc_attr( $panel_id ) . '">';
			$html .= '<span class="bmd-year-label">' . esc_html( (string) $year ) . '</span>';
			$html .= '<span class="bmd-toggle" aria-hidden="true">' . ( $is_open ? '&minus;' : '+' ) . '</span>';
			$html .= '</button>';
			$html .= '</' . $heading_tag . '>';

			$html .= '<div class="bmd-year-content" id="' . esc_attr( $panel_id ) . '" role="region"'
				. ' aria-labelledby="' . esc_attr( $button_id ) . '"'
				. ( $is_open ? '' : ' hidden' ) . '>';

			$html .= '<ul class="bmd-meetings">';

			foreach ( $meetings as $meeting ) {
				$html .= $this->render_meeting( $meeting );
			}

			$html .= '</ul>';
			$html .= '</div>'; // .bmd-year-content
			$html .= '</div>'; // .bmd-year
		}

		$html .= '</div>'; // .bmd-wrapper

		return $html;
	}

	/**
	 * Renders one meeting row.
	 *
	 * - One document: the meeting title itself is the PDF link.
	 * - Several documents: the title is plain text and each document is a link
	 *   underneath it, so it is always obvious which PDF opens.
	 *
	 * @param array $meeting Meeting data.
	 * @return string
	 */
	private function render_meeting( array $meeting ): string {
		$is_upcoming = $meeting['date'] >= current_time( 'Y-m-d' );

		$classes = 'bmd-meeting bmd-status-' . sanitize_html_class( $meeting['status'] )
			. ( $is_upcoming ? ' bmd-upcoming' : ' bmd-past' );

		$sr_hint = '<span class="bmd-sr-only"> ' . esc_html__( '(PDF, opens in a new tab)', 'board-meeting-documents' ) . '</span>';

		// Date badge: "23" over "Sep". Hidden from screen readers because the
		// title already contains the full date.
		list( $day, $month ) = BMD_Helpers::format_date_parts( $meeting['date'] );
		$badge = '<span class="bmd-date" aria-hidden="true">'
			. '<span class="bmd-date-day">' . esc_html( $day ) . '</span>'
			. '<span class="bmd-date-month">' . esc_html( $month ) . '</span>'
			. '</span>';

		$title = '<span class="bmd-meeting-title">' . esc_html( $meeting['title'] ) . '</span>';

		$html = '<li class="' . esc_attr( $classes ) . '">';

		if ( 1 === count( $meeting['docs'] ) ) {
			// Whole card is the link.
			$doc   = $meeting['docs'][0];
			$html .= '<a class="bmd-card bmd-meeting-link" href="' . esc_url( $doc['url'] ) . '" target="_blank" rel="noopener">'
				. $badge
				. '<span class="bmd-card-body">'
				. $title
				. '<span class="bmd-meeting-subtitle">' . esc_html( $doc['label'] ) . $sr_hint . '</span>'
				. '</span>'
				. '</a>';
		} else {
			// Card is static; each document is its own pill link.
			$html .= '<div class="bmd-card bmd-card--multi">'
				. $badge
				. '<div class="bmd-card-body">'
				. $title
				. '<ul class="bmd-documents">';

			foreach ( $meeting['docs'] as $doc ) {
				$html .= '<li class="bmd-document">'
					. '<a class="bmd-document-link" href="' . esc_url( $doc['url'] ) . '" target="_blank" rel="noopener">'
					. esc_html( $doc['label'] ) . $sr_hint . '</a>'
					. '</li>';
			}

			$html .= '</ul></div></div>';
		}

		$html .= '</li>';

		return $html;
	}
}

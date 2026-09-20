<?php
/**
 * Admin-only behaviour: asset loading, list table columns, sorting, notices.
 *
 * @package BoardMeetingDocuments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BMD_Admin
 */
class BMD_Admin {

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_filter( 'manage_' . BMD_POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . BMD_POST_TYPE . '_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
		add_filter( 'manage_edit-' . BMD_POST_TYPE . '_sortable_columns', array( $this, 'sortable_columns' ) );
		add_action( 'pre_get_posts', array( $this, 'sort_admin_list' ) );

		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_filter( 'removable_query_args', array( $this, 'removable_query_args' ) );
		add_filter( 'enter_title_here', array( $this, 'title_placeholder' ), 10, 2 );

		// Shortcode instructions: submenu page + side box on the edit screen.
		add_action( 'admin_menu', array( $this, 'add_instructions_page' ) );
		add_action( 'add_meta_boxes_' . BMD_POST_TYPE, array( $this, 'add_shortcodes_box' ) );

		// "Duplicate" row action in the list table.
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_action( 'admin_action_bmd_duplicate', array( $this, 'handle_duplicate' ) );
	}

	/**
	 * Adds a "Duplicate" link to each Board Meeting row.
	 *
	 * @param array   $actions Row action links.
	 * @param WP_Post $post    Current post.
	 * @return array
	 */
	public function row_actions( array $actions, WP_Post $post ): array {
		if ( BMD_POST_TYPE !== $post->post_type ) {
			return $actions;
		}

		if ( ! $this->can_duplicate( $post ) ) {
			return $actions;
		}

		$url = wp_nonce_url(
			admin_url( 'admin.php?action=bmd_duplicate&post=' . $post->ID ),
			'bmd_duplicate_' . $post->ID
		);

		$actions['bmd_duplicate'] = sprintf(
			'<a href="%s" aria-label="%s">%s</a>',
			esc_url( $url ),
			/* translators: %s: post title */
			esc_attr( sprintf( __( 'Duplicate "%s"', 'board-meeting-documents' ), get_the_title( $post ) ) ),
			esc_html__( 'Duplicate', 'board-meeting-documents' )
		);

		return $actions;
	}

	/**
	 * Whether the current user may duplicate a meeting: needs to read the
	 * source and create new meetings.
	 *
	 * @param WP_Post $post Source post.
	 * @return bool
	 */
	private function can_duplicate( WP_Post $post ): bool {
		$pto = get_post_type_object( BMD_POST_TYPE );
		if ( ! $pto ) {
			return false;
		}

		return current_user_can( 'edit_post', $post->ID ) && current_user_can( $pto->cap->create_posts );
	}

	/**
	 * Handles admin.php?action=bmd_duplicate&post=ID.
	 *
	 * Creates a new draft with the same title, date, status and document lists.
	 * PDFs are referenced by attachment ID, so nothing is copied in the Media
	 * Library. The editor is then sent to the new draft to adjust date/files.
	 *
	 * @return void
	 */
	public function handle_duplicate(): void {
		$source_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;

		if ( ! $source_id ) {
			wp_die( esc_html__( 'No meeting selected to duplicate.', 'board-meeting-documents' ) );
		}

		check_admin_referer( 'bmd_duplicate_' . $source_id );

		$source = get_post( $source_id );
		if ( ! $source || BMD_POST_TYPE !== $source->post_type ) {
			wp_die( esc_html__( 'The selected item is not a Board Meeting.', 'board-meeting-documents' ) );
		}

		if ( ! $this->can_duplicate( $source ) ) {
			wp_die( esc_html__( 'You are not allowed to duplicate this meeting.', 'board-meeting-documents' ) );
		}

		/* translators: %s: original title */
		$title = '' !== $source->post_title ? sprintf( __( '%s (Copy)', 'board-meeting-documents' ), $source->post_title ) : '';

		$new_id = wp_insert_post(
			array(
				'post_type'   => BMD_POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => wp_slash( $title ),
				'post_author' => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $new_id ) ) {
			wp_die( esc_html( $new_id->get_error_message() ) );
		}

		// Copy the meeting meta (already sanitized when it was saved; re-run for safety).
		$date = BMD_Helpers::get_meeting_date( $source_id );
		if ( '' !== $date ) {
			update_post_meta( $new_id, BMD_Helpers::META_DATE, $date );
		}

		update_post_meta( $new_id, BMD_Helpers::META_STATUS, BMD_Helpers::get_meeting_status( $source_id ) );

		foreach ( BMD_Helpers::get_document_types() as $type => $config ) {
			$docs = BMD_Helpers::sanitize_documents( BMD_Helpers::get_documents( $source_id, $type ) );
			if ( ! empty( $docs ) ) {
				update_post_meta( $new_id, $config['meta_key'], $docs );
			}
		}

		// Keep the auto-title behaviour so the "(Copy)" title is replaced once the date changes.
		if ( get_post_meta( $source_id, BMD_Helpers::META_AUTO_TITLE, true ) ) {
			update_post_meta( $new_id, BMD_Helpers::META_AUTO_TITLE, 1 );
		}

		/**
		 * Fires after a meeting has been duplicated.
		 *
		 * @param int $new_id    New draft ID.
		 * @param int $source_id Source meeting ID.
		 */
		do_action( 'bmd_meeting_duplicated', $new_id, $source_id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'post'       => $new_id,
					'action'     => 'edit',
					'bmd_notice' => 'duplicated',
				),
				admin_url( 'post.php' )
			)
		);
		exit;
	}

	/**
	 * Returns the shortcode reference used by the instructions page and side box.
	 *
	 * @return array<int,array{code:string,desc:string}>
	 */
	public static function get_shortcode_reference(): array {
		return array(
			array(
				'code' => '[bmd_meetings type="agenda"]',
				'desc' => __( 'Lists every published meeting that has at least one Agenda PDF. Put this on your Agendas page.', 'board-meeting-documents' ),
			),
			array(
				'code' => '[bmd_meetings type="minutes"]',
				'desc' => __( 'Lists every published meeting that has at least one Minutes PDF. Put this on your Minutes page.', 'board-meeting-documents' ),
			),
			array(
				'code' => '[bmd_meetings type="agenda" year="2026"]',
				'desc' => __( 'Only meetings from one year.', 'board-meeting-documents' ),
			),
			array(
				'code' => '[bmd_meetings type="minutes" order="asc"]',
				'desc' => __( 'Oldest first. Default is "desc" (newest first).', 'board-meeting-documents' ),
			),
			array(
				'code' => '[bmd_meetings type="agenda" title="Agendas"]',
				'desc' => __( 'Prints a heading above the list.', 'board-meeting-documents' ),
			),
			array(
				'code' => '[bmd_meetings type="agenda" expand="all"]',
				'desc' => __( 'Which years start open: "latest" (default), "all" or "none".', 'board-meeting-documents' ),
			),
			array(
				'code' => '[bmd_documents]',
				'desc' => __( 'All Document Sections (e.g. Annual Reports, Audit Reports, Budget), each as a collapsible list of PDFs.', 'board-meeting-documents' ),
			),
			array(
				'code' => '[bmd_documents section="annual-reports"]',
				'desc' => __( 'Only one Document Section, by its slug (or ID).', 'board-meeting-documents' ),
			),
			array(
				'code' => '[bmd_documents expand="first"]',
				'desc' => __( 'Which sections start open: "all" (default), "first" or "none".', 'board-meeting-documents' ),
			),
		);
	}

	/**
	 * Adds "Instructions" under the Board Documents menu.
	 *
	 * @return void
	 */
	public function add_instructions_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . BMD_POST_TYPE,
			__( 'Board Meeting Documents – Instructions', 'board-meeting-documents' ),
			__( 'Instructions', 'board-meeting-documents' ),
			'edit_posts',
			'bmd-instructions',
			array( $this, 'render_instructions_page' )
		);
	}

	/**
	 * Renders the Instructions page.
	 *
	 * @return void
	 */
	public function render_instructions_page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'board-meeting-documents' ) );
		}
		?>
		<div class="wrap bmd-instructions">
			<h1><?php esc_html_e( 'Board Meeting Documents – Instructions', 'board-meeting-documents' ); ?></h1>

			<h2><?php esc_html_e( '1. Shortcodes', 'board-meeting-documents' ); ?></h2>
			<p><?php esc_html_e( 'Create two pages (for example "Agendas" and "Minutes") and paste one shortcode into each. The pages update automatically whenever a Board Meeting is published, edited or trashed.', 'board-meeting-documents' ); ?></p>

			<table class="widefat striped bmd-shortcode-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Shortcode', 'board-meeting-documents' ); ?></th>
						<th scope="col"><?php esc_html_e( 'What it does', 'board-meeting-documents' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( self::get_shortcode_reference() as $item ) : ?>
						<tr>
							<td><code class="bmd-shortcode"><?php echo esc_html( $item['code'] ); ?></code></td>
							<td><?php echo esc_html( $item['desc'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h3><?php esc_html_e( 'Attributes', 'board-meeting-documents' ); ?></h3>
			<ul class="ul-disc">
				<li><code>type</code> – <?php esc_html_e( '"agenda" or "minutes". Required in practice (defaults to agenda).', 'board-meeting-documents' ); ?></li>
				<li><code>year</code> – <?php esc_html_e( 'Four-digit year to limit the list, e.g. 2026.', 'board-meeting-documents' ); ?></li>
				<li><code>order</code> – <?php esc_html_e( '"desc" (default, newest first) or "asc".', 'board-meeting-documents' ); ?></li>
				<li><code>title</code> – <?php esc_html_e( 'Optional heading shown above the accordion.', 'board-meeting-documents' ); ?></li>
				<li><code>expand</code> – <?php esc_html_e( '"latest" (default), "all" or "none".', 'board-meeting-documents' ); ?></li>
			</ul>

			<h2><?php esc_html_e( '2. Adding a meeting', 'board-meeting-documents' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'Go to Board Documents → Meetings (Agendas & Minutes) → Add New.', 'board-meeting-documents' ); ?></li>
				<li><?php esc_html_e( 'Enter the Meeting Date (required) and choose the Meeting Status.', 'board-meeting-documents' ); ?></li>
				<li><?php esc_html_e( 'Under Agenda Documents click "Select PDF" to upload or choose a PDF from the Media Library. Add a label if you need one, e.g. "Agenda (Amended)". Use "Add Agenda Document" for more files.', 'board-meeting-documents' ); ?></li>
				<li><?php esc_html_e( 'Do the same under Minutes Documents (this can be done later, after the meeting).', 'board-meeting-documents' ); ?></li>
				<li><?php esc_html_e( 'Click Publish. The title is optional – it is generated from the date if left blank.', 'board-meeting-documents' ); ?></li>
			</ol>

			<h2><?php esc_html_e( '3. How it appears on the site', 'board-meeting-documents' ); ?></h2>
			<ul class="ul-disc">
				<li><?php esc_html_e( 'Meetings are grouped by year. The latest year is open; older years are collapsed and open on click.', 'board-meeting-documents' ); ?></li>
				<li><?php esc_html_e( 'A meeting with one PDF is a single link, e.g. "Agenda - September 23, 2026". A meeting with several PDFs shows the title with one link per document underneath.', 'board-meeting-documents' ); ?></li>
				<li><?php esc_html_e( 'Status is appended to the title: "- Meeting Cancelled", "- Special Meeting", "- Joint Meeting". Normal meetings have no suffix.', 'board-meeting-documents' ); ?></li>
				<li><?php esc_html_e( 'A meeting only appears on the Agendas page if it has at least one Agenda PDF, and on the Minutes page if it has at least one Minutes PDF. Cancelled meetings are still shown.', 'board-meeting-documents' ); ?></li>
				<li><?php esc_html_e( 'Blank labels display as "Agenda" or "Minutes".', 'board-meeting-documents' ); ?></li>
			</ul>

			<h2><?php esc_html_e( '4. Document Sections (Annual Reports, Audit Reports, Budget…)', 'board-meeting-documents' ); ?></h2>
			<p><?php esc_html_e( 'For PDF lists that are not tied to a meeting, use Document Sections. Each section is a heading with its own collapsible list of files.', 'board-meeting-documents' ); ?></p>
			<ol>
				<li><?php esc_html_e( 'Go to Board Documents → Document Sections → Add New.', 'board-meeting-documents' ); ?></li>
				<li><?php esc_html_e( 'Enter the section title (e.g. "Annual Reports").', 'board-meeting-documents' ); ?></li>
				<li><?php esc_html_e( 'Add one row per PDF: select the file and type the title to show (e.g. "2025 Annual Report"). Use the arrows to reorder rows.', 'board-meeting-documents' ); ?></li>
				<li><?php esc_html_e( 'Optionally set "Order" in the Attributes box to control the order of sections on the page (lower numbers first).', 'board-meeting-documents' ); ?></li>
				<li><?php esc_html_e( 'Publish, then place [bmd_documents] on a page. Use section="slug" to show a single section.', 'board-meeting-documents' ); ?></li>
			</ol>
		</div>
		<?php
	}

	/**
	 * Adds a small "Shortcodes" reference box in the sidebar of the edit screen.
	 *
	 * @return void
	 */
	public function add_shortcodes_box(): void {
		add_meta_box(
			'bmd_shortcodes_help',
			__( 'Shortcodes', 'board-meeting-documents' ),
			array( $this, 'render_shortcodes_box' ),
			BMD_POST_TYPE,
			'side',
			'low'
		);
	}

	/**
	 * Renders the sidebar Shortcodes box.
	 *
	 * @return void
	 */
	public function render_shortcodes_box(): void {
		$instructions_url = admin_url( 'edit.php?post_type=' . BMD_POST_TYPE . '&page=bmd-instructions' );
		?>
		<p><?php esc_html_e( 'Paste these into your pages:', 'board-meeting-documents' ); ?></p>
		<p><strong><?php esc_html_e( 'Agendas page', 'board-meeting-documents' ); ?></strong><br />
			<code class="bmd-shortcode">[bmd_meetings type="agenda"]</code></p>
		<p><strong><?php esc_html_e( 'Minutes page', 'board-meeting-documents' ); ?></strong><br />
			<code class="bmd-shortcode">[bmd_meetings type="minutes"]</code></p>
		<p><a href="<?php echo esc_url( $instructions_url ); ?>"><?php esc_html_e( 'All options and instructions →', 'board-meeting-documents' ); ?></a></p>
		<?php
	}

	/**
	 * Loads admin CSS/JS and the Media Library only on the Board Meeting edit screen.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, array( BMD_POST_TYPE, BMD_Document_Sections::POST_TYPE ), true ) ) {
			return;
		}

		// Instructions page only needs the stylesheet.
		if ( 'board_meeting_page_bmd-instructions' === $hook ) {
			wp_enqueue_style( 'bmd-admin', BMD_PLUGIN_URL . 'assets/css/admin.css', array( 'dashicons' ), BMD_VERSION );
			return;
		}

		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		// Loads everything wp.media needs (Backbone, media views, uploader).
		wp_enqueue_media();

		wp_enqueue_style(
			'bmd-admin',
			BMD_PLUGIN_URL . 'assets/css/admin.css',
			array( 'dashicons' ),
			BMD_VERSION
		);

		wp_enqueue_script(
			'bmd-admin',
			BMD_PLUGIN_URL . 'assets/js/admin.js',
			array( 'media-editor' ),
			BMD_VERSION,
			true
		);

		wp_localize_script(
			'bmd-admin',
			'bmdAdmin',
			array(
				'i18n' => array(
					'frameTitle'  => __( 'Select or upload a PDF', 'board-meeting-documents' ),
					'frameButton' => __( 'Use this PDF', 'board-meeting-documents' ),
					'notPdf'      => __( 'Only PDF files can be used as meeting documents. Please select a PDF.', 'board-meeting-documents' ),
					'selectPdf'   => __( 'Select PDF', 'board-meeting-documents' ),
					'changePdf'   => __( 'Change PDF', 'board-meeting-documents' ),
				),
			)
		);
	}

	/**
	 * Adds Meeting Date / Status / Agenda / Minutes columns after the title.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function columns( array $columns ): array {
		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( 'title' === $key ) {
				$new['bmd_meeting_date']   = __( 'Meeting Date', 'board-meeting-documents' );
				$new['bmd_meeting_status'] = __( 'Status', 'board-meeting-documents' );
				$new['bmd_agenda']         = __( 'Agenda', 'board-meeting-documents' );
				$new['bmd_minutes']        = __( 'Minutes', 'board-meeting-documents' );
			}
		}

		return $new;
	}

	/**
	 * Outputs the custom column cells.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function column_content( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'bmd_meeting_date':
				$date = BMD_Helpers::get_meeting_date( $post_id );
				if ( '' === $date ) {
					echo '<span class="bmd-col-missing">' . esc_html__( 'Missing', 'board-meeting-documents' ) . '</span>';
				} else {
					echo esc_html( BMD_Helpers::format_date( $date ) );
				}
				break;

			case 'bmd_meeting_status':
				$statuses = BMD_Helpers::get_statuses();
				$status   = BMD_Helpers::get_meeting_status( $post_id );
				echo esc_html( $statuses[ $status ] ?? $status );
				break;

			case 'bmd_agenda':
			case 'bmd_minutes':
				$type  = 'bmd_agenda' === $column ? 'agenda' : 'minutes';
				$count = count( BMD_Helpers::get_documents( $post_id, $type ) );

				if ( 0 === $count ) {
					echo '<span aria-hidden="true">&mdash;</span><span class="screen-reader-text">' . esc_html__( 'None', 'board-meeting-documents' ) . '</span>';
				} else {
					/* translators: %s: number of PDF files */
					echo esc_html( sprintf( _n( '%s file', '%s files', $count, 'board-meeting-documents' ), number_format_i18n( $count ) ) );
				}
				break;
		}
	}

	/**
	 * Makes the Meeting Date column sortable.
	 *
	 * @param array $columns Sortable columns.
	 * @return array
	 */
	public function sortable_columns( array $columns ): array {
		$columns['bmd_meeting_date'] = 'bmd_meeting_date';
		return $columns;
	}

	/**
	 * Sorts the admin list by meeting date (default: newest first), while still
	 * showing meetings that have no date yet (drafts) so they are not lost.
	 *
	 * @param WP_Query $query Main admin query.
	 * @return void
	 */
	public function sort_admin_list( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( BMD_POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'edit-' . BMD_POST_TYPE !== $screen->id ) {
			return;
		}

		$orderby = (string) $query->get( 'orderby' );
		if ( '' !== $orderby && 'bmd_meeting_date' !== $orderby ) {
			return; // User chose another column (e.g. Title).
		}

		$order = 'ASC' === strtoupper( (string) $query->get( 'order' ) ) ? 'ASC' : 'DESC';

		$query->set(
			'meta_query',
			array(
				'relation'        => 'OR',
				'bmd_date_clause' => array(
					'key'     => BMD_Helpers::META_DATE,
					'compare' => 'EXISTS',
					'type'    => 'DATE',
				),
				array(
					'key'     => BMD_Helpers::META_DATE,
					'compare' => 'NOT EXISTS',
				),
			)
		);
		$query->set( 'orderby', array( 'bmd_date_clause' => $order ) );
	}

	/**
	 * Shows the "saved as draft because the date is missing" notice.
	 *
	 * @return void
	 */
	public function admin_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || BMD_POST_TYPE !== $screen->post_type ) {
			return;
		}

		// Read-only notice flag set by our own redirect; nothing is changed here.
		$notice = isset( $_GET['bmd_notice'] ) ? sanitize_key( wp_unslash( $_GET['bmd_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'missing_date' === $notice ) {
			echo '<div class="notice notice-error is-dismissible"><p>'
				. esc_html__( 'The meeting was saved as a draft because no Meeting Date was entered. Add a date and publish again.', 'board-meeting-documents' )
				. '</p></div>';
		} elseif ( 'duplicated' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Meeting duplicated as a draft. Update the date, status and PDFs, then publish.', 'board-meeting-documents' )
				. '</p></div>';
		}
	}

	/**
	 * Lets WordPress strip our notice flag from the URL after it is shown.
	 *
	 * @param array $args Removable query args.
	 * @return array
	 */
	public function removable_query_args( array $args ): array {
		$args[] = 'bmd_notice';
		return $args;
	}

	/**
	 * Replaces the "Add title" placeholder for meetings.
	 *
	 * @param string  $placeholder Placeholder text.
	 * @param WP_Post $post        Current post.
	 * @return string
	 */
	public function title_placeholder( string $placeholder, WP_Post $post ): string {
		if ( BMD_POST_TYPE === $post->post_type ) {
			return __( 'Optional title (auto-generated from the meeting date if left blank)', 'board-meeting-documents' );
		}
		return $placeholder;
	}
}

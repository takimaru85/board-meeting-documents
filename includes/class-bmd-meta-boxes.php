<?php
/**
 * Meta boxes for the Board Meeting edit screen and the save handler.
 *
 * Three meta boxes are registered:
 *   - Meeting Details   (date + status)
 *   - Agenda Documents  (repeatable PDF + label rows)
 *   - Minutes Documents (repeatable PDF + label rows)
 *
 * @package BoardMeetingDocuments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BMD_Meta_Boxes
 */
class BMD_Meta_Boxes {

	const NONCE_ACTION = 'bmd_save_meta';
	const NONCE_NAME   = 'bmd_meta_nonce';

	/**
	 * Post IDs whose publish request was downgraded to draft because the
	 * meeting date was missing. Used to add an admin notice after redirect.
	 *
	 * @var array<int,bool>
	 */
	private array $missing_date_posts = array();

	/**
	 * Post IDs for which the title was auto-generated during this request.
	 *
	 * @var array<int,bool>
	 */
	private array $auto_title_flags = array();

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes_' . BMD_POST_TYPE, array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . BMD_POST_TYPE, array( $this, 'save' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( $this, 'filter_post_data' ), 10, 2 );
		add_filter( 'redirect_post_location', array( $this, 'redirect_with_notice' ), 10, 2 );
	}

	/**
	 * Registers the meta boxes.
	 *
	 * @return void
	 */
	public function add_meta_boxes(): void {
		add_meta_box(
			'bmd_meeting_details',
			__( 'Meeting Details', 'board-meeting-documents' ),
			array( $this, 'render_details' ),
			BMD_POST_TYPE,
			'normal',
			'high'
		);

		foreach ( BMD_Helpers::get_document_types() as $type => $config ) {
			add_meta_box(
				'bmd_' . $type . '_documents',
				$config['section_title'],
				array( $this, 'render_documents' ),
				BMD_POST_TYPE,
				'normal',
				'high',
				array( 'type' => $type )
			);
		}
	}

	/**
	 * Renders the Meeting Details meta box (date + status).
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_details( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION . '_' . $post->ID, self::NONCE_NAME );

		$date   = BMD_Helpers::get_meeting_date( $post->ID );
		$status = BMD_Helpers::get_meeting_status( $post->ID );
		?>
		<table class="form-table bmd-form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<label for="bmd_meeting_date">
							<?php esc_html_e( 'Meeting Date', 'board-meeting-documents' ); ?>
							<span class="bmd-required" aria-hidden="true">*</span>
						</label>
					</th>
					<td>
						<input
							type="date"
							id="bmd_meeting_date"
							name="bmd_meeting_date"
							class="bmd-date-input"
							value="<?php echo esc_attr( $date ); ?>"
							aria-required="true"
							aria-describedby="bmd_meeting_date_desc"
						/>
						<p class="description" id="bmd_meeting_date_desc">
							<?php esc_html_e( 'Required. Shown on the frontend as, for example, "September 23, 2026".', 'board-meeting-documents' ); ?>
						</p>
						<p class="bmd-field-error" id="bmd_meeting_date_error" role="alert" hidden>
							<?php esc_html_e( 'Please enter a meeting date before publishing.', 'board-meeting-documents' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="bmd_meeting_status"><?php esc_html_e( 'Meeting Status', 'board-meeting-documents' ); ?></label>
					</th>
					<td>
						<select id="bmd_meeting_status" name="bmd_meeting_status">
							<?php foreach ( BMD_Helpers::get_statuses() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Cancelled, Special and Joint meetings are flagged in the frontend title. Normal is not.', 'board-meeting-documents' ); ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Renders an Agenda/Minutes Documents meta box.
	 *
	 * @param WP_Post $post Current post.
	 * @param array   $box  Meta box arguments; $box['args']['type'] is the document type.
	 * @return void
	 */
	public function render_documents( WP_Post $post, array $box ): void {
		$type   = isset( $box['args']['type'] ) ? (string) $box['args']['type'] : 'agenda';
		$types  = BMD_Helpers::get_document_types();
		$config = $types[ $type ] ?? $types['agenda'];

		self::render_documents_table(
			array(
				'type'              => $type,
				'field_name'        => $config['field_name'],
				'docs'              => BMD_Helpers::get_documents( $post->ID, $type ),
				'add_button'        => $config['add_button'],
				'empty_message'     => $config['empty_admin'],
				'label_placeholder' => $config['default_label'],
			)
		);
	}

	/**
	 * Renders a repeatable PDF + Label table. Shared by the meeting meta boxes
	 * and the Document Sections post type; admin.js drives it via the
	 * .bmd-documents / .bmd-doc-row class hooks.
	 *
	 * @param array $args {
	 *     @type string $type              Identifier for data-bmd-type.
	 *     @type string $field_name        Form field base name, e.g. bmd_agenda_documents.
	 *     @type array  $docs              Existing rows [ attachment_id, label ].
	 *     @type string $add_button        "Add ..." button text.
	 *     @type string $empty_message     Text shown when no rows exist.
	 *     @type string $label_placeholder Placeholder for the label input.
	 *     @type string $label_heading     Column heading for the label (default "Label").
	 * }
	 * @return void
	 */
	public static function render_documents_table( array $args ): void {
		$args = wp_parse_args(
			$args,
			array(
				'type'              => 'documents',
				'field_name'        => 'bmd_documents',
				'docs'              => array(),
				'add_button'        => __( 'Add Document', 'board-meeting-documents' ),
				'empty_message'     => __( 'No documents added yet.', 'board-meeting-documents' ),
				'label_placeholder' => '',
				'label_heading'     => __( 'Label', 'board-meeting-documents' ),
			)
		);

		$docs = is_array( $args['docs'] ) ? $args['docs'] : array();

		// Start with one empty row so the editor can pick a PDF immediately.
		if ( empty( $docs ) ) {
			$docs = array(
				array(
					'attachment_id' => 0,
					'label'         => '',
				),
			);
		}
		?>
		<div
			class="bmd-documents has-rows"
			data-bmd-type="<?php echo esc_attr( $args['type'] ); ?>"
			data-bmd-field="<?php echo esc_attr( $args['field_name'] ); ?>"
		>
			<table class="widefat striped bmd-doc-table">
				<thead>
					<tr>
						<th scope="col" class="bmd-doc-file"><?php esc_html_e( 'PDF', 'board-meeting-documents' ); ?></th>
						<th scope="col" class="bmd-doc-label"><?php echo esc_html( $args['label_heading'] ); ?></th>
						<th scope="col" class="bmd-doc-actions"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'board-meeting-documents' ); ?></span></th>
					</tr>
				</thead>
				<tbody class="bmd-doc-rows">
					<?php
					foreach ( $docs as $index => $doc ) {
						self::render_row(
							$args['field_name'],
							(string) $index,
							isset( $doc['attachment_id'] ) ? (int) $doc['attachment_id'] : 0,
							isset( $doc['label'] ) ? (string) $doc['label'] : '',
							$args['label_placeholder']
						);
					}
					?>
				</tbody>
			</table>

			<p class="bmd-empty-msg"><?php echo esc_html( $args['empty_message'] ); ?></p>

			<p class="bmd-actions">
				<button type="button" class="button button-secondary bmd-add-row">
					<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
					<?php echo esc_html( $args['add_button'] ); ?>
				</button>
			</p>

			<?php
			/*
			 * Row template used by admin.js when "Add ... Document" is clicked.
			 * __INDEX__ is replaced with a unique key; the save handler re-indexes
			 * everything anyway so the key only has to be unique within the form.
			 */
			?>
			<script type="text/html" class="bmd-row-template">
				<?php self::render_row( $args['field_name'], '__INDEX__', 0, '', $args['label_placeholder'] ); ?>
			</script>
		</div>
		<?php
	}

	/**
	 * Renders a single document row (also used as the JS template).
	 *
	 * @param string $field_name    Form field base name.
	 * @param string $index         Array index for the field name.
	 * @param int    $attachment_id Attachment ID (0 for empty row).
	 * @param string $label         Label.
	 * @param string $placeholder   Label placeholder.
	 * @return void
	 */
	private static function render_row( string $field_name, string $index, int $attachment_id, string $label, string $placeholder ): void {
		$name = $field_name . '[' . $index . ']';

		$url      = '';
		$filename = '';

		if ( $attachment_id > 0 ) {
			$url = (string) wp_get_attachment_url( $attachment_id );
			if ( '' === $url ) {
				// Attachment was deleted from the Media Library; treat the row as empty.
				$attachment_id = 0;
			} else {
				$file     = (string) get_attached_file( $attachment_id );
				$filename = '' !== $file ? wp_basename( $file ) : wp_basename( $url );
			}
		}

		$has_file = $attachment_id > 0;
		?>
		<tr class="bmd-doc-row<?php echo $has_file ? ' has-file' : ''; ?>">
			<td class="bmd-doc-file">
				<input
					type="hidden"
					class="bmd-doc-id"
					name="<?php echo esc_attr( $name ); ?>[attachment_id]"
					value="<?php echo esc_attr( (string) $attachment_id ); ?>"
				/>
				<span class="bmd-doc-filename">
					<span class="dashicons dashicons-pdf" aria-hidden="true"></span>
					<a class="bmd-doc-filename-link" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $filename ); ?></a>
				</span>
				<span class="bmd-doc-placeholder"><?php esc_html_e( 'No PDF selected', 'board-meeting-documents' ); ?></span>
				<button type="button" class="button bmd-select-pdf">
					<?php echo $has_file ? esc_html__( 'Change PDF', 'board-meeting-documents' ) : esc_html__( 'Select PDF', 'board-meeting-documents' ); ?>
				</button>
			</td>
			<td class="bmd-doc-label">
				<input
					type="text"
					class="regular-text bmd-doc-label-input"
					name="<?php echo esc_attr( $name ); ?>[label]"
					value="<?php echo esc_attr( $label ); ?>"
					placeholder="<?php echo esc_attr( $placeholder ); ?>"
					aria-label="<?php esc_attr_e( 'Document label', 'board-meeting-documents' ); ?>"
				/>
			</td>
			<td class="bmd-doc-actions">
				<span class="bmd-doc-move">
					<button type="button" class="button-link bmd-move-up" aria-label="<?php esc_attr_e( 'Move up', 'board-meeting-documents' ); ?>" title="<?php esc_attr_e( 'Move up', 'board-meeting-documents' ); ?>">
						<span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span>
					</button>
					<button type="button" class="button-link bmd-move-down" aria-label="<?php esc_attr_e( 'Move down', 'board-meeting-documents' ); ?>" title="<?php esc_attr_e( 'Move down', 'board-meeting-documents' ); ?>">
						<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
					</button>
				</span>
				<button type="button" class="button-link button-link-delete bmd-remove-row">
					<?php esc_html_e( 'Remove', 'board-meeting-documents' ); ?>
				</button>
			</td>
		</tr>
		<?php
	}

	/**
	 * Verifies the meta box nonce for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function verify_nonce( int $post_id ): bool {
		if ( empty( $_POST[ self::NONCE_NAME ] ) ) {
			return false;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );

		return (bool) wp_verify_nonce( $nonce, self::NONCE_ACTION . '_' . $post_id );
	}

	/**
	 * Reads and validates the submitted meeting date.
	 *
	 * @return string YYYY-MM-DD or ''.
	 */
	private function get_submitted_date(): string {
		// Nonce is verified by the callers before this is used.
		$raw = isset( $_POST['bmd_meeting_date'] ) ? sanitize_text_field( wp_unslash( $_POST['bmd_meeting_date'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return BMD_Helpers::sanitize_date( $raw );
	}

	/**
	 * Reads and validates the submitted meeting status.
	 *
	 * @return string
	 */
	private function get_submitted_status(): string {
		$raw = isset( $_POST['bmd_meeting_status'] ) ? sanitize_key( wp_unslash( $_POST['bmd_meeting_status'] ) ) : 'normal'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return BMD_Helpers::sanitize_status( $raw );
	}

	/**
	 * Runs before the post row is written. Two responsibilities:
	 *
	 * 1. Title fallback – if the editor left the title blank (or never touched
	 *    a title we generated earlier) generate "Board Meeting - <date>".
	 * 2. Date is required – a publish request without a valid date is saved as
	 *    a draft instead, and an admin notice explains why.
	 *
	 * @param array $data    Slashed post data about to be saved.
	 * @param array $postarr Raw post array.
	 * @return array
	 */
	public function filter_post_data( array $data, array $postarr ): array {
		if ( BMD_POST_TYPE !== ( $data['post_type'] ?? '' ) ) {
			return $data;
		}

		$post_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;

		if ( ! $post_id || ! $this->verify_nonce( $post_id ) ) {
			return $data;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return $data;
		}

		$date   = $this->get_submitted_date();
		$status = $this->get_submitted_status();

		// 1. Title fallback.
		$submitted_title = trim( wp_unslash( (string) ( $data['post_title'] ?? '' ) ) );
		$was_auto        = (bool) get_post_meta( $post_id, BMD_Helpers::META_AUTO_TITLE, true );
		$current_title   = (string) get_post_field( 'post_title', $post_id, 'raw' );

		$regenerate = '' !== $date && ( '' === $submitted_title || ( $was_auto && $submitted_title === $current_title ) );

		if ( $regenerate ) {
			$data['post_title']               = wp_slash( BMD_Helpers::get_auto_title( $date, $status ) );
			$this->auto_title_flags[ $post_id ] = true;
		} else {
			$this->auto_title_flags[ $post_id ] = false;
		}

		// 2. Date is required to publish.
		if ( '' === $date && in_array( $data['post_status'] ?? '', array( 'publish', 'future' ), true ) ) {
			$data['post_status']                = 'draft';
			$this->missing_date_posts[ $post_id ] = true;
		}

		return $data;
	}

	/**
	 * Saves the meta box values.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save( int $post_id, WP_Post $post ): void {
		if ( ! $this->verify_nonce( $post_id ) ) {
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

		// Meeting date.
		$date = $this->get_submitted_date();
		if ( '' !== $date ) {
			update_post_meta( $post_id, BMD_Helpers::META_DATE, $date );
		} else {
			delete_post_meta( $post_id, BMD_Helpers::META_DATE );
		}

		// Meeting status.
		update_post_meta( $post_id, BMD_Helpers::META_STATUS, $this->get_submitted_status() );

		// Agenda / Minutes documents.
		foreach ( BMD_Helpers::get_document_types() as $config ) {
			$field = $config['field_name'];

			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized row by row in sanitize_documents().
			$raw  = ( isset( $_POST[ $field ] ) && is_array( $_POST[ $field ] ) ) ? wp_unslash( $_POST[ $field ] ) : array();
			$docs = BMD_Helpers::sanitize_documents( $raw );

			/*
			 * Empty lists are deleted rather than stored as an empty array so the
			 * frontend can use a cheap "meta EXISTS" query to find meetings that
			 * have at least one document of this type.
			 */
			if ( ! empty( $docs ) ) {
				update_post_meta( $post_id, $config['meta_key'], $docs );
			} else {
				delete_post_meta( $post_id, $config['meta_key'] );
			}
		}

		// Remember whether the title is ours so we can keep it in sync with the date.
		if ( isset( $this->auto_title_flags[ $post_id ] ) ) {
			if ( $this->auto_title_flags[ $post_id ] ) {
				update_post_meta( $post_id, BMD_Helpers::META_AUTO_TITLE, 1 );
			} else {
				delete_post_meta( $post_id, BMD_Helpers::META_AUTO_TITLE );
			}
		}
	}

	/**
	 * Adds a query arg to the post-save redirect so BMD_Admin can show the
	 * "saved as draft, date missing" notice.
	 *
	 * @param string $location Redirect URL.
	 * @param int    $post_id  Post ID.
	 * @return string
	 */
	public function redirect_with_notice( string $location, int $post_id ): string {
		if ( empty( $this->missing_date_posts[ $post_id ] ) ) {
			return $location;
		}

		return add_query_arg(
			array(
				'message'    => 10, // Core message: "Post draft updated."
				'bmd_notice' => 'missing_date',
			),
			$location
		);
	}
}

<?php
/**
 * Media Library integration.
 *
 * @package IndexLaneSafeWebPQueue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds WebP status, row actions, and bulk actions to the Media Library.
 */
class ILSWQ_Media {
	const COLUMN       = 'ilswq_webp';
	const BULK_CONVERT = 'ilswq_convert_to_webp';
	const BULK_EXCLUDE = 'ilswq_exclude_from_webp';
	const BULK_INCLUDE = 'ilswq_include_in_webp';
	const ACTION       = 'ilswq_media_action';
	const NOTICE_KEY   = 'ilswq_notice_';
	const NOTICE_TTL   = 60;

	/**
	 * Queue instance.
	 *
	 * @var ILSWQ_Queue
	 */
	private $queue;

	/**
	 * Per-request status summaries.
	 *
	 * @var array<int, array<string, string>>
	 */
	private static $status_cache = array();

	/**
	 * Constructor.
	 *
	 * @param ILSWQ_Queue $queue Queue.
	 */
	public function __construct( ILSWQ_Queue $queue ) {
		$this->queue = $queue;
	}

	/**
	 * Register Media Library hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'manage_media_columns', array( $this, 'add_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'media_row_actions', array( $this, 'add_row_actions' ), 10, 2 );
		add_filter( 'bulk_actions-upload', array( $this, 'add_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk_actions' ), 10, 3 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_media_action' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * Add the WebP column after the attachment title.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function add_column( $columns ) {
		if ( ! self::can_manage() || ! is_array( $columns ) ) {
			return $columns;
		}

		$updated = array();
		foreach ( $columns as $key => $label ) {
			$updated[ $key ] = $label;
			if ( 'title' === $key ) {
				$updated[ self::COLUMN ] = __( 'WebP', 'indexlane-safe-webp-queue' );
			}
		}

		if ( ! isset( $updated[ self::COLUMN ] ) ) {
			$updated[ self::COLUMN ] = __( 'WebP', 'indexlane-safe-webp-queue' );
		}

		return $updated;
	}

	/**
	 * Render the WebP column for one attachment.
	 *
	 * @param string $column Column key.
	 * @param int    $post_id Attachment ID.
	 * @return void
	 */
	public function render_column( $column, $post_id ) {
		if ( self::COLUMN !== $column || ! self::can_manage() ) {
			return;
		}

		$status = self::status_summary( (int) $post_id );

		printf(
			'<span class="ilswq-status is-%1$s">%2$s</span>',
			esc_attr( $status['key'] ),
			esc_html( $status['label'] )
		);

		if ( '' !== $status['detail'] ) {
			printf( '<br><span class="ilswq-muted">%s</span>', esc_html( $status['detail'] ) );
		}
	}

	/**
	 * Add WebP row actions to the Media Library.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param WP_Post               $post Attachment post.
	 * @return array<string, string>
	 */
	public function add_row_actions( $actions, $post ) {
		if ( ! self::can_manage() || ! is_object( $post ) || empty( $post->ID ) || 'attachment' !== $post->post_type ) {
			return $actions;
		}

		$attachment_id = (int) $post->ID;
		$status        = self::status_summary( $attachment_id );
		$excluded      = ILSWQ_Scanner::is_excluded( $attachment_id );

		if ( ! $excluded && in_array( $status['key'], array( 'eligible', 'needs-review', 'failed' ), true ) ) {
			$actions['ilswq_convert'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( self::action_url( 'convert', array( $attachment_id ) ) ),
				esc_html__( 'Convert to WebP', 'indexlane-safe-webp-queue' )
			);
		}

		if ( $excluded ) {
			$actions['ilswq_include'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( self::action_url( 'include', array( $attachment_id ) ) ),
				esc_html__( 'Include in WebP', 'indexlane-safe-webp-queue' )
			);
		} else {
			$actions['ilswq_exclude'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( self::action_url( 'exclude', array( $attachment_id ) ) ),
				esc_html__( 'Exclude from WebP', 'indexlane-safe-webp-queue' )
			);
		}

		return $actions;
	}

	/**
	 * Add WebP bulk actions to the Media Library list table.
	 *
	 * @param array<string, string> $actions Bulk actions.
	 * @return array<string, string>
	 */
	public function add_bulk_actions( $actions ) {
		if ( ! self::can_manage() || ! is_array( $actions ) ) {
			return $actions;
		}

		$actions[ self::BULK_CONVERT ] = __( 'Convert to WebP', 'indexlane-safe-webp-queue' );
		$actions[ self::BULK_EXCLUDE ] = __( 'Exclude from WebP conversion', 'indexlane-safe-webp-queue' );
		$actions[ self::BULK_INCLUDE ] = __( 'Include in WebP conversion', 'indexlane-safe-webp-queue' );

		return $actions;
	}

	/**
	 * Handle the WebP bulk actions.
	 *
	 * @param string            $redirect_to Redirect URL.
	 * @param string            $action Requested bulk action.
	 * @param array<int, mixed> $post_ids Selected attachment IDs.
	 * @return string
	 */
	public function handle_bulk_actions( $redirect_to, $action, $post_ids ) {
		if ( ! self::can_manage() ) {
			return $redirect_to;
		}

		$ids = self::normalize_ids( $post_ids );
		if ( empty( $ids ) ) {
			return $redirect_to;
		}

		switch ( $action ) {
			case self::BULK_CONVERT:
				$notice = $this->queue_conversion( $ids );
				$status = $this->queue->get_public_status();
				self::remember_notice( $notice, 'queued' === $notice ? (int) $status['total'] : 0 );
				break;
			case self::BULK_EXCLUDE:
				self::remember_notice( 'excluded', self::set_exclusion( $ids, true ) );
				break;
			case self::BULK_INCLUDE:
				self::remember_notice( 'included', self::set_exclusion( $ids, false ) );
				break;
		}

		return $redirect_to;
	}

	/**
	 * Handle a single attachment row action.
	 *
	 * @return void
	 */
	public function handle_media_action() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to change WebP conversion for this image.', 'indexlane-safe-webp-queue' ) );
		}

		$task = isset( $_GET['ilswq_task'] ) && is_scalar( $_GET['ilswq_task'] ) ? sanitize_key( wp_unslash( $_GET['ilswq_task'] ) ) : '';
		$ids  = self::normalize_ids(
			isset( $_GET['ilswq_ids'] ) && is_scalar( $_GET['ilswq_ids'] ) ? explode( ',', sanitize_text_field( wp_unslash( $_GET['ilswq_ids'] ) ) ) : array()
		);

		check_admin_referer( self::ACTION . '_' . $task );

		$redirect = wp_get_referer();
		if ( '' === $redirect ) {
			$redirect = admin_url( 'upload.php' );
		}

		if ( empty( $ids ) ) {
			self::remember_notice( 'empty', 0 );
			wp_safe_redirect( $redirect );
			exit;
		}

		switch ( $task ) {
			case 'convert':
				$notice = $this->queue_conversion( $ids );
				break;
			case 'exclude':
				$notice = self::set_exclusion( $ids, true ) > 0 ? 'excluded' : 'empty';
				break;
			case 'include':
				$notice = self::set_exclusion( $ids, false ) > 0 ? 'included' : 'empty';
				break;
			default:
				$notice = 'empty';
				break;
		}

		self::remember_notice( $notice, count( $ids ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Queue selected attachments, returning a notice key.
	 *
	 * @param array<int, int> $ids Attachment IDs.
	 * @return string
	 */
	public function queue_conversion( $ids ) {
		$result = $this->queue->start_job( $ids, ILSWQ_Settings::get() );
		if ( ! is_wp_error( $result ) ) {
			return 'queued';
		}

		$code = $result->get_error_code();
		if ( 'ilswq_queue_active' === $code || 'ilswq_queue_busy' === $code ) {
			return 'busy';
		}

		if ( 'ilswq_queue_too_large' === $code ) {
			return 'large';
		}

		if ( 'ilswq_queue_no_writer' === $code ) {
			return 'no-writer';
		}

		return 'empty';
	}

	/**
	 * Exclude or include attachments.
	 *
	 * @param array<int, int> $ids Attachment IDs.
	 * @param bool            $excluded Whether the attachments should be excluded.
	 * @return int Number of changed attachments.
	 */
	public static function set_exclusion( $ids, $excluded ) {
		$changed = 0;

		foreach ( self::normalize_ids( $ids ) as $attachment_id ) {
			if ( ILSWQ_Scanner::is_excluded( $attachment_id ) === (bool) $excluded ) {
				continue;
			}

			if ( ILSWQ_Scanner::set_excluded( $attachment_id, (bool) $excluded ) ) {
				++$changed;
			}
		}

		return $changed;
	}

	/**
	 * Return a cached WebP status summary for one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, string>
	 */
	public static function status_summary( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( isset( self::$status_cache[ $attachment_id ] ) ) {
			return self::$status_cache[ $attachment_id ];
		}

		$scanner = self::scanner();
		$row     = $scanner->scan_attachment( $attachment_id, ILSWQ_Settings::get() );
		$key     = isset( $row['status_key'] ) ? sanitize_key( (string) $row['status_key'] ) : 'skipped';
		$label   = isset( $row['status'] ) ? (string) $row['status'] : ILSWQ_Scanner::status_label( $key );
		$reason  = isset( $row['reason'] ) ? (string) $row['reason'] : '';
		$detail  = '';

		if ( 'converted' === $key ) {
			$detail = self::converted_detail( $row );
		} elseif ( 'excluded' === $key ) {
			$detail = __( 'Skipped for conversion and front-end serving.', 'indexlane-safe-webp-queue' );
		} else {
			$detail = $reason;
		}

		self::$status_cache[ $attachment_id ] = array(
			'key'    => $key,
			'label'  => $label,
			'detail' => $detail,
		);

		return self::$status_cache[ $attachment_id ];
	}

	/**
	 * Render an admin notice for a completed Media Library action.
	 *
	 * @return void
	 */
	public function render_notice() {
		if ( ! self::can_manage() ) {
			return;
		}

		$notice = self::forget_notice();
		if ( empty( $notice['key'] ) ) {
			return;
		}

		$key     = (string) $notice['key'];
		$message = self::notice_message( $key, isset( $notice['count'] ) ? (int) $notice['count'] : 0 );
		if ( '' === $message ) {
			return;
		}

		$type = in_array( $key, array( 'queued', 'excluded', 'included' ), true ) ? 'success' : 'error';

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * Return the translated message for a Media Library notice key.
	 *
	 * @param string $key Notice key.
	 * @param int    $count Affected attachment count.
	 * @return string
	 */
	private static function notice_message( $key, $count ) {
		switch ( $key ) {
			case 'queued':
				return sprintf(
					/* translators: %d: attachment count. */
					_n(
						'%d image was queued for WebP conversion. Track it under Tools -> IndexLane Safe WebP Queue.',
						'%d images were queued for WebP conversion. Track them under Tools -> IndexLane Safe WebP Queue.',
						$count,
						'indexlane-safe-webp-queue'
					),
					$count
				);
			case 'excluded':
				return sprintf(
					/* translators: %d: attachment count. */
					_n(
						'%d image was excluded from WebP conversion.',
						'%d images were excluded from WebP conversion.',
						$count,
						'indexlane-safe-webp-queue'
					),
					$count
				);
			case 'included':
				return sprintf(
					/* translators: %d: attachment count. */
					_n(
						'%d image can be converted to WebP again.',
						'%d images can be converted to WebP again.',
						$count,
						'indexlane-safe-webp-queue'
					),
					$count
				);
			case 'busy':
				return __( 'A conversion job is already active. Finish or cancel it before queuing more images.', 'indexlane-safe-webp-queue' );
			case 'large':
				return __( 'That selection is larger than a single Media Library conversion job. Use Convert Entire Library on the plugin page instead.', 'indexlane-safe-webp-queue' );
			case 'empty':
				return __( 'No images were queued. The selected images may already be converted, excluded, or unsupported.', 'indexlane-safe-webp-queue' );
			case 'no-writer':
				return __( 'This server cannot write WebP with a local image editor. Open Tools -> IndexLane Safe WebP Queue and convert the image in your browser instead.', 'indexlane-safe-webp-queue' );
		}

		return '';
	}

	/**
	 * Build a nonce-protected row action URL.
	 *
	 * @param string          $task Task key.
	 * @param array<int, int> $ids Attachment IDs.
	 * @return string
	 */
	private static function action_url( $task, $ids ) {
		$ids = self::normalize_ids( $ids );

		return wp_nonce_url(
			add_query_arg(
				array(
					'action'    => self::ACTION,
					'ilswq_task' => $task,
					'ilswq_ids'  => implode( ',', $ids ),
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $task
		);
	}

	/**
	 * Store a Media Library notice for the current administrator.
	 *
	 * @param string $key Notice key.
	 * @param int    $count Affected attachment count.
	 * @return void
	 */
	private static function remember_notice( $key, $count ) {
		$key     = sanitize_key( $key );
		$user_id = get_current_user_id();

		if ( '' === $key || $user_id <= 0 ) {
			return;
		}

		set_transient(
			self::NOTICE_KEY . $user_id,
			array(
				'key'   => $key,
				'count' => max( 0, absint( $count ) ),
			),
			self::NOTICE_TTL
		);
	}

	/**
	 * Return and clear the stored Media Library notice.
	 *
	 * @return array<string, mixed>
	 */
	private static function forget_notice() {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return array();
		}

		$notice = get_transient( self::NOTICE_KEY . $user_id );
		delete_transient( self::NOTICE_KEY . $user_id );

		return is_array( $notice ) ? $notice : array();
	}

	/**
	 * Return a readable savings summary for a converted attachment.
	 *
	 * @param array<string, mixed> $row Scan row.
	 * @return string
	 */
	private static function converted_detail( $row ) {
		$webp_label = isset( $row['webp_size_label'] ) ? (string) $row['webp_size_label'] : '';
		$savings    = isset( $row['savings'] ) ? (string) $row['savings'] : '';

		if ( '' === $webp_label ) {
			return '';
		}

		if ( '' === $savings ) {
			return sprintf(
				/* translators: %s: total size of generated WebP files. */
				__( 'WebP copies: %s', 'indexlane-safe-webp-queue' ),
				$webp_label
			);
		}

		return sprintf(
			/* translators: 1: total size of generated WebP files, 2: saved percentage. */
			__( 'WebP copies: %1$s. Saved: %2$s.', 'indexlane-safe-webp-queue' ),
			$webp_label,
			$savings
		);
	}

	/**
	 * Return whether the current user may manage WebP conversion.
	 *
	 * @return bool
	 */
	private static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Normalize a list of attachment IDs.
	 *
	 * @param mixed $ids Raw IDs.
	 * @return array<int, int>
	 */
	private static function normalize_ids( $ids ) {
		$normalized = array();

		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			if ( ! is_scalar( $id ) ) {
				continue;
			}

			$attachment_id = absint( $id );
			if ( $attachment_id > 0 ) {
				$normalized[ $attachment_id ] = $attachment_id;
			}
		}

		return array_values( $normalized );
	}

	/**
	 * Return a shared scanner instance.
	 *
	 * @return ILSWQ_Scanner
	 */
	private static function scanner() {
		static $scanner = null;

		if ( null === $scanner ) {
			$scanner = new ILSWQ_Scanner();
		}

		return $scanner;
	}
}

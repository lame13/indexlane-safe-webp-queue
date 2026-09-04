<?php
/**
 * Plugin bootstrap and admin controller.
 *
 * @package IndexLaneSafeWebPQueue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin controller.
 */
class ILSWQ_Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var ILSWQ_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Admin page hook suffix.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Scanner instance.
	 *
	 * @var ILSWQ_Scanner
	 */
	private $scanner;

	/**
	 * Converter instance.
	 *
	 * @var ILSWQ_Converter
	 */
	private $converter;

	/**
	 * Persistent queue instance.
	 *
	 * @var ILSWQ_Queue
	 */
	private $queue;

	/**
	 * Return singleton instance.
	 *
	 * @return ILSWQ_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->scanner   = new ILSWQ_Scanner();
		$this->converter = new ILSWQ_Converter( $this->scanner );
		$this->queue     = new ILSWQ_Queue( $this->converter );

		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_init', array( $this->queue, 'ensure_scheduled' ) );

		add_action( 'wp_ajax_ilswq_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_ilswq_scan', array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_ilswq_convert', array( $this, 'ajax_convert' ) );
		add_action( 'wp_ajax_ilswq_cleanup', array( $this, 'ajax_cleanup' ) );
		add_action( 'wp_ajax_ilswq_validate_webp', array( $this, 'ajax_validate_webp' ) );
		add_action( 'wp_ajax_ilswq_queue_start', array( $this, 'ajax_queue_start' ) );
		add_action( 'wp_ajax_ilswq_queue_status', array( $this, 'ajax_queue_status' ) );
		add_action( 'wp_ajax_ilswq_queue_process', array( $this, 'ajax_queue_process' ) );
		add_action( 'wp_ajax_ilswq_queue_command', array( $this, 'ajax_queue_command' ) );
		add_action( ILSWQ_Queue::CRON_HOOK, array( $this->queue, 'process_scheduled' ) );

		add_filter( 'wp_get_attachment_image_src', array( 'ILSWQ_Serving', 'filter_attachment_image_src' ), 10, 4 );
		add_filter( 'wp_calculate_image_srcset', array( 'ILSWQ_Serving', 'filter_image_srcset' ), 10, 5 );
		add_filter( 'wp_update_attachment_metadata', array( $this, 'maybe_queue_attachment_metadata' ), 20, 2 );
		add_action( 'delete_attachment', array( $this, 'cleanup_deleted_attachment' ) );
	}

	/**
	 * Add Tools page.
	 *
	 * @return void
	 */
	public function add_admin_page() {
		$this->hook_suffix = add_management_page(
			__( 'IndexLane Safe WebP Queue', 'indexlane-safe-webp-queue' ),
			__( 'IndexLane Safe WebP Queue', 'indexlane-safe-webp-queue' ),
			'manage_options',
			'indexlane-safe-webp-queue',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'ilswq-admin',
			ILSWQ_URL . 'assets/admin.css',
			array(),
			ILSWQ_VERSION
		);

		wp_enqueue_script(
			'ilswq-admin',
			ILSWQ_URL . 'assets/admin.js',
			array( 'jquery' ),
			ILSWQ_VERSION,
			true
		);

		wp_localize_script(
			'ilswq-admin',
			'ILSWQ_Admin',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'ilswq_admin' ),
				'settings'   => ILSWQ_Settings::get(),
				'queue'      => $this->queue->get_public_status(),
				'strings'    => array(
					'scanning'                  => __( 'Scanning Media Library...', 'indexlane-safe-webp-queue' ),
					'scanComplete'              => __( 'Scan complete.', 'indexlane-safe-webp-queue' ),
					'convertStarted'            => __( 'Conversion job started. It can safely resume after you leave this page.', 'indexlane-safe-webp-queue' ),
					'queueComplete'             => __( 'Conversion job complete.', 'indexlane-safe-webp-queue' ),
					/* translators: %d: failed attachment count. */
					'queueCompleteWithFailures' => __( 'Conversion job finished with %d failed attachments. Review the error and use Retry Failed.', 'indexlane-safe-webp-queue' ),
					'queueConflictComplete'     => __( 'Conversion job finished with conflicting files. Review the queue summary and last error, resolve the conflicts, then scan again.', 'indexlane-safe-webp-queue' ),
					'noRows'                    => __( 'Run a scan to build a report.', 'indexlane-safe-webp-queue' ),
					'noEligible'                => __( 'No eligible rows are selected.', 'indexlane-safe-webp-queue' ),
					'requestFailed'             => __( 'Request failed.', 'indexlane-safe-webp-queue' ),
					/* translators: %s: attachment title, filename, or ID. */
					'selectAttachment'          => __( 'Select %s', 'indexlane-safe-webp-queue' ),
					'settingsSaved'             => __( 'Settings saved.', 'indexlane-safe-webp-queue' ),
					'cleanupConfirm'            => __( 'Delete WebP files generated by this plugin and clear their metadata?', 'indexlane-safe-webp-queue' ),
					/* translators: 1: deleted file count, 2: failed deletion count. */
					'cleanupSummary'            => __( 'Generated WebP cleanup complete. Deleted: %1$d. Failed: %2$d. Run a new scan to refresh the report.', 'indexlane-safe-webp-queue' ),
					'cleanupRunning'            => __( 'Cleaning generated WebP files...', 'indexlane-safe-webp-queue' ),
					'paused'                    => __( 'Scan paused.', 'indexlane-safe-webp-queue' ),
					'stopped'                   => __( 'Scan stopped.', 'indexlane-safe-webp-queue' ),
					'queueCancelConfirm'        => __( 'Cancel the pending conversion job? WebP files already completed will remain in place.', 'indexlane-safe-webp-queue' ),
					/* translators: %s: localized date and time. */
					'queueLastActivity'         => __( 'Last activity: %s', 'indexlane-safe-webp-queue' ),
					/* translators: %d: queued automatic upload count. */
					'automaticPendingOne'       => __( '%d new upload is waiting for automatic conversion.', 'indexlane-safe-webp-queue' ),
					/* translators: %d: queued automatic upload count. */
					'automaticPendingMany'      => __( '%d new uploads are waiting for automatic conversion.', 'indexlane-safe-webp-queue' ),
					/* translators: %d: automatic upload failure count. */
					'automaticFailedOne'        => __( '%d automatic conversion needs review after repeated failures. Run a scan to inspect and retry it.', 'indexlane-safe-webp-queue' ),
					/* translators: %d: automatic upload failure count. */
					'automaticFailedMany'       => __( '%d automatic conversions need review after repeated failures. Run a scan to inspect and retry them.', 'indexlane-safe-webp-queue' ),
					'validationRunning'         => __( 'Validating generated WebP files...', 'indexlane-safe-webp-queue' ),
					/* translators: %d: validated WebP file count. */
					'validationPassed'          => __( 'All generated WebP files in the current report are valid. Validated: %d.', 'indexlane-safe-webp-queue' ),
					/* translators: 1: validated file count, 2: invalid file count, 3: missing map count. */
					'validationFailed'          => __( 'Validation found missing or invalid generated WebP files. Validated: %1$d. Invalid: %2$d. Missing maps: %3$d.', 'indexlane-safe-webp-queue' ),
				),
				'csvHeaders' => array(
					__( 'Attachment ID', 'indexlane-safe-webp-queue' ),
					__( 'Title', 'indexlane-safe-webp-queue' ),
					__( 'File', 'indexlane-safe-webp-queue' ),
					__( 'Source Files', 'indexlane-safe-webp-queue' ),
					__( 'MIME Type', 'indexlane-safe-webp-queue' ),
					__( 'Dimensions', 'indexlane-safe-webp-queue' ),
					__( 'Original Size', 'indexlane-safe-webp-queue' ),
					__( 'Estimated Memory', 'indexlane-safe-webp-queue' ),
					__( 'WebP Size', 'indexlane-safe-webp-queue' ),
					__( 'Savings', 'indexlane-safe-webp-queue' ),
					__( 'Editor', 'indexlane-safe-webp-queue' ),
					__( 'Status', 'indexlane-safe-webp-queue' ),
					__( 'Reason', 'indexlane-safe-webp-queue' ),
				),
			)
		);
	}

	/**
	 * Render admin page.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'indexlane-safe-webp-queue' ) );
		}

		$settings     = ILSWQ_Settings::get();
		$checks       = ILSWQ_Capabilities::get_checks();
		$queue_status = $this->queue->get_public_status();
		?>
		<div class="wrap ilswq-wrap">
			<h1><?php esc_html_e( 'IndexLane Safe WebP Queue', 'indexlane-safe-webp-queue' ); ?></h1>
			<p class="ilswq-lede">
				<?php esc_html_e( 'Convert JPEG and PNG media attachments and their generated image sizes to sibling WebP files in small local batches. Originals are preserved and post content is not rewritten.', 'indexlane-safe-webp-queue' ); ?>
			</p>
			<p class="ilswq-lede">
				<?php esc_html_e( 'Frontend WebP serving is optional and only swaps normal WordPress image output where this plugin has generated a matching WebP file.', 'indexlane-safe-webp-queue' ); ?>
			</p>

			<div class="ilswq-layout">
				<section class="ilswq-panel">
					<h2><?php esc_html_e( 'Server Checks', 'indexlane-safe-webp-queue' ); ?></h2>
					<table class="widefat striped ilswq-checks">
						<tbody>
							<?php foreach ( $checks as $check ) : ?>
								<tr class="ilswq-check-row is-<?php echo esc_attr( $check['status'] ); ?>">
									<th scope="row"><?php echo esc_html( $check['label'] ); ?></th>
									<td><strong><?php echo esc_html( $check['value'] ); ?></strong></td>
									<td><?php echo esc_html( $check['description'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</section>

				<section class="ilswq-panel">
					<h2><?php esc_html_e( 'Queue Settings', 'indexlane-safe-webp-queue' ); ?></h2>
					<form id="ilswq-settings-form" class="ilswq-settings-form">
						<label>
							<span><?php esc_html_e( 'Batch size', 'indexlane-safe-webp-queue' ); ?></span>
							<input type="number" min="1" max="10" name="batch_size" value="<?php echo esc_attr( $settings['batch_size'] ); ?>">
						</label>
						<label>
							<span><?php esc_html_e( 'Max pixels', 'indexlane-safe-webp-queue' ); ?></span>
							<input type="number" min="1000000" max="40000000" step="1000000" name="max_pixels" value="<?php echo esc_attr( $settings['max_pixels'] ); ?>">
						</label>
						<label>
							<span><?php esc_html_e( 'JPEG quality', 'indexlane-safe-webp-queue' ); ?></span>
							<input type="number" min="1" max="100" name="jpeg_quality" value="<?php echo esc_attr( $settings['jpeg_quality'] ); ?>">
						</label>
						<label>
							<span><?php esc_html_e( 'PNG quality', 'indexlane-safe-webp-queue' ); ?></span>
							<input type="number" min="1" max="100" name="png_quality" value="<?php echo esc_attr( $settings['png_quality'] ); ?>">
						</label>
						<label class="ilswq-checkbox">
							<input type="checkbox" name="skip_larger" value="1" <?php checked( $settings['skip_larger'], 1 ); ?>>
							<span><?php esc_html_e( 'Skip when WebP is larger', 'indexlane-safe-webp-queue' ); ?></span>
						</label>
						<label class="ilswq-checkbox">
							<input type="checkbox" name="serve_webp" value="1" <?php checked( $settings['serve_webp'], 1 ); ?>>
							<span><?php esc_html_e( 'Use generated WebP in WordPress image output', 'indexlane-safe-webp-queue' ); ?></span>
						</label>
						<label class="ilswq-checkbox">
							<input type="checkbox" name="auto_uploads" value="1" <?php checked( $settings['auto_uploads'], 1 ); ?>>
							<span><?php esc_html_e( 'Generate WebP for new uploads', 'indexlane-safe-webp-queue' ); ?></span>
						</label>
						<button type="submit" class="button"><?php esc_html_e( 'Save Settings', 'indexlane-safe-webp-queue' ); ?></button>
					</form>
				</section>
			</div>

			<section class="ilswq-panel ilswq-queue-panel" aria-labelledby="ilswq-queue-title">
				<div class="ilswq-queue-heading">
					<div>
						<h2 id="ilswq-queue-title"><?php esc_html_e( 'Conversion Queue', 'indexlane-safe-webp-queue' ); ?></h2>
						<p><?php esc_html_e( 'Conversion jobs keep their progress if this page is closed. WP-Cron continues queued batches when site traffic permits.', 'indexlane-safe-webp-queue' ); ?></p>
					</div>
					<span id="ilswq-queue-state" class="ilswq-status is-<?php echo esc_attr( $queue_status['state'] ); ?>"><?php echo esc_html( $queue_status['state_label'] ); ?></span>
				</div>

				<div class="ilswq-progress-bar ilswq-queue-progress" role="progressbar" aria-label="<?php esc_attr_e( 'Conversion job progress', 'indexlane-safe-webp-queue' ); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( $queue_status['progress'] ); ?>">
					<span style="width: <?php echo esc_attr( $queue_status['progress'] ); ?>%"></span>
				</div>

				<p id="ilswq-queue-summary" class="ilswq-queue-summary" aria-live="polite"><?php echo esc_html( $queue_status['summary'] ); ?></p>
				<p id="ilswq-queue-settings" class="ilswq-muted"<?php echo empty( $queue_status['settings_summary'] ) ? ' hidden' : ''; ?>><?php echo esc_html( $queue_status['settings_summary'] ); ?></p>
				<p id="ilswq-queue-activity" class="ilswq-muted"<?php echo empty( $queue_status['last_activity_label'] ) ? ' hidden' : ''; ?>>
					<?php
					if ( ! empty( $queue_status['last_activity_label'] ) ) {
						echo esc_html(
							sprintf(
								/* translators: %s: localized date and time. */
								__( 'Last activity: %s', 'indexlane-safe-webp-queue' ),
								$queue_status['last_activity_label']
							)
						);
					}
					?>
				</p>
				<p id="ilswq-queue-error" class="ilswq-queue-error"<?php echo empty( $queue_status['last_error'] ) ? ' hidden' : ''; ?>><?php echo esc_html( $queue_status['last_error'] ); ?></p>
				<p id="ilswq-auto-pending" class="ilswq-muted"<?php echo empty( $queue_status['automatic_pending'] ) ? ' hidden' : ''; ?>>
					<?php
					if ( ! empty( $queue_status['automatic_pending'] ) ) {
						echo esc_html(
							sprintf(
								/* translators: %d: queued automatic upload count. */
								_n( '%d new upload is waiting for automatic conversion.', '%d new uploads are waiting for automatic conversion.', $queue_status['automatic_pending'], 'indexlane-safe-webp-queue' ),
								$queue_status['automatic_pending']
							)
						);
					}
					?>
				</p>
				<p id="ilswq-auto-failed" class="ilswq-queue-error"<?php echo empty( $queue_status['automatic_failed'] ) ? ' hidden' : ''; ?>>
					<?php
					if ( ! empty( $queue_status['automatic_failed'] ) ) {
						echo esc_html(
							sprintf(
								/* translators: %d: automatic upload failure count. */
								_n( '%d automatic conversion needs review after repeated failures. Run a scan to inspect and retry it.', '%d automatic conversions need review after repeated failures. Run a scan to inspect and retry them.', $queue_status['automatic_failed'], 'indexlane-safe-webp-queue' ),
								$queue_status['automatic_failed']
							)
						);
					}
					?>
				</p>

				<div class="ilswq-queue-actions">
					<button type="button" class="button" id="ilswq-queue-pause" <?php disabled( empty( $queue_status['can_pause'] ) ); ?>><?php esc_html_e( 'Pause Job', 'indexlane-safe-webp-queue' ); ?></button>
					<button type="button" class="button" id="ilswq-queue-resume" <?php disabled( empty( $queue_status['can_resume'] ) ); ?>><?php esc_html_e( 'Resume Job', 'indexlane-safe-webp-queue' ); ?></button>
					<button type="button" class="button" id="ilswq-queue-cancel" <?php disabled( empty( $queue_status['can_cancel'] ) ); ?>><?php esc_html_e( 'Cancel Job', 'indexlane-safe-webp-queue' ); ?></button>
					<button type="button" class="button" id="ilswq-queue-retry" <?php disabled( empty( $queue_status['can_retry'] ) ); ?>><?php esc_html_e( 'Retry Failed', 'indexlane-safe-webp-queue' ); ?></button>
				</div>
				<p class="ilswq-muted ilswq-queue-consequence"><?php esc_html_e( 'Pausing or cancelling takes effect after the current batch. WebP files already completed remain in place.', 'indexlane-safe-webp-queue' ); ?></p>
			</section>

			<section class="ilswq-panel ilswq-report-panel">
				<div class="ilswq-toolbar">
					<div class="ilswq-actions">
						<button type="button" class="button button-primary" id="ilswq-scan"><?php esc_html_e( 'Scan Media Library', 'indexlane-safe-webp-queue' ); ?></button>
						<button type="button" class="button" id="ilswq-resume" disabled><?php esc_html_e( 'Resume Scan', 'indexlane-safe-webp-queue' ); ?></button>
						<button type="button" class="button" id="ilswq-pause" disabled><?php esc_html_e( 'Pause Scan', 'indexlane-safe-webp-queue' ); ?></button>
						<button type="button" class="button" id="ilswq-stop" disabled><?php esc_html_e( 'Stop Scan', 'indexlane-safe-webp-queue' ); ?></button>
						<button type="button" class="button" id="ilswq-convert" disabled><?php esc_html_e( 'Convert Selected', 'indexlane-safe-webp-queue' ); ?></button>
						<button type="button" class="button" id="ilswq-validate-webp" disabled><?php esc_html_e( 'Validate WebP', 'indexlane-safe-webp-queue' ); ?></button>
						<button type="button" class="button" id="ilswq-export" disabled><?php esc_html_e( 'Export CSV', 'indexlane-safe-webp-queue' ); ?></button>
						<button type="button" class="button ilswq-danger" id="ilswq-cleanup"><?php esc_html_e( 'Delete Generated WebPs', 'indexlane-safe-webp-queue' ); ?></button>
					</div>
					<div class="ilswq-counters" aria-live="polite">
						<span><strong id="ilswq-count-total">0</strong> <?php esc_html_e( 'total', 'indexlane-safe-webp-queue' ); ?></span>
						<span><strong id="ilswq-count-eligible">0</strong> <?php esc_html_e( 'eligible', 'indexlane-safe-webp-queue' ); ?></span>
						<span><strong id="ilswq-count-converted">0</strong> <?php esc_html_e( 'converted', 'indexlane-safe-webp-queue' ); ?></span>
						<span><strong id="ilswq-count-skipped">0</strong> <?php esc_html_e( 'skipped', 'indexlane-safe-webp-queue' ); ?></span>
						<span><strong id="ilswq-count-failed">0</strong> <?php esc_html_e( 'failed', 'indexlane-safe-webp-queue' ); ?></span>
						<span><strong id="ilswq-count-needs-review">0</strong> <?php esc_html_e( 'needs review', 'indexlane-safe-webp-queue' ); ?></span>
						<span><strong id="ilswq-count-conflict">0</strong> <?php esc_html_e( 'conflicts', 'indexlane-safe-webp-queue' ); ?></span>
					</div>
				</div>

				<div id="ilswq-progress" class="ilswq-progress" hidden>
					<div class="ilswq-progress-bar"><span></span></div>
					<p></p>
				</div>

				<div id="ilswq-notice" class="ilswq-notice" hidden></div>

				<div class="ilswq-filters" aria-label="<?php esc_attr_e( 'Filter scan results', 'indexlane-safe-webp-queue' ); ?>">
					<button type="button" class="button is-active" data-ilswq-filter="all"><?php esc_html_e( 'All', 'indexlane-safe-webp-queue' ); ?></button>
					<button type="button" class="button" data-ilswq-filter="eligible"><?php esc_html_e( 'Eligible', 'indexlane-safe-webp-queue' ); ?></button>
					<button type="button" class="button" data-ilswq-filter="converted"><?php esc_html_e( 'Converted', 'indexlane-safe-webp-queue' ); ?></button>
					<button type="button" class="button" data-ilswq-filter="skipped"><?php esc_html_e( 'Skipped', 'indexlane-safe-webp-queue' ); ?></button>
					<button type="button" class="button" data-ilswq-filter="failed"><?php esc_html_e( 'Failed', 'indexlane-safe-webp-queue' ); ?></button>
					<button type="button" class="button" data-ilswq-filter="needs-review"><?php esc_html_e( 'Needs review', 'indexlane-safe-webp-queue' ); ?></button>
					<button type="button" class="button" data-ilswq-filter="conflict"><?php esc_html_e( 'Conflicts', 'indexlane-safe-webp-queue' ); ?></button>
				</div>

				<div class="ilswq-table-wrap">
					<table class="widefat striped ilswq-results">
						<thead>
							<tr>
								<td class="manage-column check-column"><input type="checkbox" id="ilswq-check-all" aria-label="<?php esc_attr_e( 'Select all eligible images', 'indexlane-safe-webp-queue' ); ?>" disabled></td>
								<th><?php esc_html_e( 'Attachment', 'indexlane-safe-webp-queue' ); ?></th>
								<th><?php esc_html_e( 'File', 'indexlane-safe-webp-queue' ); ?></th>
								<th><?php esc_html_e( 'Type', 'indexlane-safe-webp-queue' ); ?></th>
								<th><?php esc_html_e( 'Dimensions', 'indexlane-safe-webp-queue' ); ?></th>
								<th><?php esc_html_e( 'Original Size', 'indexlane-safe-webp-queue' ); ?></th>
								<th><?php esc_html_e( 'Est. Memory', 'indexlane-safe-webp-queue' ); ?></th>
								<th><?php esc_html_e( 'WebP Size', 'indexlane-safe-webp-queue' ); ?></th>
								<th><?php esc_html_e( 'Savings', 'indexlane-safe-webp-queue' ); ?></th>
								<th><?php esc_html_e( 'Editor', 'indexlane-safe-webp-queue' ); ?></th>
								<th><?php esc_html_e( 'Status', 'indexlane-safe-webp-queue' ); ?></th>
								<th><?php esc_html_e( 'Reason', 'indexlane-safe-webp-queue' ); ?></th>
							</tr>
						</thead>
						<tbody id="ilswq-results-body">
							<tr class="ilswq-empty-row">
								<td colspan="12"><?php esc_html_e( 'Run a scan to build a report.', 'indexlane-safe-webp-queue' ); ?></td>
							</tr>
						</tbody>
					</table>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * Save settings via AJAX.
	 *
	 * @return void
	 */
	public function ajax_save_settings() {
		$this->verify_ajax();
		check_ajax_referer( 'ilswq_admin', 'nonce' );

		$raw_settings = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? map_deep( wp_unslash( $_POST['settings'] ), 'sanitize_text_field' ) : array();
		$settings     = ILSWQ_Settings::from_request( $raw_settings );
		$settings     = ILSWQ_Settings::save( $settings );

		wp_send_json_success(
			array(
				'settings' => $settings,
			)
		);
	}

	/**
	 * Scan page via AJAX.
	 *
	 * @return void
	 */
	public function ajax_scan() {
		$this->verify_ajax();
		check_ajax_referer( 'ilswq_admin', 'nonce' );

		$page         = isset( $_POST['page'] ) && is_scalar( $_POST['page'] ) ? absint( sanitize_text_field( wp_unslash( $_POST['page'] ) ) ) : 1;
		$raw_settings = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? map_deep( wp_unslash( $_POST['settings'] ), 'sanitize_text_field' ) : array();
		$settings     = ILSWQ_Settings::from_request( $raw_settings );

		wp_send_json_success( $this->scanner->scan_page( $page, ILSWQ_Scanner::PER_PAGE, $settings ) );
	}

	/**
	 * Convert selected IDs via AJAX.
	 *
	 * @return void
	 */
	public function ajax_convert() {
		$this->verify_ajax();
		check_ajax_referer( 'ilswq_admin', 'nonce' );
		$this->verify_queue_idle_for_file_mutation();

		$raw_ids = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? map_deep( wp_unslash( $_POST['ids'] ), 'sanitize_text_field' ) : array();
		$ids     = array();
		foreach ( $raw_ids as $raw_id ) {
			if ( is_scalar( $raw_id ) ) {
				$ids[] = absint( $raw_id );
			}
		}
		$ids = array_filter( $ids );

		$raw_settings = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? map_deep( wp_unslash( $_POST['settings'] ), 'sanitize_text_field' ) : array();
		$settings     = ILSWQ_Settings::from_request( $raw_settings );
		$ids          = array_slice( $ids, 0, (int) $settings['batch_size'] );

		wp_send_json_success(
			array(
				'rows' => $this->converter->convert_batch( $ids, $settings ),
			)
		);
	}

	/**
	 * Start a persistent conversion job via AJAX.
	 *
	 * @return void
	 */
	public function ajax_queue_start() {
		$this->verify_ajax();
		check_ajax_referer( 'ilswq_admin', 'nonce' );

		$ids_json = isset( $_POST['ids'] ) && is_scalar( $_POST['ids'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['ids'] ) ) : '[]';
		$raw_ids  = json_decode( (string) $ids_json, true );
		$raw_ids  = is_array( $raw_ids ) ? $raw_ids : array();

		$raw_settings = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? map_deep( wp_unslash( $_POST['settings'] ), 'sanitize_text_field' ) : array();
		$settings     = ILSWQ_Settings::from_request( $raw_settings );
		$result       = $this->queue->start_job( $raw_ids, $settings );

		$this->send_queue_result( $result );
	}

	/**
	 * Return current persistent queue status via AJAX.
	 *
	 * @return void
	 */
	public function ajax_queue_status() {
		$this->verify_ajax();
		check_ajax_referer( 'ilswq_admin', 'nonce' );

		wp_send_json_success(
			array(
				'queue' => $this->queue->get_public_status(),
			)
		);
	}

	/**
	 * Let the open admin page safely process one queue batch.
	 *
	 * @return void
	 */
	public function ajax_queue_process() {
		$this->verify_ajax();
		check_ajax_referer( 'ilswq_admin', 'nonce' );

		wp_send_json_success( $this->queue->process_next_batch() );
	}

	/**
	 * Apply a pause, resume, cancel, or retry command to the queue.
	 *
	 * @return void
	 */
	public function ajax_queue_command() {
		$this->verify_ajax();
		check_ajax_referer( 'ilswq_admin', 'nonce' );

		$command = isset( $_POST['command'] ) && is_scalar( $_POST['command'] ) ? sanitize_key( wp_unslash( $_POST['command'] ) ) : '';
		switch ( $command ) {
			case 'pause':
				$result = $this->queue->pause_job();
				break;
			case 'resume':
				$result = $this->queue->resume_job();
				break;
			case 'cancel':
				$result = $this->queue->cancel_job();
				break;
			case 'retry':
				$result = $this->queue->retry_failed_job();
				break;
			default:
				$result = new WP_Error( 'ilswq_queue_invalid_command', __( 'Unknown conversion job command.', 'indexlane-safe-webp-queue' ) );
				break;
		}

		$this->send_queue_result( $result );
	}

	/**
	 * Cleanup generated WebP files via AJAX.
	 *
	 * @return void
	 */
	public function ajax_cleanup() {
		$this->verify_ajax();
		check_ajax_referer( 'ilswq_admin', 'nonce' );
		$this->verify_queue_idle_for_file_mutation();

		$reset = isset( $_POST['reset'] ) && is_scalar( $_POST['reset'] ) ? absint( sanitize_text_field( wp_unslash( $_POST['reset'] ) ) ) : 0;
		if ( $reset > 0 ) {
			delete_option( ILSWQ_OPTION_CLEANUP_PAGE );
		}

		wp_send_json_success( $this->converter->cleanup_generated( 10 ) );
	}

	/**
	 * Validate one generated WebP attachment map.
	 *
	 * @return void
	 */
	public function ajax_validate_webp() {
		$this->verify_ajax();
		check_ajax_referer( 'ilswq_admin', 'nonce' );

		$raw_ids = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? map_deep( wp_unslash( $_POST['ids'] ), 'sanitize_text_field' ) : array();
		$ids     = array();
		foreach ( $raw_ids as $raw_id ) {
			if ( is_scalar( $raw_id ) ) {
				$attachment_id = absint( $raw_id );
				if ( $attachment_id > 0 ) {
					$ids[] = $attachment_id;
				}
			}
		}

		$ids = array_slice( array_values( array_unique( $ids ) ), 0, 10 );
		if ( ! empty( $ids ) ) {
			$validated = 0;
			$invalid   = 0;
			$missing   = 0;

			foreach ( $ids as $attachment_id ) {
				$result = ILSWQ_Scanner::validate_generated_files( $attachment_id );
				if ( empty( $result['has_files'] ) ) {
					++$missing;
					continue;
				}

				$validated += max( 0, (int) $result['total'] - (int) $result['invalid'] );
				$invalid   += (int) $result['invalid'];
			}

			wp_send_json_success(
				array(
					'validated' => $validated,
					'invalid'   => $invalid,
					'missing'   => $missing,
				)
			);
		}

		$attachment_id = isset( $_POST['id'] ) && is_scalar( $_POST['id'] ) ? absint( sanitize_text_field( wp_unslash( $_POST['id'] ) ) ) : 0;
		if ( $attachment_id <= 0 ) {
			wp_send_json_error(
				array(
					'message' => __( 'No attachment selected for WebP validation.', 'indexlane-safe-webp-queue' ),
				),
				400
			);
		}

		$result = ILSWQ_Scanner::validate_generated_files( $attachment_id );
		if ( empty( $result['has_files'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No generated WebP files were found for this attachment.', 'indexlane-safe-webp-queue' ),
				),
				404
			);
		}

		if ( ! empty( $result['valid'] ) ) {
			wp_send_json_success(
				array(
					'message' => __( 'All generated WebP files for this attachment exist and are valid.', 'indexlane-safe-webp-queue' ),
				)
			);
		}

		wp_send_json_error(
			array(
				'message' => sprintf(
					/* translators: 1: invalid file count, 2: stored file count. */
					__( '%1$d of %2$d generated WebP files are missing or invalid.', 'indexlane-safe-webp-queue' ),
					(int) $result['invalid'],
					(int) $result['total']
				),
			),
			500
		);
	}

	/**
	 * Reconcile generated sizes and optionally queue automatic conversion.
	 *
	 * @param array<string,mixed> $metadata Metadata.
	 * @param int                 $attachment_id Attachment ID.
	 * @return array<string,mixed>
	 */
	public function maybe_queue_attachment_metadata( $metadata, $attachment_id ) {
		if ( empty( $metadata ) || ! is_array( $metadata ) ) {
			return $metadata;
		}

		try {
			$reconciled = $this->converter->reconcile_generated_for_metadata( (int) $attachment_id, $metadata );
			if ( ! empty( $reconciled['failed'] ) ) {
				update_post_meta( (int) $attachment_id, ILSWQ_META_LAST_ERROR, __( 'One or more obsolete generated WebP files could not be removed.', 'indexlane-safe-webp-queue' ) );
			}

			$settings = ILSWQ_Settings::get();
			if ( ! empty( $settings['auto_uploads'] ) ) {
				$queued = $this->queue->enqueue_upload( (int) $attachment_id, $settings );
				if ( is_wp_error( $queued ) ) {
					update_post_meta( (int) $attachment_id, ILSWQ_META_LAST_ERROR, $queued->get_error_message() );
				}
			}
		} catch ( Throwable $exception ) {
			update_post_meta( (int) $attachment_id, ILSWQ_META_LAST_ERROR, __( 'Attachment WebP reconciliation failed unexpectedly.', 'indexlane-safe-webp-queue' ) );
		}

		return $metadata;
	}

	/**
	 * Delete plugin-owned sidecars before WordPress removes attachment metadata.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function cleanup_deleted_attachment( $attachment_id ) {
		$this->queue->remove_attachment( (int) $attachment_id );
		$result = $this->converter->delete_generated_for_attachment( (int) $attachment_id, true );
		if ( ! empty( $result['failed'] ) ) {
			$this->queue->ensure_scheduled();
		}
	}

	/**
	 * Send a consistent AJAX response for queue commands.
	 *
	 * @param array<string, mixed>|WP_Error $result Queue result.
	 * @return void
	 */
	private function send_queue_result( $result ) {
		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
				),
				409
			);
		}

		wp_send_json_success(
			array(
				'queue' => $result,
			)
		);
	}

	/**
	 * Prevent legacy conversion or cleanup from racing persistent workers.
	 *
	 * @return void
	 */
	private function verify_queue_idle_for_file_mutation() {
		if ( ! $this->queue->has_active_job() && ! $this->queue->has_automatic_work() ) {
			return;
		}

		wp_send_json_error(
			array(
				'message' => __( 'Pause or finish queued conversion work before deleting or directly converting generated files.', 'indexlane-safe-webp-queue' ),
			),
			409
		);
	}

	/**
	 * Verify AJAX request.
	 *
	 * @return void
	 */
	private function verify_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Permission denied.', 'indexlane-safe-webp-queue' ),
				),
				403
			);
		}

	}
}

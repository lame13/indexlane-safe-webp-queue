<?php
/**
 * Persistent conversion queue.
 *
 * @package IndexLaneSafeWebPQueue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates resumable manual jobs and automatic upload work.
 */
class ILSWQ_Queue {
	const JOB_OPTION       = 'ilswq_queue_job';
	const AUTO_OPTION      = 'ilswq_auto_queue';
	const LOCK_OPTION      = 'ilswq_queue_lock';
	const CRON_HOOK        = 'ilswq_process_queue';
	const MAX_ATTACHMENTS  = 10000;
	const MAX_AUTO_ITEMS   = 1000;
	const MAX_AUTO_RETRIES = 3;
	const ERROR_LIMIT      = 20;
	const LOCK_TTL         = 900;

	/**
	 * Converter instance.
	 *
	 * @var ILSWQ_Converter
	 */
	private $converter;

	/**
	 * Constructor.
	 *
	 * @param ILSWQ_Converter $converter Converter.
	 */
	public function __construct( ILSWQ_Converter $converter ) {
		$this->converter = $converter;
	}

	/**
	 * Start a manual conversion job.
	 *
	 * @param array<int, int>    $ids Attachment IDs.
	 * @param array<string, int> $settings Settings snapshot.
	 * @return array<string, mixed>|WP_Error
	 */
	public function start_job( $ids, $settings ) {
		return $this->create_job( $ids, $settings, 'manual' );
	}

	/**
	 * Pause the active manual job after the current batch.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function pause_job() {
		$job = $this->get_job();
		if ( empty( $job ) || ! in_array( $this->job_state( $job ), array( 'queued', 'running' ), true ) ) {
			return new WP_Error( 'ilswq_queue_not_running', __( 'There is no running conversion job to pause.', 'indexlane-safe-webp-queue' ) );
		}

		$job['state']         = 'paused';
		$job['last_activity'] = time();
		$this->save_job( $job );
		$this->ensure_scheduled();

		return $this->get_public_status();
	}

	/**
	 * Resume a paused manual job.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function resume_job() {
		$job = $this->get_job();
		if ( empty( $job ) || 'paused' !== $this->job_state( $job ) ) {
			return new WP_Error( 'ilswq_queue_not_paused', __( 'There is no paused conversion job to resume.', 'indexlane-safe-webp-queue' ) );
		}

		$job['state']         = 'queued';
		$job['last_activity'] = time();
		$this->save_job( $job );
		$this->ensure_scheduled();

		return $this->get_public_status();
	}

	/**
	 * Cancel pending work without deleting completed WebP files.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function cancel_job() {
		$job = $this->get_job();
		if ( empty( $job ) || ! in_array( $this->job_state( $job ), array( 'queued', 'running', 'paused' ), true ) ) {
			return new WP_Error( 'ilswq_queue_not_active', __( 'There is no active conversion job to cancel.', 'indexlane-safe-webp-queue' ) );
		}

		$job['state']         = 'cancelled';
		$job['cancelled_at']  = time();
		$job['last_activity'] = time();
		$this->save_job( $job );
		$this->ensure_scheduled();

		return $this->get_public_status();
	}

	/**
	 * Start a new job containing only the failures from the last job.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function retry_failed_job() {
		$job = $this->get_job();
		if ( empty( $job ) || ! in_array( $this->job_state( $job ), array( 'completed', 'cancelled' ), true ) ) {
			return new WP_Error( 'ilswq_queue_not_finished', __( 'Finish or cancel the current conversion job before retrying failures.', 'indexlane-safe-webp-queue' ) );
		}

		$failed_ids = isset( $job['failure_ids'] ) && is_array( $job['failure_ids'] ) ? $job['failure_ids'] : array();
		if ( empty( $failed_ids ) ) {
			return new WP_Error( 'ilswq_queue_no_failures', __( 'The last conversion job has no failed attachments to retry.', 'indexlane-safe-webp-queue' ) );
		}

		$settings = isset( $job['settings'] ) && is_array( $job['settings'] ) ? $job['settings'] : ILSWQ_Settings::get();

		return $this->create_job( $failed_ids, $settings, 'retry' );
	}

	/**
	 * Add one attachment to the automatic upload queue.
	 *
	 * @param int                $attachment_id Attachment ID.
	 * @param array<string, int> $settings Settings snapshot.
	 * @return bool|WP_Error
	 */
	public function enqueue_upload( $attachment_id, $settings ) {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 ) {
			return new WP_Error( 'ilswq_auto_invalid_id', __( 'The uploaded attachment could not be queued.', 'indexlane-safe-webp-queue' ) );
		}

		$queue = $this->get_auto_queue();
		if ( ! isset( $queue[ $attachment_id ] ) && count( $queue ) >= self::MAX_AUTO_ITEMS ) {
			return new WP_Error( 'ilswq_auto_queue_full', __( 'The automatic upload queue is full. Open the plugin page to let queued work finish.', 'indexlane-safe-webp-queue' ) );
		}

		$queue[ $attachment_id ] = array(
			'id'           => $attachment_id,
			'token'        => wp_generate_uuid4(),
			'state'        => 'queued',
			'settings'     => ILSWQ_Settings::sanitize( $settings ),
			'attempts'     => 0,
			'available_at' => time() + 1,
			'queued_at'    => time(),
		);
		$this->save_auto_queue( $queue );
		$this->ensure_scheduled();

		return true;
	}

	/**
	 * Process one bounded unit of queue work.
	 *
	 * @return array<string, mixed>
	 */
	public function process_next_batch() {
		$token = $this->acquire_lock();
		if ( false === $token ) {
			return array(
				'busy'  => true,
				'rows'  => array(),
				'queue' => $this->get_public_status(),
			);
		}

		$rows  = array();
		$error = '';

		try {
			$job = $this->get_job();
			if ( $this->job_is_runnable( $job ) ) {
				$rows = $this->process_manual_job( $job );
			} elseif ( $this->has_due_auto_item() ) {
				$rows = $this->process_auto_items();
			} elseif ( $this->has_due_orphan_item() ) {
				$this->converter->cleanup_orphaned_generated( 10 );
			}
		} catch ( Throwable $exception ) {
			$error = __( 'The queue stopped after an unexpected error. Review the job and resume it to retry the current batch.', 'indexlane-safe-webp-queue' );
			$this->pause_after_unexpected_error( $error );
		} finally {
			$this->release_lock( $token );
		}

		$this->ensure_scheduled();

		return array(
			'busy'  => false,
			'error' => $error,
			'rows'  => $rows,
			'queue' => $this->get_public_status(),
		);
	}

	/**
	 * Run one scheduled batch.
	 *
	 * @return void
	 */
	public function process_scheduled() {
		$this->process_next_batch();
	}

	/**
	 * Ensure that pending background work has a cron event.
	 *
	 * @return void
	 */
	public function ensure_scheduled() {
		$delay = $this->next_work_delay();
		if ( null === $delay ) {
			return;
		}

		$desired   = time() + max( 1, $delay );
		$scheduled = wp_next_scheduled( self::CRON_HOOK );

		if ( false === $scheduled ) {
			wp_schedule_single_event( $desired, self::CRON_HOOK );
			return;
		}

		if ( $scheduled > $desired + 5 ) {
			wp_unschedule_event( $scheduled, self::CRON_HOOK );
			wp_schedule_single_event( $desired, self::CRON_HOOK );
		}
	}

	/**
	 * Return queue state safe for the administrator interface.
	 *
	 * @return array<string, mixed>
	 */
	public function get_public_status() {
		$job              = $this->get_job();
		$automatic_counts = $this->automatic_counts();
		$cleanup_pending  = $this->orphan_count();

		if ( empty( $job ) ) {
			return array(
				'exists'              => false,
				'state'               => 'none',
				'state_label'         => __( 'Not started', 'indexlane-safe-webp-queue' ),
				'summary'             => __( 'No conversion job has been started.', 'indexlane-safe-webp-queue' ),
				'settings_summary'    => '',
				'last_activity_label' => '',
				'last_error'          => '',
				'total'               => 0,
				'processed'           => 0,
				'pending'             => 0,
				'converted'           => 0,
				'skipped'             => 0,
				'conflicts'           => 0,
				'failed'              => 0,
				'progress'            => 0,
				'automatic_pending'   => $automatic_counts['pending'],
				'automatic_failed'    => $automatic_counts['failed'],
				'cleanup_pending'     => $cleanup_pending,
				'can_pause'           => false,
				'can_resume'          => false,
				'can_cancel'          => false,
				'can_retry'           => false,
				'has_runnable_work'   => $this->has_due_auto_item() || $this->has_due_orphan_item(),
			);
		}

		$total     = isset( $job['total'] ) ? max( 0, (int) $job['total'] ) : 0;
		$processed = isset( $job['processed'] ) ? max( 0, (int) $job['processed'] ) : 0;
		$state     = $this->job_state( $job );
		$settings  = isset( $job['settings'] ) && is_array( $job['settings'] ) ? ILSWQ_Settings::sanitize( $job['settings'] ) : ILSWQ_Settings::defaults();
		$errors    = isset( $job['recent_errors'] ) && is_array( $job['recent_errors'] ) ? $job['recent_errors'] : array();
		$last      = ! empty( $errors ) ? end( $errors ) : array();

		return array(
			'exists'              => true,
			'id'                  => isset( $job['id'] ) ? sanitize_text_field( (string) $job['id'] ) : '',
			'state'               => $state,
			'state_label'         => $this->state_label( $state ),
			'summary'             => sprintf(
				/* translators: 1: processed attachments, 2: total attachments, 3: converted, 4: skipped, 5: conflicts, 6: failed. */
				__( '%1$d of %2$d attachments processed. Converted: %3$d. Skipped: %4$d. Conflicts: %5$d. Failed: %6$d.', 'indexlane-safe-webp-queue' ),
				$processed,
				$total,
				isset( $job['converted'] ) ? (int) $job['converted'] : 0,
				isset( $job['skipped'] ) ? (int) $job['skipped'] : 0,
				isset( $job['conflicts'] ) ? (int) $job['conflicts'] : 0,
				isset( $job['failed'] ) ? (int) $job['failed'] : 0
			),
			'settings_summary'    => sprintf(
				/* translators: 1: batch size, 2: JPEG quality, 3: PNG quality. */
				__( 'Settings snapshot: batch %1$d, JPEG quality %2$d, PNG quality %3$d.', 'indexlane-safe-webp-queue' ),
				(int) $settings['batch_size'],
				(int) $settings['jpeg_quality'],
				(int) $settings['png_quality']
			),
			'last_activity_label' => ! empty( $job['last_activity'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $job['last_activity'] ) : '',
			'last_error'          => is_array( $last ) && ! empty( $last['message'] ) ? sanitize_text_field( (string) $last['message'] ) : '',
			'total'               => $total,
			'processed'           => $processed,
			'pending'             => max( 0, $total - $processed ),
			'converted'           => isset( $job['converted'] ) ? max( 0, (int) $job['converted'] ) : 0,
			'skipped'             => isset( $job['skipped'] ) ? max( 0, (int) $job['skipped'] ) : 0,
			'conflicts'           => isset( $job['conflicts'] ) ? max( 0, (int) $job['conflicts'] ) : 0,
			'failed'              => isset( $job['failed'] ) ? max( 0, (int) $job['failed'] ) : 0,
			'progress'            => $total > 0 ? min( 100, (int) round( ( $processed / $total ) * 100 ) ) : 0,
			'automatic_pending'   => $automatic_counts['pending'],
			'automatic_failed'    => $automatic_counts['failed'],
			'cleanup_pending'     => $cleanup_pending,
			'can_pause'           => in_array( $state, array( 'queued', 'running' ), true ),
			'can_resume'          => 'paused' === $state,
			'can_cancel'          => in_array( $state, array( 'queued', 'running', 'paused' ), true ),
			'can_retry'           => in_array( $state, array( 'completed', 'cancelled' ), true ) && ! empty( $job['failure_ids'] ),
			'has_runnable_work'   => $this->job_is_runnable( $job ) || $this->has_due_auto_item() || $this->has_due_orphan_item(),
		);
	}

	/**
	 * Return whether a manual conversion job can still mutate files.
	 *
	 * @return bool
	 */
	public function has_active_job() {
		return $this->job_is_active( $this->get_job() );
	}

	/**
	 * Return whether automatic upload conversions are pending.
	 *
	 * @return bool
	 */
	public function has_automatic_work() {
		$counts = $this->automatic_counts();

		return $counts['pending'] > 0;
	}

	/**
	 * Remove automatic work for an attachment that WordPress is deleting.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function remove_attachment( $attachment_id ) {
		$this->remove_automatic_items( array( absint( $attachment_id ) ) );
	}

	/**
	 * Give preserved queue state a worker after plugin reactivation.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( false === wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 1, self::CRON_HOOK );
		}
	}

	/**
	 * Remove volatile scheduling state when the plugin is deactivated.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Create and persist a bounded job.
	 *
	 * @param array<int, int>    $ids Attachment IDs.
	 * @param array<string, int> $settings Settings snapshot.
	 * @param string             $origin Job origin.
	 * @return array<string, mixed>|WP_Error
	 */
	private function create_job( $ids, $settings, $origin ) {
		$current = $this->get_job();
		if ( $this->job_is_active( $current ) ) {
			return new WP_Error( 'ilswq_queue_active', __( 'Finish or cancel the current conversion job before starting another one.', 'indexlane-safe-webp-queue' ) );
		}

		if ( $this->lock_is_active() ) {
			return new WP_Error( 'ilswq_queue_busy', __( 'The previous conversion batch is still finishing. Try again in a moment.', 'indexlane-safe-webp-queue' ) );
		}

		$normalized = array();
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			if ( is_scalar( $id ) ) {
				$attachment_id = absint( $id );
				if ( $attachment_id > 0 ) {
					$normalized[ $attachment_id ] = $attachment_id;
				}
			}
		}

		$normalized = array_values( $normalized );
		if ( empty( $normalized ) ) {
			return new WP_Error( 'ilswq_queue_empty', __( 'Select at least one eligible attachment before starting a conversion job.', 'indexlane-safe-webp-queue' ) );
		}

		if ( count( $normalized ) > self::MAX_ATTACHMENTS ) {
			return new WP_Error(
				'ilswq_queue_too_large',
				sprintf(
					/* translators: %d: maximum attachment count. */
					__( 'A single conversion job can contain at most %d attachments.', 'indexlane-safe-webp-queue' ),
					self::MAX_ATTACHMENTS
				)
			);
		}

		$now = time();
		$job = array(
			'id'              => wp_generate_uuid4(),
			'origin'          => sanitize_key( $origin ),
			'state'           => 'queued',
			'attachment_ids'  => $normalized,
			'cursor'          => 0,
			'in_progress'     => array(),
			'total'           => count( $normalized ),
			'processed'       => 0,
			'converted'       => 0,
			'skipped'         => 0,
			'conflicts'       => 0,
			'failed'          => 0,
			'failure_ids'     => array(),
			'recent_errors'   => array(),
			'settings'        => ILSWQ_Settings::sanitize( $settings ),
			'created_at'      => $now,
			'started_at'      => 0,
			'last_activity'   => $now,
			'completed_at'    => 0,
			'cancelled_at'    => 0,
		);

		$this->save_job( $job );
		$this->remove_automatic_items( $normalized );
		$this->ensure_scheduled();

		return $this->get_public_status();
	}

	/**
	 * Process one manual job batch.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return array<int, array<string, mixed>>
	 */
	private function process_manual_job( $job ) {
		$ids      = isset( $job['attachment_ids'] ) && is_array( $job['attachment_ids'] ) ? $job['attachment_ids'] : array();
		$settings = isset( $job['settings'] ) && is_array( $job['settings'] ) ? ILSWQ_Settings::sanitize( $job['settings'] ) : ILSWQ_Settings::get();
		$batch    = isset( $job['in_progress'] ) && is_array( $job['in_progress'] ) ? array_values( array_filter( array_map( 'absint', $job['in_progress'] ) ) ) : array();

		if ( empty( $batch ) ) {
			$cursor           = isset( $job['cursor'] ) ? max( 0, (int) $job['cursor'] ) : 0;
			$batch            = array_slice( $ids, $cursor, (int) $settings['batch_size'] );
			$job['cursor']    = $cursor + count( $batch );
			$job['in_progress'] = $batch;
		}

		if ( empty( $batch ) ) {
			$job['state']         = 'completed';
			$job['completed_at']  = time();
			$job['last_activity'] = time();
			$this->save_job( $job );
			return array();
		}

		$job['state'] = 'running';
		if ( empty( $job['started_at'] ) ) {
			$job['started_at'] = time();
		}
		$job['last_activity'] = time();
		$this->save_job( $job );

		$rows = array();
		foreach ( $batch as $attachment_id ) {
			try {
				$row    = $this->converter->convert_attachment( (int) $attachment_id, $settings );
				$rows[] = $row;
				$this->record_manual_result( $job, (int) $attachment_id, $row );
			} catch ( Throwable $exception ) {
				$this->record_manual_failure(
					$job,
					(int) $attachment_id,
					__( 'Unexpected conversion error.', 'indexlane-safe-webp-queue' )
				);
			}
			++$job['processed'];
		}

		$latest = $this->get_job();
		if ( empty( $latest['id'] ) || empty( $job['id'] ) || (string) $latest['id'] !== (string) $job['id'] ) {
			return $rows;
		}

		foreach ( array( 'cursor', 'processed', 'converted', 'skipped', 'conflicts', 'failed', 'failure_ids', 'recent_errors', 'started_at' ) as $field ) {
			$latest[ $field ] = $job[ $field ];
		}
		$latest['in_progress']   = array();
		$latest['last_activity'] = time();

		if ( 'cancelled' === $this->job_state( $latest ) ) {
			// Cancellation stops before the next batch; completed output remains in place.
		} elseif ( 'paused' === $this->job_state( $latest ) ) {
			// A pause requested during processing takes effect after this batch.
		} elseif ( (int) $latest['cursor'] >= (int) $latest['total'] ) {
			$latest['state']        = 'completed';
			$latest['completed_at'] = time();
		} else {
			$latest['state'] = 'queued';
		}

		$this->save_job( $latest );

		return $rows;
	}

	/**
	 * Record a manual conversion result.
	 *
	 * @param array<string, mixed> $job Job, by reference.
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $row Result row.
	 * @return void
	 */
	private function record_manual_result( &$job, $attachment_id, $row ) {
		$status = isset( $row['status_key'] ) ? sanitize_key( (string) $row['status_key'] ) : 'failed';
		$reason = isset( $row['reason'] ) ? sanitize_text_field( (string) $row['reason'] ) : '';

		if ( 'converted' === $status ) {
			++$job['converted'];
			delete_post_meta( $attachment_id, ILSWQ_META_LAST_ERROR );
			return;
		}

		if ( in_array( $status, array( 'skipped', 'already-exists' ), true ) ) {
			++$job['skipped'];
			delete_post_meta( $attachment_id, ILSWQ_META_LAST_ERROR );
			return;
		}

		if ( 'conflict' === $status ) {
			++$job['conflicts'];
			$this->append_error( $job, $attachment_id, $reason );
			update_post_meta( $attachment_id, ILSWQ_META_LAST_ERROR, $reason );
			return;
		}

		$this->record_manual_failure(
			$job,
			$attachment_id,
			'' !== $reason ? $reason : __( 'Conversion did not complete.', 'indexlane-safe-webp-queue' )
		);
	}

	/**
	 * Record a retryable manual failure.
	 *
	 * @param array<string, mixed> $job Job, by reference.
	 * @param int                  $attachment_id Attachment ID.
	 * @param string               $message Error message.
	 * @return void
	 */
	private function record_manual_failure( &$job, $attachment_id, $message ) {
		++$job['failed'];
		$job['failure_ids'][] = absint( $attachment_id );
		$job['failure_ids']   = array_values( array_unique( array_filter( array_map( 'absint', $job['failure_ids'] ) ) ) );
		$this->append_error( $job, $attachment_id, $message );
		update_post_meta( $attachment_id, ILSWQ_META_LAST_ERROR, sanitize_text_field( $message ) );
	}

	/**
	 * Append a bounded error record to a job.
	 *
	 * @param array<string, mixed> $job Job, by reference.
	 * @param int                  $attachment_id Attachment ID.
	 * @param string               $message Message.
	 * @return void
	 */
	private function append_error( &$job, $attachment_id, $message ) {
		$message = sanitize_text_field( $message );
		if ( '' === $message ) {
			$message = __( 'Conversion did not complete.', 'indexlane-safe-webp-queue' );
		}

		$job['recent_errors'][] = array(
			'attachment_id' => absint( $attachment_id ),
			'message'       => $message,
			'time'          => time(),
		);
		$job['recent_errors']   = array_slice( $job['recent_errors'], -self::ERROR_LIMIT );
	}

	/**
	 * Process due automatic upload items.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function process_auto_items() {
		$queue          = $this->get_auto_queue();
		$original_queue = $queue;
		$rows           = array();
		$now            = time();
		$count          = 0;

		foreach ( $queue as $key => $item ) {
			if ( $count >= 3 ) {
				continue;
			}
			if ( ! is_array( $item ) ) {
				unset( $queue[ $key ] );
				++$count;
				continue;
			}
			if ( $this->automatic_item_failed( $item ) ) {
				continue;
			}
			if ( isset( $item['available_at'] ) && (int) $item['available_at'] > $now ) {
				continue;
			}

			$attachment_id = isset( $item['id'] ) ? absint( $item['id'] ) : absint( $key );
			$settings      = isset( $item['settings'] ) && is_array( $item['settings'] ) ? ILSWQ_Settings::sanitize( $item['settings'] ) : ILSWQ_Settings::get();
			$status        = 'failed';
			$reason        = __( 'Unexpected conversion error.', 'indexlane-safe-webp-queue' );

			try {
				$row    = $this->converter->convert_attachment( $attachment_id, $settings );
				$rows[] = $row;
				$status = isset( $row['status_key'] ) ? sanitize_key( (string) $row['status_key'] ) : 'failed';
				$reason = isset( $row['reason'] ) ? sanitize_text_field( (string) $row['reason'] ) : '';
			} catch ( Throwable $exception ) {
				// The generic message avoids exposing filesystem details in stored admin output.
			}

			if ( in_array( $status, array( 'converted', 'skipped', 'already-exists' ), true ) ) {
				unset( $queue[ $key ] );
				delete_post_meta( $attachment_id, ILSWQ_META_LAST_ERROR );
			} elseif ( 'conflict' === $status ) {
				$item['state']      = 'failed';
				$item['attempts']   = self::MAX_AUTO_RETRIES;
				$item['failed_at']  = $now;
				$item['last_error'] = $reason;
				unset( $item['available_at'] );
				$queue[ $key ] = $item;
				update_post_meta( $attachment_id, ILSWQ_META_LAST_ERROR, $reason );
			} else {
				$item['attempts'] = isset( $item['attempts'] ) ? (int) $item['attempts'] + 1 : 1;
				update_post_meta( $attachment_id, ILSWQ_META_LAST_ERROR, $reason );

				if ( (int) $item['attempts'] >= self::MAX_AUTO_RETRIES ) {
					$item['state']      = 'failed';
					$item['failed_at']  = $now;
					$item['last_error'] = $reason;
					unset( $item['available_at'] );
					$queue[ $key ] = $item;
				} else {
					$item['state']        = 'queued';
					$item['available_at'] = $now + min( 900, 60 * (int) pow( 5, max( 0, (int) $item['attempts'] - 1 ) ) );
					$queue[ $key ]        = $item;
				}
			}

			++$count;
		}

		$latest_queue = $this->get_auto_queue();
		foreach ( $original_queue as $key => $original_item ) {
			if ( ! array_key_exists( $key, $latest_queue ) ) {
				unset( $queue[ $key ] );
			}
		}

		foreach ( $latest_queue as $key => $latest_item ) {
			$was_present = array_key_exists( $key, $original_queue );
			$is_newer    = $was_present && is_array( $original_queue[ $key ] ) && is_array( $latest_item ) && isset( $latest_item['token'] ) && ( ! isset( $original_queue[ $key ]['token'] ) || (string) $latest_item['token'] !== (string) $original_queue[ $key ]['token'] );

			if ( ! $was_present || $is_newer ) {
				$queue[ $key ] = $latest_item;
			}
		}

		$this->save_auto_queue( $queue );

		return $rows;
	}

	/**
	 * Pause an active job after an unexpected queue-level error.
	 *
	 * @param string $message Safe error message.
	 * @return void
	 */
	private function pause_after_unexpected_error( $message ) {
		$job = $this->get_job();
		if ( empty( $job ) || ! $this->job_is_runnable( $job ) ) {
			return;
		}

		$job['state']         = 'paused';
		$job['last_activity'] = time();
		$this->append_error( $job, 0, $message );
		$this->save_job( $job );
	}

	/**
	 * Return the number of remembered orphan cleanup paths.
	 *
	 * @return int
	 */
	private function orphan_count() {
		$status = $this->converter->orphan_cleanup_status();

		return isset( $status['count'] ) ? max( 0, (int) $status['count'] ) : 0;
	}

	/**
	 * Return whether a remembered orphan cleanup is ready now.
	 *
	 * @return bool
	 */
	private function has_due_orphan_item() {
		$status = $this->converter->orphan_cleanup_status();

		return ! empty( $status['due'] );
	}

	/**
	 * Return delay until the next unit of work, or null when idle.
	 *
	 * @return int|null
	 */
	private function next_work_delay() {
		$job = $this->get_job();
		if ( $this->job_is_runnable( $job ) ) {
			return 1;
		}

		$next = null;
		foreach ( $this->get_auto_queue() as $item ) {
			if ( ! is_array( $item ) || $this->automatic_item_failed( $item ) ) {
				continue;
			}

			$available = isset( $item['available_at'] ) ? (int) $item['available_at'] : time();
			$delay     = max( 1, $available - time() );
			$next      = null === $next ? $delay : min( $next, $delay );
		}

		$orphan_status = $this->converter->orphan_cleanup_status();
		if ( ! empty( $orphan_status['count'] ) ) {
			$next_at      = isset( $orphan_status['next_at'] ) ? (int) $orphan_status['next_at'] : time();
			$orphan_delay = max( 1, $next_at - time() );
			$next         = null === $next ? $orphan_delay : min( $orphan_delay, $next );
		}

		return $next;
	}

	/**
	 * Return whether an automatic queue item is ready now.
	 *
	 * @return bool
	 */
	private function has_due_auto_item() {
		$now = time();
		foreach ( $this->get_auto_queue() as $item ) {
			if ( is_array( $item ) && $this->automatic_item_failed( $item ) ) {
				continue;
			}
			if ( ! is_array( $item ) || ! isset( $item['available_at'] ) || (int) $item['available_at'] <= $now ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return true for an active manual job.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return bool
	 */
	private function job_is_active( $job ) {
		return ! empty( $job ) && in_array( $this->job_state( $job ), array( 'queued', 'running', 'paused' ), true );
	}

	/**
	 * Return true for a manual job the worker may process.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return bool
	 */
	private function job_is_runnable( $job ) {
		return ! empty( $job ) && in_array( $this->job_state( $job ), array( 'queued', 'running' ), true );
	}

	/**
	 * Return a normalized job state.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return string
	 */
	private function job_state( $job ) {
		$state = isset( $job['state'] ) ? sanitize_key( (string) $job['state'] ) : 'none';

		return in_array( $state, array( 'queued', 'running', 'paused', 'completed', 'cancelled' ), true ) ? $state : 'none';
	}

	/**
	 * Return the translated state label.
	 *
	 * @param string $state State key.
	 * @return string
	 */
	private function state_label( $state ) {
		switch ( $state ) {
			case 'queued':
				return __( 'Queued', 'indexlane-safe-webp-queue' );
			case 'running':
				return __( 'Running', 'indexlane-safe-webp-queue' );
			case 'paused':
				return __( 'Paused', 'indexlane-safe-webp-queue' );
			case 'completed':
				return __( 'Completed', 'indexlane-safe-webp-queue' );
			case 'cancelled':
				return __( 'Cancelled', 'indexlane-safe-webp-queue' );
			default:
				return __( 'Not started', 'indexlane-safe-webp-queue' );
		}
	}

	/**
	 * Read the stored manual job.
	 *
	 * @return array<string, mixed>
	 */
	private function get_job() {
		$job = get_option( self::JOB_OPTION, array() );

		return is_array( $job ) ? $job : array();
	}

	/**
	 * Persist a manual job without autoloading it.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return void
	 */
	private function save_job( $job ) {
		update_option( self::JOB_OPTION, $job, false );
	}

	/**
	 * Read automatic upload queue items.
	 *
	 * @return array<int|string, array<string, mixed>>
	 */
	private function get_auto_queue() {
		$queue = get_option( self::AUTO_OPTION, array() );

		return is_array( $queue ) ? $queue : array();
	}

	/**
	 * Count runnable and exhausted automatic upload items.
	 *
	 * @return array<string, int>
	 */
	private function automatic_counts() {
		$counts = array(
			'pending' => 0,
			'failed'  => 0,
		);

		foreach ( $this->get_auto_queue() as $item ) {
			if ( is_array( $item ) && $this->automatic_item_failed( $item ) ) {
				++$counts['failed'];
			} else {
				++$counts['pending'];
			}
		}

		return $counts;
	}

	/**
	 * Return whether an automatic item has exhausted its retries.
	 *
	 * @param array<string, mixed> $item Automatic queue item.
	 * @return bool
	 */
	private function automatic_item_failed( $item ) {
		return isset( $item['state'] ) && 'failed' === sanitize_key( (string) $item['state'] );
	}

	/**
	 * Remove automatic work superseded by an explicit manual job.
	 *
	 * @param array<int, int> $ids Attachment IDs.
	 * @return void
	 */
	private function remove_automatic_items( $ids ) {
		$queue = $this->get_auto_queue();

		foreach ( $ids as $attachment_id ) {
			unset( $queue[ absint( $attachment_id ) ] );
		}

		$this->save_auto_queue( $queue );
	}

	/**
	 * Persist or clear automatic upload queue items.
	 *
	 * @param array<int|string, array<string, mixed>> $queue Queue items.
	 * @return void
	 */
	private function save_auto_queue( $queue ) {
		if ( empty( $queue ) ) {
			delete_option( self::AUTO_OPTION );
			return;
		}

		update_option( self::AUTO_OPTION, $queue, false );
	}

	/**
	 * Acquire the short-lived queue worker lock.
	 *
	 * @return string|false
	 */
	private function acquire_lock() {
		$token = wp_generate_uuid4();
		$lock  = array(
			'token'   => $token,
			'expires' => time() + self::LOCK_TTL,
		);

		if ( add_option( self::LOCK_OPTION, $lock, '', 'no' ) ) {
			return $token;
		}

		$existing = get_option( self::LOCK_OPTION, array() );
		if ( is_array( $existing ) && ! empty( $existing['expires'] ) && (int) $existing['expires'] < time() ) {
			delete_option( self::LOCK_OPTION );
			if ( add_option( self::LOCK_OPTION, $lock, '', 'no' ) ) {
				return $token;
			}
		}

		return false;
	}

	/**
	 * Return whether a non-expired worker lock exists.
	 *
	 * @return bool
	 */
	private function lock_is_active() {
		$lock = get_option( self::LOCK_OPTION, array() );
		if ( ! is_array( $lock ) || empty( $lock['expires'] ) ) {
			return false;
		}

		if ( (int) $lock['expires'] < time() ) {
			delete_option( self::LOCK_OPTION );
			return false;
		}

		return true;
	}

	/**
	 * Release a queue worker lock owned by this process.
	 *
	 * @param string $token Lock token.
	 * @return void
	 */
	private function release_lock( $token ) {
		$lock = get_option( self::LOCK_OPTION, array() );
		if ( is_array( $lock ) && ! empty( $lock['token'] ) && hash_equals( (string) $lock['token'], (string) $token ) ) {
			delete_option( self::LOCK_OPTION );
		}
	}
}

<?php
/**
 * WP-CLI commands.
 *
 * @package IndexLaneSafeWebPQueue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes scanning, conversion, cleanup, and reporting to WP-CLI.
 */
class ILSWQ_CLI {
	/**
	 * Report page size used while walking the Media Library.
	 */
	const SCAN_PER_PAGE = 50;

	/**
	 * Converter instance.
	 *
	 * @var ILSWQ_Converter
	 */
	private $converter;

	/**
	 * Queue instance.
	 *
	 * @var ILSWQ_Queue
	 */
	private $queue;

	/**
	 * Scanner instance.
	 *
	 * @var ILSWQ_Scanner
	 */
	private $scanner;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->scanner   = new ILSWQ_Scanner();
		$this->converter = new ILSWQ_Converter( $this->scanner );
		$this->queue     = new ILSWQ_Queue( $this->converter );
	}

	/**
	 * Show server support, conversion settings, and stored savings.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp ilswq status
	 *
	 * @param array<int, string>    $args Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function status( $args, $assoc_args ) {
		unset( $args );

		if ( ! ILSWQ_Capabilities::has_webp_writer() ) {
			WP_CLI::warning(
				ILSWQ_Browser::is_enabled()
					? __( 'This server has no WordPress image editor that can write WebP. Browser conversion is enabled, so convert from the plugin page.', 'indexlane-safe-webp-queue' )
					: __( 'This server has no WordPress image editor that can write WebP.', 'indexlane-safe-webp-queue' )
			);
		}

		self::render( $this->status_rows(), array( 'label', 'value' ), $assoc_args );
	}

	/**
	 * List Media Library images with their WebP status.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Only show one status: eligible, converted, needs-review, skipped, failed, conflict, or excluded.
	 *
	 * [--search=<term>]
	 * : Filter by filename, attachment title, or attachment ID.
	 *
	 * [--limit=<number>]
	 * : Maximum number of rows to print. Use 0 to print every match.
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp ilswq scan --status=eligible --limit=20
	 *     wp ilswq scan --status=conflict --format=json
	 *
	 * @param array<int, string>    $args Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function scan( $args, $assoc_args ) {
		unset( $args );

		$report = $this->scan_rows( $assoc_args );
		$format = self::flag( $assoc_args, 'format', 'table' );

		if ( 'count' === $format ) {
			WP_CLI::line( (string) count( $report['rows'] ) );

			return;
		}

		self::render( $report['rows'], self::scan_fields(), $assoc_args );

		if ( 'table' === $format ) {
			WP_CLI::log(
				sprintf(
					/* translators: 1: scanned attachment count, 2: total attachment count, 3: eligible attachment count. */
					__( 'Scanned %1$d of %2$d images. Eligible: %3$d.', 'indexlane-safe-webp-queue' ),
					(int) $report['scanned'],
					(int) $report['total'],
					(int) $report['counts']['eligible']
				)
			);
		}
	}

	/**
	 * Convert images to WebP.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Attachment IDs to convert.
	 *
	 * [--all]
	 * : Queue every convertible image in the Media Library.
	 *
	 * [--dry-run]
	 * : Report which images would be converted without writing files.
	 *
	 * [--batch=<number>]
	 * : Attachments per conversion batch. Defaults to the stored setting.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ilswq convert 12 34 56
	 *     wp ilswq convert --all
	 *     wp ilswq convert --all --dry-run
	 *
	 * @param array<int, string>    $args Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function convert( $args, $assoc_args ) {
		$settings = $this->settings( $assoc_args );
		$dry_run  = self::has_flag( $assoc_args, 'dry-run' );
		$all      = self::has_flag( $assoc_args, 'all' );

		if ( ! ILSWQ_Capabilities::has_webp_writer() ) {
			WP_CLI::error(
				ILSWQ_Browser::is_enabled()
					? __( 'This server has no WordPress image editor that can write WebP. Browser conversion always runs from the plugin page, not from WP-CLI.', 'indexlane-safe-webp-queue' )
					: __( 'This server has no WordPress image editor that can write WebP.', 'indexlane-safe-webp-queue' )
			);
		}

		$ids = $this->attachment_ids( $args );

		if ( $dry_run ) {
			$this->convert_dry_run( $ids, $all, $settings, $assoc_args );

			return;
		}

		if ( $all ) {
			$result = $this->queue->start_library_job( $settings );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}

			WP_CLI::log(
				sprintf(
					/* translators: %d: attachment count. */
					__( 'Queued the whole Media Library: %d images.', 'indexlane-safe-webp-queue' ),
					(int) $result['total']
				)
			);
		} elseif ( ! empty( $ids ) ) {
			$result = $this->queue->start_job( $ids, $settings );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}

			WP_CLI::log(
				sprintf(
					/* translators: %d: attachment count. */
					__( 'Queued %d images.', 'indexlane-safe-webp-queue' ),
					(int) $result['total']
				)
			);
		} else {
			WP_CLI::error( __( 'Provide attachment IDs or use --all.', 'indexlane-safe-webp-queue' ) );
		}

		$status = $this->run_job();

		WP_CLI::log(
			sprintf(
				/* translators: 1: processed count, 2: converted count, 3: skipped count, 4: conflict count, 5: failed count. */
				__( 'Processed %1$d images. Converted: %2$d. Skipped: %3$d. Conflicts: %4$d. Failed: %5$d.', 'indexlane-safe-webp-queue' ),
				(int) $status['processed'],
				(int) $status['converted'],
				(int) $status['skipped'],
				(int) $status['conflicts'],
				(int) $status['failed']
			)
		);

		if ( (int) $status['failed'] > 0 ) {
			WP_CLI::warning(
				sprintf(
					/* translators: %d: failed attachment count. */
					_n( '%d image failed to convert. Use "wp ilswq queue --retry" to try again.', '%d images failed to convert. Use "wp ilswq queue --retry" to try again.', (int) $status['failed'], 'indexlane-safe-webp-queue' ),
					(int) $status['failed']
				)
			);
		}

		if ( 'completed' === $status['state'] ) {
			WP_CLI::success( __( 'Conversion job complete.', 'indexlane-safe-webp-queue' ) );
		}
	}

	/**
	 * Delete the WebP files generated by this plugin.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report how many generated files would be deleted.
	 *
	 * [--yes]
	 * : Delete without asking for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ilswq cleanup --dry-run
	 *     wp ilswq cleanup --yes
	 *
	 * @param array<int, string>    $args Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function cleanup( $args, $assoc_args ) {
		unset( $args );

		if ( self::has_flag( $assoc_args, 'dry-run' ) ) {
			$count = $this->count_generated_files();
			WP_CLI::log(
				sprintf(
					/* translators: %d: generated WebP file count. */
					_n( '%d generated WebP file would be deleted.', '%d generated WebP files would be deleted.', $count, 'indexlane-safe-webp-queue' ),
					$count
				)
			);

			return;
		}

		$this->require_idle_queue();

		if ( ! self::has_flag( $assoc_args, 'yes' ) ) {
			WP_CLI::confirm( __( 'Delete every WebP file generated by this plugin?', 'indexlane-safe-webp-queue' ), $assoc_args );
		}

		$deleted = 0;
		$failed  = 0;
		$restart = true;

		do {
			$result = $this->queue->run_file_mutation(
				function () use ( $restart ) {
					if ( $restart ) {
						delete_option( ILSWQ_OPTION_CLEANUP_PAGE );
					}
					return $this->converter->cleanup_generated( 25 );
				}
			);
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
			$restart  = false;
			$deleted += isset( $result['deleted'] ) ? (int) $result['deleted'] : 0;
			$failed  += isset( $result['failed'] ) ? (int) $result['failed'] : 0;

			if ( empty( $result['processed'] ) && ! empty( $result['hasMore'] ) ) {
				WP_CLI::error( __( 'Cleanup did not finish. Run the command again to continue.', 'indexlane-safe-webp-queue' ) );
			}
		} while ( ! empty( $result['hasMore'] ) );

		WP_CLI::log(
			sprintf(
				/* translators: 1: deleted file count, 2: failed deletion count. */
				__( 'Deleted %1$d generated WebP files. Failed: %2$d.', 'indexlane-safe-webp-queue' ),
				$deleted,
				$failed
			)
		);

		if ( $failed > 0 ) {
			WP_CLI::warning( __( 'Some generated files could not be deleted. Run cleanup again to retry them.', 'indexlane-safe-webp-queue' ) );

			return;
		}

		WP_CLI::success( __( 'Generated WebP cleanup complete.', 'indexlane-safe-webp-queue' ) );
	}

	/**
	 * Show stored savings, optionally rebuilding them from attachment metadata.
	 *
	 * ## OPTIONS
	 *
	 * [--recalculate]
	 * : Rebuild stored totals by reading every stored WebP map.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp ilswq totals
	 *     wp ilswq totals --recalculate
	 *
	 * @param array<int, string>    $args Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function totals( $args, $assoc_args ) {
		unset( $args );

		if ( self::has_flag( $assoc_args, 'recalculate' ) ) {
			$this->require_idle_queue();
			$steps = 0;

			do {
				$step = $this->queue->run_file_mutation(
					static function () use ( $steps ) {
						return ILSWQ_Totals::rebuild_step( 0 === $steps );
					}
				);
				if ( is_wp_error( $step ) ) {
					WP_CLI::error( $step->get_error_message() );
				}
				++$steps;

			} while ( empty( $step['done'] ) );

			if ( 'table' === self::flag( $assoc_args, 'format', 'table' ) ) {
				WP_CLI::log(
					sprintf(
						/* translators: %d: inspected attachment count. */
						__( 'Rebuilt savings from %d attachments.', 'indexlane-safe-webp-queue' ),
						(int) $step['processed']
					)
				);
			}
		}

		self::render( $this->totals_rows(), array( 'label', 'value' ), $assoc_args );
	}

	/**
	 * Inspect or control the conversion queue.
	 *
	 * ## OPTIONS
	 *
	 * [--pause]
	 * : Pause the active job after the current batch.
	 *
	 * [--resume]
	 * : Resume a paused job.
	 *
	 * [--cancel]
	 * : Cancel pending work. Completed WebP files stay in place.
	 *
	 * [--retry]
	 * : Retry the last job's failures; very large failure sets rescan the library.
	 *
	 * [--process]
	 * : Run pending batches until the queue is idle.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ilswq queue
	 *     wp ilswq queue --process
	 *     wp ilswq queue --cancel
	 *
	 * @param array<int, string>    $args Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function queue( $args, $assoc_args ) {
		unset( $args );

		$commands = array(
			'pause'  => 'pause_job',
			'resume' => 'resume_job',
			'cancel' => 'cancel_job',
			'retry'  => 'retry_failed_job',
		);

		foreach ( $commands as $flag => $method ) {
			if ( ! self::has_flag( $assoc_args, $flag ) ) {
				continue;
			}

			$result = $this->queue->$method();
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
		}

		if ( self::has_flag( $assoc_args, 'process' ) ) {
			$this->run_job();
		}

		$status = $this->queue->get_public_status();
		WP_CLI::log(
			sprintf(
				/* translators: 1: queue state label, 2: queue summary. */
				__( 'Queue state: %1$s. %2$s', 'indexlane-safe-webp-queue' ),
				(string) $status['state_label'],
				(string) $status['summary']
			)
		);

		if ( ! empty( $status['last_error'] ) ) {
			WP_CLI::warning( (string) $status['last_error'] );
		}
	}

	/**
	 * Return status rows for the site.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function status_rows() {
		$rows     = array();
		$settings = ILSWQ_Settings::get();
		$status   = $this->queue->get_public_status();
		$totals   = ILSWQ_Totals::summary();
		$library  = ILSWQ_Scanner::count_library_attachments();
		$next     = wp_next_scheduled( ILSWQ_Queue::CRON_HOOK );

		$rows[] = self::row( __( 'Plugin version', 'indexlane-safe-webp-queue' ), ILSWQ_VERSION );
		$rows[] = self::row( __( 'WebP writer', 'indexlane-safe-webp-queue' ), ILSWQ_Capabilities::preferred_editor_label() );
		$rows[] = self::row( __( 'Convertible Media Library images', 'indexlane-safe-webp-queue' ), (string) $library );
		$rows[] = self::row( __( 'Queue state', 'indexlane-safe-webp-queue' ), (string) $status['state_label'] );
		$rows[] = self::row( __( 'Queue progress', 'indexlane-safe-webp-queue' ), (string) $status['summary'] );
		$rows[] = self::row( __( 'Automatic uploads pending', 'indexlane-safe-webp-queue' ), (string) (int) $status['automatic_pending'] );
		$rows[] = self::row( __( 'Automatic uploads failed', 'indexlane-safe-webp-queue' ), (string) (int) $status['automatic_failed'] );
		$rows[] = self::row( __( 'Next scheduled batch', 'indexlane-safe-webp-queue' ), false !== $next ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $next ) : '' );
		$rows[] = self::row( __( 'Batch size', 'indexlane-safe-webp-queue' ), (string) (int) $settings['batch_size'] );
		$rows[] = self::row( __( 'JPEG quality', 'indexlane-safe-webp-queue' ), (string) (int) $settings['jpeg_quality'] );
		$rows[] = self::row( __( 'PNG quality', 'indexlane-safe-webp-queue' ), (string) (int) $settings['png_quality'] );
		$rows[] = self::row( __( 'Max pixels', 'indexlane-safe-webp-queue' ), (string) (int) $settings['max_pixels'] );
		$rows[] = self::row( __( 'Skip larger WebP files', 'indexlane-safe-webp-queue' ), ! empty( $settings['skip_larger'] ) ? __( 'Yes', 'indexlane-safe-webp-queue' ) : __( 'No', 'indexlane-safe-webp-queue' ) );
		$rows[] = self::row( __( 'Serve WebP on the front end', 'indexlane-safe-webp-queue' ), ! empty( $settings['serve_webp'] ) ? __( 'Yes', 'indexlane-safe-webp-queue' ) : __( 'No', 'indexlane-safe-webp-queue' ) );
		$rows[] = self::row( __( 'Convert new uploads', 'indexlane-safe-webp-queue' ), ! empty( $settings['auto_uploads'] ) ? __( 'Yes', 'indexlane-safe-webp-queue' ) : __( 'No', 'indexlane-safe-webp-queue' ) );
		$rows[] = self::row( __( 'Browser conversion', 'indexlane-safe-webp-queue' ), ! empty( $settings['browser_conversion'] ) ? __( 'Yes', 'indexlane-safe-webp-queue' ) : __( 'No', 'indexlane-safe-webp-queue' ) );
		$rows[] = self::row( __( 'Generated WebP files', 'indexlane-safe-webp-queue' ), (string) $totals['labels']['files'] );
		$rows[] = self::row( __( 'Original bytes covered', 'indexlane-safe-webp-queue' ), (string) $totals['labels']['source'] );
		$rows[] = self::row( __( 'WebP bytes stored', 'indexlane-safe-webp-queue' ), (string) $totals['labels']['webp'] );
		$rows[] = self::row( __( 'Saved bytes', 'indexlane-safe-webp-queue' ), (string) $totals['labels']['saved'] );
		$rows[] = self::row( __( 'Saved percentage', 'indexlane-safe-webp-queue' ), (string) $totals['labels']['percent'] );

		foreach ( ILSWQ_Capabilities::get_checks() as $check ) {
			$rows[] = self::row(
				(string) $check['label'],
				sprintf(
					/* translators: 1: check value, 2: check status. */
					__( '%1$s (%2$s)', 'indexlane-safe-webp-queue' ),
					(string) $check['value'],
					(string) $check['status']
				)
			);
		}

		return $rows;
	}

	/**
	 * Collect scan rows for the given filters.
	 *
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	private function scan_rows( $assoc_args ) {
		$settings = $this->settings( $assoc_args );
		$status   = self::flag( $assoc_args, 'status', '' );
		$search   = strtolower( self::flag( $assoc_args, 'search', '' ) );
		$limit    = max( 0, (int) self::flag( $assoc_args, 'limit', '100' ) );
		$page     = 1;
		$rows     = array();
		$counts   = array(
			'eligible' => 0,
			'converted' => 0,
			'skipped'  => 0,
			'failed'   => 0,
			'conflict' => 0,
		);
		$scanned  = 0;
		$total    = 0;

		do {
			$report = $this->scanner->scan_page( $page, self::SCAN_PER_PAGE, $settings );
			$total  = (int) $report['total'];

			foreach ( $report['rows'] as $row ) {
				++$scanned;
				$key = isset( $row['status_key'] ) ? (string) $row['status_key'] : 'skipped';
				if ( ! isset( $counts[ $key ] ) ) {
					$counts[ $key ] = 0;
				}
				++$counts[ $key ];

				if ( '' !== $status && $key !== $status ) {
					continue;
				}

				if ( '' !== $search && ! self::matches_search( $row, $search ) ) {
					continue;
				}

				$rows[] = self::scan_row( $row );
				if ( $limit > 0 && count( $rows ) >= $limit ) {
					break 2;
				}
			}

			++$page;
		} while ( ! empty( $report['hasMore'] ) );

		return array(
			'rows'    => $rows,
			'scanned' => $scanned,
			'total'   => $total,
			'counts'  => $counts,
		);
	}

	/**
	 * Return savings rows.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function totals_rows() {
		$totals = ILSWQ_Totals::summary();

		return array(
			self::row( __( 'Generated WebP files', 'indexlane-safe-webp-queue' ), (string) $totals['labels']['files'] ),
			self::row( __( 'Original bytes covered', 'indexlane-safe-webp-queue' ), (string) $totals['labels']['source'] ),
			self::row( __( 'WebP bytes stored', 'indexlane-safe-webp-queue' ), (string) $totals['labels']['webp'] ),
			self::row( __( 'Saved bytes', 'indexlane-safe-webp-queue' ), (string) $totals['labels']['saved'] ),
			self::row( __( 'Saved percentage', 'indexlane-safe-webp-queue' ), (string) $totals['labels']['percent'] ),
			self::row( __( 'Last updated', 'indexlane-safe-webp-queue' ), (string) $totals['labels']['updated'] ),
		);
	}

	/**
	 * Count generated WebP files stored on disk.
	 *
	 * @return int
	 */
	private function count_generated_files() {
		$page  = 1;
		$count = 0;

		do {
			$query = new WP_Query(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'fields'         => 'ids',
					'posts_per_page' => 100,
					'paged'          => $page,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'meta_query'     => array(
						'relation' => 'OR',
						array(
							'key'     => ILSWQ_META_WEBP_FILES,
							'compare' => 'EXISTS',
						),
						array(
							'key'     => ILSWQ_META_WEBP_PATH,
							'compare' => 'EXISTS',
						),
					),
				)
			);

			foreach ( array_unique( array_map( 'absint', $query->posts ) ) as $attachment_id ) {
				foreach ( ILSWQ_Scanner::get_webp_map( (int) $attachment_id ) as $entry ) {
					if ( ! empty( $entry['webp'] ) && file_exists( (string) $entry['webp'] ) ) {
						++$count;
					}
				}
			}

			++$page;
			$has_more = $page <= (int) $query->max_num_pages;
		} while ( $has_more );

		return $count;
	}

	/**
	 * Run the active conversion job until it stops.
	 *
	 * @return array<string, mixed>
	 */
	private function run_job() {
		$batches = 0;
		$stalled = 0;
		$last    = '';
		$status  = $this->queue->get_public_status();

		do {
			$result = $this->queue->process_next_batch();
			$status = $result['queue'];
			++$batches;

			if ( ! empty( $result['error'] ) ) {
				WP_CLI::error( $result['error'] );
			}

			$progress = wp_json_encode( array( isset( $status['id'] ) ? $status['id'] : '', $status['processed'], $status['automatic_pending'], $status['cleanup_pending'] ) );
			$stalled  = $progress === $last ? $stalled + 1 : 0;
			$last     = $progress;

			if ( $stalled > 20 ) {
				WP_CLI::error( __( 'The conversion job stopped making progress. Use "wp ilswq queue --process" to continue.', 'indexlane-safe-webp-queue' ) );
			}

			if ( ! empty( $result['busy'] ) || $stalled > 0 ) {
				sleep( 1 );
			}

			if ( 0 === $batches % 10 || ! in_array( (string) $status['state'], array( 'queued', 'running' ), true ) ) {
				WP_CLI::log(
					sprintf(
						/* translators: 1: processed count, 2: total count, 3: converted count, 4: failed count. */
						__( 'Processed %1$d of %2$d images. Converted: %3$d. Failed: %4$d.', 'indexlane-safe-webp-queue' ),
						(int) $status['processed'],
						(int) $status['total'],
						(int) $status['converted'],
						(int) $status['failed']
					)
				);
			}
		} while ( in_array( (string) $status['state'], array( 'queued', 'running' ), true ) || $status['automatic_pending'] > 0 || $status['cleanup_pending'] > 0 );

		return $status;
	}

	/**
	 * Report what a conversion would do without writing files.
	 *
	 * @param array<int, int>    $ids Attachment IDs.
	 * @param bool               $all Whether to inspect the whole library.
	 * @param array<string, int> $settings Settings snapshot.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	private function convert_dry_run( $ids, $all, $settings, $assoc_args ) {
		$convertible = 0;
		$rows        = array();
		$inspect     = $all ? $this->library_ids_sample() : array_values( $ids );

		foreach ( $inspect as $attachment_id ) {
			$row  = $this->scanner->scan_attachment( (int) $attachment_id, $settings );
			$key  = isset( $row['status_key'] ) ? (string) $row['status_key'] : 'skipped';

			if ( in_array( $key, array( 'eligible', 'needs-review' ), true ) ) {
				++$convertible;
			}

			$rows[] = self::scan_row( $row );
		}

		self::render( $rows, self::scan_fields(), $assoc_args );
		WP_CLI::log(
			sprintf(
				/* translators: 1: convertible image count, 2: inspected image count. */
				__( 'Dry run: %1$d of %2$d inspected images can be converted.', 'indexlane-safe-webp-queue' ),
				$convertible,
				count( $rows )
			)
		);
	}

	/**
	 * Return every convertible attachment ID for a dry run.
	 *
	 * @return array<int, int>
	 */
	private function library_ids_sample() {
		$ids   = array();
		$cursor = 0;
		$max_id = ILSWQ_Scanner::last_library_id();

		do {
			$batch = ILSWQ_Scanner::get_library_page_ids( $cursor, 100, $max_id );
			foreach ( $batch as $attachment_id ) {
				$ids[] = (int) $attachment_id;
			}

			$cursor = empty( $batch ) ? $cursor : max( $batch );
		} while ( ! empty( $batch ) );

		return $ids;
	}

	/**
	 * Turn positional arguments into attachment IDs.
	 *
	 * @param array<int, string> $args Positional arguments.
	 * @return array<int, int>
	 */
	private function attachment_ids( $args ) {
		$ids = array();

		foreach ( is_array( $args ) ? $args : array() as $value ) {
			$attachment_id = absint( $value );
			if ( $attachment_id > 0 ) {
				$ids[ $attachment_id ] = $attachment_id;
			}
		}

		return array_values( $ids );
	}

	/**
	 * Return the settings snapshot for a command.
	 *
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return array<string, int>
	 */
	private function settings( $assoc_args ) {
		$settings = ILSWQ_Settings::get();
		$batch    = absint( self::flag( $assoc_args, 'batch', '' ) );

		if ( $batch > 0 ) {
			$settings['batch_size'] = $batch;
			$settings               = ILSWQ_Settings::sanitize( $settings );
		}

		return $settings;
	}

	/**
	 * Return scan output fields.
	 *
	 * @return array<int, string>
	 */
	private static function scan_fields() {
		return array( 'id', 'title', 'file', 'type', 'status', 'original', 'webp', 'savings', 'reason' );
	}

	/**
	 * Flatten a scan row for output.
	 *
	 * @param array<string, mixed> $row Scan row.
	 * @return array<string, mixed>
	 */
	private static function scan_row( $row ) {
		return array(
			'id'       => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'title'    => isset( $row['title'] ) ? (string) $row['title'] : '',
			'file'     => isset( $row['file'] ) ? (string) $row['file'] : '',
			'type'     => isset( $row['type'] ) ? (string) $row['type'] : '',
			'status'   => isset( $row['status'] ) ? (string) $row['status'] : '',
			'original' => isset( $row['original_size_label'] ) ? (string) $row['original_size_label'] : '',
			'webp'     => isset( $row['webp_size_label'] ) ? (string) $row['webp_size_label'] : '',
			'savings'  => isset( $row['savings'] ) ? (string) $row['savings'] : '',
			'reason'   => isset( $row['reason'] ) ? (string) $row['reason'] : '',
		);
	}

	/**
	 * Return whether a scan row matches a search term.
	 *
	 * @param array<string, mixed> $row Scan row.
	 * @param string               $search Lowercase search term.
	 * @return bool
	 */
	private static function matches_search( $row, $search ) {
		foreach ( array( 'title', 'file', 'id' ) as $field ) {
			$value = isset( $row[ $field ] ) ? strtolower( (string) $row[ $field ] ) : '';
			if ( '' !== $value && false !== strpos( $value, $search ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build a label/value row.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 * @return array<string, string>
	 */
	private static function row( $label, $value ) {
		return array(
			'label' => $label,
			'value' => $value,
		);
	}

	/**
	 * Render rows through WP-CLI.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows.
	 * @param array<int, string>                $fields Fields.
	 * @param array<string, string>             $assoc_args Associative arguments.
	 * @return void
	 */
	private static function render( $rows, $fields, $assoc_args ) {
		\WP_CLI\Utils\format_items( self::flag( $assoc_args, 'format', 'table' ), $rows, $fields );
	}

	/**
	 * Keep cleanup and totals rebuilds from overlapping queued conversion.
	 *
	 * @return void
	 */
	private function require_idle_queue() {
		if ( $this->queue->has_active_job() || $this->queue->has_automatic_work() ) {
			WP_CLI::error( __( 'Finish or cancel queued conversion work before cleanup or recalculating savings.', 'indexlane-safe-webp-queue' ) );
		}
	}

	/**
	 * Return an associative argument value.
	 *
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @param string                $key Key.
	 * @param string                $default Default value.
	 * @return string
	 */
	private static function flag( $assoc_args, $key, $default ) {
		if ( ! is_array( $assoc_args ) || ! isset( $assoc_args[ $key ] ) || ! is_scalar( $assoc_args[ $key ] ) ) {
			return (string) $default;
		}

		return (string) $assoc_args[ $key ];
	}

	/**
	 * Return whether a boolean flag is present.
	 *
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @param string                $key Key.
	 * @return bool
	 */
	private static function has_flag( $assoc_args, $key ) {
		if ( ! is_array( $assoc_args ) || ! array_key_exists( $key, $assoc_args ) ) {
			return false;
		}

		$value = $assoc_args[ $key ];

		return ! in_array( $value, array( false, 'false', '0', 0, 'no' ), true );
	}
}

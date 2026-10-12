<?php
/**
 * WebP converter.
 *
 * @package IndexLaneSafeWebPQueue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts eligible attachments one at a time.
 */
class ILSWQ_Converter {
	/**
	 * Scanner instance.
	 *
	 * @var ILSWQ_Scanner
	 */
	private $scanner;

	/**
	 * Constructor.
	 *
	 * @param ILSWQ_Scanner $scanner Scanner.
	 */
	public function __construct( ILSWQ_Scanner $scanner ) {
		$this->scanner = $scanner;
	}

	/**
	 * Convert a batch of attachment IDs.
	 *
	 * @param array<int, int>    $ids Attachment IDs.
	 * @param array<string, int> $settings Settings.
	 * @return array<int, array<string, mixed>>
	 */
	public function convert_batch( $ids, $settings ) {
		$rows = array();

		foreach ( $ids as $attachment_id ) {
			$rows[] = $this->convert_attachment( (int) $attachment_id, $settings );
		}

		return $rows;
	}

	/**
	 * Convert all eligible source files for one attachment.
	 *
	 * @param int                $attachment_id Attachment ID.
	 * @param array<string, int> $settings Settings.
	 * @return array<string, mixed>
	 */
	public function convert_attachment( $attachment_id, $settings ) {
		$sources = $this->scanner->scan_sources( $attachment_id, $settings );
		return $this->convert_scanned_sources( $attachment_id, $settings, $sources );
	}

	/**
	 * Convert an attachment while using freshly generated metadata.
	 *
	 * @param int                 $attachment_id Attachment ID.
	 * @param array<string, int>  $settings Settings.
	 * @param array<string,mixed> $metadata Attachment metadata.
	 * @return array<string, mixed>
	 */
	public function convert_attachment_with_metadata( $attachment_id, $settings, $metadata ) {
		$sources = $this->scanner->scan_sources( $attachment_id, $settings, $metadata );

		return $this->convert_scanned_sources( $attachment_id, $settings, $sources );
	}

	/**
	 * Convert all eligible scanned source files for one attachment.
	 *
	 * @param int                              $attachment_id Attachment ID.
	 * @param array<string, int>              $settings Settings.
	 * @param array<int, array<string,mixed>> $sources Scanned sources.
	 * @return array<string, mixed>
	 */
	private function convert_scanned_sources( $attachment_id, $settings, $sources ) {
		if ( ILSWQ_Scanner::is_excluded( $attachment_id ) || empty( $sources ) ) {
			return $this->scanner->scan_attachment( $attachment_id, $settings );
		}

		$map              = ILSWQ_Scanner::get_webp_map( $attachment_id );
		$converted        = 0;
		$skipped          = array();
		$conflicts        = array();
		$failures         = array();
		$browser_required = array();

		foreach ( $sources as $source ) {
			if ( empty( $source['eligible'] ) || ! empty( $source['generated_entries']['webp'] ) ) {
				continue;
			}

			$result = $this->convert_source( $source, $settings );
			if ( is_wp_error( $result ) ) {
				$failures[] = $result->get_error_message();
				continue;
			}

			if ( ! empty( $result['conflict'] ) ) {
				$conflicts[] = isset( $result['reason'] ) ? (string) $result['reason'] : __( 'A sibling WebP file conflicts with the generated output path.', 'indexlane-safe-webp-queue' );
				continue;
			}

			if ( ! empty( $result['skipped'] ) ) {
				$skipped[] = isset( $result['reason'] ) ? (string) $result['reason'] : __( 'Conversion skipped', 'indexlane-safe-webp-queue' );
				continue;
			}

			if ( ! empty( $result['browser_required'] ) ) {
				$browser_required[] = isset( $result['reason'] ) ? (string) $result['reason'] : __( 'Convert this file in the browser.', 'indexlane-safe-webp-queue' );
				continue;
			}

			if ( ! empty( $result['entry'] ) && is_array( $result['entry'] ) ) {
				$map = $this->map_with_entry( $attachment_id, $map, $result['entry'] );
				++$converted;
			}
		}

		if ( $converted > 0 ) {
			$this->save_generated_map( $attachment_id, $map );
		}

		$row = $this->scanner->scan_attachment( $attachment_id, $settings );
		if ( ! empty( $row['server_complete'] ) && 'eligible' === $row['status_key'] ) {
			$row = $this->scanner->with_status( $row, 'converted', __( 'WebP conversion complete', 'indexlane-safe-webp-queue' ), ! empty( $row['eligible'] ) );
		}


		if ( ! empty( $failures ) ) {
			return $this->scanner->with_status(
				$row,
				$converted > 0 ? 'needs-review' : 'failed',
				sprintf(
					/* translators: 1: failure count, 2: first failure reason. */
					__( '%1$d file conversions failed. First failure: %2$s', 'indexlane-safe-webp-queue' ),
					count( $failures ),
					$failures[0]
				),
				false
			);
		}

		if ( ! empty( $conflicts ) ) {
			return $this->scanner->with_status( $row, 'conflict', $conflicts[0], ! empty( $row['eligible'] ) );
		}

		// Files that only the browser backend can encode keep their scanned
		// eligibility so the report can still offer them, and the queue counts
		// them as skipped rather than failed.
		if ( ! empty( $browser_required ) ) {
			$browser_ready = ! empty( $row['browser_source_count'] );
			return $this->scanner->with_status(
				$row,
				$converted > 0 ? 'needs-review' : ( $browser_ready ? 'eligible' : 'skipped' ),
				$browser_required[0],
				$browser_ready
			);
		}

		if ( 0 === $converted && ! empty( $skipped ) ) {
			return $this->scanner->with_status( $row, 'skipped', $skipped[0], false );
		}

		return $row;
	}

	/**
	 * Delete plugin-generated WebP files in small batches.
	 *
	 * @param int $limit Batch limit.
	 * @return array<string, int|bool>
	 */
	public function cleanup_generated( $limit ) {
		$limit = max( 1, min( 25, absint( $limit ) ) );
		$page  = max( 1, absint( get_option( ILSWQ_OPTION_CLEANUP_PAGE, 1 ) ) );

		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => $limit,
				'paged'          => $page,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		$processed = 0;
		$deleted   = 0;
		$failed    = 0;

		foreach ( $query->posts as $attachment_id ) {
			++$processed;

			if ( ! $this->attachment_has_generated_meta( (int) $attachment_id ) ) {
				continue;
			}

			$entries           = $this->entries_for_cleanup( (int) $attachment_id );
			$attachment_failed = false;
			foreach ( $entries as $path => $entry ) {
				if ( ! $this->is_safe_generated_path( $path ) ) {
					++$failed;
					$attachment_failed = true;
					continue;
				}

				$existed = file_exists( $path );
				if ( $existed ) {
					wp_delete_file( $path );
					clearstatcache( true, $path );
				}

				if ( file_exists( $path ) ) {
					++$failed;
					$attachment_failed = true;
				} else {
					$deleted += $existed ? 1 : 0;
					$this->forget_generated_entry( (int) $attachment_id, $path, $entry );
				}
			}

			if ( ! $attachment_failed ) {
				$this->delete_generated_meta( (int) $attachment_id );
			}
		}

		if ( $page < (int) $query->max_num_pages ) {
			update_option( ILSWQ_OPTION_CLEANUP_PAGE, $page + 1, false );
		} else {
			delete_option( ILSWQ_OPTION_CLEANUP_PAGE );
		}

		return array(
			'processed' => $processed,
			'deleted'   => $deleted,
			'failed'    => $failed,
			'hasMore'   => $page < (int) $query->max_num_pages,
		);
	}

	/**
	 * Delete generated files owned by one attachment.
	 *
	 * Failed paths may be remembered because WordPress removes attachment meta
	 * after the deletion lifecycle has finished.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $remember_failures Whether failed paths need orphan retries.
	 * @return array<string, int>
	 */
	public function delete_generated_for_attachment( $attachment_id, $remember_failures = false ) {
		$attachment_id = absint( $attachment_id );
		$entries       = $this->entries_for_cleanup( $attachment_id );
		$deleted       = 0;
		$failed        = 0;

		foreach ( $entries as $path => $entry ) {
			if ( ! $this->is_safe_generated_path( $path ) ) {
				++$failed;
				continue;
			}

			$existed = file_exists( $path );
			if ( $existed ) {
				wp_delete_file( $path );
				clearstatcache( true, $path );
			}

			if ( file_exists( $path ) ) {
				++$failed;
				if ( $remember_failures ) {
					$this->remember_orphan_path( $path, $entry );
				}
			} else {
				$deleted += $existed ? 1 : 0;
				$this->forget_generated_entry( $attachment_id, $path, $entry );
			}
		}

		if ( 0 === $failed ) {
			$this->delete_generated_meta( $attachment_id );
		}

		return array(
			'deleted' => $deleted,
			'failed'  => $failed,
		);
	}

	/**
	 * Remove tracked outputs for attachment sizes no longer in current metadata.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $metadata Fresh attachment metadata.
	 * @return array<string, int>
	 */
	public function reconcile_generated_for_metadata( $attachment_id, $metadata ) {
		$attachment_id = absint( $attachment_id );
		$map           = ILSWQ_Scanner::get_webp_map( $attachment_id );
		if ( empty( $map ) ) {
			return array(
				'removed' => 0,
				'failed'  => 0,
			);
		}

		$current = array();
		foreach ( $this->scanner->get_attachment_sources( $attachment_id, $metadata ) as $source ) {
			if ( empty( $source['name'] ) || empty( $source['path'] ) ) {
				continue;
			}

			$name             = sanitize_key( (string) $source['name'] );
			$source_path      = wp_normalize_path( (string) $source['path'] );
			foreach ( ILSWQ_Scanner::OUTPUT_FORMATS as $format ) {
				$current[ ILSWQ_Scanner::map_entry_key( $name, $format ) ] = array(
					'source' => $source_path,
					'webp'   => ILSWQ_Scanner::output_path( $source_path, $format ),
				);
			}
		}

		$removed = 0;
		$failed  = 0;
		$changed = false;

		foreach ( $map as $name => $entry ) {
			$source_path = is_array( $entry ) && ! empty( $entry['source'] ) ? wp_normalize_path( (string) $entry['source'] ) : '';
			$webp_path   = is_array( $entry ) && ! empty( $entry['webp'] ) ? wp_normalize_path( (string) $entry['webp'] ) : '';
			$key         = sanitize_key( (string) $name );
			$is_current  = isset( $current[ $key ] ) && $current[ $key ]['source'] === $source_path && $current[ $key ]['webp'] === $webp_path;

			if ( $is_current ) {
				continue;
			}

			if ( '' !== $webp_path && file_exists( $webp_path ) ) {
				if ( ! $this->is_safe_generated_path( $webp_path ) ) {
					++$failed;
					continue;
				}

				wp_delete_file( $webp_path );
				clearstatcache( true, $webp_path );
				if ( file_exists( $webp_path ) ) {
					++$failed;
					continue;
				}
			}

			unset( $map[ $name ] );
			ILSWQ_Totals::forget( $entry );
			++$removed;
			$changed = true;
		}

		if ( $changed ) {
			$this->save_generated_map( $attachment_id, $map );
		}

		return array(
			'removed' => $removed,
			'failed'  => $failed,
		);
	}

	/**
	 * Retry remembered paths whose attachment metadata no longer exists.
	 *
	 * @param int $limit Maximum paths to inspect.
	 * @return array<string, int>
	 */
	public function cleanup_orphaned_generated( $limit ) {
		$limit   = max( 1, min( 25, absint( $limit ) ) );
		$records = get_option( ILSWQ_OPTION_ORPHAN_WEBPS, array() );
		$records = is_array( $records ) ? $records : array();
		$deleted = 0;
		$failed  = 0;
		$checked = 0;
		$now     = time();

		foreach ( $records as $key => $record ) {
			if ( $checked >= $limit ) {
				continue;
			}
			if ( ! is_array( $record ) ) {
				unset( $records[ $key ] );
				++$checked;
				continue;
			}

			$available_at = isset( $record['available_at'] ) ? (int) $record['available_at'] : 0;
			if ( $available_at > $now ) {
				continue;
			}

			++$checked;
			$relative = isset( $record['relative'] ) ? (string) $record['relative'] : '';
			$path     = ILSWQ_Scanner::relative_to_path( $relative );

			if ( '' === $path || ! $this->is_safe_generated_path( $path ) ) {
				unset( $records[ $key ] );
				continue;
			}

			$existed = file_exists( $path );
			if ( $existed ) {
				wp_delete_file( $path );
				clearstatcache( true, $path );
			}

			if ( file_exists( $path ) ) {
				++$failed;
				$attempts               = isset( $record['attempts'] ) ? (int) $record['attempts'] + 1 : 1;
				$record['attempts']     = $attempts;
				$record['available_at'] = $now + min( DAY_IN_SECONDS, 60 * (int) pow( 5, max( 0, $attempts - 1 ) ) );
				$records[ $key ]        = $record;
			} else {
				if ( $existed ) {
					++$deleted;
				}
				if ( isset( $record['source_size'], $record['webp_size'] ) ) {
					$record['webp'] = $path;
					ILSWQ_Totals::forget( $record );
				}
				unset( $records[ $key ] );
			}
		}

		if ( empty( $records ) ) {
			delete_option( ILSWQ_OPTION_ORPHAN_WEBPS );
		} else {
			update_option( ILSWQ_OPTION_ORPHAN_WEBPS, $records, false );
		}

		return array(
			'deleted' => $deleted,
			'failed'  => $failed,
		);
	}

	/**
	 * Return scheduling information for remembered orphan paths.
	 *
	 * @return array<string, int|bool>
	 */
	public function orphan_cleanup_status() {
		$records = get_option( ILSWQ_OPTION_ORPHAN_WEBPS, array() );
		$records = is_array( $records ) ? $records : array();
		$count   = 0;
		$next    = 0;
		$due     = false;
		$now     = time();

		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				++$count;
				$due = true;
				continue;
			}
			++$count;

			$available = isset( $record['available_at'] ) ? (int) $record['available_at'] : 0;
			if ( $available <= $now ) {
				$due = true;
			}
			if ( 0 === $next || $available < $next ) {
				$next = $available;
			}
		}

		return array(
			'count'   => $count,
			'due'     => $due,
			'next_at' => $next,
		);
	}

	/**
	 * Convert one source file.
	 *
	 * @param array<string, mixed> $source Source.
	 * @param array<string, int>   $settings Settings.
	 * @return array<string, mixed>|WP_Error
	 */
	private function convert_source( $source, $settings ) {
		if ( ! empty( $source['browser_only'] ) ) {
			return array(
				'browser_required' => true,
				'reason'           => __( 'Convert this file in the browser: it is larger than your server image tools can handle.', 'indexlane-safe-webp-queue' ),
			);
		}

		if ( ! ILSWQ_Capabilities::has_webp_writer() ) {
			return $this->convert_source_with_php( $source, $settings );
		}

		$target = $this->prepare_output_target( $source );
		if ( is_wp_error( $target ) || ! empty( $target['conflict'] ) ) {
			return $target;
		}

		$source_path = $target['source'];
		$output_path = $target['output'];

		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}

		ILSWQ_Capabilities::ensure_image_includes();

		$editor = $this->get_webp_editor( $source_path );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		$editor_class = get_class( $editor );
		if ( ! ILSWQ_Capabilities::editor_class_supports_webp( $editor_class ) ) {
			return new WP_Error( 'ilswq_editor_no_webp', __( 'Selected image editor cannot write WebP', 'indexlane-safe-webp-queue' ) );
		}

		$quality = ILSWQ_Settings::quality_for_mime( isset( $source['mime_type'] ) ? (string) $source['mime_type'] : '', $settings );
		if ( method_exists( $editor, 'set_quality' ) ) {
			$editor->set_quality( $quality );
		}

		$temporary = $this->create_temporary_output( $output_path );
		if ( is_wp_error( $temporary ) ) {
			return $temporary;
		}

		// Core resets quality when save() changes the output MIME type. Keep the
		// requested WebP quality in effect for this synchronous save only.
		$webp_quality = static function ( $default_quality, $mime_type ) use ( $quality ) {
			return 'image/webp' === $mime_type ? $quality : $default_quality;
		};
		add_filter( 'wp_editor_set_quality', $webp_quality, PHP_INT_MAX, 2 );
		try {
			$saved = $editor->save( $temporary['seed'], 'image/webp' );
		} finally {
			remove_filter( 'wp_editor_set_quality', $webp_quality, PHP_INT_MAX );
		}
		if ( is_wp_error( $saved ) ) {
			$this->delete_temporary_output( $temporary );

			return $saved;
		}

		$generated_path = isset( $saved['path'] ) ? wp_normalize_path( (string) $saved['path'] ) : '';
		if ( $generated_path !== $temporary['output'] ) {
			$this->delete_temporary_output( $temporary );

			return new WP_Error( 'ilswq_unexpected_temporary_path', __( 'The image editor did not use the reserved temporary WebP path.', 'indexlane-safe-webp-queue' ) );
		}

		$this->delete_temporary_seed( $temporary );

		$editor_label = false !== strpos( $editor_class, 'Imagick' ) ? 'Imagick' : 'GD';

		return $this->finalize_generated_file( $source, $generated_path, $temporary, $editor_label, $settings );
	}

	/**
	 * Convert one source with the pure-PHP backend, or defer to the browser.
	 *
	 * Reached only when no image editor on this host can write WebP. The
	 * bundled encoder is a strict fallback: it never displaces GD or Imagick,
	 * it is capped by megapixels, and a file it cannot read (a progressive
	 * JPEG on a host without GD, for example) is handed to the browser just as
	 * this path used to do unconditionally.
	 *
	 * @param array<string, mixed> $source Source.
	 * @param array<string, int>   $settings Settings.
	 * @return array<string, mixed>|WP_Error
	 */
	private function convert_source_with_php( $source, $settings ) {
		if ( ! ILSWQ_Capabilities::php_encoder_enabled() ) {
			return array(
				'browser_required' => true,
				'reason'           => __( 'Convert this file in the browser: this server has no image editor that can write WebP.', 'indexlane-safe-webp-queue' ),
			);
		}

		if ( ! ILSWQ_PHP_Encoder::source_within_limits( $source ) ) {
			return array(
				'browser_required' => true,
				'reason'           => __( 'Convert this file in the browser: it is larger than the built-in PHP encoder handles.', 'indexlane-safe-webp-queue' ),
			);
		}

		// An early, cheap check so a doomed conversion never spends minutes
		// encoding; install_bytes_result() repeats it after the encode.
		$target = $this->prepare_output_target( $source );
		if ( is_wp_error( $target ) || ! empty( $target['conflict'] ) ) {
			return $target;
		}

		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}

		$quality      = ILSWQ_Settings::quality_for_mime( isset( $source['mime_type'] ) ? (string) $source['mime_type'] : '', $settings );
		$source_mtime = filemtime( $target['source'] );
		$encoded      = ILSWQ_PHP_Encoder::encode( (string) $target['source'], $quality );

		if ( is_wp_error( $encoded ) ) {
			if ( in_array( $encoded->get_error_code(), array( 'ilswq_php_unsupported', 'ilswq_php_skipped_webp', 'ilswq_php_limits' ), true ) ) {
				// Nothing local can read it; the browser backend still might.
				return array(
					'browser_required' => true,
					'reason'           => $encoded->get_error_message(),
				);
			}

			return $encoded;
		}

		// The bytes come from an in-process encoder over pixels this request
		// just decoded, so they do not need the untrusted-upload container
		// walk the browser backend runs. What is worth checking is that the
		// file did not change under us between the scan and the encode: the
		// encoder reports the geometry it actually coded, and it carries a
		// source ICC profile through to an ICCP chunk, which a stricter
		// container check would reject.
		$expected_w = isset( $source['width'] ) ? (int) $source['width'] : 0;
		$expected_h = isset( $source['height'] ) ? (int) $source['height'] : 0;
		clearstatcache( true, $target['source'] );
		if ( (int) $encoded['width'] !== $expected_w || (int) $encoded['height'] !== $expected_h
			|| filemtime( $target['source'] ) !== $source_mtime
			|| (int) filesize( $target['source'] ) !== (int) $source['original_size'] ) {
			return new WP_Error( 'ilswq_php_source_changed', __( 'The source image changed while it was converting. Convert it again.', 'indexlane-safe-webp-queue' ) );
		}

		return $this->install_bytes_result( $source, (string) $encoded['bytes'], 'PHP (picowebp)', $settings );
	}

	/**
	 * Install generated image bytes produced somewhere other than the server image editor.
	 *
	 * The browser conversion backend hands validated bytes to this method so
	 * that install, ownership, conflict, totals and generated-map handling stay
	 * identical to the local image editor path.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $source Scanned source.
	 * @param string               $bytes Encoded image bytes.
	 * @param string               $editor_label Backend label stored with the entry.
	 * @param array<string, int>   $settings Settings.
	 * @param string $format Output format.
	 * @return array<string, mixed>|WP_Error
	 */
	public function install_generated_bytes( $attachment_id, $source, $bytes, $editor_label, $settings, $format = 'webp' ) {
		$format = ILSWQ_Scanner::normalize_format( $format );
		$result = $this->install_bytes_result( $source, $bytes, $editor_label, $settings, $format );

		if ( ! is_wp_error( $result ) && ! empty( $result['entry'] ) && is_array( $result['entry'] ) ) {
			$map = $this->map_with_entry( absint( $attachment_id ), ILSWQ_Scanner::get_webp_map( $attachment_id ), $result['entry'] );
			$this->save_generated_map( absint( $attachment_id ), $map );
		}

		return $result;
	}

	/**
	 * Stage already-encoded bytes and finalize the generated file.
	 *
	 * Unlike install_generated_bytes() this does not touch the stored map or
	 * totals, so the batch loop stays the single place that records a run.
	 *
	 * @param array<string, mixed> $source Scanned source.
	 * @param string               $bytes Encoded image bytes.
	 * @param string               $editor_label Backend label stored with the entry.
	 * @param array<string, int>   $settings Settings.
	 * @param string $format Output format.
	 * @return array<string, mixed>|WP_Error
	 */
	private function install_bytes_result( $source, $bytes, $editor_label, $settings, $format = 'webp' ) {
		$format = ILSWQ_Scanner::normalize_format( $format );
		$target = $this->prepare_output_target( $source, $format );
		if ( is_wp_error( $target ) || ! empty( $target['conflict'] ) ) {
			return $target;
		}

		if ( ! is_string( $bytes ) || '' === $bytes ) {
			return new WP_Error( 'ilswq_empty_output', __( 'The conversion produced no image data.', 'indexlane-safe-webp-queue' ) );
		}

		$temporary = $this->create_temporary_output( $target['output'], $format );
		if ( is_wp_error( $temporary ) ) {
			return $temporary;
		}

		// Bytes handed here were validated by the calling backend. The output is
		// a plugin-owned temporary file inside the uploads directory.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $temporary['output'], $bytes, LOCK_EX );
		if ( strlen( $bytes ) !== $written ) {
			$this->delete_temporary_output( $temporary );

			return new WP_Error( 'ilswq_write_failed', __( 'Could not write the converted image file.', 'indexlane-safe-webp-queue' ) );
		}

		$this->delete_temporary_seed( $temporary );

		return $this->finalize_generated_file( $source, $temporary['output'], $temporary, $editor_label, $settings, $format );
	}

	/**
	 * Merge one generated entry into a map and update the stored totals.
	 *
	 * @param int                                  $attachment_id Attachment ID.
	 * @param array<string, array<string, mixed>>  $map Runtime WebP map.
	 * @param array<string, mixed>                 $entry New entry.
	 * @return array<string, array<string, mixed>> Updated map.
	 */
	private function map_with_entry( $attachment_id, $map, $entry ) {
		$name     = isset( $entry['name'] ) ? sanitize_key( (string) $entry['name'] ) : 'full';
		$key      = ILSWQ_Scanner::map_entry_key( $name, ILSWQ_Scanner::entry_format( $entry ) );
		$previous = isset( $map[ $key ] ) && is_array( $map[ $key ] ) ? $map[ $key ] : array();

		$map[ $key ] = $entry;
		ILSWQ_Totals::record( $previous, $map[ $key ] );

		return $map;
	}

	/**
	 * Resolve and validate the source and final output path for a conversion.
	 *
	 * @param array<string, mixed> $source Scanned source.
	 * @param string $format Output format.
	 * @return array<string, mixed>|WP_Error
	 */
	private function prepare_output_target( $source, $format = 'webp' ) {
		$format      = ILSWQ_Scanner::normalize_format( $format );
		$source_path = isset( $source['path'] ) ? wp_normalize_path( (string) $source['path'] ) : '';
		if ( '' === $source_path || ! file_exists( $source_path ) ) {
			return new WP_Error( 'ilswq_source_missing', __( 'File missing', 'indexlane-safe-webp-queue' ) );
		}

		if ( ! ILSWQ_Scanner::is_uploads_path( $source_path ) ) {
			return new WP_Error( 'ilswq_source_outside_uploads', __( 'File is outside the uploads directory', 'indexlane-safe-webp-queue' ) );
		}

		$output_path = ILSWQ_Scanner::output_path( $source_path, $format );
		$owned_path  = '';
		if ( ! empty( $source['owned_entries'][ $format ]['webp'] ) ) {
			$owned_path = wp_normalize_path( (string) $source['owned_entries'][ $format ]['webp'] );
		} elseif ( 'webp' === $format && isset( $source['existing_plugin_webp_path'] ) ) {
			$owned_path = wp_normalize_path( (string) $source['existing_plugin_webp_path'] );
		}
		$owns_output = $output_path === $owned_path;

		if ( file_exists( $output_path ) && ! $owns_output ) {
			$reason = sprintf(
				/* translators: %s: output format label, such as "AVIF". */
				__( 'A sibling %s file exists but is not owned by this plugin. Move or rename it before converting.', 'indexlane-safe-webp-queue' ),
				ILSWQ_Scanner::format_label( $format )
			);

			return array(
				'conflict' => true,
				'reason'   => $reason,
			);
		}

		return array(
			'source' => $source_path,
			'output' => $output_path,
			'owns'   => $owns_output,
		);
	}

	/**
	 * Validate a staged WebP file, install it and build its generated-map entry.
	 *
	 * @param array<string, mixed>  $source Scanned source.
	 * @param string                $generated_path Staged WebP path.
	 * @param array<string, string> $temporary Reserved temporary paths.
	 * @param string                $editor_label Backend label stored with the entry.
	 * @param array<string, int>    $settings Settings.
	 * @param string $format Output format.
	 * @return array<string, mixed>|WP_Error
	 */
	private function finalize_generated_file( $source, $generated_path, $temporary, $editor_label, $settings, $format = 'webp' ) {
		$format      = ILSWQ_Scanner::normalize_format( $format );
		$source_path = wp_normalize_path( (string) $source['path'] );
		$output_path = ILSWQ_Scanner::output_path( $source_path, $format );
		$owned_path  = '';
		if ( ! empty( $source['owned_entries'][ $format ]['webp'] ) ) {
			$owned_path = wp_normalize_path( (string) $source['owned_entries'][ $format ]['webp'] );
		} elseif ( 'webp' === $format && isset( $source['existing_plugin_webp_path'] ) ) {
			$owned_path = wp_normalize_path( (string) $source['existing_plugin_webp_path'] );
		}
		$owns_output = $output_path === $owned_path;
		$quality     = ILSWQ_Settings::quality_for_format( isset( $source['mime_type'] ) ? (string) $source['mime_type'] : '', $format, $settings );

		if ( ! file_exists( $generated_path ) ) {
			$this->delete_temporary_output( $temporary );

			return new WP_Error( 'ilswq_conversion_failed', __( 'Conversion failed', 'indexlane-safe-webp-queue' ) );
		}

		$webp_size = $this->validate_generated_file( $generated_path, $format );
		if ( is_wp_error( $webp_size ) ) {
			$this->delete_temporary_output( $temporary );

			return $webp_size;
		}

		$original_size = isset( $source['original_size'] ) ? (int) $source['original_size'] : 0;
		if ( ! empty( $settings['skip_larger'] ) && $original_size > 0 && $webp_size >= $original_size ) {
			$this->delete_temporary_output( $temporary );

			return array(
				'skipped' => true,
				/* translators: %s: output format label, such as "AVIF". */
				'reason'  => sprintf( __( '%s larger than original', 'indexlane-safe-webp-queue' ), ILSWQ_Scanner::format_label( $format ) ),
			);
		}

		clearstatcache( true, $output_path );
		if ( file_exists( $output_path ) && ! $owns_output ) {
			$this->delete_temporary_output( $temporary );

			return array(
				'conflict' => true,
				/* translators: %s: output format label, such as "AVIF". */
				'reason'   => sprintf( __( 'A sibling %s file appeared during conversion and was left unchanged.', 'indexlane-safe-webp-queue' ), ILSWQ_Scanner::format_label( $format ) ),
			);
		}

		$replace_owned_output = $owns_output && file_exists( $output_path );
		if ( ! $this->move_generated_file( $generated_path, $output_path, $replace_owned_output ) ) {
			$this->delete_temporary_output( $temporary );

			return new WP_Error( 'ilswq_move_failed', __( 'Could not install the validated image file.', 'indexlane-safe-webp-queue' ) );
		}

		clearstatcache( true, $output_path );
		if ( ! file_exists( $output_path ) ) {
			return new WP_Error( 'ilswq_install_failed', __( 'The validated image file could not be found after installation.', 'indexlane-safe-webp-queue' ) );
		}

		$source_mtime = filemtime( $source_path );
		$source_mtime = false === $source_mtime ? 0 : (int) $source_mtime;
		$mime_type    = isset( $source['mime_type'] ) ? (string) $source['mime_type'] : '';

		return array(
			'entry' => array(
				'name'         => isset( $source['name'] ) ? sanitize_key( (string) $source['name'] ) : 'full',
				'label'        => isset( $source['label'] ) ? (string) $source['label'] : '',
				'source'       => $source_path,
				'webp'         => $output_path,
				'format'       => $format,
				'source_size'  => $original_size,
				'webp_size'    => $webp_size,
				'width'        => isset( $source['width'] ) ? (int) $source['width'] : 0,
				'height'       => isset( $source['height'] ) ? (int) $source['height'] : 0,
				'mime_type'    => $mime_type,
				'editor'       => $editor_label,
				'quality'      => $quality,
				'source_mtime' => $source_mtime,
				'fingerprint'  => ILSWQ_Scanner::generation_fingerprint( $source_path, $original_size, $source_mtime, $mime_type, $quality ),
				'created'      => current_time( 'mysql' ),
				'version'      => ILSWQ_VERSION,
			),
		);
	}

	/**
	 * Validate generated output as a non-empty image container.
	 *
	 * @param string $final_path Generated file path.
	 * @param string $format Output format.
	 * @return int|WP_Error Generated byte size on success.
	 */
	private function validate_generated_file( $final_path, $format = 'webp' ) {
		$format    = ILSWQ_Scanner::normalize_format( $format );
		$webp_size = filesize( $final_path );

		if ( false === $webp_size || $webp_size <= 0 ) {
			/* translators: %s: output format label, such as "AVIF". */
			return new WP_Error( 'ilswq_empty_webp', sprintf( __( 'Generated %s file is empty', 'indexlane-safe-webp-queue' ), ILSWQ_Scanner::format_label( $format ) ) );
		}

		if ( ! ILSWQ_Scanner::is_valid_generated_file( $final_path, $format ) ) {
			/* translators: %s: output format label, such as "AVIF". */
			return new WP_Error( 'ilswq_invalid_webp', sprintf( __( 'Generated file is not a valid %s image', 'indexlane-safe-webp-queue' ), ILSWQ_Scanner::format_label( $format ) ) );
		}

		return (int) $webp_size;
	}

	/**
	 * Reserve temporary paths beside the final generated output.
	 *
	 * @param string $output_path Final output path.
	 * @param string $format Output format.
	 * @return array<string, string>|WP_Error
	 */
	private function create_temporary_output( $output_path, $format = 'webp' ) {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$directory = trailingslashit( dirname( $output_path ) );
		$seed      = wp_tempnam( $output_path, $directory );
		$seed      = is_string( $seed ) ? wp_normalize_path( $seed ) : '';

		if ( '' === $seed || ! file_exists( $seed ) || trailingslashit( dirname( $seed ) ) !== $directory ) {
			if ( '' !== $seed && file_exists( $seed ) ) {
				wp_delete_file( $seed );
			}

			return new WP_Error( 'ilswq_temp_failed', __( 'Could not reserve a temporary image file in the uploads directory.', 'indexlane-safe-webp-queue' ) );
		}

		$temporary_output = preg_replace( '/\.tmp$/i', ILSWQ_Scanner::format_extension( $format ), $seed );
		if ( ! is_string( $temporary_output ) || $temporary_output === $seed || $temporary_output === $output_path ) {
			wp_delete_file( $seed );

			return new WP_Error( 'ilswq_temp_failed', __( 'Could not reserve a temporary image file in the uploads directory.', 'indexlane-safe-webp-queue' ) );
		}

		return array(
			'seed'   => $seed,
			'output' => wp_normalize_path( $temporary_output ),
		);
	}

	/**
	 * Delete the empty seed after the image editor has saved its WebP output.
	 *
	 * @param array<string, string> $temporary Temporary paths.
	 * @return void
	 */
	private function delete_temporary_seed( $temporary ) {
		if ( ! empty( $temporary['seed'] ) && file_exists( $temporary['seed'] ) ) {
			wp_delete_file( $temporary['seed'] );
		}
	}

	/**
	 * Delete only temporary files reserved by this conversion attempt.
	 *
	 * @param array<string, string> $temporary Temporary paths.
	 * @return void
	 */
	private function delete_temporary_output( $temporary ) {
		foreach ( array( 'seed', 'output' ) as $key ) {
			if ( ! empty( $temporary[ $key ] ) && file_exists( $temporary[ $key ] ) ) {
				wp_delete_file( $temporary[ $key ] );
			}
		}
	}

	/**
	 * Move a generated file through the WordPress filesystem layer.
	 *
	 * @param string $from Source path.
	 * @param string $to Destination path.
	 * @param bool   $overwrite Whether to replace an existing plugin-owned file.
	 * @return bool
	 */
	private function move_generated_file( $from, $to, $overwrite ) {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! WP_Filesystem() || ! is_object( $wp_filesystem ) ) {
			return false;
		}

		return (bool) $wp_filesystem->move( $from, $to, (bool) $overwrite );
	}

	/**
	 * Save generated map and legacy full-size metadata.
	 *
	 * @param int                                  $attachment_id Attachment ID.
	 * @param array<string, array<string, mixed>>  $map WebP map.
	 * @return void
	 */
	private function save_generated_map( $attachment_id, $map ) {
		$stored = $this->map_for_storage( $map );
		if ( empty( $stored ) ) {
			$this->delete_generated_meta( $attachment_id );
			return;
		}

		update_post_meta( $attachment_id, ILSWQ_META_WEBP_FILES, $stored );
		update_post_meta( $attachment_id, ILSWQ_META_CREATED, current_time( 'mysql' ) );
		update_post_meta( $attachment_id, ILSWQ_META_VERSION, ILSWQ_VERSION );

		if ( isset( $stored['full'] ) && is_array( $stored['full'] ) ) {
			update_post_meta( $attachment_id, ILSWQ_META_WEBP_SIZE, isset( $stored['full']['webp_size'] ) ? (int) $stored['full']['webp_size'] : 0 );
			update_post_meta( $attachment_id, ILSWQ_META_SOURCE_SIZE, isset( $stored['full']['source_size'] ) ? (int) $stored['full']['source_size'] : 0 );
			update_post_meta( $attachment_id, ILSWQ_META_EDITOR, isset( $stored['full']['editor'] ) ? (string) $stored['full']['editor'] : '' );
		} else {
			delete_post_meta( $attachment_id, ILSWQ_META_WEBP_SIZE );
			delete_post_meta( $attachment_id, ILSWQ_META_SOURCE_SIZE );
			delete_post_meta( $attachment_id, ILSWQ_META_EDITOR );
		}

		delete_post_meta( $attachment_id, ILSWQ_META_WEBP_PATH );
	}

	/**
	 * Convert runtime absolute paths into uploads-relative paths before storage.
	 *
	 * @param array<string, array<string, mixed>> $map Runtime WebP map.
	 * @return array<string, array<string, mixed>>
	 */
	private function map_for_storage( $map ) {
		$stored = array();

		foreach ( $map as $name => $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['source'] ) || empty( $entry['webp'] ) ) {
				continue;
			}

			$source_rel = ILSWQ_Scanner::path_to_relative( (string) $entry['source'] );
			$webp_rel   = ILSWQ_Scanner::path_to_relative( (string) $entry['webp'] );
			if ( '' === $source_rel || '' === $webp_rel ) {
				continue;
			}

			$stored[ sanitize_key( (string) $name ) ] = array(
				'name'         => isset( $entry['name'] ) ? sanitize_key( (string) $entry['name'] ) : sanitize_key( (string) $name ),
				'label'        => isset( $entry['label'] ) ? sanitize_text_field( (string) $entry['label'] ) : '',
				'source_rel'   => $source_rel,
				'webp_rel'     => $webp_rel,
				'format'       => ILSWQ_Scanner::entry_format( $entry ),
				'source_size'  => isset( $entry['source_size'] ) ? (int) $entry['source_size'] : 0,
				'webp_size'    => isset( $entry['webp_size'] ) ? (int) $entry['webp_size'] : 0,
				'width'        => isset( $entry['width'] ) ? (int) $entry['width'] : 0,
				'height'       => isset( $entry['height'] ) ? (int) $entry['height'] : 0,
				'mime_type'    => isset( $entry['mime_type'] ) ? sanitize_mime_type( (string) $entry['mime_type'] ) : '',
				'editor'       => isset( $entry['editor'] ) ? sanitize_text_field( (string) $entry['editor'] ) : '',
				'quality'      => ! empty( $entry['quality'] ) ? max( 1, min( 100, absint( $entry['quality'] ) ) ) : 0,
				'source_mtime' => isset( $entry['source_mtime'] ) ? max( 0, (int) $entry['source_mtime'] ) : 0,
				'fingerprint'  => isset( $entry['fingerprint'] ) ? sanitize_text_field( (string) $entry['fingerprint'] ) : '',
				'created'      => isset( $entry['created'] ) ? sanitize_text_field( (string) $entry['created'] ) : current_time( 'mysql' ),
				'version'      => isset( $entry['version'] ) ? sanitize_text_field( (string) $entry['version'] ) : ILSWQ_VERSION,
			);
		}

		return $stored;
	}

	/**
	 * Get a preferred WordPress image editor that can write WebP.
	 *
	 * @param string $source_path Source path.
	 * @return WP_Image_Editor|WP_Error
	 */
	private function get_webp_editor( $source_path ) {
		$available_classes = array();

		if ( ILSWQ_Capabilities::imagick_can_write_webp() ) {
			$available_classes[] = 'WP_Image_Editor_Imagick';
		}

		if ( ILSWQ_Capabilities::gd_can_write_webp() ) {
			$available_classes[] = 'WP_Image_Editor_GD';
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- This is a WordPress core filter.
		$preferred_classes = apply_filters( 'wp_image_editors', $available_classes );
		$preferred_classes = is_array( $preferred_classes ) ? $preferred_classes : $available_classes;
		$editor_classes    = array_values( array_intersect( $preferred_classes, $available_classes ) );

		$last_error = null;

		foreach ( $editor_classes as $editor_class ) {
			$filter = function () use ( $editor_class ) {
				return array( $editor_class );
			};

			add_filter( 'wp_image_editors', $filter, 1000 );
			$editor = wp_get_image_editor( $source_path );
			remove_filter( 'wp_image_editors', $filter, 1000 );

			if ( is_wp_error( $editor ) ) {
				$last_error = $editor;
				continue;
			}

			if ( ILSWQ_Capabilities::editor_class_supports_webp( get_class( $editor ) ) ) {
				return $editor;
			}
		}

		if ( $last_error instanceof WP_Error ) {
			return $last_error;
		}

		return new WP_Error( 'ilswq_no_webp_editor', __( 'No local image editor can write WebP', 'indexlane-safe-webp-queue' ) );
	}

	/**
	 * Return generated entries stored for cleanup, keyed by normalized path.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, array<string, mixed>>
	 */
	private function entries_for_cleanup( $attachment_id ) {
		$entries = array();

		foreach ( ILSWQ_Scanner::get_webp_map( $attachment_id ) as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['webp'] ) ) {
				$entries[ wp_normalize_path( (string) $entry['webp'] ) ] = $entry;
			}
		}

		$legacy_path = (string) get_post_meta( $attachment_id, ILSWQ_META_WEBP_PATH, true );
		if ( '' !== $legacy_path ) {
			$legacy_path = wp_normalize_path( $legacy_path );

			if ( ! isset( $entries[ $legacy_path ] ) ) {
				$entries[ $legacy_path ] = array(
					'webp'        => $legacy_path,
					'webp_size'   => (int) get_post_meta( $attachment_id, ILSWQ_META_WEBP_SIZE, true ),
					'source_size' => (int) get_post_meta( $attachment_id, ILSWQ_META_SOURCE_SIZE, true ),
				);
			}
		}

		return $entries;
	}

	/**
	 * Remember a safe uploads-relative path after attachment deletion fails.
	 *
	 * @param string $path Generated WebP path.
	 * @param array<string, mixed> $entry Stored amounts to retain for cleanup.
	 * @return void
	 */
	private function remember_orphan_path( $path, $entry ) {
		$relative = ILSWQ_Scanner::path_to_relative( $path );
		if ( '' === $relative || ! $this->is_generated_file_name( $relative ) ) {
			return;
		}

		$records = get_option( ILSWQ_OPTION_ORPHAN_WEBPS, array() );
		$records = is_array( $records ) ? $records : array();
		$key     = hash( 'sha256', $relative );

		$records[ $key ] = array(
			'relative'     => $relative,
			'attempts'     => 0,
			'available_at' => time() + 60,
			'source_size'  => isset( $entry['source_size'] ) ? (int) $entry['source_size'] : 0,
			'webp_size'    => isset( $entry['webp_size'] ) ? (int) $entry['webp_size'] : 0,
		);
		update_option( ILSWQ_OPTION_ORPHAN_WEBPS, $records, false );
	}

	/**
	 * Remove cleaned ownership so retries cannot count it twice.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param string $path Deleted or already missing output.
	 * @param array<string, mixed> $entry Stored entry.
	 * @return void
	 */
	private function forget_generated_entry( $attachment_id, $path, $entry ) {
		$map = ILSWQ_Scanner::get_webp_map( $attachment_id );
		foreach ( $map as $name => $stored ) {
			if ( isset( $stored['webp'] ) && wp_normalize_path( $stored['webp'] ) === $path ) {
				unset( $map[ $name ] );
			}
		}
		$this->save_generated_map( $attachment_id, $map );
		ILSWQ_Totals::forget( $entry );
	}

	/**
	 * Return true when an attachment has plugin-generated WebP metadata.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function attachment_has_generated_meta( $attachment_id ) {
		return metadata_exists( 'post', $attachment_id, ILSWQ_META_WEBP_FILES ) || metadata_exists( 'post', $attachment_id, ILSWQ_META_WEBP_PATH );
	}

	/**
	 * Delete plugin metadata.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	private function delete_generated_meta( $attachment_id ) {
		delete_post_meta( $attachment_id, ILSWQ_META_WEBP_FILES );
		delete_post_meta( $attachment_id, ILSWQ_META_WEBP_PATH );
		delete_post_meta( $attachment_id, ILSWQ_META_WEBP_SIZE );
		delete_post_meta( $attachment_id, ILSWQ_META_SOURCE_SIZE );
		delete_post_meta( $attachment_id, ILSWQ_META_EDITOR );
		delete_post_meta( $attachment_id, ILSWQ_META_CREATED );
		delete_post_meta( $attachment_id, ILSWQ_META_VERSION );
		delete_post_meta( $attachment_id, ILSWQ_META_LAST_ERROR );
	}

	/**
	 * Only delete WebP files inside uploads.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	private function is_safe_generated_path( $path ) {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return false;
		}

		$path = wp_normalize_path( $path );
		if ( ! $this->is_generated_file_name( $path ) ) {
			return false;
		}

		$real_base = realpath( $uploads['basedir'] );
		if ( false === $real_base ) {
			return false;
		}

		$real_base = trailingslashit( wp_normalize_path( $real_base ) );
		if ( file_exists( $path ) || is_link( $path ) ) {
			$real_path = realpath( $path );
			if ( false === $real_path ) {
				return false;
			}

			return 0 === strpos( wp_normalize_path( $real_path ), $real_base );
		}

		$base_dir = trailingslashit( wp_normalize_path( $uploads['basedir'] ) );
		if ( 0 !== strpos( $path, $base_dir ) ) {
			return false;
		}

		$relative = substr( $path, strlen( $base_dir ) );
		if ( '' === $relative || in_array( '..', explode( '/', $relative ), true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Return true when a path uses one of this plugin's generated extensions.
	 *
	 * @param string $path File path or uploads-relative path.
	 * @return bool
	 */
	private function is_generated_file_name( $path ) {
		$path = strtolower( (string) $path );

		foreach ( ILSWQ_Scanner::OUTPUT_FORMATS as $format ) {
			$extension = ILSWQ_Scanner::format_extension( $format );
			if ( $extension === substr( $path, -strlen( $extension ) ) ) {
				return true;
			}
		}

		return false;
	}
}

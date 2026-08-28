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
		if ( empty( $sources ) ) {
			return $this->scanner->scan_attachment( $attachment_id, $settings );
		}

		$map       = ILSWQ_Scanner::get_webp_map( $attachment_id );
		$converted = 0;
		$skipped   = array();
		$conflicts = array();
		$failures  = array();

		foreach ( $sources as $source ) {
			if ( empty( $source['eligible'] ) ) {
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

			if ( ! empty( $result['entry'] ) && is_array( $result['entry'] ) ) {
				$name         = isset( $result['entry']['name'] ) ? sanitize_key( (string) $result['entry']['name'] ) : 'full';
				$map[ $name ] = $result['entry'];
				++$converted;
			}
		}

		if ( $converted > 0 ) {
			$this->save_generated_map( $attachment_id, $map );
		}

		$row = $this->scanner->scan_attachment( $attachment_id, $settings );

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

			$paths             = $this->generated_paths_for_cleanup( (int) $attachment_id );
			$attachment_failed = false;
			foreach ( $paths as $path ) {
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
				} elseif ( $existed ) {
					++$deleted;
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
	 * Convert one source file.
	 *
	 * @param array<string, mixed> $source Source.
	 * @param array<string, int>   $settings Settings.
	 * @return array<string, mixed>|WP_Error
	 */
	private function convert_source( $source, $settings ) {
		$source_path = isset( $source['path'] ) ? wp_normalize_path( (string) $source['path'] ) : '';
		if ( '' === $source_path || ! file_exists( $source_path ) ) {
			return new WP_Error( 'ilswq_source_missing', __( 'File missing', 'indexlane-safe-webp-queue' ) );
		}

		if ( ! ILSWQ_Scanner::is_uploads_path( $source_path ) ) {
			return new WP_Error( 'ilswq_source_outside_uploads', __( 'File is outside the uploads directory', 'indexlane-safe-webp-queue' ) );
		}

		$output_path = ILSWQ_Scanner::output_path( $source_path );
		$owned_path  = isset( $source['existing_plugin_webp_path'] ) ? wp_normalize_path( (string) $source['existing_plugin_webp_path'] ) : '';
		$owns_output = $output_path === $owned_path;

		if ( file_exists( $output_path ) && ! $owns_output ) {
			return array(
				'conflict' => true,
				'reason'   => __( 'A sibling WebP file exists but is not owned by this plugin. Move or rename it before converting.', 'indexlane-safe-webp-queue' ),
			);
		}

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

		$saved = $editor->save( $temporary['seed'], 'image/webp' );
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

		if ( ! file_exists( $generated_path ) ) {
			$this->delete_temporary_output( $temporary );

			return new WP_Error( 'ilswq_conversion_failed', __( 'Conversion failed', 'indexlane-safe-webp-queue' ) );
		}

		$webp_size = $this->validate_webp_file( $generated_path );
		if ( is_wp_error( $webp_size ) ) {
			$this->delete_temporary_output( $temporary );

			return $webp_size;
		}

		$original_size = isset( $source['original_size'] ) ? (int) $source['original_size'] : 0;
		if ( ! empty( $settings['skip_larger'] ) && $original_size > 0 && $webp_size >= $original_size ) {
			$this->delete_temporary_output( $temporary );

			return array(
				'skipped' => true,
				'reason'  => __( 'WebP larger than original', 'indexlane-safe-webp-queue' ),
			);
		}

		clearstatcache( true, $output_path );
		if ( file_exists( $output_path ) && ! $owns_output ) {
			$this->delete_temporary_output( $temporary );

			return array(
				'conflict' => true,
				'reason'   => __( 'A sibling WebP file appeared during conversion and was left unchanged.', 'indexlane-safe-webp-queue' ),
			);
		}

		$replace_owned_output = $owns_output && file_exists( $output_path );
		if ( ! $this->move_generated_file( $generated_path, $output_path, $replace_owned_output ) ) {
			$this->delete_temporary_output( $temporary );

			return new WP_Error( 'ilswq_move_failed', __( 'Could not install the validated WebP file.', 'indexlane-safe-webp-queue' ) );
		}

		clearstatcache( true, $output_path );
		if ( ! file_exists( $output_path ) ) {
			return new WP_Error( 'ilswq_install_failed', __( 'The validated WebP file could not be found after installation.', 'indexlane-safe-webp-queue' ) );
		}

		$editor_label = false !== strpos( $editor_class, 'Imagick' ) ? 'Imagick' : 'GD';

		return array(
			'entry' => array(
				'name'        => isset( $source['name'] ) ? sanitize_key( (string) $source['name'] ) : 'full',
				'label'       => isset( $source['label'] ) ? (string) $source['label'] : '',
				'source'      => $source_path,
				'webp'        => $output_path,
				'source_size' => $original_size,
				'webp_size'   => $webp_size,
				'width'       => isset( $source['width'] ) ? (int) $source['width'] : 0,
				'height'      => isset( $source['height'] ) ? (int) $source['height'] : 0,
				'mime_type'   => isset( $source['mime_type'] ) ? (string) $source['mime_type'] : '',
				'editor'      => $editor_label,
				'created'     => current_time( 'mysql' ),
				'version'     => ILSWQ_VERSION,
			),
		);
	}

	/**
	 * Validate generated output as a non-empty WebP file.
	 *
	 * @param string $final_path Generated file path.
	 * @return int|WP_Error WebP byte size on success.
	 */
	private function validate_webp_file( $final_path ) {
		$webp_size = filesize( $final_path );

		if ( false === $webp_size || $webp_size <= 0 ) {
			return new WP_Error( 'ilswq_empty_webp', __( 'Generated WebP file is empty', 'indexlane-safe-webp-queue' ) );
		}

		if ( ! ILSWQ_Scanner::is_valid_webp_file( $final_path ) ) {
			return new WP_Error( 'ilswq_invalid_webp', __( 'Generated file is not a valid WebP image', 'indexlane-safe-webp-queue' ) );
		}

		return (int) $webp_size;
	}

	/**
	 * Reserve temporary paths beside the final WebP output.
	 *
	 * @param string $output_path Final WebP output path.
	 * @return array<string, string>|WP_Error
	 */
	private function create_temporary_output( $output_path ) {
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

			return new WP_Error( 'ilswq_temp_failed', __( 'Could not reserve a temporary WebP file in the uploads directory.', 'indexlane-safe-webp-queue' ) );
		}

		$temporary_output = preg_replace( '/\.tmp$/i', '.webp', $seed );
		if ( ! is_string( $temporary_output ) || $temporary_output === $seed || $temporary_output === $output_path ) {
			wp_delete_file( $seed );

			return new WP_Error( 'ilswq_temp_failed', __( 'Could not reserve a temporary WebP file in the uploads directory.', 'indexlane-safe-webp-queue' ) );
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
		update_post_meta( $attachment_id, ILSWQ_META_WEBP_FILES, $this->map_for_storage( $map ) );
		update_post_meta( $attachment_id, ILSWQ_META_CREATED, current_time( 'mysql' ) );
		update_post_meta( $attachment_id, ILSWQ_META_VERSION, ILSWQ_VERSION );

		if ( isset( $map['full'] ) && is_array( $map['full'] ) ) {
			update_post_meta( $attachment_id, ILSWQ_META_WEBP_SIZE, isset( $map['full']['webp_size'] ) ? (int) $map['full']['webp_size'] : 0 );
			update_post_meta( $attachment_id, ILSWQ_META_SOURCE_SIZE, isset( $map['full']['source_size'] ) ? (int) $map['full']['source_size'] : 0 );
			update_post_meta( $attachment_id, ILSWQ_META_EDITOR, isset( $map['full']['editor'] ) ? (string) $map['full']['editor'] : '' );
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
				'name'        => isset( $entry['name'] ) ? sanitize_key( (string) $entry['name'] ) : sanitize_key( (string) $name ),
				'label'       => isset( $entry['label'] ) ? sanitize_text_field( (string) $entry['label'] ) : '',
				'source_rel'  => $source_rel,
				'webp_rel'    => $webp_rel,
				'source_size' => isset( $entry['source_size'] ) ? (int) $entry['source_size'] : 0,
				'webp_size'   => isset( $entry['webp_size'] ) ? (int) $entry['webp_size'] : 0,
				'width'       => isset( $entry['width'] ) ? (int) $entry['width'] : 0,
				'height'      => isset( $entry['height'] ) ? (int) $entry['height'] : 0,
				'mime_type'   => isset( $entry['mime_type'] ) ? sanitize_mime_type( (string) $entry['mime_type'] ) : '',
				'editor'      => isset( $entry['editor'] ) ? sanitize_text_field( (string) $entry['editor'] ) : '',
				'created'     => isset( $entry['created'] ) ? sanitize_text_field( (string) $entry['created'] ) : current_time( 'mysql' ),
				'version'     => ILSWQ_VERSION,
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
	 * Return generated paths stored for cleanup.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<int, string>
	 */
	private function generated_paths_for_cleanup( $attachment_id ) {
		$paths = array();
		$map   = ILSWQ_Scanner::get_webp_map( $attachment_id );

		foreach ( $map as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['webp'] ) ) {
				$paths[] = wp_normalize_path( (string) $entry['webp'] );
			}
		}

		$legacy_path = (string) get_post_meta( $attachment_id, ILSWQ_META_WEBP_PATH, true );
		if ( '' !== $legacy_path ) {
			$paths[] = wp_normalize_path( $legacy_path );
		}

		return array_values( array_unique( array_filter( $paths ) ) );
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
		if ( '.webp' !== substr( strtolower( $path ), -5 ) ) {
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
}

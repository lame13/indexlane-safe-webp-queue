<?php
/**
 * Media Library scanner.
 *
 * @package IndexLaneSafeWebPQueue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scans attachments without decoding full image data.
 */
class ILSWQ_Scanner {
	const PER_PAGE = 50;

	/**
	 * Scan one page of image attachments.
	 *
	 * @param int                $page Page number.
	 * @param int                $per_page Attachments per page.
	 * @param array<string, int> $settings Settings.
	 * @return array<string, mixed>
	 */
	public function scan_page( $page, $per_page, $settings ) {
		$page     = max( 1, absint( $page ) );
		$per_page = max( 1, min( 100, absint( $per_page ) ) );

		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array(
					'image/jpeg',
					'image/jpg',
					'image/pjpeg',
					'image/png',
					'image/x-png',
					'image/webp',
					'image/gif',
					'image/svg+xml',
				),
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'paged'          => $page,
				'posts_per_page' => $per_page,
			)
		);

		$rows = array();
		foreach ( $query->posts as $attachment_id ) {
			$rows[] = $this->scan_attachment( (int) $attachment_id, $settings );
		}

		return array(
			'rows'       => $rows,
			'page'       => $page,
			'nextPage'   => $page + 1,
			'total'      => (int) $query->found_posts,
			'totalPages' => (int) $query->max_num_pages,
			'hasMore'    => $page < (int) $query->max_num_pages,
		);
	}

	/**
	 * Scan a single attachment.
	 *
	 * @param int                $attachment_id Attachment ID.
	 * @param array<string, int> $settings Settings.
	 * @return array<string, mixed>
	 */
	public function scan_attachment( $attachment_id, $settings ) {
		$row     = $this->base_row( $attachment_id );
		$sources = $this->scan_sources( $attachment_id, $settings );

		if ( empty( $sources ) ) {
			return $this->with_status( $row, 'skipped', __( 'File missing', 'indexlane-safe-webp-queue' ), false );
		}

		return $this->summarize_sources( $row, $sources );
	}

	/**
	 * Return scanned source files for an attachment.
	 *
	 * @param int                      $attachment_id Attachment ID.
	 * @param array<string, int>       $settings Settings.
	 * @param array<string,mixed>|null $metadata Optional attachment metadata.
	 * @return array<int, array<string, mixed>>
	 */
	public function scan_sources( $attachment_id, $settings, $metadata = null ) {
		$sources  = $this->get_attachment_sources( $attachment_id, $metadata );
		$webp_map = self::get_webp_map( $attachment_id );
		$scanned  = array();

		foreach ( $sources as $source ) {
			$scanned[] = $this->scan_source( $source, $settings, $webp_map );
		}

		return $scanned;
	}

	/**
	 * Return original and intermediate image source files for an attachment.
	 *
	 * @param int                      $attachment_id Attachment ID.
	 * @param array<string,mixed>|null $metadata Optional attachment metadata.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_attachment_sources( $attachment_id, $metadata = null ) {
		$file = get_attached_file( $attachment_id );
		if ( empty( $file ) ) {
			return array();
		}

		$file     = wp_normalize_path( $file );
		if ( null === $metadata ) {
			$metadata = wp_get_attachment_metadata( $attachment_id );
		}
		$metadata = is_array( $metadata ) ? $metadata : array();

		$mime_type = get_post_mime_type( $attachment_id );
		if ( empty( $mime_type ) ) {
			$file_type = wp_check_filetype( $file );
			$mime_type = isset( $file_type['type'] ) ? (string) $file_type['type'] : '';
		}

		$sources = array(
			array(
				'name'      => 'full',
				'label'     => __( 'Full size', 'indexlane-safe-webp-queue' ),
				'path'      => $file,
				'file'      => wp_basename( $file ),
				'mime_type' => $mime_type,
				'width'     => isset( $metadata['width'] ) ? absint( $metadata['width'] ) : 0,
				'height'    => isset( $metadata['height'] ) ? absint( $metadata['height'] ) : 0,
			),
		);

		$seen = array( $file => true );

		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size_name => $size ) {
				if ( empty( $size['file'] ) ) {
					continue;
				}

				$size_path = $this->build_intermediate_path( $file, (string) $size['file'] );
				if ( isset( $seen[ $size_path ] ) ) {
					continue;
				}

				$size_mime = isset( $size['mime-type'] ) ? (string) $size['mime-type'] : '';
				if ( '' === $size_mime ) {
					$file_type = wp_check_filetype( $size_path );
					$size_mime = isset( $file_type['type'] ) ? (string) $file_type['type'] : '';
				}

				$sources[] = array(
					'name'      => sanitize_key( (string) $size_name ),
					'label'     => (string) $size_name,
					'path'      => $size_path,
					'file'      => wp_basename( $size_path ),
					'mime_type' => $size_mime,
					'width'     => isset( $size['width'] ) ? absint( $size['width'] ) : 0,
					'height'    => isset( $size['height'] ) ? absint( $size['height'] ) : 0,
				);

				$seen[ $size_path ] = true;
			}
		}

		return $sources;
	}

	/**
	 * Return generated WebP map for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_webp_map( $attachment_id ) {
		$stored_map = get_post_meta( $attachment_id, ILSWQ_META_WEBP_FILES, true );
		$stored_map = is_array( $stored_map ) ? $stored_map : array();
		$map        = array();

		$legacy_path = (string) get_post_meta( $attachment_id, ILSWQ_META_WEBP_PATH, true );
		if ( '' !== $legacy_path && ! isset( $stored_map['full'] ) ) {
			$stored_map['full'] = array(
				'source'      => (string) get_attached_file( $attachment_id ),
				'webp'        => $legacy_path,
				'webp_size'   => (int) get_post_meta( $attachment_id, ILSWQ_META_WEBP_SIZE, true ),
				'source_size' => (int) get_post_meta( $attachment_id, ILSWQ_META_SOURCE_SIZE, true ),
				'editor'      => (string) get_post_meta( $attachment_id, ILSWQ_META_EDITOR, true ),
			);
		}

		foreach ( $stored_map as $name => $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['webp'] ) ) {
				if ( empty( $entry['webp_rel'] ) ) {
					continue;
				}
			}

			$source = '';
			$webp   = '';

			if ( ! empty( $entry['source_rel'] ) ) {
				$source = self::relative_to_path( (string) $entry['source_rel'] );
			} elseif ( ! empty( $entry['source'] ) ) {
				$source = wp_normalize_path( (string) $entry['source'] );
			}

			if ( ! empty( $entry['webp_rel'] ) ) {
				$webp = self::relative_to_path( (string) $entry['webp_rel'] );
			} elseif ( ! empty( $entry['webp'] ) ) {
				$webp = wp_normalize_path( (string) $entry['webp'] );
			}

			if ( '' === $webp ) {
				continue;
			}

			$entry['source'] = $source;
			$entry['webp']   = $webp;
			$map[ $name ]    = $entry;
		}

		return $map;
	}

	/**
	 * Convert an uploads-relative path to an absolute path.
	 *
	 * @param string $relative Relative uploads path.
	 * @return string
	 */
	public static function relative_to_path( $relative ) {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		$relative = ltrim( wp_normalize_path( $relative ), '/' );
		if ( '' === $relative || '.' === $relative || '..' === $relative || false !== strpos( $relative, '../' ) || false !== strpos( $relative, '/..' ) ) {
			return '';
		}

		return trailingslashit( wp_normalize_path( $uploads['basedir'] ) ) . $relative;
	}

	/**
	 * Convert an absolute uploads path to a relative uploads path.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	public static function path_to_relative( $path ) {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		$path     = wp_normalize_path( $path );
		$base_dir = trailingslashit( wp_normalize_path( $uploads['basedir'] ) );

		if ( 0 !== strpos( $path, $base_dir ) ) {
			return '';
		}

		return ltrim( substr( $path, strlen( $base_dir ) ), '/' );
	}

	/**
	 * Return true when a file path resolves inside the uploads directory.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	public static function is_uploads_path( $path ) {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $path ) ) {
			return false;
		}

		$real_base = realpath( $uploads['basedir'] );
		$real_path = realpath( dirname( $path ) );

		if ( false === $real_base || false === $real_path ) {
			return false;
		}

		$real_base = trailingslashit( wp_normalize_path( $real_base ) );
		$real_path = trailingslashit( wp_normalize_path( $real_path ) );

		return 0 === strpos( $real_path, $real_base );
	}

	/**
	 * Build the plugin output path for a source file.
	 *
	 * @param string $source_file Source file path.
	 * @return string
	 */
	public static function output_path( $source_file ) {
		return wp_normalize_path( $source_file ) . '.webp';
	}

	/**
	 * Convert an uploads path to a URL when possible.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	public static function path_to_url( $path ) {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			return '';
		}

		$path     = wp_normalize_path( $path );
		$base_dir = trailingslashit( wp_normalize_path( $uploads['basedir'] ) );

		if ( 0 !== strpos( $path, $base_dir ) ) {
			return '';
		}

		$relative = ltrim( substr( $path, strlen( $base_dir ) ), '/' );
		$parts    = array_map( 'rawurlencode', explode( '/', $relative ) );

		return trailingslashit( $uploads['baseurl'] ) . implode( '/', $parts );
	}

	/**
	 * Return true when a file is a valid non-empty WebP image.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	public static function is_valid_webp_file( $path ) {
		if ( '' === $path || ! file_exists( $path ) ) {
			return false;
		}

		$size = filesize( $path );
		if ( false === $size || $size <= 0 ) {
			return false;
		}

		$info = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( $path ) : @getimagesize( $path );

		return is_array( $info ) && ! empty( $info['mime'] ) && 'image/webp' === $info['mime'];
	}

	/**
	 * Validate every generated WebP entry stored for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, int|bool>
	 */
	public static function validate_generated_files( $attachment_id ) {
		$stored_map = get_post_meta( $attachment_id, ILSWQ_META_WEBP_FILES, true );
		$has_map    = metadata_exists( 'post', $attachment_id, ILSWQ_META_WEBP_FILES );
		$legacy     = (string) get_post_meta( $attachment_id, ILSWQ_META_WEBP_PATH, true );
		$map        = self::get_webp_map( $attachment_id );

		if ( is_array( $stored_map ) ) {
			$total = count( $stored_map );
		} elseif ( $has_map ) {
			$total = 1;
		} else {
			$total = 0;
		}

		if ( '' !== $legacy && ( ! is_array( $stored_map ) || ! isset( $stored_map['full'] ) ) ) {
			++$total;
		}

		$invalid = max( 0, $total - count( $map ) );
		foreach ( $map as $entry ) {
			$path = is_array( $entry ) && ! empty( $entry['webp'] ) ? wp_normalize_path( (string) $entry['webp'] ) : '';
			if (
				'' === $path ||
				'.webp' !== substr( strtolower( $path ), -5 ) ||
				! self::is_uploads_path( $path ) ||
				! self::is_valid_webp_file( $path )
			) {
				++$invalid;
			}
		}

		return array(
			'has_files' => $total > 0,
			'valid'     => $total > 0 && 0 === $invalid,
			'total'     => $total,
			'invalid'   => $invalid,
		);
	}

	/**
	 * Format bytes for display.
	 *
	 * @param int|float $bytes Bytes.
	 * @return string
	 */
	public static function format_bytes( $bytes ) {
		$bytes = (float) $bytes;

		if ( $bytes <= 0 ) {
			return '0 B';
		}

		$units = array(
			__( 'B', 'indexlane-safe-webp-queue' ),
			__( 'KB', 'indexlane-safe-webp-queue' ),
			__( 'MB', 'indexlane-safe-webp-queue' ),
			__( 'GB', 'indexlane-safe-webp-queue' ),
		);
		$index = 0;

		while ( $bytes >= 1024 && $index < count( $units ) - 1 ) {
			$bytes /= 1024;
			++$index;
		}

		return sprintf( '%s %s', number_format_i18n( $bytes, $index > 0 ? 1 : 0 ), $units[ $index ] );
	}

	/**
	 * Estimate decoded memory risk.
	 *
	 * @param int $width Width.
	 * @param int $height Height.
	 * @return int
	 */
	public static function estimate_memory_bytes( $width, $height ) {
		$width  = max( 0, (int) $width );
		$height = max( 0, (int) $height );

		return $width * $height * 5;
	}

	/**
	 * Build a portable fingerprint for one generated output.
	 *
	 * @param string $source_path Source path.
	 * @param int    $source_size Source byte size.
	 * @param int    $source_mtime Source modification time.
	 * @param string $mime_type Source MIME type.
	 * @param int    $quality Conversion quality.
	 * @return string
	 */
	public static function generation_fingerprint( $source_path, $source_size, $source_mtime, $mime_type, $quality ) {
		$relative = self::path_to_relative( $source_path );
		$payload  = array(
			'schema'       => 1,
			'source'       => $relative,
			'source_size'  => max( 0, (int) $source_size ),
			'source_mtime' => max( 0, (int) $source_mtime ),
			'mime_type'    => sanitize_mime_type( $mime_type ),
			'quality'      => max( 1, min( 100, absint( $quality ) ) ),
		);

		return hash( 'sha256', (string) wp_json_encode( $payload ) );
	}

	/**
	 * Return whether a generated entry still represents its source file.
	 *
	 * Quality is intentionally excluded so a valid old output can continue to
	 * be served until its atomically generated replacement is ready.
	 *
	 * @param array<string, mixed> $entry Runtime map entry.
	 * @return bool
	 */
	public static function source_matches_map_entry( $entry ) {
		if ( empty( $entry['source'] ) || empty( $entry['webp'] ) ) {
			return false;
		}

		$source_path = wp_normalize_path( (string) $entry['source'] );
		$webp_path   = wp_normalize_path( (string) $entry['webp'] );
		if ( ! file_exists( $source_path ) || ! file_exists( $webp_path ) ) {
			return false;
		}

		$source_size = filesize( $source_path );
		if ( false !== $source_size && ! empty( $entry['source_size'] ) && (int) $entry['source_size'] !== (int) $source_size ) {
			return false;
		}

		$source_mtime = filemtime( $source_path );
		$webp_mtime   = filemtime( $webp_path );
		if ( false !== $source_mtime && ! empty( $entry['source_mtime'] ) && (int) $entry['source_mtime'] !== (int) $source_mtime ) {
			return false;
		}

		if ( false !== $source_mtime && false !== $webp_mtime && $webp_mtime < $source_mtime ) {
			return false;
		}

		return true;
	}

	/**
	 * Return true for supported source image types.
	 *
	 * @param string $mime_type MIME type.
	 * @return bool
	 */
	public static function is_supported_source_mime( $mime_type ) {
		return in_array( $mime_type, array( 'image/jpeg', 'image/jpg', 'image/pjpeg', 'image/png', 'image/x-png' ), true );
	}

	/**
	 * Add status fields.
	 *
	 * @param array<string, mixed> $row Row.
	 * @param string               $status_key Status key.
	 * @param string               $reason Reason.
	 * @param bool                 $eligible Eligible.
	 * @return array<string, mixed>
	 */
	public function with_status( $row, $status_key, $reason, $eligible ) {
		$status_key        = sanitize_title( $status_key );
		$row['status']     = self::status_label( $status_key );
		$row['status_key'] = $status_key;
		$row['reason']     = $reason;
		$row['eligible']   = (bool) $eligible;

		return $row;
	}

	/**
	 * Return the translated label for a stable status key.
	 *
	 * @param string $status_key Status key.
	 * @return string
	 */
	public static function status_label( $status_key ) {
		switch ( $status_key ) {
			case 'eligible':
				return __( 'Eligible', 'indexlane-safe-webp-queue' );
			case 'converted':
				return __( 'Converted', 'indexlane-safe-webp-queue' );
			case 'already-exists':
				return __( 'Already exists', 'indexlane-safe-webp-queue' );
			case 'needs-review':
				return __( 'Needs review', 'indexlane-safe-webp-queue' );
			case 'failed':
				return __( 'Failed', 'indexlane-safe-webp-queue' );
			case 'conflict':
				return __( 'Conflict', 'indexlane-safe-webp-queue' );
			case 'skipped':
			default:
				return __( 'Skipped', 'indexlane-safe-webp-queue' );
		}
	}

	/**
	 * Scan one source file.
	 *
	 * @param array<string, mixed>               $source Source data.
	 * @param array<string, int>                 $settings Settings.
	 * @param array<string, array<string,mixed>> $webp_map WebP map.
	 * @return array<string, mixed>
	 */
	private function scan_source( $source, $settings, $webp_map ) {
		$source['status']                 = self::status_label( 'skipped' );
		$source['status_key']             = 'skipped';
		$source['reason']                 = '';
		$source['eligible']               = false;
		$source['original_size']          = 0;
		$source['original_size_label']    = '';
		$source['estimated_memory']       = 0;
		$source['estimated_memory_label'] = '';
		$source['webp_size']              = 0;
		$source['webp_size_label']        = '';
		$source['webp_url']               = '';

		$file = isset( $source['path'] ) ? wp_normalize_path( (string) $source['path'] ) : '';
		if ( '' === $file ) {
			return $this->source_with_status( $source, 'skipped', __( 'File missing', 'indexlane-safe-webp-queue' ), false );
		}

		if ( ! file_exists( $file ) ) {
			return $this->source_with_status( $source, 'skipped', __( 'File missing', 'indexlane-safe-webp-queue' ), false );
		}

		if ( ! is_readable( $file ) ) {
			return $this->source_with_status( $source, 'skipped', __( 'File is not readable', 'indexlane-safe-webp-queue' ), false );
		}

		if ( ! self::is_uploads_path( $file ) ) {
			return $this->source_with_status( $source, 'skipped', __( 'File is outside the uploads directory', 'indexlane-safe-webp-queue' ), false );
		}

		$mime_type = isset( $source['mime_type'] ) ? (string) $source['mime_type'] : '';
		if ( '' === $mime_type ) {
			$file_type = wp_check_filetype( $file );
			$mime_type = isset( $file_type['type'] ) ? (string) $file_type['type'] : '';
		}

		$source['mime_type'] = $mime_type;
		$source['type']      = $this->short_mime_label( $mime_type );

		$dimensions = $this->fill_dimensions( $source, $file );
		$width      = (int) $dimensions['width'];
		$height     = (int) $dimensions['height'];

		$source['width']       = $width;
		$source['height']      = $height;
		$source['dimensions']  = $width > 0 && $height > 0 ? sprintf( '%d x %d', $width, $height ) : '';
		$source['pixel_count'] = $width * $height;

		$original_size                 = filesize( $file );
		$source['original_size']       = false === $original_size ? 0 : (int) $original_size;
		$source['original_size_label'] = self::format_bytes( $source['original_size'] );

		$estimated_memory                      = self::estimate_memory_bytes( $width, $height );
		$source['estimated_memory']            = $estimated_memory;
		$source['estimated_memory_label']      = self::format_bytes( $estimated_memory );
		$source['output_path']                 = self::output_path( $file );
		$source['existing_plugin_webp_entry']  = $this->webp_entry_from_map( $source, $webp_map );
		$source['existing_plugin_webp_path']   = ! empty( $source['existing_plugin_webp_entry']['webp'] ) ? wp_normalize_path( (string) $source['existing_plugin_webp_entry']['webp'] ) : '';

		if ( '' !== $source['existing_plugin_webp_path'] && file_exists( $source['existing_plugin_webp_path'] ) ) {
			if ( ! self::is_valid_webp_file( $source['existing_plugin_webp_path'] ) ) {
				return $this->source_with_status( $source, 'needs-review', __( 'Generated WebP file is invalid', 'indexlane-safe-webp-queue' ), true );
			}

			$entry_issue = $this->generated_entry_issue( $source, $source['existing_plugin_webp_entry'], $settings );
			if ( '' !== $entry_issue ) {
				return $this->source_with_status( $source, 'needs-review', $entry_issue, true );
			}

			return $this->source_with_existing_webp(
				$source,
				$source['existing_plugin_webp_path'],
				'converted',
				__( 'Generated by this plugin', 'indexlane-safe-webp-queue' )
			);
		}

		if ( file_exists( $source['output_path'] ) ) {
			return $this->source_with_existing_webp(
				$source,
				$source['output_path'],
				'conflict',
				__( 'A sibling WebP file exists but is not owned by this plugin. Move or rename it before converting.', 'indexlane-safe-webp-queue' )
			);
		}

		if ( 'image/webp' === $mime_type ) {
			return $this->source_with_status( $source, 'skipped', __( 'Already WebP', 'indexlane-safe-webp-queue' ), false );
		}

		if ( ! self::is_supported_source_mime( $mime_type ) ) {
			return $this->source_with_status( $source, 'skipped', __( 'Unsupported MIME type', 'indexlane-safe-webp-queue' ), false );
		}

		if ( $width <= 0 || $height <= 0 ) {
			return $this->source_with_status( $source, 'skipped', __( 'Dimensions unavailable', 'indexlane-safe-webp-queue' ), false );
		}

		if ( ! ILSWQ_Capabilities::has_webp_writer() ) {
			return $this->source_with_status( $source, 'skipped', __( 'WebP not supported by server', 'indexlane-safe-webp-queue' ), false );
		}

		if ( ! ILSWQ_Capabilities::is_writable_path( dirname( $file ) ) ) {
			return $this->source_with_status( $source, 'skipped', __( 'Upload directory not writable', 'indexlane-safe-webp-queue' ), false );
		}

		if ( ! empty( $settings['max_pixels'] ) && $source['pixel_count'] > (int) $settings['max_pixels'] ) {
			return $this->source_with_status( $source, 'skipped', __( 'Image exceeds max pixel limit', 'indexlane-safe-webp-queue' ), false );
		}

		$memory_limit = ILSWQ_Capabilities::memory_limit_bytes();
		if ( $memory_limit > 0 ) {
			$safe_memory = (int) floor( $memory_limit * 0.70 );
			if ( $estimated_memory > $safe_memory ) {
				return $this->source_with_status( $source, 'skipped', __( 'Image too large for current memory limit', 'indexlane-safe-webp-queue' ), false );
			}
		}

		return $this->source_with_status( $source, 'eligible', __( 'Ready for conversion', 'indexlane-safe-webp-queue' ), true );
	}

	/**
	 * Build base row fields.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, mixed>
	 */
	private function base_row( $attachment_id ) {
		$title = get_the_title( $attachment_id );

		return array(
			'id'                     => $attachment_id,
			'title'                  => '' !== $title ? $title : sprintf(
				/* translators: %d: attachment ID. */
				__( 'Attachment #%d', 'indexlane-safe-webp-queue' ),
				$attachment_id
			),
			'edit_url'               => get_edit_post_link( $attachment_id, '' ),
			'file'                   => '',
			'type'                   => '',
			'mime_type'              => '',
			'width'                  => 0,
			'height'                 => 0,
			'dimensions'             => '',
			'pixel_count'            => 0,
			'original_size'          => 0,
			'original_size_label'    => '',
			'estimated_memory'       => 0,
			'estimated_memory_label' => '',
			'webp_size'              => 0,
			'webp_size_label'        => '',
			'savings'                => '',
			'webp_url'               => '',
			'editor'                 => '',
			'source_count'           => 0,
			'source_count_label'     => '',
			'eligible_source_count'  => 0,
			'converted_source_count' => 0,
			'generated_source_count' => 0,
			'existing_source_count'  => 0,
			'skipped_source_count'   => 0,
			'status'                 => self::status_label( 'skipped' ),
			'status_key'             => 'skipped',
			'reason'                 => '',
			'eligible'               => false,
		);
	}

	/**
	 * Summarize scanned sources into one admin table row.
	 *
	 * @param array<string, mixed>              $row Row.
	 * @param array<int, array<string, mixed>>  $sources Sources.
	 * @return array<string, mixed>
	 */
	private function summarize_sources( $row, $sources ) {
		$full = isset( $sources[0] ) ? $sources[0] : array();

		$row['file']       = isset( $full['file'] ) ? (string) $full['file'] : '';
		$row['type']       = isset( $full['type'] ) ? (string) $full['type'] : $this->short_mime_label( isset( $full['mime_type'] ) ? (string) $full['mime_type'] : '' );
		$row['mime_type']  = isset( $full['mime_type'] ) ? (string) $full['mime_type'] : '';
		$row['width']      = isset( $full['width'] ) ? (int) $full['width'] : 0;
		$row['height']     = isset( $full['height'] ) ? (int) $full['height'] : 0;
		$row['dimensions'] = isset( $full['dimensions'] ) ? (string) $full['dimensions'] : '';
		$row['editor']     = ILSWQ_Capabilities::preferred_editor_label();

		$source_count          = count( $sources );
		$counts                = array(
			'eligible'       => 0,
			'needs_review'   => 0,
			'converted'      => 0,
			'already_exists' => 0,
			'conflict'       => 0,
			'skipped'        => 0,
			'failed'         => 0,
		);
		$first_reason          = '';
		$first_review_reason   = '';
		$first_conflict_reason = '';

		foreach ( $sources as $source ) {
			$status_key = isset( $source['status_key'] ) ? (string) $source['status_key'] : 'skipped';
			$reason     = isset( $source['reason'] ) ? (string) $source['reason'] : '';
			if ( ! empty( $source['existing_plugin_webp_path'] ) ) {
				++$row['generated_source_count'];
			}

			$row['original_size']    += isset( $source['original_size'] ) ? (int) $source['original_size'] : 0;
			$row['estimated_memory']  = max( (int) $row['estimated_memory'], isset( $source['estimated_memory'] ) ? (int) $source['estimated_memory'] : 0 );
			$row['webp_size']        += isset( $source['webp_size'] ) ? (int) $source['webp_size'] : 0;
			$row['pixel_count']       = max( (int) $row['pixel_count'], isset( $source['pixel_count'] ) ? (int) $source['pixel_count'] : 0 );

			if ( '' === $first_reason && '' !== $reason ) {
				$first_reason = $reason;
			}

			if ( 'needs-review' === $status_key && '' === $first_review_reason && '' !== $reason ) {
				$first_review_reason = $reason;
			}

			if ( 'conflict' === $status_key && '' === $first_conflict_reason && '' !== $reason ) {
				$first_conflict_reason = $reason;
			}

			if ( 'eligible' === $status_key ) {
				++$counts['eligible'];
			} elseif ( 'needs-review' === $status_key ) {
				++$counts['needs_review'];
			} elseif ( 'converted' === $status_key ) {
				++$counts['converted'];
			} elseif ( 'already-exists' === $status_key ) {
				++$counts['already_exists'];
			} elseif ( 'conflict' === $status_key ) {
				++$counts['conflict'];
			} elseif ( 'failed' === $status_key ) {
				++$counts['failed'];
			} else {
				++$counts['skipped'];
			}
		}

		$row['source_count']           = $source_count;
		$row['source_count_label']     = sprintf(
			/* translators: %d: source file count. */
			_n( '%d source file', '%d source files', $source_count, 'indexlane-safe-webp-queue' ),
			$source_count
		);
		$row['eligible_source_count']  = $counts['eligible'] + $counts['needs_review'];
		$row['converted_source_count'] = $counts['converted'];
		$row['existing_source_count']  = $counts['already_exists'];
		$row['skipped_source_count']   = $counts['skipped'];
		$row['original_size_label']    = self::format_bytes( (int) $row['original_size'] );
		$row['estimated_memory_label'] = self::format_bytes( (int) $row['estimated_memory'] );
		$row['webp_size_label']        = (int) $row['webp_size'] > 0 ? self::format_bytes( (int) $row['webp_size'] ) : '';
		$row['savings']                = $this->savings_label( (int) $row['original_size'], (int) $row['webp_size'] );

		if ( $counts['conflict'] > 0 ) {
			return $this->with_status(
				$row,
				'conflict',
				'' !== $first_conflict_reason ? $first_conflict_reason : sprintf(
					/* translators: %d: source file count. */
					_n( '%d source file has a conflicting sibling WebP', '%d source files have conflicting sibling WebPs', $counts['conflict'], 'indexlane-safe-webp-queue' ),
					$counts['conflict']
				),
				$counts['eligible'] + $counts['needs_review'] > 0
			);
		}

		if ( $counts['needs_review'] > 0 ) {
			return $this->with_status(
				$row,
				'needs-review',
				'' !== $first_review_reason ? $first_review_reason : sprintf(
					/* translators: 1: review source count, 2: total source count. */
					__( '%1$d of %2$d source files need review', 'indexlane-safe-webp-queue' ),
					$counts['needs_review'],
					$source_count
				),
				true
			);
		}

		if ( $counts['eligible'] > 0 ) {
			return $this->with_status(
				$row,
				'eligible',
				sprintf(
					/* translators: 1: eligible source count, 2: total source count. */
					__( '%1$d of %2$d source files ready', 'indexlane-safe-webp-queue' ),
					$counts['eligible'],
					$source_count
				),
				true
			);
		}

		if ( $counts['failed'] > 0 ) {
			return $this->with_status( $row, 'failed', $first_reason, false );
		}

		if ( $counts['converted'] > 0 && 0 === $counts['skipped'] && 0 === $counts['already_exists'] ) {
			return $this->with_status(
				$row,
				'converted',
				sprintf(
					/* translators: %d: converted source count. */
					__( '%d WebP files generated', 'indexlane-safe-webp-queue' ),
					$counts['converted']
				),
				false
			);
		}

		if ( $counts['converted'] > 0 || $counts['already_exists'] > 0 ) {
			return $this->with_status(
				$row,
				'needs-review',
				sprintf(
					/* translators: 1: ready WebP count, 2: total source count. */
					__( '%1$d of %2$d source files have WebP copies', 'indexlane-safe-webp-queue' ),
					$counts['converted'] + $counts['already_exists'],
					$source_count
				),
				false
			);
		}

		return $this->with_status( $row, 'skipped', $first_reason, false );
	}

	/**
	 * Add status fields to one source.
	 *
	 * @param array<string, mixed> $source Source.
	 * @param string               $status_key Status key.
	 * @param string               $reason Reason.
	 * @param bool                 $eligible Eligible.
	 * @return array<string, mixed>
	 */
	private function source_with_status( $source, $status_key, $reason, $eligible ) {
		$status_key           = sanitize_title( $status_key );
		$source['status']     = self::status_label( $status_key );
		$source['status_key'] = $status_key;
		$source['reason']     = $reason;
		$source['eligible']   = (bool) $eligible;

		return $source;
	}

	/**
	 * Add existing WebP details to one source.
	 *
	 * @param array<string, mixed> $source Source.
	 * @param string               $webp_path WebP path.
	 * @param string               $status_key Status key.
	 * @param string               $reason Reason.
	 * @return array<string, mixed>
	 */
	private function source_with_existing_webp( $source, $webp_path, $status_key, $reason ) {
		$webp_size = filesize( $webp_path );

		$source['webp_size']       = false === $webp_size ? 0 : (int) $webp_size;
		$source['webp_size_label'] = self::format_bytes( $source['webp_size'] );
		$source['webp_url']        = self::path_to_url( $webp_path );

		return $this->source_with_status( $source, $status_key, $reason, false );
	}

	/**
	 * Return the reason a generated entry should be regenerated.
	 *
	 * Existing entries without 0.2 fingerprint fields remain compatible and
	 * continue to use the source/WebP modification-time check.
	 *
	 * @param array<string, mixed> $source Source data.
	 * @param array<string, mixed> $entry Stored map entry.
	 * @param array<string, int>   $settings Current settings.
	 * @return string
	 */
	private function generated_entry_issue( $source, $entry, $settings ) {
		if ( ! self::source_matches_map_entry( $entry ) ) {
			return __( 'Source file changed since WebP generation', 'indexlane-safe-webp-queue' );
		}

		$mime_type       = isset( $source['mime_type'] ) ? (string) $source['mime_type'] : '';
		$current_quality = ILSWQ_Settings::quality_for_mime( $mime_type, $settings );
		$stored_quality  = isset( $entry['quality'] ) ? absint( $entry['quality'] ) : 0;

		if ( $stored_quality > 0 && $stored_quality !== $current_quality ) {
			return __( 'WebP was generated with different quality settings', 'indexlane-safe-webp-queue' );
		}

		if ( ! empty( $entry['fingerprint'] ) && $stored_quality > 0 ) {
			$source_path  = isset( $source['path'] ) ? wp_normalize_path( (string) $source['path'] ) : '';
			$source_size  = isset( $source['original_size'] ) ? (int) $source['original_size'] : 0;
			$source_mtime = filemtime( $source_path );
			$source_mtime = false === $source_mtime ? 0 : (int) $source_mtime;
			$expected     = self::generation_fingerprint( $source_path, $source_size, $source_mtime, $mime_type, $current_quality );

			if ( ! hash_equals( (string) $entry['fingerprint'], $expected ) ) {
				return __( 'WebP generation details no longer match the source', 'indexlane-safe-webp-queue' );
			}
		}

		return '';
	}

	/**
	 * Return source dimensions, falling back to image headers.
	 *
	 * @param array<string, mixed> $source Source.
	 * @param string               $file File path.
	 * @return array<string, int>
	 */
	private function fill_dimensions( $source, $file ) {
		$width  = isset( $source['width'] ) ? absint( $source['width'] ) : 0;
		$height = isset( $source['height'] ) ? absint( $source['height'] ) : 0;

		if ( $width <= 0 || $height <= 0 ) {
			$image_size = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( $file ) : @getimagesize( $file );
			if ( is_array( $image_size ) ) {
				$width  = isset( $image_size[0] ) ? absint( $image_size[0] ) : 0;
				$height = isset( $image_size[1] ) ? absint( $image_size[1] ) : 0;
			}
		}

		return array(
			'width'  => $width,
			'height' => $height,
		);
	}

	/**
	 * Get a plugin-generated WebP entry from the stored map.
	 *
	 * @param array<string, mixed>               $source Source.
	 * @param array<string, array<string,mixed>> $webp_map WebP map.
	 * @return array<string, mixed>
	 */
	private function webp_entry_from_map( $source, $webp_map ) {
		$name        = isset( $source['name'] ) ? (string) $source['name'] : '';
		$path        = isset( $source['path'] ) ? wp_normalize_path( (string) $source['path'] ) : '';
		$output_path = self::output_path( $path );

		if ( '' !== $name && isset( $webp_map[ $name ]['webp'] ) ) {
			$entry_source = isset( $webp_map[ $name ]['source'] ) ? wp_normalize_path( (string) $webp_map[ $name ]['source'] ) : '';
			$entry_webp   = wp_normalize_path( (string) $webp_map[ $name ]['webp'] );
			if ( $path === $entry_source && $output_path === $entry_webp ) {
				return $webp_map[ $name ];
			}
		}

		foreach ( $webp_map as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['source'] ) || empty( $entry['webp'] ) ) {
				continue;
			}

			$entry_source = wp_normalize_path( (string) $entry['source'] );
			$entry_webp   = wp_normalize_path( (string) $entry['webp'] );
			if ( $path === $entry_source && $output_path === $entry_webp ) {
				return $entry;
			}
		}

		return array();
	}

	/**
	 * Build an intermediate source path.
	 *
	 * @param string $full_path Full attachment path.
	 * @param string $relative_file Intermediate file value from metadata.
	 * @return string
	 */
	private function build_intermediate_path( $full_path, $relative_file ) {
		$relative_file = wp_normalize_path( $relative_file );

		if ( function_exists( 'path_is_absolute' ) && path_is_absolute( $relative_file ) ) {
			return $relative_file;
		}

		return trailingslashit( dirname( $full_path ) ) . $relative_file;
	}

	/**
	 * Return a short MIME label.
	 *
	 * @param string $mime_type MIME type.
	 * @return string
	 */
	private function short_mime_label( $mime_type ) {
		switch ( $mime_type ) {
			case 'image/jpeg':
			case 'image/jpg':
			case 'image/pjpeg':
				return 'JPEG';
			case 'image/png':
			case 'image/x-png':
				return 'PNG';
			case 'image/webp':
				return 'WebP';
			case 'image/gif':
				return 'GIF';
			case 'image/svg+xml':
				return 'SVG';
			default:
				return $mime_type;
		}
	}

	/**
	 * Build savings label.
	 *
	 * @param int $original_size Original bytes.
	 * @param int $webp_size WebP bytes.
	 * @return string
	 */
	private function savings_label( $original_size, $webp_size ) {
		if ( $original_size <= 0 || $webp_size <= 0 ) {
			return '';
		}

		$saved   = $original_size - $webp_size;
		$percent = ( $saved / $original_size ) * 100;

		return sprintf( '%s%%', number_format_i18n( $percent, 1 ) );
	}
}

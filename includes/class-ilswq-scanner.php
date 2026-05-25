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
			return $this->with_status( $row, 'Skipped', __( 'File missing', 'indexlane-safe-webp-queue' ), false );
		}

		return $this->summarize_sources( $row, $sources );
	}

	/**
	 * Return scanned source files for an attachment.
	 *
	 * @param int                $attachment_id Attachment ID.
	 * @param array<string, int> $settings Settings.
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
	 * @param int $attachment_id Attachment ID.
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

		$units = array( 'B', 'KB', 'MB', 'GB' );
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
	 * @param string               $status Status.
	 * @param string               $reason Reason.
	 * @param bool                 $eligible Eligible.
	 * @return array<string, mixed>
	 */
	public function with_status( $row, $status, $reason, $eligible ) {
		$row['status']     = $status;
		$row['status_key'] = sanitize_title( $status );
		$row['reason']     = $reason;
		$row['eligible']   = (bool) $eligible;

		return $row;
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
		$source['status']                 = 'Skipped';
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
			return $this->source_with_status( $source, 'Skipped', __( 'File missing', 'indexlane-safe-webp-queue' ), false );
		}

		if ( ! file_exists( $file ) ) {
			return $this->source_with_status( $source, 'Skipped', __( 'File missing', 'indexlane-safe-webp-queue' ), false );
		}

		if ( ! is_readable( $file ) ) {
			return $this->source_with_status( $source, 'Skipped', __( 'File is not readable', 'indexlane-safe-webp-queue' ), false );
		}

		if ( ! self::is_uploads_path( $file ) ) {
			return $this->source_with_status( $source, 'Skipped', __( 'File is outside the uploads directory', 'indexlane-safe-webp-queue' ), false );
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

		$estimated_memory                    = self::estimate_memory_bytes( $width, $height );
		$source['estimated_memory']          = $estimated_memory;
		$source['estimated_memory_label']    = self::format_bytes( $estimated_memory );
		$source['output_path']               = self::output_path( $file );
		$source['existing_plugin_webp_path'] = $this->webp_path_from_map( $source, $webp_map );

		if ( '' !== $source['existing_plugin_webp_path'] && file_exists( $source['existing_plugin_webp_path'] ) ) {
			if ( ! self::is_valid_webp_file( $source['existing_plugin_webp_path'] ) ) {
				return $this->source_with_status( $source, 'Needs review', __( 'Generated WebP file is invalid', 'indexlane-safe-webp-queue' ), true );
			}

			if ( $this->webp_is_stale( $file, $source['existing_plugin_webp_path'] ) ) {
				return $this->source_with_status( $source, 'Needs review', __( 'Generated WebP is older than the source file', 'indexlane-safe-webp-queue' ), true );
			}

			return $this->source_with_existing_webp(
				$source,
				$source['existing_plugin_webp_path'],
				'Converted',
				__( 'Generated by this plugin', 'indexlane-safe-webp-queue' )
			);
		}

		if ( file_exists( $source['output_path'] ) ) {
			if ( ! self::is_valid_webp_file( $source['output_path'] ) ) {
				return $this->source_with_status( $source, 'Needs review', __( 'Existing sibling WebP is invalid', 'indexlane-safe-webp-queue' ), true );
			}

			if ( $this->webp_is_stale( $file, $source['output_path'] ) ) {
				return $this->source_with_status( $source, 'Needs review', __( 'Existing sibling WebP is older than the source file', 'indexlane-safe-webp-queue' ), true );
			}

			return $this->source_with_existing_webp(
				$source,
				$source['output_path'],
				'Already exists',
				__( 'Sibling WebP already exists', 'indexlane-safe-webp-queue' )
			);
		}

		if ( 'image/webp' === $mime_type ) {
			return $this->source_with_status( $source, 'Skipped', __( 'Already WebP', 'indexlane-safe-webp-queue' ), false );
		}

		if ( ! self::is_supported_source_mime( $mime_type ) ) {
			return $this->source_with_status( $source, 'Skipped', __( 'Unsupported MIME type', 'indexlane-safe-webp-queue' ), false );
		}

		if ( $width <= 0 || $height <= 0 ) {
			return $this->source_with_status( $source, 'Skipped', __( 'Dimensions unavailable', 'indexlane-safe-webp-queue' ), false );
		}

		if ( ! ILSWQ_Capabilities::has_webp_writer() ) {
			return $this->source_with_status( $source, 'Skipped', __( 'WebP not supported by server', 'indexlane-safe-webp-queue' ), false );
		}

		if ( ! ILSWQ_Capabilities::is_writable_path( dirname( $file ) ) ) {
			return $this->source_with_status( $source, 'Skipped', __( 'Upload directory not writable', 'indexlane-safe-webp-queue' ), false );
		}

		if ( ! empty( $settings['max_pixels'] ) && $source['pixel_count'] > (int) $settings['max_pixels'] ) {
			return $this->source_with_status( $source, 'Skipped', __( 'Image exceeds max pixel limit', 'indexlane-safe-webp-queue' ), false );
		}

		$memory_limit = ILSWQ_Capabilities::memory_limit_bytes();
		if ( $memory_limit > 0 ) {
			$safe_memory = (int) floor( $memory_limit * 0.70 );
			if ( $estimated_memory > $safe_memory ) {
				return $this->source_with_status( $source, 'Skipped', __( 'Image too large for current memory limit', 'indexlane-safe-webp-queue' ), false );
			}
		}

		return $this->source_with_status( $source, 'Eligible', __( 'Ready for conversion', 'indexlane-safe-webp-queue' ), true );
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
			'existing_source_count'  => 0,
			'skipped_source_count'   => 0,
			'status'                 => 'Skipped',
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

		$source_count = count( $sources );
		$counts       = array(
			'eligible'       => 0,
			'needs_review'   => 0,
			'converted'      => 0,
			'already_exists' => 0,
			'skipped'        => 0,
			'failed'         => 0,
		);
		$first_reason        = '';
		$first_review_reason = '';

		foreach ( $sources as $source ) {
			$status = isset( $source['status'] ) ? (string) $source['status'] : 'Skipped';
			$reason = isset( $source['reason'] ) ? (string) $source['reason'] : '';

			$row['original_size']    += isset( $source['original_size'] ) ? (int) $source['original_size'] : 0;
			$row['estimated_memory']  = max( (int) $row['estimated_memory'], isset( $source['estimated_memory'] ) ? (int) $source['estimated_memory'] : 0 );
			$row['webp_size']        += isset( $source['webp_size'] ) ? (int) $source['webp_size'] : 0;
			$row['pixel_count']       = max( (int) $row['pixel_count'], isset( $source['pixel_count'] ) ? (int) $source['pixel_count'] : 0 );

			if ( '' === $first_reason && '' !== $reason ) {
				$first_reason = $reason;
			}

			if ( 'Needs review' === $status && '' === $first_review_reason && '' !== $reason ) {
				$first_review_reason = $reason;
			}

			if ( 'Eligible' === $status ) {
				++$counts['eligible'];
			} elseif ( 'Needs review' === $status ) {
				++$counts['needs_review'];
			} elseif ( 'Converted' === $status ) {
				++$counts['converted'];
			} elseif ( 'Already exists' === $status ) {
				++$counts['already_exists'];
			} elseif ( 'Failed' === $status ) {
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

		if ( $counts['needs_review'] > 0 ) {
			return $this->with_status(
				$row,
				'Needs review',
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
				'Eligible',
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
			return $this->with_status( $row, 'Failed', $first_reason, false );
		}

		if ( $counts['converted'] > 0 && 0 === $counts['skipped'] && 0 === $counts['already_exists'] ) {
			return $this->with_status(
				$row,
				'Converted',
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
				'Needs review',
				sprintf(
					/* translators: 1: ready WebP count, 2: total source count. */
					__( '%1$d of %2$d source files have WebP copies', 'indexlane-safe-webp-queue' ),
					$counts['converted'] + $counts['already_exists'],
					$source_count
				),
				false
			);
		}

		return $this->with_status( $row, 'Skipped', $first_reason, false );
	}

	/**
	 * Add status fields to one source.
	 *
	 * @param array<string, mixed> $source Source.
	 * @param string               $status Status.
	 * @param string               $reason Reason.
	 * @param bool                 $eligible Eligible.
	 * @return array<string, mixed>
	 */
	private function source_with_status( $source, $status, $reason, $eligible ) {
		$source['status']   = $status;
		$source['reason']   = $reason;
		$source['eligible'] = (bool) $eligible;

		return $source;
	}

	/**
	 * Add existing WebP details to one source.
	 *
	 * @param array<string, mixed> $source Source.
	 * @param string               $webp_path WebP path.
	 * @param string               $status Status.
	 * @param string               $reason Reason.
	 * @return array<string, mixed>
	 */
	private function source_with_existing_webp( $source, $webp_path, $status, $reason ) {
		$webp_size = filesize( $webp_path );

		$source['webp_size']       = false === $webp_size ? 0 : (int) $webp_size;
		$source['webp_size_label'] = self::format_bytes( $source['webp_size'] );
		$source['webp_url']        = self::path_to_url( $webp_path );

		return $this->source_with_status( $source, $status, $reason, false );
	}

	/**
	 * Return true when an existing WebP is older than its source.
	 *
	 * @param string $source_path Source path.
	 * @param string $webp_path WebP path.
	 * @return bool
	 */
	private function webp_is_stale( $source_path, $webp_path ) {
		$source_mtime = filemtime( $source_path );
		$webp_mtime   = filemtime( $webp_path );

		if ( false === $source_mtime || false === $webp_mtime ) {
			return false;
		}

		return $webp_mtime < $source_mtime;
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
	 * Get a plugin-generated WebP path from the stored map.
	 *
	 * @param array<string, mixed>               $source Source.
	 * @param array<string, array<string,mixed>> $webp_map WebP map.
	 * @return string
	 */
	private function webp_path_from_map( $source, $webp_map ) {
		$name = isset( $source['name'] ) ? (string) $source['name'] : '';
		$path = isset( $source['path'] ) ? wp_normalize_path( (string) $source['path'] ) : '';

		if ( '' !== $name && isset( $webp_map[ $name ]['webp'] ) ) {
			$entry_source = isset( $webp_map[ $name ]['source'] ) ? wp_normalize_path( (string) $webp_map[ $name ]['source'] ) : '';
			if ( '' === $entry_source || $path === $entry_source ) {
				return wp_normalize_path( (string) $webp_map[ $name ]['webp'] );
			}
		}

		foreach ( $webp_map as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['source'] ) || empty( $entry['webp'] ) ) {
				continue;
			}

			if ( $path === wp_normalize_path( (string) $entry['source'] ) ) {
				return wp_normalize_path( (string) $entry['webp'] );
			}
		}

		return '';
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

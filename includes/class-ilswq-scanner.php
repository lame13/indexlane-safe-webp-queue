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
	 * Output formats this plugin can generate, in serving preference order.
	 *
	 * @var array<int, string>
	 */
	const OUTPUT_FORMATS = array( 'avif', 'webp' );

	/**
	 * ISO-BMFF brands accepted as a still AVIF image.
	 *
	 * @var array<int, string>
	 */
	const AVIF_BRANDS = array( 'avif', 'mif1', 'miaf', 'MA1B', 'MA1A' );

	/**
	 * Normalize an output format name.
	 *
	 * @param mixed $format Format value.
	 * @return string webp or avif.
	 */
	public static function normalize_format( $format ) {
		return 'avif' === strtolower( (string) $format ) ? 'avif' : 'webp';
	}

	/**
	 * File extension for an output format.
	 *
	 * @param string $format Format.
	 * @return string
	 */
	public static function format_extension( $format ) {
		return 'avif' === self::normalize_format( $format ) ? '.avif' : '.webp';
	}

	/**
	 * MIME type for an output format.
	 *
	 * @param string $format Format.
	 * @return string
	 */
	public static function format_mime( $format ) {
		return 'avif' === self::normalize_format( $format ) ? 'image/avif' : 'image/webp';
	}

	/**
	 * Display label for an output format.
	 *
	 * @param string $format Format.
	 * @return string
	 */
	public static function format_label( $format ) {
		return 'avif' === self::normalize_format( $format ) ? 'AVIF' : 'WebP';
	}

	/**
	 * Stored map key for one size and format.
	 *
	 * The WebP key keeps its historical bare size name so existing installs
	 * keep working; AVIF entries use a suffixed key beside them.
	 *
	 * @param string $name Size name.
	 * @param string $format Format.
	 * @return string
	 */
	public static function map_entry_key( $name, $format ) {
		$name = sanitize_key( (string) $name );
		if ( '' === $name ) {
			$name = 'full';
		}

		return 'avif' === self::normalize_format( $format ) ? $name . '__avif' : $name;
	}

	/**
	 * Format recorded for a stored map entry.
	 *
	 * @param array<string, mixed> $entry Map entry.
	 * @return string
	 */
	public static function entry_format( $entry ) {
		return self::normalize_format( isset( $entry['format'] ) ? $entry['format'] : 'webp' );
	}

	/**
	 * Return the source MIME types this plugin can convert.
	 *
	 * @return array<int, string>
	 */
	public static function convertible_mime_types() {
		return array( 'image/jpeg', 'image/jpg', 'image/pjpeg', 'image/png', 'image/x-png' );
	}

	/**
	 * Return the MIME types listed in the Media Library report.
	 *
	 * @return array<int, string>
	 */
	public static function report_mime_types() {
		return array_merge(
			self::convertible_mime_types(),
			array( 'image/webp', 'image/gif', 'image/svg+xml' )
		);
	}

	/**
	 * Return query arguments for one report page.
	 *
	 * @param int $page Page number.
	 * @param int $per_page Attachments per page.
	 * @return array<string, mixed>
	 */
	public static function report_query_args( $page, $per_page ) {
		return array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => self::report_mime_types(),
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'paged'          => max( 1, absint( $page ) ),
			'posts_per_page' => max( 1, absint( $per_page ) ),
		);
	}

	/**
	 * Return query arguments for convertible attachments, skipping opt-outs.
	 *
	 * Pages use the last attachment ID so removals cannot shift later work.
	 *
	 * @param int $after_id Last processed attachment ID.
	 * @param int $limit Maximum attachments.
	 * @return array<string, mixed>
	 */
	public static function library_query_args( $after_id, $limit ) {
		return array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => self::convertible_mime_types(),
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'posts_per_page' => max( 1, absint( $limit ) ),
			'ilswq_after_id' => max( 0, absint( $after_id ) ),
			'meta_query'     => array(
				array(
					'key'     => ILSWQ_META_EXCLUDE,
					'compare' => 'NOT EXISTS',
				),
			),
		);
	}

	/**
	 * Return one page of convertible attachment IDs.
	 *
	 * @param int $after_id Last processed attachment ID.
	 * @param int $limit Maximum attachments.
	 * @param int $max_id Last attachment included in this job.
	 * @return array<int, int>
	 */
	public static function get_library_page_ids( $after_id, $limit, $max_id = PHP_INT_MAX ) {
		$args = self::library_query_args( $after_id, $limit );
		$args['no_found_rows'] = true;
		$filter = static function ( $where, $query ) use ( $after_id, $max_id ) {
			global $wpdb;
			if ( null !== $query->get( 'ilswq_after_id', null ) ) {
				$where .= $wpdb->prepare( " AND {$wpdb->posts}.ID > %d AND {$wpdb->posts}.ID <= %d", $after_id, $max_id );
			}
			return $where;
		};
		add_filter( 'posts_where', $filter, 10, 2 );
		try {
			$query = new WP_Query( $args );
		} finally {
			remove_filter( 'posts_where', $filter, 10 );
		}

		return array_values( array_filter( array_map( 'absint', $query->posts ) ) );
	}

	/**
	 * Return the last convertible attachment ID when a library job starts.
	 *
	 * @return int
	 */
	public static function last_library_id() {
		$args = self::library_query_args( 0, 1 );
		$args['order'] = 'DESC';
		$args['no_found_rows'] = true;
		$query = new WP_Query( $args );

		return empty( $query->posts ) ? 0 : (int) $query->posts[0];
	}

	/**
	 * Count convertible attachments that are not excluded.
	 *
	 * @return int
	 */
	public static function count_library_attachments() {
		$args   = self::library_query_args( 0, 1 );
		$query  = new WP_Query( $args );

		return max( 0, (int) $query->found_posts );
	}

	/**
	 * Return whether an attachment is excluded from image conversion.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function is_excluded( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 ) {
			return false;
		}

		return '' !== (string) get_post_meta( $attachment_id, ILSWQ_META_EXCLUDE, true );
	}

	/**
	 * Exclude an attachment from WebP conversion, or include it again.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $excluded Whether the attachment should be excluded.
	 * @return bool Whether the stored value changed.
	 */
	public static function set_excluded( $attachment_id, $excluded ) {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 ) {
			return false;
		}

		if ( $excluded ) {
			return false !== update_post_meta( $attachment_id, ILSWQ_META_EXCLUDE, '1' );
		}

		return (bool) delete_post_meta( $attachment_id, ILSWQ_META_EXCLUDE );
	}

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

		$query = new WP_Query( self::report_query_args( $page, $per_page ) );

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
		$row = $this->base_row( $attachment_id );

		if ( self::is_excluded( $attachment_id ) ) {
			return $this->with_status( $row, 'excluded', __( 'Excluded from image conversion', 'indexlane-safe-webp-queue' ), false );
		}

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
				'format'      => 'webp',
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
			$entry['format'] = self::entry_format( $entry );
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
	 * @param string $format Output format.
	 * @return string
	 */
	public static function output_path( $source_file, $format = 'webp' ) {
		return wp_normalize_path( $source_file ) . self::format_extension( $format );
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
	 * Return true when a file is a valid non-empty generated image.
	 *
	 * WebP uses the WordPress image-size check. AVIF deliberately uses a
	 * bounded container check instead: `getimagesize()` only reports AVIF on hosts whose
	 * PHP image support is new enough, and a file this plugin produced must not
	 * be reported invalid just because the host cannot decode it.
	 *
	 * @param string $path File path.
	 * @param string $format Output format.
	 * @return bool
	 */
	public static function is_valid_generated_file( $path, $format = 'webp' ) {
		if ( 'webp' === self::normalize_format( $format ) ) {
			return self::is_valid_webp_file( $path );
		}

		if ( '' === $path || ! file_exists( $path ) ) {
			return false;
		}

		$size = filesize( $path );
		if ( false === $size || $size < 32 || $size > ILSWQ_Browser::MAX_OUTPUT ) {
			return false;
		}

		$bytes = self::read_file_prefix( $path, (int) $size );

		return strlen( $bytes ) === (int) $size && null !== ILSWQ_Browser::avif_dimensions( $bytes );
	}

	/**
	 * Read a bounded prefix of a local file.
	 *
	 * WordPress's filesystem API cannot read a byte range, so this keeps a
	 * documented, read-only exception for a size-limited image container.
	 *
	 * @param string $path File path.
	 * @param int    $bytes Maximum bytes to read.
	 * @return string Empty string when unreadable.
	 */
	public static function read_file_prefix( $path, $bytes ) {
		$bytes = max( 1, (int) $bytes );

		// WordPress's filesystem API cannot read a byte range, so this keeps a
		// documented, bounded, read-only exception for a size-limited image container.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = file_get_contents( $path, false, null, 0, $bytes );

		return is_string( $data ) ? $data : '';
	}

	/**
	 * Return the ISO-BMFF major brand of a still AVIF file, or null.
	 *
	 * @param string $bytes File header bytes.
	 * @return string|null
	 */
	public static function avif_brand( $bytes ) {
		if ( strlen( $bytes ) < 16 || 'ftyp' !== substr( $bytes, 4, 4 ) ) {
			return null;
		}
		$size  = unpack( 'N', substr( $bytes, 0, 4 ) )[1];
		$brand = substr( $bytes, 8, 4 );
		if ( $size < 16 || $size > strlen( $bytes ) || 0 !== ( $size - 16 ) % 4 || ! in_array( $brand, self::AVIF_BRANDS, true ) ) {
			return null;
		}
		$has_avif = 'avif' === $brand;
		for ( $at = 16; $at < $size; $at += 4 ) {
			$compatible = substr( $bytes, $at, 4 );
			if ( in_array( $compatible, array( 'avis', 'msf1', 'heic', 'heix', 'hevc', 'hevx' ), true ) ) {
				return null;
			}
			$has_avif = $has_avif || 'avif' === $compatible;
		}

		return $has_avif ? $brand : null;
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
			$path   = is_array( $entry ) && ! empty( $entry['webp'] ) ? wp_normalize_path( (string) $entry['webp'] ) : '';
			$format = is_array( $entry ) ? self::entry_format( $entry ) : 'webp';
			if (
				'' === $path ||
				self::format_extension( $format ) !== substr( strtolower( $path ), -strlen( self::format_extension( $format ) ) ) ||
				! self::is_uploads_path( $path ) ||
				! self::is_valid_generated_file( $path, $format )
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
			case 'excluded':
				return __( 'Excluded', 'indexlane-safe-webp-queue' );
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
		$source['formats']               = array();
		$source['format_label']          = '';
		$source['generated_entries']     = array();
		$source['owned_entries']         = array();
		$source['browser_eligible']      = false;
		$source['browser_only']           = false;
		$source['php_only']               = false;

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

		$estimated_memory                 = self::estimate_memory_bytes( $width, $height );
		$source['estimated_memory']       = $estimated_memory;
		$source['estimated_memory_label'] = self::format_bytes( $estimated_memory );
		$source['output_path']            = self::output_path( $file );
		$issues                          = array();
		$generated                       = array();

		foreach ( self::OUTPUT_FORMATS as $format ) {
			$entry = $this->webp_entry_from_map( $source, $webp_map, $format );
			if ( empty( $entry['webp'] ) ) {
				continue;
			}

			// Ownership survives stale, missing, and damaged output.
			$source['owned_entries'][ $format ] = $entry;
			$entry_path = wp_normalize_path( (string) $entry['webp'] );
			if ( ! file_exists( $entry_path ) ) {
				continue;
			}

			if ( ! self::is_valid_generated_file( $entry_path, $format ) ) {
				/* translators: %s: output format label, such as "AVIF". */
				$issues[ $format ] = sprintf( __( 'Generated %s file is invalid', 'indexlane-safe-webp-queue' ), self::format_label( $format ) );
				continue;
			}

			$entry_issue = $this->generated_entry_issue( $source, $entry, $settings );
			if ( '' !== $entry_issue ) {
				$issues[ $format ] = $entry_issue;
				continue;
			}

			$generated[ $format ] = $entry;
		}

		$source['generated_entries']          = $generated;
		$source['formats']                   = array_keys( $generated );
		$source['format_label']               = implode( ', ', array_map( array( __CLASS__, 'format_label' ), $source['formats'] ) );
		$source['existing_plugin_webp_entry'] = isset( $source['owned_entries']['webp'] ) ? $source['owned_entries']['webp'] : array();
		$source['existing_plugin_webp_path']  = ! empty( $source['existing_plugin_webp_entry']['webp'] ) ? wp_normalize_path( (string) $source['existing_plugin_webp_entry']['webp'] ) : '';

		// Offer the configured browser format independently of server WebP work.
		$browser_format = ILSWQ_Settings::normalize_format( isset( $settings['browser_format'] ) ? $settings['browser_format'] : 'webp' );
		$browser_path = self::output_path( $file, $browser_format );
		$source['browser_eligible'] = self::source_is_browser_convertible( $source )
			&& empty( $generated[ $browser_format ] )
			&& ( ! file_exists( $browser_path ) || isset( $source['owned_entries'][ $browser_format ] ) )
			&& ILSWQ_Capabilities::is_writable_path( dirname( $file ) )
			&& ( empty( $settings['max_pixels'] ) || $source['pixel_count'] <= (int) $settings['max_pixels'] );

		if ( ! empty( $generated ) ) {
			$primary = isset( $generated['webp'] ) ? $generated['webp'] : reset( $generated );
			$source = $this->source_with_existing_webp(
				$source,
				wp_normalize_path( (string) $primary['webp'] ),
				self::entry_format( $primary ),
				'converted',
				__( 'Generated by this plugin', 'indexlane-safe-webp-queue' )
			);
			// The server still needs WebP even when an AVIF copy exists.
			if ( isset( $generated['webp'] ) || ( ! ILSWQ_Capabilities::has_unattended_backend() && ! $source['browser_eligible'] ) ) {
				return $source;
			}
		}

		$entry_issue = isset( $issues['webp'] ) ? $issues['webp'] : '';
		if ( $source['browser_eligible'] && isset( $issues[ $browser_format ] ) ) {
			$entry_issue = $issues[ $browser_format ];
		}

		// A foreign AVIF must not block the WebP server queue (or vice versa).
		if ( file_exists( $source['output_path'] ) && empty( $source['owned_entries']['webp'] ) ) {
			return $this->source_with_status( $source, 'conflict', __( 'A sibling WebP file exists but is not owned by this plugin. Move or rename it before converting.', 'indexlane-safe-webp-queue' ), false );
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

		$server_writer = ILSWQ_Capabilities::has_webp_writer();
		$php_on        = ILSWQ_Capabilities::php_encoder_enabled();
		$php_ready     = $php_on && ILSWQ_PHP_Encoder::source_within_limits( $source );
		$browser_on    = ILSWQ_Browser::is_enabled();
		$browser_ready = $browser_on && ILSWQ_Browser::source_within_limits( $source );

		if ( ! $server_writer && ! $php_ready && ! $browser_ready ) {
			// No server backend can take this file and either the browser is
			// off or the file is outside the browser encoder limits.
			return $this->source_with_status(
				$source,
				'skipped',
				$browser_on
					? __( 'Image is too large for browser conversion', 'indexlane-safe-webp-queue' )
					: ( $php_on
						? __( 'Image is too large for the built-in PHP encoder', 'indexlane-safe-webp-queue' )
						: __( 'WebP not supported by server', 'indexlane-safe-webp-queue' ) ),
				false
			);
		}

		if ( ! ILSWQ_Capabilities::is_writable_path( dirname( $file ) ) ) {
			return $this->source_with_status( $source, 'skipped', __( 'Upload directory not writable', 'indexlane-safe-webp-queue' ), false );
		}

		if ( ! empty( $settings['max_pixels'] ) && $source['pixel_count'] > (int) $settings['max_pixels'] ) {
			return $this->source_with_status( $source, 'skipped', __( 'Image exceeds max pixel limit', 'indexlane-safe-webp-queue' ), false );
		}

		// Browser conversion runs on the administrator's device, so the PHP
		// memory ceiling only constrains the server-side backends. Files that
		// only the browser can handle stay eligible and are flagged as such.
		if ( $server_writer || $php_ready ) {
			$memory_limit = ILSWQ_Capabilities::memory_limit_bytes();
			if ( $memory_limit > 0 ) {
				$safe_memory = (int) floor( $memory_limit * 0.70 );
				if ( $estimated_memory > $safe_memory ) {
					if ( $browser_ready ) {
						$source['browser_only'] = true;

						return $this->source_with_status( $source, 'eligible', __( 'Convert in the browser: larger than your server image tools can handle', 'indexlane-safe-webp-queue' ), true );
					}

					return $this->source_with_status( $source, 'skipped', __( 'Image too large for current memory limit', 'indexlane-safe-webp-queue' ), false );
				}
			}
		}

		if ( ! $server_writer && ! $php_ready && $browser_ready ) {
			$source['browser_only'] = true;
		}

		$source['php_only'] = ! $server_writer && $php_ready;

		// Regeneration has the same limits as first-time conversion. In
		// particular, a quality change must not bypass the PHP memory check.
		if ( '' !== $entry_issue ) {
			return $this->source_with_status( $source, 'needs-review', $entry_issue, true );
		}

		return $this->source_with_status(
			$source,
			'eligible',
			$source['php_only']
				? __( 'Ready for conversion with the built-in PHP encoder', 'indexlane-safe-webp-queue' )
				: __( 'Ready for conversion', 'indexlane-safe-webp-queue' ),
			true
		);
	}

	/**
	 * Build base row fields.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, mixed>
	 */
	private function base_row( $attachment_id ) {
		$title    = get_the_title( $attachment_id );
		$alt_text = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );

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
			'browser_source_count'   => 0,
			'browser_sources'        => array(),
			'browser_only'           => false,
			'converted_source_count' => 0,
			'generated_source_count' => 0,
			'existing_source_count'  => 0,
			'skipped_source_count'   => 0,
			'status'                 => self::status_label( 'skipped' ),
			'status_key'             => 'skipped',
			'reason'                 => '',
			'eligible'               => false,
			'alt_text'               => $alt_text,
			'has_alt_text'           => '' !== $alt_text,
			'ai_review_status'       => class_exists( 'ILSWQ_AI' ) ? ILSWQ_AI::review_status( $attachment_id ) : '',
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
		$server_backend    = ILSWQ_Capabilities::preferred_server_backend_label();
		$row['editor']     = '' !== $server_backend
			? $server_backend
			: __( 'WASM (browser)', 'indexlane-safe-webp-queue' );

		$source_count          = count( $sources );
		$browser_sources       = array();
		$browser_only_count    = 0;
		$editors               = array();
		$formats               = array();
		$covered_source_bytes  = 0;
		$webp_source_count     = 0;
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
			if ( ! empty( $source['owned_entries'] ) ) {
				++$row['generated_source_count'];
			}
			if ( isset( $source['generated_entries']['webp'] ) ) {
				++$webp_source_count;
			}
			foreach ( $source['generated_entries'] as $format => $entry ) {
				$formats[] = $format;
				if ( ! empty( $entry['editor'] ) ) {
					$editors[] = (string) $entry['editor'];
				}
				$covered_source_bytes += (int) $source['original_size'];
				$row['webp_size'] += (int) filesize( $entry['webp'] );
			}

			$row['original_size']    += isset( $source['original_size'] ) ? (int) $source['original_size'] : 0;
			$row['estimated_memory']  = max( (int) $row['estimated_memory'], isset( $source['estimated_memory'] ) ? (int) $source['estimated_memory'] : 0 );
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

			if ( ! empty( $source['browser_eligible'] ) ) {
				if ( ! empty( $source['browser_only'] ) ) {
					++$browser_only_count;
				}

				$browser_sources[] = array(
					'name'   => isset( $source['name'] ) ? sanitize_key( (string) $source['name'] ) : 'full',
					'label'  => isset( $source['label'] ) ? (string) $source['label'] : '',
					'file'   => isset( $source['file'] ) ? (string) $source['file'] : '',
					'width'  => isset( $source['width'] ) ? (int) $source['width'] : 0,
					'height' => isset( $source['height'] ) ? (int) $source['height'] : 0,
					'bytes'  => isset( $source['original_size'] ) ? (int) $source['original_size'] : 0,
				);
			}
		}

		$row['source_count']           = $source_count;
		$row['server_complete']        = $source_count > 0 && $webp_source_count === $source_count;
		$row['source_count_label']     = sprintf(
			/* translators: %d: source file count. */
			_n( '%d source file', '%d source files', $source_count, 'indexlane-safe-webp-queue' ),
			$source_count
		);
		$row['eligible_source_count']  = $counts['eligible'] + $counts['needs_review'];
		$row['browser_sources']        = $browser_sources;
		$row['browser_source_count']   = count( $browser_sources );
		$row['browser_only']           = $browser_only_count > 0;
		$row['converted_source_count'] = $counts['converted'];
		$row['existing_source_count']  = $counts['already_exists'];
		$row['skipped_source_count']   = $counts['skipped'];
		$row['original_size_label']    = self::format_bytes( (int) $row['original_size'] );
		$row['estimated_memory_label'] = self::format_bytes( (int) $row['estimated_memory'] );
		$row['webp_size_label']        = (int) $row['webp_size'] > 0 ? self::format_bytes( (int) $row['webp_size'] ) : '';
		$row['savings']                = $this->savings_label( $covered_source_bytes, (int) $row['webp_size'] );
		$row['formats']                = array_values( array_unique( $formats ) );
		$row['format_label']           = implode( ', ', array_map( array( __CLASS__, 'format_label' ), $row['formats'] ) );
		if ( ! empty( $editors ) ) {
			$row['editor'] = implode( ', ', array_unique( $editors ) );
		} elseif ( $browser_only_count > 0 && $browser_only_count >= $row['eligible_source_count'] ) {
			$row['editor'] = __( 'WASM (browser)', 'indexlane-safe-webp-queue' );
		}

		if ( ! empty( $browser_sources ) && 0 === $row['eligible_source_count'] ) {
			return $this->with_status( $row, 'eligible', __( 'Ready for conversion in your browser', 'indexlane-safe-webp-queue' ), true );
		}

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
			if ( $browser_only_count > 0 && $browser_only_count >= $counts['eligible'] ) {
				return $this->with_status(
					$row,
					'eligible',
					sprintf(
						/* translators: 1: browser-only source count, 2: total source count. */
						__( '%1$d of %2$d source files need conversion in your browser', 'indexlane-safe-webp-queue' ),
						$browser_only_count,
						$source_count
					),
					true
				);
			}

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
					__( '%d source files converted', 'indexlane-safe-webp-queue' ),
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
					__( '%1$d of %2$d source files have generated copies', 'indexlane-safe-webp-queue' ),
					$counts['converted'] + $counts['already_exists'],
					$source_count
				),
				false
			);
		}

		return $this->with_status( $row, 'skipped', $first_reason, false );
	}

	/**
	 * Whether the browser backend could encode one scanned source file.
	 *
	 * @param array<string, mixed> $source Scanned source.
	 * @return bool
	 */
	private static function source_is_browser_convertible( $source ) {
		if ( ! ILSWQ_Browser::is_enabled() || ! ILSWQ_Browser::source_within_limits( $source ) ) {
			return false;
		}

		$mime_type = isset( $source['mime_type'] ) ? (string) $source['mime_type'] : '';

		return self::is_supported_source_mime( $mime_type );
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
	 * @param string               $format Generated format.
	 * @param string               $status_key Status key.
	 * @param string               $reason Reason.
	 * @return array<string, mixed>
	 */
	private function source_with_existing_webp( $source, $webp_path, $format, $status_key, $reason ) {
		$webp_size = filesize( $webp_path );

		$source['webp_size']       = false === $webp_size ? 0 : (int) $webp_size;
		$source['webp_size_label'] = self::format_bytes( $source['webp_size'] );
		$source['webp_url']        = self::path_to_url( $webp_path );
		$source['webp_format']     = self::normalize_format( $format );

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
	 * @param array<string, mixed> $settings Current settings.
	 * @return string
	 */
	private function generated_entry_issue( $source, $entry, $settings ) {
		if ( ! self::source_matches_map_entry( $entry ) ) {
			return __( 'Source file changed since WebP generation', 'indexlane-safe-webp-queue' );
		}

		$mime_type       = isset( $source['mime_type'] ) ? (string) $source['mime_type'] : '';
		$format          = self::entry_format( $entry );
		$current_quality = ILSWQ_Settings::quality_for_format( $mime_type, $format, $settings );
		$stored_quality  = isset( $entry['quality'] ) ? absint( $entry['quality'] ) : 0;

		if ( $stored_quality > 0 && $stored_quality !== $current_quality ) {
			return 'avif' === $format
				? __( 'AVIF was generated with different quality settings', 'indexlane-safe-webp-queue' )
				: __( 'WebP was generated with different quality settings', 'indexlane-safe-webp-queue' );
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
	 * Get a plugin-generated entry for one source and output format.
	 *
	 * @param array<string, mixed>               $source Source.
	 * @param array<string, array<string,mixed>> $webp_map WebP map.
	 * @param string                             $format Output format.
	 * @return array<string, mixed>
	 */
	private function webp_entry_from_map( $source, $webp_map, $format = 'webp' ) {
		$format      = self::normalize_format( $format );
		$name        = isset( $source['name'] ) ? sanitize_key( (string) $source['name'] ) : '';
		$path        = isset( $source['path'] ) ? wp_normalize_path( (string) $source['path'] ) : '';
		$output_path = self::output_path( $path, $format );

		$candidate_keys = array();
		if ( '' !== $name ) {
			$candidate_keys[] = self::map_entry_key( $name, $format );
			$candidate_keys[] = $name;
		}

		foreach ( $candidate_keys as $key ) {
			if ( ! isset( $webp_map[ $key ]['webp'] ) || self::entry_format( $webp_map[ $key ] ) !== $format ) {
				continue;
			}

			$entry_source = isset( $webp_map[ $key ]['source'] ) ? wp_normalize_path( (string) $webp_map[ $key ]['source'] ) : '';
			$entry_webp   = wp_normalize_path( (string) $webp_map[ $key ]['webp'] );
			if ( $path === $entry_source && $output_path === $entry_webp ) {
				return $webp_map[ $key ];
			}
		}

		foreach ( $webp_map as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['source'] ) || empty( $entry['webp'] ) ) {
				continue;
			}
			if ( self::entry_format( $entry ) !== $format ) {
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

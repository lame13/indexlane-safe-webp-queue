<?php
/**
 * Optional frontend WebP serving filters.
 *
 * @package IndexLaneSafeWebPQueue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replaces normal WordPress image output with generated WebP files when enabled.
 */
class ILSWQ_Serving {
	/**
	 * Filter wp_get_attachment_image_src output.
	 *
	 * @param array<int, mixed>|false $image Image data.
	 * @param int                     $attachment_id Attachment ID.
	 * @param string|array<int, int>  $size Requested size.
	 * @param bool                    $icon Icon flag.
	 * @return array<int, mixed>|false
	 */
	public static function filter_attachment_image_src( $image, $attachment_id, $size, $icon ) {
		if ( $icon || ! self::should_serve() || ! is_array( $image ) || empty( $image[0] ) ) {
			return $image;
		}

		$webp_url = self::webp_url_for_source_url( (int) $attachment_id, (string) $image[0], $size );
		if ( '' !== $webp_url ) {
			$image[0] = $webp_url;
		}

		return $image;
	}

	/**
	 * Filter srcset sources.
	 *
	 * @param array<string, array<string, mixed>> $sources Sources.
	 * @param array<int, int>                     $size_array Requested size.
	 * @param string                              $image_src Image source.
	 * @param array<string, mixed>                $image_meta Image metadata.
	 * @param int                                 $attachment_id Attachment ID.
	 * @return array<string, array<string, mixed>>
	 */
	public static function filter_image_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		if ( ! self::should_serve() || empty( $sources ) || ! is_array( $sources ) ) {
			return $sources;
		}

		foreach ( $sources as $width => $source ) {
			if ( empty( $source['url'] ) ) {
				continue;
			}

			$webp_url = self::webp_url_for_source_url( (int) $attachment_id, (string) $source['url'], null );
			if ( '' !== $webp_url ) {
				$sources[ $width ]['url'] = $webp_url;
			}
		}

		return $sources;
	}

	/**
	 * Return true when optional frontend serving is enabled.
	 *
	 * @return bool
	 */
	private static function should_serve() {
		if ( is_admin() ) {
			return false;
		}

		$settings = ILSWQ_Settings::get();

		return ! empty( $settings['serve_webp'] );
	}

	/**
	 * Get a generated WebP URL for a source URL.
	 *
	 * @param int                    $attachment_id Attachment ID.
	 * @param string                 $source_url Source URL.
	 * @param string|array<int,int>|null $size Requested size.
	 * @return string
	 */
	private static function webp_url_for_source_url( $attachment_id, $source_url, $size ) {
		$entries = self::map_entries_with_urls( $attachment_id );
		if ( empty( $entries ) ) {
			return '';
		}

		$source_path = self::url_path( $source_url );
		foreach ( $entries as $entry ) {
			if ( empty( $entry['source_url'] ) || empty( $entry['webp_url'] ) ) {
				continue;
			}

			if ( $source_path === self::url_path( $entry['source_url'] ) ) {
				return (string) $entry['webp_url'];
			}
		}

		return '';
	}

	/**
	 * Return WebP map entries with URLs.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, array<string, mixed>>
	 */
	private static function map_entries_with_urls( $attachment_id ) {
		$map     = ILSWQ_Scanner::get_webp_map( $attachment_id );
		$entries = array();

		foreach ( $map as $name => $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['source'] ) || empty( $entry['webp'] ) ) {
				continue;
			}

			$source_path = wp_normalize_path( (string) $entry['source'] );
			$webp_path   = wp_normalize_path( (string) $entry['webp'] );

			if ( ! ILSWQ_Scanner::is_valid_webp_file( $webp_path ) ) {
				continue;
			}

			$source_mtime = filemtime( $source_path );
			$webp_mtime   = filemtime( $webp_path );
			if ( false !== $source_mtime && false !== $webp_mtime && $webp_mtime < $source_mtime ) {
				continue;
			}

			$source_url = ILSWQ_Scanner::path_to_url( $source_path );
			$webp_url   = ILSWQ_Scanner::path_to_url( $webp_path );
			if ( '' === $source_url || '' === $webp_url ) {
				continue;
			}

			$entry['source_url'] = $source_url;
			$entry['webp_url']   = $webp_url;
			$entries[ $name ]    = $entry;
		}

		return $entries;
	}

	/**
	 * Return a normalized URL path for matching.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function url_path( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			return '';
		}

		return rawurldecode( $path );
	}
}

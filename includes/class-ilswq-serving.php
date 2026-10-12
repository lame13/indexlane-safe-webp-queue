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
	 * Valid generated entries cached for the current request.
	 *
	 * @var array<int, array<string, array<string, mixed>>>
	 */
	private static $entry_cache = array();

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
	 * Add an AVIF `<source>` element around a WordPress image tag.
	 *
	 * `wp_get_attachment_image_src()` and `wp_calculate_image_srcset()` can
	 * only swap a URL, so they keep serving WebP, which every current browser
	 * reads. AVIF is newer, so it is offered through a `<picture>` element with
	 * the unchanged `<img>` as the fallback instead of by rewriting a URL that
	 * has no fallback. A client that does not support AVIF therefore keeps the
	 * WordPress output, including its own srcset.
	 *
	 * @param string               $html Image HTML.
	 * @param int                  $attachment_id Attachment ID.
	 * @param string|array<int,int> $size Requested size.
	 * @param bool                 $icon Icon flag.
	 * @param array<string, mixed> $attr Attributes.
	 * @return string
	 */
	public static function filter_attachment_image_html( $html, $attachment_id, $size, $icon, $attr ) {
		unset( $size, $attr );

		if ( $icon || ! self::should_serve() || ! is_string( $html ) || ! self::is_image_tag( $html ) ) {
			return $html;
		}

		$sources = self::picture_sources( (int) $attachment_id, $html );
		if ( empty( $sources ) ) {
			return $html;
		}

		return '<picture>' . implode( '', $sources ) . $html . '</picture>';
	}

	/**
	 * Build the AVIF `<source>` element for a `<picture>`.
	 *
	 * Only AVIF is added here. WebP keeps the URL-swap behaviour above, because
	 * every current browser reads WebP and a `<picture>` wrapper is a visible
	 * markup change a theme may not expect. Wrapping is skipped entirely when
	 * there is no AVIF copy, so a WebP-only site renders exactly as it did
	 * before this release.
	 *
	 * The candidate URLs are taken from the `<img>` tag itself, so a source
	 * is only offered at sizes WordPress was already willing to serve. That
	 * keeps the browser's existing responsive choice intact and prevents a
	 * large AVIF file being selected for a small slot.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $html Image HTML.
	 * @return array<int, string>
	 */
	private static function picture_sources( $attachment_id, $html ) {
		$entries = self::map_entries_with_urls( $attachment_id );
		if ( empty( $entries ) ) {
			return array();
		}

		$avif_urls = array();
		foreach ( $entries as $entry ) {
			if ( 'avif' !== ILSWQ_Scanner::entry_format( $entry ) ) {
				continue;
			}
			$source_path = self::url_path( $entry['source_url'] );
			$avif_urls[ $source_path ] = $entry['webp_url'];
			// The earlier URL filter may already have selected owned WebP.
			foreach ( $entries as $fallback ) {
				if ( 'webp' === ILSWQ_Scanner::entry_format( $fallback ) && self::url_path( $fallback['source_url'] ) === $source_path ) {
					$avif_urls[ self::url_path( $fallback['webp_url'] ) ] = $entry['webp_url'];
				}
			}
		}

		$srcset = self::img_attribute( $html, 'srcset' );
		$sizes  = self::img_attribute( $html, 'sizes' );
		$output = array();
		if ( '' === $srcset ) {
			$path = self::url_path( self::img_attribute( $html, 'src' ) );
			if ( ! isset( $avif_urls[ $path ] ) ) {
				return array();
			}
			$output[] = $avif_urls[ $path ];
		} else {
			foreach ( explode( ',', $srcset ) as $candidate ) {
				if ( ! preg_match( '/^\s*(\S+)\s+(\d+w|(?:\d+(?:\.\d+)?)x)\s*$/', $candidate, $match ) ) {
					return array();
				}
				$path = self::url_path( $match[1] );
				// Preserve the exact crops and every responsive candidate.
				// Partial AVIF coverage could force a thumbnail into a large slot.
				if ( ! isset( $avif_urls[ $path ] ) ) {
					return array();
				}
				$output[] = $avif_urls[ $path ] . ' ' . $match[2];
			}
		}

		return array( sprintf(
			'<source type="image/avif" srcset="%s"%s>',
			esc_attr( implode( ', ', $output ) ),
			'' !== $srcset && '' !== $sizes ? ' sizes="' . esc_attr( $sizes ) . '"' : ''
		) );
	}

	/**
	 * Read one attribute from an image tag.
	 *
	 * @param string $html Image HTML.
	 * @param string $name Attribute name.
	 * @return string
	 */
	private static function img_attribute( $html, $name ) {
		$pattern = '/\s' . preg_quote( $name, '/' ) . '\s*=\s*("|\')(.*?)\1/is';
		if ( ! preg_match( $pattern, $html, $matches ) ) {
			return '';
		}

		return html_entity_decode( (string) $matches[2], ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Return true when a string is a bare `<img>` tag we may wrap.
	 *
	 * @param string $html Image HTML.
	 * @return bool
	 */
	private static function is_image_tag( $html ) {
		return (bool) preg_match( '/^\s*<img\s[^<>]*>\s*$/i', $html );
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
			// A URL swap has no fallback, so only the universally readable WebP
			// format is swapped here; AVIF is delivered by filter_attachment_image_html().
			if ( 'webp' !== ILSWQ_Scanner::entry_format( $entry ) ) {
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
		if ( isset( self::$entry_cache[ $attachment_id ] ) ) {
			return self::$entry_cache[ $attachment_id ];
		}

		if ( ILSWQ_Scanner::is_excluded( $attachment_id ) ) {
			self::$entry_cache[ $attachment_id ] = array();

			return self::$entry_cache[ $attachment_id ];
		}

		$map     = ILSWQ_Scanner::get_webp_map( $attachment_id );
		$entries = array();

		foreach ( $map as $name => $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['source'] ) || empty( $entry['webp'] ) ) {
				continue;
			}

			$source_path = wp_normalize_path( (string) $entry['source'] );
			$webp_path   = wp_normalize_path( (string) $entry['webp'] );
			$format      = ILSWQ_Scanner::entry_format( $entry );

			if ( ! ILSWQ_Scanner::is_valid_generated_file( $webp_path, $format ) ) {
				continue;
			}

			if ( ! ILSWQ_Scanner::source_matches_map_entry( $entry ) ) {
				continue;
			}

			$source_url = ILSWQ_Scanner::path_to_url( $source_path );
			$webp_url   = ILSWQ_Scanner::path_to_url( $webp_path );
			if ( '' === $source_url || '' === $webp_url ) {
				continue;
			}

			$entry['source_url'] = $source_url;
			$entry['webp_url']   = $webp_url;
			$entry['format']     = $format;
			$entries[ $name ]    = $entry;
		}

		self::$entry_cache[ $attachment_id ] = $entries;

		return self::$entry_cache[ $attachment_id ];
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

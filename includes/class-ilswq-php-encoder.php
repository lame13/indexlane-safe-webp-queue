<?php
/**
 * Pure-PHP (picowebp) conversion backend.
 *
 * The bundled picowebp encoder writes a VP8 lossy WebP key frame in pure PHP,
 * so a host that has no WebP-writing image editor can still convert on the
 * server: unattended, through the normal queue, and from WP-CLI. It is a
 * fallback, not a replacement for GD or Imagick — it costs roughly two orders
 * of magnitude more CPU than libwebp — so it is capped by megapixels, gated on
 * PHP 8.1+, and off until an administrator turns it on.
 *
 * @package IndexLaneSafeWebPQueue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server-side conversion that needs no image editor at all.
 */
class ILSWQ_PHP_Encoder {
	/**
	 * Bundled library directory, relative to the plugin root.
	 */
	const LIBRARY_DIR = 'includes/picowebp/src/';

	/**
	 * Minimum PHP version the encoder supports.
	 */
	const MIN_PHP = '8.1.0';

	/**
	 * Hard ceiling on the pixels one pure-PHP conversion may touch.
	 *
	 * The encoder costs roughly one CPU-second per 40,000 pixels, so this
	 * default keeps a single attachment near a minute of work on ordinary
	 * hardware. Hosts that can afford more raise it with the filter.
	 */
	const MAX_PIXELS = 2000000;

	/** Maximum dimension representable in a VP8 frame header. */
	const MAX_AXIS = 16383;

	/**
	 * Cached availability result.
	 *
	 * @var bool|null
	 */
	private static $available = null;

	/**
	 * Whether the bundled autoloader has been registered.
	 *
	 * @var bool
	 */
	private static $bootstrapped = false;

	/**
	 * Make the picowebp classes loadable.
	 *
	 * A site-level Composer autoloader that already provides the classes is
	 * left alone. Otherwise the bundled copy is mapped in, so the plugin works
	 * on a host that has no Composer and no image extension.
	 *
	 * @return void
	 */
	public static function bootstrap() {
		if ( self::$bootstrapped || version_compare( PHP_VERSION, self::MIN_PHP, '<' ) ) {
			return;
		}

		self::$bootstrapped = true;

		if ( class_exists( 'PicoWebP\\Vp8\\Vp8LossyEncoder', false ) ) {
			return;
		}

		$base = defined( 'ILSWQ_DIR' ) ? ILSWQ_DIR . self::LIBRARY_DIR : '';
		if ( '' === $base || ! is_dir( $base ) ) {
			return;
		}

		spl_autoload_register(
			static function ( $class ) use ( $base ) {
				if ( 0 !== strpos( $class, 'PicoWebP\\' ) ) {
					return;
				}

				// Drop the "Vp8" namespace segment: the package maps both
				// "PicoWebP\Vp8\" and "PicoWebP\Spike\" onto the same tree.
				$relative = (string) preg_replace( '/^Vp8\\\\/', '', substr( $class, strlen( 'PicoWebP\\' ) ) );
				$path     = $base . str_replace( '\\', '/', $relative ) . '.php';

				if ( is_file( $path ) ) {
					require_once $path;
				}
			}
		);
	}

	/**
	 * Whether this host can run the pure-PHP encoder.
	 *
	 * @return bool
	 */
	public static function available() {
		if ( null !== self::$available ) {
			return self::$available;
		}

		self::bootstrap();

		$supported = version_compare( PHP_VERSION, self::MIN_PHP, '>=' )
			&& extension_loaded( 'zlib' )
			&& class_exists( 'PicoWebP\\Vp8\\Vp8LossyEncoder' );

		/**
		 * Filters whether the pure-PHP conversion backend is usable.
		 *
		 * A host may ship the encoder and still prefer to keep it off the
		 * table, and the integration tests need to exercise the missing
		 * library path.
		 *
		 * @param bool $supported Whether the bundled encoder can run here.
		 */
		self::$available = (bool) apply_filters( 'ilswq_php_encoder_available', $supported );

		return self::$available;
	}

	/**
	 * Describe why the pure-PHP backend is unusable, for the server checks.
	 *
	 * @return string Empty when the backend is available.
	 */
	public static function unavailable_reason() {
		if ( self::available() ) {
			return '';
		}

		if ( version_compare( PHP_VERSION, self::MIN_PHP, '<' ) ) {
			return sprintf(
				/* translators: %s: minimum PHP version, such as "8.1". */
				__( 'Requires PHP %s or newer.', 'indexlane-safe-webp-queue' ),
				self::MIN_PHP
			);
		}

		if ( ! extension_loaded( 'zlib' ) ) {
			return __( 'Requires the PHP zlib extension.', 'indexlane-safe-webp-queue' );
		}

		return __( 'The bundled encoder is missing.', 'indexlane-safe-webp-queue' );
	}

	/**
	 * Whether the pure-PHP backend is turned on for this site.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		if ( ! self::available() ) {
			return false;
		}

		$settings = ILSWQ_Settings::get();
		$enabled  = ! empty( $settings['php_conversion'] );

		/**
		 * Filters whether the pure-PHP conversion backend is offered.
		 *
		 * @param bool $enabled Whether the backend may be used.
		 */
		return (bool) apply_filters( 'ilswq_php_conversion_enabled', $enabled );
	}

	/**
	 * Effective pixel ceiling for one pure-PHP conversion.
	 *
	 * Never above the site's own max-pixels setting, so the pure-PHP backend
	 * can only ever be stricter than the image editor path.
	 *
	 * @return int
	 */
	public static function max_pixels() {
		$settings = ILSWQ_Settings::get();
		$site     = isset( $settings['max_pixels'] ) ? absint( $settings['max_pixels'] ) : self::MAX_PIXELS;

		/**
		 * Filters the pixel ceiling for the pure-PHP conversion backend.
		 *
		 * @param int $max_pixels Pixels one conversion may touch.
		 */
		$cap = absint( apply_filters( 'ilswq_php_encoder_max_pixels', self::MAX_PIXELS ) );
		if ( $cap < 1 ) {
			$cap = self::MAX_PIXELS;
		}

		return $site > 0 ? min( $site, $cap ) : $cap;
	}

	/**
	 * Whether one scanned source fits inside the pure-PHP limits.
	 *
	 * @param array<string, mixed> $source Scanned source.
	 * @return bool
	 */
	public static function source_within_limits( $source ) {
		$width  = isset( $source['width'] ) ? (int) $source['width'] : 0;
		$height = isset( $source['height'] ) ? (int) $source['height'] : 0;

		if ( $width < 1 || $height < 1 || $width > self::MAX_AXIS || $height > self::MAX_AXIS ) {
			return false;
		}

		return ( $width * $height ) <= self::max_pixels();
	}

	/**
	 * Encode one source file to WebP bytes.
	 *
	 * @param string $path Source path.
	 * @param int    $quality Quality 1-100.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function encode( $path, $quality ) {
		if ( ! self::available() ) {
			return new WP_Error( 'ilswq_php_unavailable', self::unavailable_reason() );
		}

		self::bootstrap();

		// Attachment metadata can be stale. Check the file itself before a
		// decode allocates memory or the slow encode begins.
		if ( ! is_file( (string) $path ) || ! is_readable( (string) $path ) ) {
			return new WP_Error( 'ilswq_php_read_failed', __( 'The source image could not be read for pure-PHP conversion.', 'indexlane-safe-webp-queue' ) );
		}
		$image = @getimagesize( (string) $path );
		if ( is_array( $image ) && IMAGETYPE_WEBP !== $image[2]
			&& ! self::source_within_limits( array( 'width' => $image[0], 'height' => $image[1] ) ) ) {
			return new WP_Error( 'ilswq_php_limits', __( 'The image exceeds the built-in PHP encoder limits. Use browser conversion if it fits the browser limits.', 'indexlane-safe-webp-queue' ) );
		}

		// The readers return stored pixels and do not rotate or mirror them.
		// Reject orientation-dependent JPEGs instead of saving a wrong copy.
		if ( is_array( $image ) && IMAGETYPE_JPEG === $image[2] && self::jpeg_requires_orientation( (string) $path ) ) {
			return new WP_Error( 'ilswq_php_unsupported', __( 'This photo requires EXIF rotation or mirroring. Upload an upright copy before converting it.', 'indexlane-safe-webp-queue' ) );
		}

		try {
			$pixels = \PicoWebP\Vp8\ImageInput::pixels( (string) $path );
		} catch ( \PicoWebP\Vp8\SkippedInput $exception ) {
			return new WP_Error( 'ilswq_php_skipped_webp', __( 'Already WebP', 'indexlane-safe-webp-queue' ) );
		} catch ( \RuntimeException $exception ) {
			// The bundled readers refuse what they cannot decode — a
			// progressive JPEG on a host without GD, for example. That is a
			// routing problem, not a failure: another backend may still take it.
			return new WP_Error( 'ilswq_php_unsupported', __( 'The built-in PHP encoder cannot read this image. Try browser conversion instead.', 'indexlane-safe-webp-queue' ) );
		} catch ( \Throwable $exception ) {
			return new WP_Error( 'ilswq_php_read_failed', __( 'The source image could not be read for pure-PHP conversion.', 'indexlane-safe-webp-queue' ) );
		}

		if ( empty( $pixels['w'] ) || empty( $pixels['h'] ) || ! isset( $pixels['rgb'] ) || ! is_string( $pixels['rgb'] ) ) {
			return new WP_Error( 'ilswq_php_read_failed', __( 'The source image could not be read for pure-PHP conversion.', 'indexlane-safe-webp-queue' ) );
		}

		$quality = max( 1, min( 100, absint( $quality ) ) );

		try {
			$encoder             = \PicoWebP\Vp8\Vp8LossyEncoder::fromQuality( $quality );
			$encoder->alphaPlane = isset( $pixels['alpha'] ) && is_string( $pixels['alpha'] ) ? $pixels['alpha'] : null;
			$encoder->iccProfile = isset( $pixels['icc'] ) && is_string( $pixels['icc'] ) ? $pixels['icc'] : null;
			$output              = $encoder->encode( $pixels['rgb'], (int) $pixels['w'], (int) $pixels['h'] );
		} catch ( \Throwable $exception ) {
			return new WP_Error( 'ilswq_php_encode_failed', __( 'The pure-PHP encoder could not convert this image.', 'indexlane-safe-webp-queue' ) );
		}

		if ( empty( $output['webp'] ) || ! is_string( $output['webp'] ) ) {
			return new WP_Error( 'ilswq_php_encode_failed', __( 'The pure-PHP encoder produced no image data.', 'indexlane-safe-webp-queue' ) );
		}

		return array(
			'bytes'  => $output['webp'],
			'width'  => (int) $pixels['w'],
			'height' => (int) $pixels['h'],
			'reader' => isset( $pixels['reader'] ) ? (string) $pixels['reader'] : '',
		);
	}

	/**
	 * Walk JPEG markers so an earlier XMP marker cannot hide EXIF orientation.
	 *
	 * @param string $path JPEG source path.
	 * @return bool Whether the source requires orientation or has bad metadata.
	 */
	private static function jpeg_requires_orientation( $path ) {
		$handle = @fopen( $path, 'rb' );
		if ( false === $handle ) {
			return true;
		}

		try {
			fseek( $handle, 2 ); // Skip the JPEG SOI marker.
			while ( ! feof( $handle ) ) {
				$marker = fread( $handle, 2 );
				if ( strlen( $marker ) < 2 || "\xff" !== $marker[0] ) {
					return true;
				}
				while ( "\xff" === $marker[1] ) {
					$next = fread( $handle, 1 );
					if ( '' === $next || false === $next ) {
						return true;
					}
					$marker[1] = $next;
				}
				if ( "\xda" === $marker[1] || "\xd9" === $marker[1] ) {
					return false; // Pixel data or end of image.
				}
				$length_bytes = fread( $handle, 2 );
				if ( strlen( $length_bytes ) < 2 ) {
					return true;
				}
				$length = unpack( 'n', $length_bytes )[1] - 2;
				if ( $length < 0 ) {
					return true;
				}
				if ( "\xe1" === $marker[1] && $length > 0 ) {
					$app1 = fread( $handle, $length );
					if ( strlen( $app1 ) !== $length || self::exif_requires_orientation( $app1 ) ) {
						return true;
					}
				} else {
					fseek( $handle, $length, SEEK_CUR );
				}
			}
		} finally {
			fclose( $handle );
		}

		return true;
	}

	/**
	 * Check the EXIF orientation tag without requiring the EXIF extension.
	 *
	 * @param string $app1 JPEG APP1 marker contents.
	 * @return bool True for a rotation, mirror, or malformed EXIF header.
	 */
	private static function exif_requires_orientation( $app1 ) {
		if ( "Exif\x00\x00" !== substr( $app1, 0, 6 ) ) {
			return false;
		}

		$tiff   = substr( $app1, 6 );
		$endian = substr( $tiff, 0, 2 );
		if ( strlen( $tiff ) < 8 || ! in_array( $endian, array( 'II', 'MM' ), true ) ) {
			return true;
		}

		$little = 'II' === $endian;
		$header = unpack( $little ? 'vmagic/Voffset' : 'nmagic/Noffset', substr( $tiff, 2, 6 ) );
		$offset = (int) $header['offset'];
		if ( 42 !== $header['magic'] || $offset < 8 || $offset + 2 > strlen( $tiff ) ) {
			return true;
		}

		$count = unpack( $little ? 'v' : 'n', substr( $tiff, $offset, 2 ) )[1];
		if ( $count > intdiv( strlen( $tiff ) - $offset - 2, 12 ) ) {
			return true;
		}

		for ( $i = 0; $i < $count; ++$i ) {
			$entry = unpack( $little ? 'vtag/vtype/Vcount/vvalue' : 'ntag/ntype/Ncount/nvalue', substr( $tiff, $offset + 2 + $i * 12, 12 ) );
			if ( 0x0112 === $entry['tag'] ) {
				return 3 !== $entry['type'] || 1 !== $entry['count'] || 1 !== $entry['value'];
			}
		}

		return false;
	}
}

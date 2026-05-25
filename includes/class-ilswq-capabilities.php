<?php
/**
 * Server capability checks.
 *
 * @package IndexLaneSafeWebPQueue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects local image conversion support.
 */
class ILSWQ_Capabilities {
	/**
	 * Ensure WordPress image editor classes are available.
	 *
	 * @return void
	 */
	public static function ensure_image_includes() {
		if ( ! function_exists( 'wp_get_image_editor' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		if ( defined( 'ABSPATH' ) && defined( 'WPINC' ) ) {
			if ( ! class_exists( 'WP_Image_Editor' ) ) {
				require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
			}

			if ( ! class_exists( 'WP_Image_Editor_GD' ) ) {
				require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php';
			}

			if ( ! class_exists( 'WP_Image_Editor_Imagick' ) ) {
				require_once ABSPATH . WPINC . '/class-wp-image-editor-imagick.php';
			}
		}
	}

	/**
	 * Check whether a filesystem path is writable.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public static function is_writable_path( $path ) {
		if ( function_exists( 'wp_is_writable' ) ) {
			return wp_is_writable( $path );
		}

		return is_writable( $path );
	}

	/**
	 * Return server checks for the admin screen.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function get_checks() {
		self::ensure_image_includes();

		$checks = array();

		$wp_editor_available = function_exists( 'wp_get_image_editor' );
		$checks[]            = self::check(
			__( 'WordPress image editor', 'indexlane-safe-webp-queue' ),
			$wp_editor_available ? __( 'Available', 'indexlane-safe-webp-queue' ) : __( 'Missing', 'indexlane-safe-webp-queue' ),
			$wp_editor_available ? 'pass' : 'fail',
			__( 'Required for local conversion.', 'indexlane-safe-webp-queue' )
		);

		$imagick_available = class_exists( 'Imagick' );
		$checks[]          = self::check(
			__( 'Imagick extension', 'indexlane-safe-webp-queue' ),
			$imagick_available ? __( 'Available', 'indexlane-safe-webp-queue' ) : __( 'Not available', 'indexlane-safe-webp-queue' ),
			$imagick_available ? 'pass' : 'warn',
			__( 'Used when available and able to write WebP.', 'indexlane-safe-webp-queue' )
		);

		$imagick_webp = self::imagick_can_write_webp();
		$checks[]     = self::check(
			__( 'Imagick WebP writing', 'indexlane-safe-webp-queue' ),
			$imagick_webp ? __( 'Supported', 'indexlane-safe-webp-queue' ) : __( 'Not supported', 'indexlane-safe-webp-queue' ),
			$imagick_webp ? 'pass' : 'warn',
			__( 'Depends on the ImageMagick build installed by the host.', 'indexlane-safe-webp-queue' )
		);

		$gd_available = extension_loaded( 'gd' );
		$checks[]     = self::check(
			__( 'GD extension', 'indexlane-safe-webp-queue' ),
			$gd_available ? __( 'Available', 'indexlane-safe-webp-queue' ) : __( 'Not available', 'indexlane-safe-webp-queue' ),
			$gd_available ? 'pass' : 'warn',
			__( 'Used as a local fallback when WebP support exists.', 'indexlane-safe-webp-queue' )
		);

		$gd_webp = self::gd_can_write_webp();
		$checks[] = self::check(
			__( 'GD WebP writing', 'indexlane-safe-webp-queue' ),
			$gd_webp ? __( 'Supported', 'indexlane-safe-webp-queue' ) : __( 'Not supported', 'indexlane-safe-webp-queue' ),
			$gd_webp ? 'pass' : 'warn',
			__( 'Requires PHP GD with imagewebp support.', 'indexlane-safe-webp-queue' )
		);

		$has_writer = self::has_webp_writer();
		$checks[]   = self::check(
			__( 'Local WebP writer', 'indexlane-safe-webp-queue' ),
			$has_writer ? self::preferred_editor_label() : __( 'None found', 'indexlane-safe-webp-queue' ),
			$has_writer ? 'pass' : 'fail',
			__( 'At least one local editor must be able to write WebP.', 'indexlane-safe-webp-queue' )
		);

		$memory_limit = ini_get( 'memory_limit' );
		$memory_bytes = self::memory_limit_bytes();
		if ( -1 === $memory_bytes ) {
			$memory_status = 'pass';
			$memory_value  = __( 'Unlimited', 'indexlane-safe-webp-queue' );
		} else {
			$memory_status = $memory_bytes >= 134217728 ? 'pass' : 'warn';
			$memory_value  = $memory_limit ? $memory_limit : __( 'Unknown', 'indexlane-safe-webp-queue' );
		}
		$checks[] = self::check(
			__( 'PHP memory limit', 'indexlane-safe-webp-queue' ),
			$memory_value,
			$memory_status,
			__( 'Large images are skipped when estimated decoded memory is risky.', 'indexlane-safe-webp-queue' )
		);

		$max_execution_time = (int) ini_get( 'max_execution_time' );
		$execution_value    = 0 === $max_execution_time ? __( 'No limit', 'indexlane-safe-webp-queue' ) : sprintf(
			/* translators: %d: seconds. */
			__( '%d seconds', 'indexlane-safe-webp-queue' ),
			$max_execution_time
		);
		$checks[]           = self::check(
			__( 'PHP max execution time', 'indexlane-safe-webp-queue' ),
			$execution_value,
			0 === $max_execution_time || $max_execution_time >= 30 ? 'pass' : 'warn',
			__( 'The queue processes small batches to reduce timeout risk.', 'indexlane-safe-webp-queue' )
		);

		$uploads          = wp_upload_dir();
		$uploads_writable = empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) && self::is_writable_path( $uploads['basedir'] );
		$checks[]         = self::check(
			__( 'Uploads directory', 'indexlane-safe-webp-queue' ),
			$uploads_writable ? __( 'Writable', 'indexlane-safe-webp-queue' ) : __( 'Not writable', 'indexlane-safe-webp-queue' ),
			$uploads_writable ? 'pass' : 'fail',
			__( 'Generated WebP files are written beside their source files.', 'indexlane-safe-webp-queue' )
		);

		return $checks;
	}

	/**
	 * Build a check row.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 * @param string $status Status.
	 * @param string $description Description.
	 * @return array<string, string>
	 */
	private static function check( $label, $value, $status, $description ) {
		return array(
			'label'       => $label,
			'value'       => $value,
			'status'      => $status,
			'description' => $description,
		);
	}

	/**
	 * Return true when any local editor can write WebP.
	 *
	 * @return bool
	 */
	public static function has_webp_writer() {
		return self::imagick_can_write_webp() || self::gd_can_write_webp();
	}

	/**
	 * Return the preferred local editor label.
	 *
	 * @return string
	 */
	public static function preferred_editor_label() {
		if ( self::imagick_can_write_webp() ) {
			return 'Imagick';
		}

		if ( self::gd_can_write_webp() ) {
			return 'GD';
		}

		return '';
	}

	/**
	 * Check Imagick WebP writing support.
	 *
	 * @return bool
	 */
	public static function imagick_can_write_webp() {
		self::ensure_image_includes();

		if ( ! class_exists( 'Imagick' ) || ! class_exists( 'WP_Image_Editor_Imagick' ) ) {
			return false;
		}

		try {
			$formats = Imagick::queryFormats( 'WEBP' );
		} catch ( Throwable $exception ) {
			return false;
		}

		if ( empty( $formats ) ) {
			return false;
		}

		return self::editor_class_supports_webp( 'WP_Image_Editor_Imagick' );
	}

	/**
	 * Check GD WebP writing support.
	 *
	 * @return bool
	 */
	public static function gd_can_write_webp() {
		self::ensure_image_includes();

		if ( ! extension_loaded( 'gd' ) || ! function_exists( 'imagewebp' ) || ! class_exists( 'WP_Image_Editor_GD' ) ) {
			return false;
		}

		if ( function_exists( 'gd_info' ) ) {
			$info = gd_info();
			if ( empty( $info['WebP Support'] ) ) {
				return false;
			}
		}

		return self::editor_class_supports_webp( 'WP_Image_Editor_GD' );
	}

	/**
	 * Check whether a WordPress image editor class can write WebP.
	 *
	 * @param string $class_name Editor class.
	 * @return bool
	 */
	public static function editor_class_supports_webp( $class_name ) {
		if ( ! class_exists( $class_name ) || ! is_callable( array( $class_name, 'supports_mime_type' ) ) ) {
			return false;
		}

		return (bool) call_user_func( array( $class_name, 'supports_mime_type' ), 'image/webp' );
	}

	/**
	 * Return memory limit as bytes.
	 *
	 * @return int -1 means unlimited.
	 */
	public static function memory_limit_bytes() {
		return self::size_to_bytes( ini_get( 'memory_limit' ) );
	}

	/**
	 * Convert PHP shorthand size to bytes.
	 *
	 * @param string|false $value Size value.
	 * @return int
	 */
	public static function size_to_bytes( $value ) {
		if ( false === $value ) {
			return 0;
		}

		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 0;
		}

		if ( '-1' === $value ) {
			return -1;
		}

		$unit   = strtolower( substr( $value, -1 ) );
		$number = (float) $value;

		switch ( $unit ) {
			case 'g':
				$number *= 1024;
				// Fall through.
			case 'm':
				$number *= 1024;
				// Fall through.
			case 'k':
				$number *= 1024;
				break;
		}

		return (int) round( $number );
	}
}

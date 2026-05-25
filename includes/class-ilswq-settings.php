<?php
/**
 * Settings helpers.
 *
 * @package IndexLaneSafeWebPQueue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles conservative plugin settings.
 */
class ILSWQ_Settings {
	const OPTION = 'ilswq_settings';

	/**
	 * Return default settings.
	 *
	 * @return array<string, int>
	 */
	public static function defaults() {
		return array(
			'batch_size'   => 3,
			'max_pixels'   => 16000000,
			'jpeg_quality' => 82,
			'png_quality'  => 90,
			'skip_larger'  => 1,
			'serve_webp'   => 0,
			'auto_uploads' => 0,
		);
	}

	/**
	 * Return saved settings merged with defaults.
	 *
	 * @return array<string, int>
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );

		return self::sanitize( is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Read and sanitize settings from an AJAX request.
	 *
	 * @param mixed $source Request value.
	 * @return array<string, int>
	 */
	public static function from_request( $source ) {
		$raw = is_array( $source ) ? wp_unslash( $source ) : array();

		if ( ! isset( $raw['skip_larger'] ) ) {
			$raw['skip_larger'] = 0;
		}

		if ( ! isset( $raw['serve_webp'] ) ) {
			$raw['serve_webp'] = 0;
		}

		if ( ! isset( $raw['auto_uploads'] ) ) {
			$raw['auto_uploads'] = 0;
		}

		return self::sanitize( $raw );
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, int>
	 */
	public static function sanitize( $settings ) {
		$defaults = self::defaults();

		$batch_size = absint( self::scalar_or_default( $settings, 'batch_size', $defaults['batch_size'] ) );
		$batch_size = max( 1, min( 10, $batch_size ) );

		$max_pixels = absint( self::scalar_or_default( $settings, 'max_pixels', $defaults['max_pixels'] ) );
		$max_pixels = max( 1000000, min( 40000000, $max_pixels ) );

		$jpeg_quality = absint( self::scalar_or_default( $settings, 'jpeg_quality', $defaults['jpeg_quality'] ) );
		$jpeg_quality = max( 1, min( 100, $jpeg_quality ) );

		$png_quality = absint( self::scalar_or_default( $settings, 'png_quality', $defaults['png_quality'] ) );
		$png_quality = max( 1, min( 100, $png_quality ) );

		$skip_larger  = ! empty( self::scalar_or_default( $settings, 'skip_larger', $defaults['skip_larger'] ) );
		$serve_webp   = ! empty( self::scalar_or_default( $settings, 'serve_webp', $defaults['serve_webp'] ) );
		$auto_uploads = ! empty( self::scalar_or_default( $settings, 'auto_uploads', $defaults['auto_uploads'] ) );

		return array(
			'batch_size'   => $batch_size,
			'max_pixels'   => $max_pixels,
			'jpeg_quality' => $jpeg_quality,
			'png_quality'  => $png_quality,
			'skip_larger'  => $skip_larger ? 1 : 0,
			'serve_webp'   => $serve_webp ? 1 : 0,
			'auto_uploads' => $auto_uploads ? 1 : 0,
		);
	}

	/**
	 * Save settings.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, int>
	 */
	public static function save( $settings ) {
		$sanitized = self::sanitize( $settings );
		update_option( self::OPTION, $sanitized, false );

		return $sanitized;
	}

	/**
	 * Return quality for a MIME type.
	 *
	 * @param string              $mime_type MIME type.
	 * @param array<string, int>  $settings Settings.
	 * @return int
	 */
	public static function quality_for_mime( $mime_type, $settings ) {
		if ( 'image/png' === $mime_type || 'image/x-png' === $mime_type ) {
			return (int) $settings['png_quality'];
		}

		return (int) $settings['jpeg_quality'];
	}

	/**
	 * Return a scalar setting value or a default.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @param string               $key Setting key.
	 * @param mixed                $default Default value.
	 * @return mixed
	 */
	private static function scalar_or_default( $settings, $key, $default ) {
		if ( ! isset( $settings[ $key ] ) || ! is_scalar( $settings[ $key ] ) ) {
			return $default;
		}

		return $settings[ $key ];
	}
}

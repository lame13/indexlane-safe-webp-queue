<?php
/**
 * Browser (WebAssembly) conversion backend.
 *
 * The administrator's browser downloads a source image from this site, encodes
 * it with the bundled WebP codec inside a Web Worker and uploads the result.
 * The server never runs an image encoder on this path: it authorises the work,
 * publishes an exact source revision, validates the returned container and
 * reuses the same install, totals and generated-map handling as the local
 * image editor backend.
 *
 * @package IndexLaneSafeWebPQueue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST endpoints that drive browser-side conversion.
 */
class ILSWQ_Browser {
	const REST_NAMESPACE = 'ilswq-wasm/v1';
	const VERSION        = 'wasm-webp-v1';
	const TOKEN_TTL      = 600;
	const MAX_INPUT      = 20971520;
	const MAX_OUTPUT     = 33554432;
	const MAX_AXIS       = 8192;
	const MAX_PIXELS     = 16000000;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Whether browser conversion may be offered on this site.
	 *
	 * The setting starts off. Turning it on only offers an extra backend: the
	 * server image editor path keeps working exactly as before.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = ILSWQ_Settings::get();
		$enabled  = ! empty( $settings['browser_conversion'] );

		/**
		 * Filters whether the browser (WebAssembly) conversion backend is offered.
		 *
		 * @param bool $enabled Whether browser conversion is available.
		 */
		return (bool) apply_filters( 'ilswq_browser_conversion_enabled', $enabled );
	}

	/**
	 * Per-user configuration for the admin page.
	 *
	 * @return array<string, mixed>
	 */
	public static function admin_config() {
		return array(
			'enabled'    => self::is_enabled(),
			'prepareUrl' => rest_url( self::REST_NAMESPACE . '/prepare' ),
			'finishUrl'  => rest_url( self::REST_NAMESPACE . '/finish' ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'bundleUrl'  => add_query_arg( 'ver', ILSWQ_VERSION, ILSWQ_URL . 'assets/wasm/safewebp-browser.js' ),
		);
	}

	/**
	 * Whether one scanned source fits inside the browser encoder limits.
	 *
	 * Kept beside the REST checks so the report, the queue and the endpoint all
	 * agree on which files the browser backend can actually accept.
	 *
	 * @param array<string, mixed> $source Scanned source.
	 * @return bool
	 */
	public static function source_within_limits( $source ) {
		$bytes  = isset( $source['original_size'] ) ? (int) $source['original_size'] : 0;
		$width  = isset( $source['width'] ) ? (int) $source['width'] : 0;
		$height = isset( $source['height'] ) ? (int) $source['height'] : 0;

		if ( $bytes < 1 || $bytes > self::MAX_INPUT ) {
			return false;
		}

		return self::dimensions_within_limits( $width, $height );
	}

	/**
	 * Whether a canvas size fits inside the browser encoder limits.
	 *
	 * @param int $width Width.
	 * @param int $height Height.
	 * @return bool
	 */
	public static function dimensions_within_limits( $width, $height ) {
		$width  = (int) $width;
		$height = (int) $height;

		if ( $width < 1 || $height < 1 || $width > self::MAX_AXIS || $height > self::MAX_AXIS ) {
			return false;
		}

		return ( $width * $height ) <= self::MAX_PIXELS;
	}

	/**
	 * Register the conversion routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		foreach ( array( 'prepare', 'finish' ) as $action ) {
			register_rest_route(
				self::REST_NAMESPACE,
				'/' . $action,
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, $action ),
					'permission_callback' => array( __CLASS__, 'permission' ),
				)
			);
		}
	}

	/**
	 * Require an authenticated administrator who may edit attachments.
	 *
	 * WordPress cookie authentication already validated the REST nonce before
	 * this callback runs, so an invalid nonce is rejected as unauthenticated.
	 *
	 * @return bool|WP_Error
	 */
	public static function permission() {
		if ( ! self::is_enabled() ) {
			return new WP_Error( 'ilswq_browser_disabled', __( 'Browser conversion is not available on this site.', 'indexlane-safe-webp-queue' ), array( 'status' => 403 ) );
		}

		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) || ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'ilswq_browser_forbidden', __( 'You cannot convert images on this site.', 'indexlane-safe-webp-queue' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Publish the exact source revision the browser must convert.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function prepare( $request ) {
		$attachment_id = absint( $request->get_param( 'attachmentId' ) );
		$size_key      = sanitize_text_field( (string) $request->get_param( 'sizeKey' ) );

		if ( $attachment_id < 1 || '' === $size_key ) {
			return new WP_Error( 'ilswq_browser_request', __( 'An attachment and size are required.', 'indexlane-safe-webp-queue' ), array( 'status' => 400 ) );
		}

		$resolved = self::resolve_source( $attachment_id, $size_key );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$source = $resolved['source'];
		$record = $resolved['record'];

		if ( is_array( $record ) ) {
			return rest_ensure_response( self::stored_response( $record, $source ) );
		}

		if ( self::server_job_is_running() ) {
			return new WP_Error(
				'ilswq_browser_queue_active',
				__( 'A server conversion job is running. Pause or finish it before converting in the browser.', 'indexlane-safe-webp-queue' ),
				array( 'status' => 409 )
			);
		}

		$input_bytes = filesize( $source['path'] );
		$digest      = hash_file( 'sha256', $source['path'] );
		if ( false === $input_bytes || ! is_string( $digest ) ) {
			return new WP_Error( 'ilswq_browser_source', __( 'The source image is not readable.', 'indexlane-safe-webp-queue' ), array( 'status' => 409 ) );
		}

		$quality  = (int) ILSWQ_Settings::quality_for_mime( (string) $source['mime_type'], ILSWQ_Settings::get() );
		$token    = wp_generate_password( 43, false, false );
		$job      = array(
			'userId'       => get_current_user_id(),
			'attachmentId' => $attachment_id,
			'sizeKey'      => $size_key,
			'path'         => $source['path'],
			'sha256'       => $digest,
			'inputBytes'   => (int) $input_bytes,
			'width'        => (int) $source['width'],
			'height'       => (int) $source['height'],
			'quality'      => $quality,
			'version'      => self::VERSION,
			'expires'      => time() + self::TOKEN_TTL,
		);
		$stored   = set_transient( self::token_key( $token ), $job, self::TOKEN_TTL );
		if ( ! $stored ) {
			return new WP_Error( 'ilswq_browser_token', __( 'Could not start a browser conversion job.', 'indexlane-safe-webp-queue' ), array( 'status' => 503 ) );
		}

		return rest_ensure_response(
			array(
				'status'       => 'prepared',
				'token'        => $token,
				'sourceUrl'    => ILSWQ_Scanner::path_to_url( $source['path'] ),
				'sourceSha256' => $job['sha256'],
				'width'        => $job['width'],
				'height'       => $job['height'],
				'inputBytes'   => $job['inputBytes'],
				'mimeType'     => (string) $source['mime_type'],
				'quality'      => $quality,
				'version'      => self::VERSION,
				'attachmentId' => $attachment_id,
				'sizeKey'      => $size_key,
			)
		);
	}

	/**
	 * Accept encoded bytes and publish them as the plugin's WebP sidecar.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function finish( $request ) {
		// Use the queue's lock for the entire read/validate/install/map update.
		// This also serializes uploads from other tabs and automatic jobs.
		$queue = new ILSWQ_Queue( new ILSWQ_Converter( new ILSWQ_Scanner() ) );
		$lock  = $queue->acquire_lock();
		if ( false === $lock ) {
			return new WP_Error( 'ilswq_browser_busy', __( 'Another conversion is saving files. Wait a moment and try again.', 'indexlane-safe-webp-queue' ), array( 'status' => 409 ) );
		}

		try {
			return self::finish_locked( $request );
		} finally {
			$queue->release_lock( $lock );
		}
	}

	/**
	 * Validate and install an upload while holding the shared conversion lock.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function finish_locked( $request ) {
		$token = (string) $request->get_param( 'token' );
		$files = $request->get_file_params();
		$file  = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : null;

		if ( '' === $token || ! is_array( $file ) || empty( $file['tmp_name'] ) || ! empty( $file['error'] ) ) {
			return new WP_Error( 'ilswq_browser_request', __( 'The conversion result is missing.', 'indexlane-safe-webp-queue' ), array( 'status' => 400 ) );
		}

		$key = self::token_key( $token );
		$job = get_transient( $key );
		if ( ! is_array( $job ) ) {
			return new WP_Error( 'ilswq_browser_token', __( 'This conversion has expired. Convert the image again.', 'indexlane-safe-webp-queue' ), array( 'status' => 400 ) );
		}
		if ( (int) $job['userId'] !== get_current_user_id() || $job['version'] !== self::VERSION || (int) $job['expires'] < time() ) {
			return new WP_Error( 'ilswq_browser_token', __( 'This conversion has expired. Convert the image again.', 'indexlane-safe-webp-queue' ), array( 'status' => 400 ) );
		}

		if ( is_array( $job['result'] ?? null ) ) {
			return rest_ensure_response( $job['result'] );
		}

		if ( self::server_job_is_running( false ) ) {
			return new WP_Error( 'ilswq_browser_queue_active', __( 'A server conversion job is running. Pause or finish it before converting in the browser.', 'indexlane-safe-webp-queue' ), array( 'status' => 409 ) );
		}

		$uploaded_size = filesize( (string) $file['tmp_name'] );
		if ( false === $uploaded_size || $uploaded_size < 1 || $uploaded_size > self::MAX_OUTPUT ) {
			return new WP_Error( 'ilswq_browser_output', __( 'The converted image is empty or too large.', 'indexlane-safe-webp-queue' ), array( 'status' => 413 ) );
		}

		// The uploaded temporary file is read directly: it is owned by this
		// request and never exposed through the WordPress filesystem layer.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$bytes = (string) file_get_contents( (string) $file['tmp_name'], false, null, 0, self::MAX_OUTPUT + 1 );
		if ( '' === $bytes || strlen( $bytes ) > self::MAX_OUTPUT ) {
			return new WP_Error( 'ilswq_browser_output', __( 'The converted image is empty or too large.', 'indexlane-safe-webp-queue' ), array( 'status' => 413 ) );
		}

		$dimensions = self::webp_dimensions( $bytes );
		if ( null === $dimensions ) {
			return new WP_Error( 'ilswq_browser_container', __( 'The converted image is not a supported still WebP file.', 'indexlane-safe-webp-queue' ), array( 'status' => 400 ) );
		}
		if ( $dimensions[0] !== (int) $job['width'] || $dimensions[1] !== (int) $job['height'] ) {
			return new WP_Error( 'ilswq_browser_dimensions', __( 'The converted image size does not match its source.', 'indexlane-safe-webp-queue' ), array( 'status' => 400 ) );
		}

		$path = wp_normalize_path( (string) $job['path'] );
		if ( ! file_exists( $path ) || ! ILSWQ_Scanner::is_uploads_path( $path ) ) {
			return new WP_Error( 'ilswq_browser_source', __( 'The source image is no longer available.', 'indexlane-safe-webp-queue' ), array( 'status' => 409 ) );
		}
		$digest = hash_file( 'sha256', $path );
		if ( ! is_string( $digest ) || ! hash_equals( (string) $job['sha256'], $digest ) ) {
			return new WP_Error( 'ilswq_browser_stale', __( 'The source image changed while it was converting. Convert it again.', 'indexlane-safe-webp-queue' ), array( 'status' => 409 ) );
		}

		$resolved = self::resolve_source( (int) $job['attachmentId'], (string) $job['sizeKey'] );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$source   = $resolved['source'];
		$settings = ILSWQ_Settings::get();
		if ( $path !== wp_normalize_path( (string) $source['path'] ) || (int) $source['width'] !== (int) $job['width'] || (int) $source['height'] !== (int) $job['height'] ) {
			return new WP_Error( 'ilswq_browser_stale', __( 'The source image changed while it was converting. Convert it again.', 'indexlane-safe-webp-queue' ), array( 'status' => 409 ) );
		}
		if ( (int) $job['quality'] !== ILSWQ_Settings::quality_for_mime( (string) $source['mime_type'], $settings ) ) {
			return new WP_Error( 'ilswq_browser_settings', __( 'The quality setting changed while this image was converting. Convert it again.', 'indexlane-safe-webp-queue' ), array( 'status' => 409 ) );
		}

		$converter = new ILSWQ_Converter( new ILSWQ_Scanner() );
		$result    = $converter->install_generated_bytes(
			(int) $job['attachmentId'],
			$source,
			$bytes,
			'WASM (browser)',
			$settings
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! empty( $result['conflict'] ) ) {
			return new WP_Error(
				'ilswq_browser_conflict',
				isset( $result['reason'] ) ? (string) $result['reason'] : __( 'A sibling WebP file is in the way.', 'indexlane-safe-webp-queue' ),
				array( 'status' => 409 )
			);
		}

		if ( ! empty( $result['skipped'] ) ) {
			$response = array(
				'status'       => 'skipped_not_smaller',
				'inputBytes'   => (int) $job['inputBytes'],
				'outputBytes'  => strlen( $bytes ),
				'width'        => $dimensions[0],
				'height'       => $dimensions[1],
				'reason'       => isset( $result['reason'] ) ? (string) $result['reason'] : '',
				'sourceSha256' => (string) $job['sha256'],
				'version'      => self::VERSION,
			);
		} else {
			$entry    = isset( $result['entry'] ) && is_array( $result['entry'] ) ? $result['entry'] : array();
			$response = array(
				'status'        => 'ready',
				'inputBytes'    => (int) $job['inputBytes'],
				'outputBytes'   => isset( $entry['webp_size'] ) ? (int) $entry['webp_size'] : strlen( $bytes ),
				'width'         => $dimensions[0],
				'height'        => $dimensions[1],
				'outputUrl'     => ILSWQ_Scanner::path_to_url( (string) ( $entry['webp'] ?? '' ) ),
				'outputSha256'  => hash( 'sha256', $bytes ),
				'sourceSha256'  => (string) $job['sha256'],
				'attachmentId'  => (int) $job['attachmentId'],
				'sizeKey'       => (string) $job['sizeKey'],
				'version'       => self::VERSION,
			);
		}

		// Remember the outcome so a retried upload returns the same answer.
		$job['result'] = $response;
		set_transient( $key, $job, max( 60, (int) $job['expires'] - time() ) );

		return rest_ensure_response( $response );
	}

	/**
	 * Resolve one attachment size to a local source file.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $size_key Size key.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function resolve_source( $attachment_id, $size_key ) {
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return new WP_Error( 'ilswq_browser_attachment', __( 'Unknown attachment.', 'indexlane-safe-webp-queue' ), array( 'status' => 404 ) );
		}

		if ( ILSWQ_Scanner::is_excluded( $attachment_id ) ) {
			return new WP_Error( 'ilswq_browser_excluded', __( 'This image is excluded from WebP conversion.', 'indexlane-safe-webp-queue' ), array( 'status' => 409 ) );
		}

		$scanner = new ILSWQ_Scanner();
		$settings = ILSWQ_Settings::get();
		$match   = null;

		foreach ( $scanner->scan_sources( $attachment_id, $settings ) as $source ) {
			if ( sanitize_key( (string) $source['name'] ) !== sanitize_key( $size_key ) ) {
				continue;
			}
			$match = $source;
			break;
		}

		if ( null === $match ) {
			return new WP_Error( 'ilswq_browser_size', __( 'Unknown image size.', 'indexlane-safe-webp-queue' ), array( 'status' => 404 ) );
		}

		$path = wp_normalize_path( (string) $match['path'] );
		if ( ! file_exists( $path ) || ! ILSWQ_Scanner::is_uploads_path( $path ) ) {
			return new WP_Error( 'ilswq_browser_source', __( 'The source image is not available.', 'indexlane-safe-webp-queue' ), array( 'status' => 404 ) );
		}
		if ( ! ILSWQ_Scanner::is_supported_source_mime( (string) $match['mime_type'] ) ) {
			return new WP_Error( 'ilswq_browser_mime', __( 'Only JPEG and PNG images can be converted in the browser.', 'indexlane-safe-webp-queue' ), array( 'status' => 415 ) );
		}

		$size = filesize( $path );
		if ( false === $size || $size < 1 || $size > self::MAX_INPUT ) {
			return new WP_Error( 'ilswq_browser_size_limit', __( 'This file is too large to convert in the browser.', 'indexlane-safe-webp-queue' ), array( 'status' => 413 ) );
		}

		if ( ! self::dimensions_within_limits( isset( $match['width'] ) ? $match['width'] : 0, isset( $match['height'] ) ? $match['height'] : 0 ) ) {
			return new WP_Error( 'ilswq_browser_pixels', __( 'This image is too large to convert in the browser.', 'indexlane-safe-webp-queue' ), array( 'status' => 413 ) );
		}

		if ( (int) $match['width'] * (int) $match['height'] > (int) $settings['max_pixels'] ) {
			return new WP_Error( 'ilswq_browser_pixels', __( 'Image exceeds max pixel limit', 'indexlane-safe-webp-queue' ), array( 'status' => 413 ) );
		}

		$record = null;
		if ( 'converted' === $match['status_key'] ) {
			$record = $match['existing_plugin_webp_entry'];
		} elseif ( 'conflict' === $match['status_key'] ) {
			return new WP_Error( 'ilswq_browser_conflict', $match['reason'], array( 'status' => 409 ) );
		} elseif ( empty( $match['eligible'] ) ) {
			return new WP_Error( 'ilswq_browser_source', $match['reason'], array( 'status' => 409 ) );
		}

		return array(
			'source' => $match,
			'record' => $record,
		);
	}

	/**
	 * Describe an already recorded conversion for the browser.
	 *
	 * @param array<string, mixed> $record Stored map entry.
	 * @param array<string, mixed> $source Resolved source.
	 * @return array<string, mixed>
	 */
	private static function stored_response( $record, $source ) {
		return array(
			'status'       => 'ready',
			'inputBytes'   => isset( $record['source_size'] ) ? (int) $record['source_size'] : 0,
			'outputBytes'  => isset( $record['webp_size'] ) ? (int) $record['webp_size'] : 0,
			'width'        => isset( $record['width'] ) ? (int) $record['width'] : (int) $source['width'],
			'height'       => isset( $record['height'] ) ? (int) $record['height'] : (int) $source['height'],
			'outputUrl'    => isset( $record['webp'] ) ? ILSWQ_Scanner::path_to_url( (string) $record['webp'] ) : '',
			'editor'       => isset( $record['editor'] ) ? (string) $record['editor'] : '',
			'version'      => self::VERSION,
		);
	}

	/**
	 * Whether a server-side conversion job is queued or running.
	 *
	 * Both backends replace the same sidecar files and stored map, so the
	 * browser backend refuses to start while the queue is moving.
	 *
	 * @param bool $include_lock Include a batch still finishing after a pause.
	 * @return bool
	 */
	private static function server_job_is_running( $include_lock = true ) {
		$lock = get_option( ILSWQ_Queue::LOCK_OPTION, array() );
		if ( $include_lock && is_array( $lock ) && ! empty( $lock['expires'] ) && (int) $lock['expires'] >= time() ) {
			return true;
		}
		$job = get_option( ILSWQ_Queue::JOB_OPTION, array() );
		if ( ! is_array( $job ) || empty( $job['state'] ) ) {
			return false;
		}

		return in_array( (string) $job['state'], array( 'queued', 'running' ), true );
	}

	/**
	 * Transient key for a conversion token.
	 *
	 * @param string $token Opaque token.
	 * @return string
	 */
	private static function token_key( $token ) {
		return 'ilswq_wasm_' . hash( 'sha256', (string) $token );
	}

	/**
	 * Validate a still WebP container and return its canvas dimensions.
	 *
	 * This proves the byte layout, chunk bounds and feature flags; it does not
	 * decode the compressed pixels.
	 *
	 * @param string $bytes File bytes.
	 * @return array<int, int>|null [width, height] or null when invalid.
	 */
	public static function webp_dimensions( $bytes ) {
		$total = strlen( $bytes );
		if ( $total < 20 || $total > self::MAX_OUTPUT ) {
			return null;
		}
		if ( 'RIFF' !== substr( $bytes, 0, 4 ) || 'WEBP' !== substr( $bytes, 8, 4 ) ) {
			return null;
		}
		if ( self::u32( $bytes, 4 ) !== $total - 8 ) {
			return null;
		}

		$position = 12;
		$extended = null;
		$alpha    = null;
		$image    = null;
		$kind     = '';

		while ( $position < $total ) {
			if ( $position + 8 > $total ) {
				return null;
			}
			$tag   = substr( $bytes, $position, 4 );
			$size  = self::u32( $bytes, $position + 4 );
			$start = $position + 8;
			$end   = $start + $size;
			if ( $size < 1 || $end + ( $size & 1 ) > $total ) {
				return null;
			}
			if ( ( $size & 1 ) && "\0" !== $bytes[ $end ] ) {
				return null;
			}

			if ( 'VP8X' === $tag ) {
				if ( 12 !== $position || 10 !== $size || null !== $extended ) {
					return null;
				}
				$flags = ord( $bytes[ $start ] );
				if ( 0 !== ( $flags & ~0x10 ) || "\0\0\0" !== substr( $bytes, $start + 1, 3 ) ) {
					return null;
				}
				$extended = array(
					self::u24( $bytes, $start + 4 ) + 1,
					self::u24( $bytes, $start + 7 ) + 1,
					(bool) ( $flags & 0x10 ),
				);
			} elseif ( 'ALPH' === $tag ) {
				if ( null === $extended || ! $extended[2] || null !== $alpha || null !== $image || $size < 2 ) {
					return null;
				}
				$alpha = true;
			} elseif ( 'VP8 ' === $tag ) {
				if ( null !== $image || $size < 11 || "\x9d\x01\x2a" !== substr( $bytes, $start + 3, 3 ) ) {
					return null;
				}
				$image = array(
					self::u16( $bytes, $start + 6 ) & 0x3fff,
					self::u16( $bytes, $start + 8 ) & 0x3fff,
				);
				$kind  = 'VP8 ';
			} elseif ( 'VP8L' === $tag ) {
				if ( null !== $image || null !== $alpha || $size < 6 || 0x2f !== ord( $bytes[ $start ] ) ) {
					return null;
				}
				$bits  = self::u32( $bytes, $start + 1 );
				$image = array( ( $bits & 0x3fff ) + 1, ( ( $bits >> 14 ) & 0x3fff ) + 1 );
				$kind  = 'VP8L';
			} else {
				// Includes ANIM, ANMF, ICCP, EXIF and XMP.
				return null;
			}

			$position = $end + ( $size & 1 );
		}

		if ( null === $image || $image[0] < 1 || $image[1] < 1 ) {
			return null;
		}
		if ( $image[0] > self::MAX_AXIS || $image[1] > self::MAX_AXIS || ( $image[0] * $image[1] ) > self::MAX_PIXELS ) {
			return null;
		}
		if ( null !== $extended && ( $extended[0] !== $image[0] || $extended[1] !== $image[1] ) ) {
			return null;
		}
		if ( 'VP8 ' === $kind && null !== $extended && $extended[2] !== (bool) $alpha ) {
			return null;
		}

		return $image;
	}

	/**
	 * Little-endian 16-bit integer.
	 *
	 * @param string $bytes Bytes.
	 * @param int    $at Offset.
	 * @return int
	 */
	private static function u16( $bytes, $at ) {
		return unpack( 'v', substr( $bytes, $at, 2 ) )[1];
	}

	/**
	 * Little-endian 24-bit integer.
	 *
	 * @param string $bytes Bytes.
	 * @param int    $at Offset.
	 * @return int
	 */
	private static function u24( $bytes, $at ) {
		return ord( $bytes[ $at ] ) | ( ord( $bytes[ $at + 1 ] ) << 8 ) | ( ord( $bytes[ $at + 2 ] ) << 16 );
	}

	/**
	 * Little-endian 32-bit integer.
	 *
	 * @param string $bytes Bytes.
	 * @param int    $at Offset.
	 * @return int
	 */
	private static function u32( $bytes, $at ) {
		return unpack( 'V', substr( $bytes, $at, 4 ) )[1];
	}
}

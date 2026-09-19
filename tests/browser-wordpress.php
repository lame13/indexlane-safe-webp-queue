<?php
/**
 * Local WordPress integration test for the browser (WebAssembly) backend.
 *
 * The browser encoder itself runs in the administrator's browser. This test
 * covers everything on the server side of that contract: capability routing,
 * the REST prepare/finish handshake, container validation, trust boundaries,
 * stored metadata and frontend serving.
 *
 * Usage:
 * php tests/browser-wordpress.php /path/to/wordpress
 *
 * @package IndexLaneSafeWebPQueue
 */

$wp_path = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : rtrim( (string) getenv( 'WP_PATH' ), '/\\' );

if ( '' === $wp_path || ! file_exists( $wp_path . '/wp-load.php' ) ) {
	fwrite( STDERR, "WordPress path is required.\n" );
	exit( 1 );
}

define( 'WP_USE_THEMES', false );
require $wp_path . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

if ( ! is_plugin_active( 'indexlane-safe-webp-queue/indexlane-safe-webp-queue.php' ) ) {
	$result = activate_plugin( 'indexlane-safe-webp-queue/indexlane-safe-webp-queue.php' );
	if ( is_wp_error( $result ) ) {
		fwrite( STDERR, $result->get_error_message() . "\n" );
		exit( 1 );
	}
}

// Activation inside this process happens after plugins_loaded.
ILSWQ_Plugin::instance();
ILSWQ_Browser::init();

if ( ! function_exists( 'imagewebp' ) ) {
	fwrite( STDERR, "GD with WebP writing is required to build browser conversion fixtures.\n" );
	exit( 3 );
}

/**
 * Fail the test.
 *
 * @param string $message Message.
 * @return void
 */
function ilswq_browser_fail( $message ) {
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

/**
 * Assert a condition.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 */
function ilswq_browser_assert( $condition, $message ) {
	if ( ! $condition ) {
		ilswq_browser_fail( $message );
	}
}

/**
 * Return the decoded body of a REST response.
 *
 * @param mixed $response Response.
 * @return mixed
 */
function ilswq_browser_body( $response ) {
	return $response instanceof WP_REST_Response ? $response->get_data() : $response;
}

/**
 * Assert a response failed with a specific error code and HTTP status.
 *
 * The REST server converts returned WP_Error objects into error responses, so
 * both shapes are accepted here.
 *
 * @param mixed  $response Response.
 * @param string $code Expected error code.
 * @param int    $status Expected HTTP status.
 * @param string $label Case label.
 * @return void
 */
function ilswq_browser_assert_error( $response, $code, $status, $label ) {
	if ( $response instanceof WP_Error ) {
		$actual_code   = (string) $response->get_error_code();
		$error_data    = $response->get_error_data();
		$actual_status = is_array( $error_data ) && isset( $error_data['status'] ) ? (int) $error_data['status'] : 500;
	} elseif ( $response instanceof WP_REST_Response ) {
		$data          = $response->get_data();
		$actual_code   = is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : '';
		$actual_status = (int) $response->get_status();
	} else {
		ilswq_browser_fail( $label . ': expected an error response, received ' . gettype( $response ) . '.' );
		return;
	}

	ilswq_browser_assert( $code === $actual_code, $label . ': expected error ' . $code . ', received "' . $actual_code . '".' );
	ilswq_browser_assert( $status === $actual_status, $label . ': expected HTTP ' . $status . ', received ' . $actual_status . '.' );
}

/**
 * Create a test JPEG.
 *
 * @param string $path Path.
 * @param int    $width Width.
 * @param int    $height Height.
 * @return void
 */
function ilswq_browser_create_jpeg( $path, $width = 1200, $height = 800 ) {
	$image = imagecreatetruecolor( $width, $height );
	for ( $y = 0; $y < $height; ++$y ) {
		$color = imagecolorallocate( $image, ( $y * 3 ) % 255, ( 90 + $y ) % 255, ( 180 + ( $y * 2 ) ) % 255 );
		imageline( $image, 0, $y, $width - 1, $y, $color );
	}
	imagejpeg( $image, $path, 90 );
	imagedestroy( $image );
}

/**
 * Create a transparent PNG.
 *
 * @param string $path Path.
 * @return void
 */
function ilswq_browser_create_png( $path ) {
	$image = imagecreatetruecolor( 600, 400 );
	imagealphablending( $image, false );
	imagesavealpha( $image, true );
	imagefilledrectangle( $image, 0, 0, 599, 399, imagecolorallocatealpha( $image, 0, 0, 0, 127 ) );
	imagealphablending( $image, true );
	imagefilledellipse( $image, 240, 180, 300, 220, imagecolorallocatealpha( $image, 30, 120, 220, 20 ) );
	imagefilledrectangle( $image, 260, 120, 520, 320, imagecolorallocatealpha( $image, 220, 70, 50, 35 ) );
	imagepng( $image, $path );
	imagedestroy( $image );
}

/**
 * Insert an image attachment and generate its metadata.
 *
 * @param string $path Source path.
 * @param string $name Upload file name.
 * @return int
 */
function ilswq_browser_insert_attachment( $path, $name ) {
	$uploads = wp_upload_dir();
	$target  = trailingslashit( $uploads['path'] ) . $name;

	if ( ! copy( $path, $target ) ) {
		ilswq_browser_fail( 'Could not copy the fixture into the uploads directory.' );
	}

	$file_type = wp_check_filetype( $target );
	$mime      = isset( $file_type['type'] ) && '' !== $file_type['type'] ? $file_type['type'] : 'image/jpeg';

	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => $mime,
			'post_title'     => pathinfo( $name, PATHINFO_FILENAME ),
			'post_status'    => 'inherit',
			'guid'           => trailingslashit( $uploads['url'] ) . $name,
		),
		$target
	);

	if ( is_wp_error( $attachment_id ) || $attachment_id <= 0 ) {
		ilswq_browser_fail( 'Could not create the fixture attachment.' );
	}

	$metadata = wp_generate_attachment_metadata( $attachment_id, $target );
	wp_update_attachment_metadata( $attachment_id, $metadata );

	return (int) $attachment_id;
}

/**
 * Encode a fixture WebP with GD at a known size.
 *
 * @param string $path Output path.
 * @param int    $width Width.
 * @param int    $height Height.
 * @param bool   $with_alpha Include an alpha channel.
 * @return bool
 */
function ilswq_browser_encode_fixture( $path, $width, $height, $with_alpha = false ) {
	$image = imagecreatetruecolor( $width, $height );

	if ( $with_alpha ) {
		imagealphablending( $image, false );
		imagesavealpha( $image, true );
		imagefilledrectangle( $image, 0, 0, $width - 1, $height - 1, imagecolorallocatealpha( $image, 0, 0, 0, 127 ) );
		imagealphablending( $image, true );
	}

	for ( $y = 0; $y < $height; ++$y ) {
		$color = imagecolorallocate( $image, ( $y * 5 ) % 255, ( 40 + ( $y * 2 ) ) % 255, ( 200 + $y ) % 255 );
		imageline( $image, 0, $y, $width - 1, $y, $color );
	}

	imagewebp( $image, $path, 80 );
	imagedestroy( $image );

	return file_exists( $path ) && filesize( $path ) > 0;
}

/**
 * Dispatch one browser REST request.
 *
 * @param string              $action Route action.
 * @param array<string,mixed> $params Body parameters.
 * @param array<string,mixed> $files Fake uploaded files.
 * @return WP_REST_Response|WP_Error
 */
function ilswq_browser_request( $action, $params = array(), $files = array() ) {
	$request = new WP_REST_Request( 'POST', '/ilswq-wasm/v1/' . $action );
	$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}

	if ( ! empty( $files ) ) {
		$request->set_file_params( $files );
	}

	return rest_do_request( $request );
}

/**
 * Prepare one source file.
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $size_key Size key.
 * @return WP_REST_Response|WP_Error
 */
function ilswq_browser_prepare( $attachment_id, $size_key ) {
	return ilswq_browser_request(
		'prepare',
		array(
			'attachmentId' => $attachment_id,
			'sizeKey'      => $size_key,
		)
	);
}

/**
 * Assert a response is a successful REST response and return its body.
 *
 * @param mixed  $response Response.
 * @param string $label Case label.
 * @return array<string,mixed>
 */
function ilswq_browser_success( $response, $label ) {
	$data = ilswq_browser_body( $response );

	if ( ! $response instanceof WP_REST_Response ) {
		ilswq_browser_fail( $label . ': expected a REST response.' );
	}

	ilswq_browser_assert( 200 === (int) $response->get_status(), $label . ': expected HTTP 200, received ' . $response->get_status() . '.' );

	if ( ! is_array( $data ) || isset( $data['code'] ) ) {
		ilswq_browser_fail( $label . ': received an error response.' );
	}

	return $data;
}

/**
 * Upload encoded bytes for a prepared token.
 *
 * @param string $token Token.
 * @param string $path Local file holding the encoded bytes.
 * @return WP_REST_Response|WP_Error
 */
function ilswq_browser_finish( $token, $path ) {
	return ilswq_browser_request(
		'finish',
		array( 'token' => $token ),
		array(
			'file' => array(
				'name'     => 'converted.webp',
				'type'     => 'image/webp',
				'tmp_name' => $path,
				'error'    => 0,
				'size'     => (int) filesize( $path ),
			),
		)
	);
}

$uploads = wp_upload_dir();
if ( ! empty( $uploads['error'] ) ) {
	ilswq_browser_fail( 'The uploads directory is unavailable.' );
}

ILSWQ_Settings::save(
	array(
		'batch_size'         => 3,
		'max_pixels'         => 16000000,
		'jpeg_quality'       => 80,
		'png_quality'        => 80,
		'skip_larger'        => 1,
		'serve_webp'         => 1,
		'auto_uploads'       => 1,
		'browser_conversion' => 0,
	)
);

$login = 'ilswq-browser-admin';
$user  = get_user_by( 'login', $login );
if ( ! $user ) {
	$user_id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => $login . '@example.test',
			'role'       => 'administrator',
		)
	);
	if ( is_wp_error( $user_id ) ) {
		ilswq_browser_fail( 'Could not create the test administrator.' );
	}
	$user = get_user_by( 'id', $user_id );
}
wp_set_current_user( (int) $user->ID );

$temp_dir = trailingslashit( get_temp_dir() ) . 'ilswq-browser-' . wp_generate_password( 8, false, false );
wp_mkdir_p( $temp_dir );

$jpeg_fixture = $temp_dir . '/photo.jpg';
$png_fixture  = $temp_dir . '/alpha.png';
ilswq_browser_create_jpeg( $jpeg_fixture );
ilswq_browser_create_png( $png_fixture );

$jpeg_id = ilswq_browser_insert_attachment( $jpeg_fixture, 'ilswq-browser-photo.jpg' );
$png_id  = ilswq_browser_insert_attachment( $png_fixture, 'ilswq-browser-alpha.png' );

// This server reports that it cannot write WebP, so the browser is the only backend.
add_filter( 'ilswq_has_server_webp_writer', '__return_false' );

$settings = ILSWQ_Settings::get();
$scanner  = new ILSWQ_Scanner();

// 1. With browser conversion off, the source stays skipped.
ilswq_browser_assert( ! ILSWQ_Browser::is_enabled(), 'Browser conversion must start disabled.' );

$off_row = $scanner->scan_attachment( $jpeg_id, $settings );
ilswq_browser_assert( 'skipped' === $off_row['status_key'], 'A source without any WebP writer must be skipped while browser conversion is off.' );
ilswq_browser_assert( 0 === count( $off_row['browser_sources'] ), 'Browser targets were exposed while browser conversion was off.' );

// 2. Turning browser conversion on makes the source browser-eligible.
$settings['browser_conversion'] = 1;
ILSWQ_Settings::save( $settings );
$settings = ILSWQ_Settings::get();

ilswq_browser_assert( ILSWQ_Browser::is_enabled(), 'Browser conversion did not turn on.' );

$row = $scanner->scan_attachment( $jpeg_id, $settings );
ilswq_browser_assert( 'eligible' === $row['status_key'], 'A browser-convertible source must be eligible.' );
ilswq_browser_assert( ! empty( $row['eligible'] ), 'A browser-convertible source must stay selectable.' );
ilswq_browser_assert( ! empty( $row['browser_only'] ), 'A source with no server writer must be marked browser-only.' );

$names = array();
foreach ( $row['browser_sources'] as $source ) {
	$names[] = $source['name'];
}

ilswq_browser_assert( in_array( 'full', $names, true ), 'The full-size file was missing from the browser targets.' );
ilswq_browser_assert( in_array( 'thumbnail', $names, true ), 'The generated thumbnail was missing from the browser targets.' );
ilswq_browser_assert( count( $names ) === count( array_unique( $names ) ), 'Browser targets contained duplicate size keys.' );

// 3. The server queue refuses work it cannot do, and direct conversion does not fail the row.
$queue     = new ILSWQ_Queue( new ILSWQ_Converter( new ILSWQ_Scanner() ) );
$started   = $queue->start_job( array( $jpeg_id ), $settings );
$converter = new ILSWQ_Converter( new ILSWQ_Scanner() );

ilswq_browser_assert( is_wp_error( $started ), 'A conversion job must be refused without a server WebP writer.' );
ilswq_browser_assert( 'ilswq_queue_no_writer' === $started->get_error_code(), 'The queue returned the wrong refusal reason.' );

$converted = $converter->convert_attachment( $jpeg_id, $settings );
ilswq_browser_assert( 'eligible' === $converted['status_key'], 'Server conversion must leave a browser-only row eligible instead of failed.' );
ilswq_browser_assert( ! file_exists( ILSWQ_Scanner::output_path( get_attached_file( $jpeg_id ) ) ), 'Server conversion created a WebP file without a WebP writer.' );

// Automatic uploads cannot run either, and must not leave an error behind.
$queued = $queue->enqueue_upload( $jpeg_id, $settings );
ilswq_browser_assert( is_wp_error( $queued ), 'Automatic upload conversion must be refused without a server WebP writer.' );

// An upload queued before the writer disappears must not retry browser-only work.
remove_filter( 'ilswq_has_server_webp_writer', '__return_false' );
ilswq_browser_assert( true === $queue->enqueue_upload( $jpeg_id, $settings ), 'Could not queue automatic regression fixture.' );
$auto_queue = get_option( ILSWQ_Queue::AUTO_OPTION );
$auto_queue[ $jpeg_id ]['available_at'] = time() - 1;
update_option( ILSWQ_Queue::AUTO_OPTION, $auto_queue );
add_filter( 'ilswq_has_server_webp_writer', '__return_false' );
$auto_result = $queue->process_next_batch();
ilswq_browser_assert( 'eligible' === $auto_result['rows'][0]['status_key'], 'Automatic conversion did not preserve browser eligibility.' );
$remaining_auto = get_option( ILSWQ_Queue::AUTO_OPTION, array() );
ilswq_browser_assert( ! isset( $remaining_auto[ $jpeg_id ] ), 'Browser-only automatic upload was scheduled for a futile retry.' );
ilswq_browser_assert( ! get_post_meta( $jpeg_id, ILSWQ_META_LAST_ERROR, true ), 'Browser-only automatic upload was marked as failed.' );
$queue->remove_attachment( $png_id );

// 4. Prepare publishes one exact source revision.
$prepared = ilswq_browser_prepare( $jpeg_id, 'full' );
$prepared = ilswq_browser_success( $prepared, 'Prepare' );
ilswq_browser_assert( 'prepared' === $prepared['status'], 'Prepare did not return a prepared job.' );
ilswq_browser_assert( is_string( $prepared['token'] ) && strlen( $prepared['token'] ) >= 20, 'Prepare did not return a usable token.' );
ilswq_browser_assert( 1 === preg_match( '/^[a-f0-9]{64}$/', $prepared['sourceSha256'] ), 'Prepare did not return a SHA-256 digest.' );
ilswq_browser_assert( 1200 === (int) $prepared['width'] && 800 === (int) $prepared['height'], 'Prepare returned the wrong source dimensions.' );
ilswq_browser_assert( 'image/jpeg' === $prepared['mimeType'], 'Prepare returned the wrong MIME type.' );
ilswq_browser_assert( 80 === (int) $prepared['quality'], 'Prepare did not use the saved JPEG quality.' );
ilswq_browser_assert( ILSWQ_Scanner::path_to_url( get_attached_file( $jpeg_id ) ) === $prepared['sourceUrl'], 'Prepare did not publish a same-origin source URL.' );

ilswq_browser_assert_error( ilswq_browser_prepare( $jpeg_id, 'not-a-size' ), 'ilswq_browser_size', 404, 'Unknown size' );
ilswq_browser_assert_error( ilswq_browser_prepare( 999999999, 'full' ), 'ilswq_browser_attachment', 404, 'Unknown attachment' );

ILSWQ_Scanner::set_excluded( $jpeg_id, true );
ilswq_browser_assert_error( ilswq_browser_prepare( $jpeg_id, 'full' ), 'ilswq_browser_excluded', 409, 'Excluded attachment' );
ILSWQ_Scanner::set_excluded( $jpeg_id, false );

// 5. Finish rejects anything that is not the promised image.
$encoded = $temp_dir . '/encoded.webp';
ilswq_browser_assert( ilswq_browser_encode_fixture( $encoded, 1200, 800 ), 'Could not build a WebP fixture.' );
ilswq_browser_assert( 'VP8 ' === substr( (string) file_get_contents( $encoded ), 12, 4 ), 'The fixture encoder did not produce a lossy VP8 container.' );

$alpha_webp = $temp_dir . '/alpha.webp';
ilswq_browser_assert( ilswq_browser_encode_fixture( $alpha_webp, 600, 400, true ), 'Could not build a transparent WebP fixture.' );

$garbage = $temp_dir . '/garbage.webp';
file_put_contents( $garbage, 'this is not a webp file at all, not even close' );

$wrong_size = $temp_dir . '/wrong-size.webp';
ilswq_browser_assert( ilswq_browser_encode_fixture( $wrong_size, 300, 200 ), 'Could not build a mismatched WebP fixture.' );

$wrong_prepare = ilswq_browser_prepare( $jpeg_id, 'full' );
$wrong_prepare = ilswq_browser_success( $wrong_prepare, 'Prepare for rejections' );
ilswq_browser_assert_error( ilswq_browser_finish( $wrong_prepare['token'], $garbage ), 'ilswq_browser_container', 400, 'Garbage upload' );
ilswq_browser_assert_error( ilswq_browser_finish( $wrong_prepare['token'], $wrong_size ), 'ilswq_browser_dimensions', 400, 'Wrong dimensions' );

// 6. A valid upload is installed exactly like a server conversion.
$totals_before = ILSWQ_Totals::summary();
$response      = ilswq_browser_finish( $wrong_prepare['token'], $encoded );
$stored        = ilswq_browser_success( $response, 'Valid upload' );
ilswq_browser_assert( 'ready' === $stored['status'], 'Finish did not report a ready conversion.' );
ilswq_browser_assert( (int) $stored['outputBytes'] === (int) filesize( $encoded ), 'Finish reported the wrong WebP size.' );

$sidecar = ILSWQ_Scanner::output_path( get_attached_file( $jpeg_id ) );
ilswq_browser_assert( file_exists( $sidecar ), 'The browser conversion did not create the WebP sidecar.' );
ilswq_browser_assert( ILSWQ_Scanner::is_valid_webp_file( $sidecar ), 'The installed sidecar is not a valid WebP file.' );
ilswq_browser_assert( ! file_exists( get_attached_file( $jpeg_id ) . '.tmp' ), 'A temporary file was left behind.' );

$map = ILSWQ_Scanner::get_webp_map( $jpeg_id );
ilswq_browser_assert( isset( $map['full'] ), 'The generated WebP map is missing the converted size.' );
ilswq_browser_assert( 'WASM (browser)' === $map['full']['editor'], 'The stored editor label did not record the browser backend.' );
ilswq_browser_assert( 1200 === (int) $map['full']['width'] && 800 === (int) $map['full']['height'], 'The stored map entry has the wrong dimensions.' );
ilswq_browser_assert( (int) $map['full']['source_size'] === (int) filesize( get_attached_file( $jpeg_id ) ), 'The stored source size is wrong.' );

$totals_after = ILSWQ_Totals::summary();
ilswq_browser_assert(
	(int) $totals_after['files'] === (int) $totals_before['files'] + 1,
	'Stored totals were not updated for the browser conversion.'
);

// Report the actual encoder on a host that also has a server writer, and count
// savings only for source sizes that have a corresponding WebP.
remove_filter( 'ilswq_has_server_webp_writer', '__return_false' );
$partial = $scanner->scan_attachment( $jpeg_id, $settings );
ilswq_browser_assert( 'WASM (browser)' === $partial['editor'], 'Report replaced the actual encoder with the server preference.' );
$expected_savings = round( ( 1 - $map['full']['webp_size'] / $map['full']['source_size'] ) * 100, 1 );
ilswq_browser_assert( abs( (float) $partial['savings'] - $expected_savings ) < 0.11, 'Partial conversion counted untouched sizes as savings.' );
add_filter( 'ilswq_has_server_webp_writer', '__return_false' );

// 7. A prepared job and a converted size both replay without re-encoding.
$replayed = ilswq_browser_success( ilswq_browser_prepare( $jpeg_id, 'full' ), 'Replay prepare' );
ilswq_browser_assert( 'ready' === $replayed['status'], 'Replaying a converted size did not report it as ready.' );
ilswq_browser_assert( empty( $replayed['token'] ), 'Replaying a converted size handed out a new encoding token.' );

$replayed_data = ilswq_browser_success( ilswq_browser_finish( $wrong_prepare['token'], $encoded ), 'Replay finish' );
ilswq_browser_assert( 'ready' === $replayed_data['status'], 'Replaying a stored conversion returned the wrong status.' );

// Changed quality and damaged output must trigger regeneration, not a cached success.
$settings['jpeg_quality'] = 70;
ILSWQ_Settings::save( $settings );
$requantize = ilswq_browser_success( ilswq_browser_prepare( $jpeg_id, 'full' ), 'Changed quality' );
ilswq_browser_assert( 'prepared' === $requantize['status'] && 70 === $requantize['quality'], 'Browser conversion reused output at the wrong quality.' );
$settings['jpeg_quality'] = 80;
ILSWQ_Settings::save( $settings );
ilswq_browser_assert_error( ilswq_browser_finish( $requantize['token'], $encoded ), 'ilswq_browser_settings', 409, 'Quality changed during encoding' );
file_put_contents( $sidecar, 'broken output' );
$repair = ilswq_browser_success( ilswq_browser_prepare( $jpeg_id, 'full' ), 'Damaged WebP' );
ilswq_browser_assert( 'prepared' === $repair['status'], 'A damaged WebP was reported as ready.' );
ilswq_browser_success( ilswq_browser_finish( $repair['token'], $encoded ), 'Repair damaged WebP' );

// Configured limits must apply at the endpoint even with a stale report.
$limit_metadata = wp_get_attachment_metadata( $jpeg_id );
$larger_metadata = $limit_metadata;
$larger_metadata['width'] = 2000;
update_post_meta( $jpeg_id, '_wp_attachment_metadata', $larger_metadata );
$settings['max_pixels'] = 1000000;
ILSWQ_Settings::save( $settings );
ilswq_browser_assert_error( ilswq_browser_prepare( $jpeg_id, 'full' ), 'ilswq_browser_pixels', 413, 'Configured pixel limit' );
update_post_meta( $jpeg_id, '_wp_attachment_metadata', $limit_metadata );
$settings['max_pixels'] = 16000000;
ILSWQ_Settings::save( $settings );

// Regenerating a browser-created output must not bypass server memory limits.
$memory_metadata = wp_get_attachment_metadata( $jpeg_id );
$large_metadata = $memory_metadata;
$large_metadata['width'] = 3000;
$large_metadata['height'] = 3000;
update_post_meta( $jpeg_id, '_wp_attachment_metadata', $large_metadata );
$settings['jpeg_quality'] = 70;
ILSWQ_Settings::save( $settings );
remove_filter( 'ilswq_has_server_webp_writer', '__return_false' );
$previous_memory = ini_get( 'memory_limit' );
ini_set( 'memory_limit', '48M' );
$regenerate_sources = $scanner->scan_sources( $jpeg_id, $settings );
ini_set( 'memory_limit', $previous_memory );
$regenerate_full = array_values( array_filter( $regenerate_sources, static function ( $source ) { return 'full' === $source['name']; } ) )[0];
ilswq_browser_assert( ! empty( $regenerate_full['browser_only'] ), 'Regeneration bypassed the server memory limit.' );
add_filter( 'ilswq_has_server_webp_writer', '__return_false' );
update_post_meta( $jpeg_id, '_wp_attachment_metadata', $memory_metadata );
$settings['jpeg_quality'] = 80;
ILSWQ_Settings::save( $settings );

// A shared worker lock prevents uploads from racing a queue batch or another tab.
$lock = $queue->acquire_lock();
ilswq_browser_assert( false !== $lock, 'Could not hold the shared worker lock.' );
ilswq_browser_assert_error( ilswq_browser_prepare( $jpeg_id, 'thumbnail' ), 'ilswq_browser_queue_active', 409, 'Batch finishing after pause' );
ilswq_browser_assert_error( ilswq_browser_finish( $repair['token'], $encoded ), 'ilswq_browser_busy', 409, 'Concurrent upload' );
$mutation_ran = false;
$mutation_result = $queue->run_file_mutation( static function () use ( &$mutation_ran ) { $mutation_ran = true; } );
ilswq_browser_assert_error( $mutation_result, 'ilswq_files_busy', 500, 'Concurrent cleanup/recalculation' );
ilswq_browser_assert( ! $mutation_ran, 'A direct file operation ran during a browser save.' );
$queue->release_lock( $lock );
ilswq_browser_assert( ! get_option( ILSWQ_Queue::LOCK_OPTION ), 'Worker lock leaked.' );
$mutation_result = $queue->run_file_mutation( static function () use ( $repair, $encoded ) {
	ilswq_browser_assert_error( ilswq_browser_finish( $repair['token'], $encoded ), 'ilswq_browser_busy', 409, 'Browser save during cleanup/recalculation' );
	return true;
} );
ilswq_browser_assert( true === $mutation_result, 'The unlocked direct file operation did not run.' );
ilswq_browser_assert( ! get_option( ILSWQ_Queue::LOCK_OPTION ), 'Direct file operation leaked its lock.' );
try {
	$queue->run_file_mutation( static function () { throw new RuntimeException( 'Expected fixture failure.' ); } );
	ilswq_browser_fail( 'The failing direct file operation did not run.' );
} catch ( RuntimeException $exception ) {
	ilswq_browser_assert( 'Expected fixture failure.' === $exception->getMessage(), 'Unexpected direct operation exception.' );
}
ilswq_browser_assert( ! get_option( ILSWQ_Queue::LOCK_OPTION ), 'Failed direct file operation leaked its lock.' );

// 8. Frontend serving uses the browser-generated file.
$image = wp_get_attachment_image_src( $jpeg_id, 'full' );
ilswq_browser_assert( is_array( $image ) && ! empty( $image[0] ), 'Could not read the served attachment image.' );
ilswq_browser_assert( false !== strpos( $image[0], '.webp' ), 'Frontend serving did not switch to the generated WebP file.' );

// 9. A changed source is rejected instead of overwriting the written sidecar.
$stale = ilswq_browser_success( ilswq_browser_prepare( $png_id, 'full' ), 'Prepare PNG' );

$png_source = get_attached_file( $png_id );
$png_backup = $temp_dir . '/alpha-original.png';
copy( $png_source, $png_backup );
file_put_contents( $png_source, file_get_contents( $png_backup ) . 'tampered' );

ilswq_browser_assert_error( ilswq_browser_finish( $stale['token'], $alpha_webp ), 'ilswq_browser_stale', 409, 'Changed source' );
copy( $png_backup, $png_source );

// Resolving a replaced attachment path must not install bytes from the old source.
$replacement = dirname( $png_source ) . '/ilswq-browser-replaced.png';
copy( $png_source, $replacement );
$metadata = wp_get_attachment_metadata( $png_id );
$changed_metadata = $metadata;
$changed_metadata['file'] = ILSWQ_Scanner::path_to_relative( $replacement );
update_post_meta( $png_id, '_wp_attached_file', $changed_metadata['file'] );
update_post_meta( $png_id, '_wp_attachment_metadata', $changed_metadata );
ilswq_browser_assert_error( ilswq_browser_finish( $stale['token'], $alpha_webp ), 'ilswq_browser_stale', 409, 'Replaced attachment path' );
ilswq_browser_assert( ! file_exists( ILSWQ_Scanner::output_path( $replacement ) ), 'Old bytes were installed for the replacement source.' );
update_post_meta( $png_id, '_wp_attached_file', ILSWQ_Scanner::path_to_relative( $png_source ) );
update_post_meta( $png_id, '_wp_attachment_metadata', $metadata );
wp_delete_file( $replacement );

// Reject excessive uploads before reading them into PHP memory.
$oversized = $temp_dir . '/oversized.webp';
$handle = fopen( $oversized, 'w' );
ftruncate( $handle, ILSWQ_Browser::MAX_OUTPUT + 1 );
fclose( $handle );
ilswq_browser_assert_error( ilswq_browser_finish( $stale['token'], $oversized ), 'ilswq_browser_output', 413, 'Oversized upload' );

// 10. A sibling WebP this plugin does not own is left alone.
$png_sidecar = ILSWQ_Scanner::output_path( $png_source );
file_put_contents( $png_sidecar, 'foreign file' );
ilswq_browser_assert_error( ilswq_browser_prepare( $png_id, 'full' ), 'ilswq_browser_conflict', 409, 'Prepare with foreign sibling' );

ilswq_browser_assert_error( ilswq_browser_finish( $stale['token'], $alpha_webp ), 'ilswq_browser_conflict', 409, 'Foreign sibling file' );
ilswq_browser_assert( 'foreign file' === file_get_contents( $png_sidecar ), 'The foreign sibling WebP file was modified.' );
wp_delete_file( $png_sidecar );

// 11. An alpha WebP container is accepted, and a prepared job is owned by one user.
$alpha = ilswq_browser_success( ilswq_browser_prepare( $png_id, 'full' ), 'Prepare transparent PNG' );

$alpha_data = ilswq_browser_success( ilswq_browser_finish( $alpha['token'], $alpha_webp ), 'Install transparent WebP' );
ilswq_browser_assert( 'ready' === $alpha_data['status'], 'A transparent WebP was not installed.' );

$other_login = 'ilswq-browser-other';
$other       = get_user_by( 'login', $other_login );
if ( ! $other ) {
	$other_id = wp_insert_user(
		array(
			'user_login' => $other_login,
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => $other_login . '@example.test',
			'role'       => 'administrator',
		)
	);
	$other    = get_user_by( 'id', $other_id );
}

$fresh = ilswq_browser_prepare( $jpeg_id, 'thumbnail' );
$fresh = ilswq_browser_success( $fresh, 'Prepare thumbnail' );

wp_set_current_user( (int) $other->ID );
$stolen = ilswq_browser_finish( $fresh['token'], $encoded );
wp_set_current_user( (int) $user->ID );
ilswq_browser_assert_error( $stolen, 'ilswq_browser_token', 400, 'Another administrator using a token' );

// 12. The browser backend waits for a running server job.
update_option(
	ILSWQ_Queue::JOB_OPTION,
	array(
		'id'    => 'ilswq-test-job',
		'state' => 'running',
	)
);

ilswq_browser_assert_error( ilswq_browser_prepare( $jpeg_id, 'thumbnail' ), 'ilswq_browser_queue_active', 409, 'Running server job' );
ilswq_browser_assert_error( ilswq_browser_finish( $fresh['token'], $encoded ), 'ilswq_browser_queue_active', 409, 'Queue started during encoding' );
delete_option( ILSWQ_Queue::JOB_OPTION );

// 13. The container validator accepts the shapes the browser may produce and rejects the rest.
$encoded_bytes = (string) file_get_contents( $encoded );
$vp8_dims      = ILSWQ_Browser::webp_dimensions( $encoded_bytes );
ilswq_browser_assert( is_array( $vp8_dims ) && 1200 === $vp8_dims[0] && 800 === $vp8_dims[1], 'A lossy VP8 container was not validated.' );

$alpha_dims = ILSWQ_Browser::webp_dimensions( (string) file_get_contents( $alpha_webp ) );
ilswq_browser_assert( is_array( $alpha_dims ) && 600 === $alpha_dims[0] && 400 === $alpha_dims[1], 'A transparent container was not validated.' );

// Rebuild the encoded VP8 payload inside an explicit VP8X/ALPH container.
$canvas    = substr( pack( 'V', 1199 ), 0, 3 ) . substr( pack( 'V', 799 ), 0, 3 );
$vp8x      = 'VP8X' . pack( 'V', 10 ) . "\x10\x00\x00\x00" . $canvas;
$alpha     = 'ALPH' . pack( 'V', 2 ) . "\x00\x00";
$vp8_chunk = substr( $encoded_bytes, 12 );
$extended  = 'WEBP' . $vp8x . $alpha . $vp8_chunk;
$extended  = 'RIFF' . pack( 'V', strlen( $extended ) ) . $extended;
ilswq_browser_assert( ILSWQ_Browser::webp_dimensions( $extended ) === $vp8_dims, 'An extended VP8X/ALPH container was not validated.' );

$no_alpha = 'WEBP' . $vp8x . $vp8_chunk;
$no_alpha = 'RIFF' . pack( 'V', strlen( $no_alpha ) ) . $no_alpha;
ilswq_browser_assert( null === ILSWQ_Browser::webp_dimensions( $no_alpha ), 'An extended container claiming alpha without an ALPH chunk was accepted.' );

$animated = 'WEBP' . 'VP8X' . pack( 'V', 10 ) . "\x02\x00\x00\x00" . $canvas;
$animated = 'RIFF' . pack( 'V', strlen( $animated ) ) . $animated;
ilswq_browser_assert( null === ILSWQ_Browser::webp_dimensions( $animated ), 'An animated WebP container was accepted.' );
ilswq_browser_assert( null === ILSWQ_Browser::webp_dimensions( (string) file_get_contents( $garbage ) ), 'Non-WebP bytes were accepted.' );

// 14. Cleanup removes browser-generated files like any other generated file.
$cleanup = $converter->delete_generated_for_attachment( $jpeg_id, true );
ilswq_browser_assert( empty( $cleanup['failed'] ), 'Cleanup reported failed deletions.' );
ilswq_browser_assert( ! file_exists( $sidecar ), 'Cleanup did not remove the browser-generated WebP file.' );

foreach ( glob( $temp_dir . '/*' ) ?: array() as $file ) {
	wp_delete_file( $file );
}
wp_delete_file( $jpeg_fixture );
wp_delete_file( $png_fixture );
@rmdir( $temp_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

wp_delete_attachment( $jpeg_id, true );
wp_delete_attachment( $png_id, true );

fwrite( STDOUT, "Browser conversion backend integration test passed.\n" );

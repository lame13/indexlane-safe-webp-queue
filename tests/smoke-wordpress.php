<?php
/**
 * Local WordPress smoke test for Safe WebP Queue.
 *
 * Usage:
 * php tests/smoke-wordpress.php /path/to/wordpress
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

// Activation inside this process occurs after plugins_loaded; ensure hooks exist.
ILSWQ_Plugin::instance();

if ( ! ILSWQ_Capabilities::has_webp_writer() ) {
	fwrite( STDERR, "No WebP writer is available in this WordPress environment.\n" );
	exit( 2 );
}

$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/indexlane-safe-webp-queue/indexlane-safe-webp-queue.php', false, false );
if ( 'IndexLane Safe WebP Queue' !== $plugin_data['Name'] || '0.2.0' !== $plugin_data['Version'] || ! empty( $plugin_data['UpdateURI'] ) ) {
	fwrite( STDERR, "Release plugin metadata does not match 0.2.0.\n" );
	exit( 1 );
}

$expected_editor = trim( (string) getenv( 'ILSWQ_SMOKE_EDITOR' ) );
if ( '' !== $expected_editor ) {
	$expected_editor_class = 'Imagick' === $expected_editor ? 'WP_Image_Editor_Imagick' : ( 'GD' === $expected_editor ? 'WP_Image_Editor_GD' : '' );
	if ( '' === $expected_editor_class || ! ILSWQ_Capabilities::editor_class_supports_webp( $expected_editor_class ) ) {
		fwrite( STDERR, "Requested WebP editor is unavailable: {$expected_editor}.\n" );
		exit( 2 );
	}

	add_filter(
		'wp_image_editors',
		static function () use ( $expected_editor_class ) {
			return array( $expected_editor_class );
		}
	);
}

/**
 * Fail the smoke test.
 *
 * @param string $message Message.
 * @return void
 */
function ilswq_smoke_fail( $message ) {
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

/**
 * Create a test JPEG.
 *
 * @param string $path Path.
 * @return void
 */
function ilswq_smoke_create_jpeg( $path ) {
	$image = imagecreatetruecolor( 1200, 800 );
	for ( $y = 0; $y < 800; ++$y ) {
		$color = imagecolorallocate( $image, ( $y * 3 ) % 255, ( 90 + $y ) % 255, ( 180 + ( $y * 2 ) ) % 255 );
		imageline( $image, 0, $y, 1199, $y, $color );
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
function ilswq_smoke_create_png( $path ) {
	$image = imagecreatetruecolor( 600, 400 );
	imagealphablending( $image, false );
	imagesavealpha( $image, true );

	$transparent = imagecolorallocatealpha( $image, 0, 0, 0, 127 );
	imagefilledrectangle( $image, 0, 0, 599, 399, $transparent );

	imagealphablending( $image, true );
	$blue = imagecolorallocatealpha( $image, 30, 120, 220, 20 );
	$red  = imagecolorallocatealpha( $image, 220, 70, 50, 35 );
	imagefilledellipse( $image, 240, 180, 300, 220, $blue );
	imagefilledrectangle( $image, 260, 120, 520, 320, $red );

	imagepng( $image, $path );
	imagedestroy( $image );
}

/**
 * Insert an image attachment and generate metadata.
 *
 * @param string $path Path.
 * @param string $mime MIME type.
 * @param bool   $generate_metadata Whether to generate image sub-sizes.
 * @return int
 */
function ilswq_smoke_insert_attachment( $path, $mime, $generate_metadata = true ) {
	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => $mime,
			'post_title'     => sanitize_file_name( wp_basename( $path ) ),
			'post_status'    => 'inherit',
		),
		$path
	);

	if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
		ilswq_smoke_fail( 'Could not insert attachment.' );
	}

	if ( $generate_metadata ) {
		$metadata = wp_generate_attachment_metadata( $attachment_id, $path );
		wp_update_attachment_metadata( $attachment_id, $metadata );
	}

	return (int) $attachment_id;
}

/**
 * Validate generated WebP map.
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $label Label.
 * @return array<string, array<string, mixed>>
 */
function ilswq_smoke_validate_map( $attachment_id, $label ) {
	global $expected_editor;

	$map = ILSWQ_Scanner::get_webp_map( $attachment_id );
	if ( empty( $map ) ) {
		ilswq_smoke_fail( $label . ' did not generate any WebP files.' );
	}

	foreach ( $map as $entry ) {
		if ( empty( $entry['source'] ) || empty( $entry['webp'] ) ) {
			ilswq_smoke_fail( $label . ' generated an incomplete map entry.' );
		}

		if ( ! file_exists( $entry['source'] ) ) {
			ilswq_smoke_fail( $label . ' source file was removed.' );
		}

		if ( ! file_exists( $entry['webp'] ) ) {
			ilswq_smoke_fail( $label . ' WebP file is missing.' );
		}

		$info = wp_getimagesize( $entry['webp'] );
		if ( ! is_array( $info ) || empty( $info['mime'] ) || 'image/webp' !== $info['mime'] ) {
			ilswq_smoke_fail( $label . ' generated an invalid WebP file.' );
		}

		if ( '' !== $expected_editor && ( empty( $entry['editor'] ) || $expected_editor !== $entry['editor'] ) ) {
			ilswq_smoke_fail( $label . ' did not use the requested ' . $expected_editor . ' editor.' );
		}
	}

	return $map;
}

/**
 * Validate that new WebP maps are stored as uploads-relative paths.
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $label Label.
 * @return void
 */
function ilswq_smoke_validate_relative_storage( $attachment_id, $label ) {
	$stored = get_post_meta( $attachment_id, ILSWQ_META_WEBP_FILES, true );
	if ( empty( $stored ) || ! is_array( $stored ) ) {
		ilswq_smoke_fail( $label . ' did not store a WebP map.' );
	}

	foreach ( $stored as $entry ) {
		if ( ! is_array( $entry ) || empty( $entry['source_rel'] ) || empty( $entry['webp_rel'] ) ) {
			ilswq_smoke_fail( $label . ' did not store relative WebP paths.' );
		}

		if ( isset( $entry['source'] ) || isset( $entry['webp'] ) ) {
			ilswq_smoke_fail( $label . ' stored absolute WebP paths in new metadata.' );
		}

		if ( 0 === strpos( (string) $entry['source_rel'], '/' ) || 0 === strpos( (string) $entry['webp_rel'], '/' ) ) {
			ilswq_smoke_fail( $label . ' stored a rooted path instead of an uploads-relative path.' );
		}

		if ( empty( $entry['quality'] ) || empty( $entry['source_mtime'] ) || empty( $entry['fingerprint'] ) || 64 !== strlen( (string) $entry['fingerprint'] ) ) {
			ilswq_smoke_fail( $label . ' did not store a complete 0.2 generation fingerprint.' );
		}
	}
}

$uploads = wp_upload_dir();
if ( ! empty( $uploads['error'] ) ) {
	ilswq_smoke_fail( $uploads['error'] );
}

$original_settings        = ILSWQ_Settings::get();
$settings                 = $original_settings;
$settings['skip_larger']  = 0;
$settings['serve_webp']   = 0;
$settings['auto_uploads'] = 0;
ILSWQ_Settings::save( $settings );
delete_option( ILSWQ_OPTION_CLEANUP_PAGE );
delete_option( ILSWQ_Queue::JOB_OPTION );
delete_option( ILSWQ_Queue::AUTO_OPTION );
delete_option( ILSWQ_Queue::LOCK_OPTION );
delete_option( ILSWQ_OPTION_ORPHAN_WEBPS );
wp_clear_scheduled_hook( ILSWQ_Queue::CRON_HOOK );

$jpeg_path         = trailingslashit( $uploads['path'] ) . wp_unique_filename( $uploads['path'], 'ilswq-smoke-photo.jpg' );
$png_path          = trailingslashit( $uploads['path'] ) . wp_unique_filename( $uploads['path'], 'ilswq-smoke-transparent.png' );
$auto_jpeg_path    = trailingslashit( $uploads['path'] ) . wp_unique_filename( $uploads['path'], 'ilswq-smoke-auto-photo.jpg' );
$auto_failure_path = trailingslashit( $uploads['path'] ) . wp_unique_filename( $uploads['path'], 'ilswq-smoke-auto-failure.jpg' );
$foreign_path      = trailingslashit( $uploads['path'] ) . wp_unique_filename( $uploads['path'], 'ilswq-smoke-foreign-photo.jpg' );
$retry_path        = trailingslashit( $uploads['path'] ) . wp_unique_filename( $uploads['path'], 'ilswq-smoke-retry-photo.jpg' );
$lifecycle_path    = trailingslashit( $uploads['path'] ) . wp_unique_filename( $uploads['path'], 'ilswq-smoke-lifecycle-photo.jpg' );

ilswq_smoke_create_jpeg( $jpeg_path );
ilswq_smoke_create_png( $png_path );
ilswq_smoke_create_jpeg( $auto_jpeg_path );
ilswq_smoke_create_jpeg( $auto_failure_path );
ilswq_smoke_create_jpeg( $foreign_path );
ilswq_smoke_create_jpeg( $retry_path );
ilswq_smoke_create_jpeg( $lifecycle_path );

$jpeg_id      = ilswq_smoke_insert_attachment( $jpeg_path, 'image/jpeg' );
$png_id       = ilswq_smoke_insert_attachment( $png_path, 'image/png' );
$foreign_id   = ilswq_smoke_insert_attachment( $foreign_path, 'image/jpeg', false );
$retry_id     = ilswq_smoke_insert_attachment( $retry_path, 'image/jpeg' );
$lifecycle_id = ilswq_smoke_insert_attachment( $lifecycle_path, 'image/jpeg' );

$scanner   = new ILSWQ_Scanner();
$converter = new ILSWQ_Converter( $scanner );
$queue     = new ILSWQ_Queue( $converter );

$foreign_webp = ILSWQ_Scanner::output_path( $foreign_path );
if ( false === file_put_contents( $foreign_webp, 'foreign WebP sidecar fixture' ) ) {
	ilswq_smoke_fail( 'Could not create the foreign sidecar fixture.' );
}

$foreign_hash = hash_file( 'sha256', $foreign_webp );
$foreign_row  = $scanner->scan_attachment( $foreign_id, $settings );
if ( 'conflict' !== $foreign_row['status_key'] || ! empty( $foreign_row['eligible'] ) ) {
	ilswq_smoke_fail( 'A foreign sibling WebP was not reported as an ineligible conflict.' );
}

$foreign_result = $converter->convert_attachment( $foreign_id, $settings );
clearstatcache( true, $foreign_webp );
if (
	'conflict' !== $foreign_result['status_key'] ||
	! file_exists( $foreign_webp ) ||
	$foreign_hash !== hash_file( 'sha256', $foreign_webp ) ||
	metadata_exists( 'post', $foreign_id, ILSWQ_META_WEBP_FILES )
) {
	ilswq_smoke_fail( 'Conversion replaced, deleted, or claimed ownership of a foreign sibling WebP.' );
}

$job = $queue->start_job( array( $jpeg_id, $png_id ), $settings );
if ( is_wp_error( $job ) || 'queued' !== $job['state'] || 2 !== (int) $job['total'] ) {
	ilswq_smoke_fail( 'Persistent conversion job did not start with the selected attachments.' );
}

$paused = $queue->pause_job();
$reloaded_queue = new ILSWQ_Queue( $converter );
$persisted      = $reloaded_queue->get_public_status();
if ( is_wp_error( $paused ) || 'paused' !== $persisted['state'] || ! $persisted['can_resume'] ) {
	ilswq_smoke_fail( 'Persistent conversion job did not survive a pause and queue reload.' );
}

$resumed = $reloaded_queue->resume_job();
if ( is_wp_error( $resumed ) || 'queued' !== $resumed['state'] ) {
	ilswq_smoke_fail( 'Persistent conversion job could not resume.' );
}

update_option(
	ILSWQ_Queue::LOCK_OPTION,
	array(
		'token'   => 'smoke-test-lock',
		'expires' => time() + 60,
	),
	false
);
$locked_result = $reloaded_queue->process_next_batch();
delete_option( ILSWQ_Queue::LOCK_OPTION );
if ( empty( $locked_result['busy'] ) || 0 !== (int) $locked_result['queue']['processed'] ) {
	ilswq_smoke_fail( 'Queue worker lock did not prevent a duplicate batch.' );
}

$queue_runs = 0;
do {
	$queue_result = $reloaded_queue->process_next_batch();
	$job_status   = $queue_result['queue'];
	++$queue_runs;
	if ( $queue_runs > 20 ) {
		ilswq_smoke_fail( 'Persistent conversion job did not finish within 20 batches.' );
	}
} while ( in_array( $job_status['state'], array( 'queued', 'running' ), true ) );

if ( 'completed' !== $job_status['state'] || 2 !== (int) $job_status['processed'] || 2 !== (int) $job_status['converted'] ) {
	ilswq_smoke_fail( 'Persistent conversion job did not record a complete result.' );
}

$jpeg_map = ilswq_smoke_validate_map( $jpeg_id, 'JPEG' );
$png_map  = ilswq_smoke_validate_map( $png_id, 'PNG' );

$validation = ILSWQ_Scanner::validate_generated_files( $jpeg_id );
if ( empty( $validation['valid'] ) || count( $jpeg_map ) !== (int) $validation['total'] ) {
	ilswq_smoke_fail( 'Complete WebP validation rejected a valid generated map.' );
}

$validation_keys = array_keys( $jpeg_map );
$last_key        = end( $validation_keys );
$corrupt_path    = $jpeg_map[ $last_key ]['webp'];
$valid_contents  = file_get_contents( $corrupt_path );
if ( false === $valid_contents || false === file_put_contents( $corrupt_path, 'not a WebP image' ) ) {
	ilswq_smoke_fail( 'Could not prepare the validation failure fixture.' );
}

clearstatcache( true, $corrupt_path );
$validation = ILSWQ_Scanner::validate_generated_files( $jpeg_id );
if ( ! empty( $validation['valid'] ) || (int) $validation['invalid'] < 1 ) {
	ilswq_smoke_fail( 'Complete WebP validation passed when one stored file was invalid.' );
}

if ( false === file_put_contents( $corrupt_path, $valid_contents ) ) {
	ilswq_smoke_fail( 'Could not restore the validation fixture.' );
}
clearstatcache( true, $corrupt_path );

ilswq_smoke_validate_relative_storage( $jpeg_id, 'JPEG' );
ilswq_smoke_validate_relative_storage( $png_id, 'PNG' );

if ( count( $jpeg_map ) < 2 ) {
	ilswq_smoke_fail( 'JPEG did not convert any generated intermediate sizes.' );
}

$changed_quality                  = $settings;
$changed_quality['jpeg_quality'] = 71;
$quality_row                     = $scanner->scan_attachment( $jpeg_id, $changed_quality );
if ( 'needs-review' !== $quality_row['status_key'] || empty( $quality_row['eligible'] ) || false === strpos( $quality_row['reason'], 'quality' ) ) {
	ilswq_smoke_fail( 'A changed JPEG quality setting did not mark generated output for safe regeneration.' );
}

$disable_editors = static function () {
	return array();
};
add_filter( 'wp_image_editors', $disable_editors, 999 );

$retry_job = $queue->start_job( array( $retry_id ), $settings );
if ( is_wp_error( $retry_job ) ) {
	ilswq_smoke_fail( 'Could not start the retry fixture job.' );
}
$retry_result = $queue->process_next_batch();
remove_filter( 'wp_image_editors', $disable_editors, 999 );

if ( 'completed' !== $retry_result['queue']['state'] || 1 !== (int) $retry_result['queue']['failed'] || empty( $retry_result['queue']['can_retry'] ) ) {
	ilswq_smoke_fail( 'A failed conversion was not retained for retry.' );
}

$retried = $queue->retry_failed_job();
if ( is_wp_error( $retried ) ) {
	ilswq_smoke_fail( 'Failed conversion job could not be retried.' );
}
$retry_result = $queue->process_next_batch();
if ( 'completed' !== $retry_result['queue']['state'] || 1 !== (int) $retry_result['queue']['converted'] || 0 !== (int) $retry_result['queue']['failed'] ) {
	ilswq_smoke_fail( 'Retried conversion did not complete successfully.' );
}
$retry_map = ilswq_smoke_validate_map( $retry_id, 'Retried JPEG' );

$cancel_job = $queue->start_job( array( $lifecycle_id ), $settings );
if ( is_wp_error( $cancel_job ) || is_wp_error( $queue->cancel_job() ) ) {
	ilswq_smoke_fail( 'Lifecycle fixture job could not be cancelled.' );
}
$cancelled = $queue->get_public_status();
if ( 'cancelled' !== $cancelled['state'] || metadata_exists( 'post', $lifecycle_id, ILSWQ_META_WEBP_FILES ) ) {
	ilswq_smoke_fail( 'Cancelling a job did not preserve pending attachment files.' );
}

$lifecycle_job = $queue->start_job( array( $lifecycle_id ), $settings );
if ( is_wp_error( $lifecycle_job ) ) {
	ilswq_smoke_fail( 'Could not restart the lifecycle fixture job.' );
}
$lifecycle_result = $queue->process_next_batch();
if ( 'completed' !== $lifecycle_result['queue']['state'] ) {
	ilswq_smoke_fail( 'Lifecycle fixture conversion did not complete.' );
}
$lifecycle_map = ilswq_smoke_validate_map( $lifecycle_id, 'Lifecycle JPEG' );

$lifecycle_metadata = wp_get_attachment_metadata( $lifecycle_id );
$lifecycle_sizes    = isset( $lifecycle_metadata['sizes'] ) && is_array( $lifecycle_metadata['sizes'] ) ? array_keys( $lifecycle_metadata['sizes'] ) : array();
$obsolete_name     = ! empty( $lifecycle_sizes ) ? end( $lifecycle_sizes ) : '';
if ( '' === $obsolete_name || empty( $lifecycle_map[ $obsolete_name ]['webp'] ) ) {
	ilswq_smoke_fail( 'Lifecycle fixture did not contain a generated size to reconcile.' );
}

$obsolete_webp = $lifecycle_map[ $obsolete_name ]['webp'];
unset( $lifecycle_metadata['sizes'][ $obsolete_name ] );
wp_update_attachment_metadata( $lifecycle_id, $lifecycle_metadata );
clearstatcache( true, $obsolete_webp );
$reconciled_map = ILSWQ_Scanner::get_webp_map( $lifecycle_id );
if ( file_exists( $obsolete_webp ) || isset( $reconciled_map[ $obsolete_name ] ) ) {
	ilswq_smoke_fail( 'Obsolete generated size was not reconciled after attachment metadata changed.' );
}

$lifecycle_paths = array();
foreach ( $reconciled_map as $entry ) {
	$lifecycle_paths[] = $entry['webp'];
}

$blocked_lifecycle_path = reset( $lifecycle_paths );
$block_lifecycle_delete = static function ( $delete_path ) use ( $blocked_lifecycle_path ) {
	if ( wp_normalize_path( $delete_path ) === wp_normalize_path( $blocked_lifecycle_path ) ) {
		return $delete_path . '.blocked';
	}

	return $delete_path;
};
add_filter( 'wp_delete_file', $block_lifecycle_delete );
wp_delete_attachment( $lifecycle_id, true );
remove_filter( 'wp_delete_file', $block_lifecycle_delete );

foreach ( $lifecycle_paths as $path ) {
	if ( $path !== $blocked_lifecycle_path && file_exists( $path ) ) {
		ilswq_smoke_fail( 'Deleting an attachment left a plugin-owned WebP sidecar behind.' );
	}
}

$orphan_records = get_option( ILSWQ_OPTION_ORPHAN_WEBPS, array() );
if ( ! file_exists( $blocked_lifecycle_path ) || empty( $orphan_records ) || ! is_array( $orphan_records ) ) {
	ilswq_smoke_fail( 'A failed attachment-sidecar deletion was not retained for background retry.' );
}

foreach ( $orphan_records as $key => $record ) {
	if ( is_array( $record ) ) {
		$orphan_records[ $key ]['available_at'] = time() - 1;
	}
}
update_option( ILSWQ_OPTION_ORPHAN_WEBPS, $orphan_records, false );
$queue->process_next_batch();
clearstatcache( true, $blocked_lifecycle_path );
if ( file_exists( $blocked_lifecycle_path ) || get_option( ILSWQ_OPTION_ORPHAN_WEBPS, array() ) ) {
	ilswq_smoke_fail( 'Background cleanup did not retry a failed attachment-sidecar deletion.' );
}

$settings['auto_uploads'] = 1;
ILSWQ_Settings::save( $settings );

$auto_jpeg_id = ilswq_smoke_insert_attachment( $auto_jpeg_path, 'image/jpeg' );
if ( metadata_exists( 'post', $auto_jpeg_id, ILSWQ_META_WEBP_FILES ) ) {
	ilswq_smoke_fail( 'Automatic upload conversion still ran synchronously inside the metadata request.' );
}

sleep( 1 );
$auto_runs = 0;
do {
	$auto_result = $queue->process_next_batch();
	$auto_status = $auto_result['queue'];
	++$auto_runs;
	if ( $auto_runs > 20 ) {
		ilswq_smoke_fail( 'Queued automatic upload conversion did not finish within 20 batches.' );
	}
} while ( (int) $auto_status['automatic_pending'] > 0 );

$auto_jpeg_map = ilswq_smoke_validate_map( $auto_jpeg_id, 'Automatic upload JPEG' );
ilswq_smoke_validate_relative_storage( $auto_jpeg_id, 'Automatic upload JPEG' );

$auto_failure_id = ilswq_smoke_insert_attachment( $auto_failure_path, 'image/jpeg' );
add_filter( 'wp_image_editors', $disable_editors, 999 );

for ( $attempt = 1; $attempt <= ILSWQ_Queue::MAX_AUTO_RETRIES; ++$attempt ) {
	$automatic_queue = get_option( ILSWQ_Queue::AUTO_OPTION, array() );
	if ( empty( $automatic_queue[ $auto_failure_id ] ) || ! is_array( $automatic_queue[ $auto_failure_id ] ) ) {
		ilswq_smoke_fail( 'Automatic conversion failure disappeared before retries were exhausted.' );
	}

	$automatic_queue[ $auto_failure_id ]['available_at'] = time() - 1;
	update_option( ILSWQ_Queue::AUTO_OPTION, $automatic_queue, false );
	$auto_failure_result = $queue->process_next_batch();
}

remove_filter( 'wp_image_editors', $disable_editors, 999 );

$auto_failure_status = $auto_failure_result['queue'];
if (
	0 !== (int) $auto_failure_status['automatic_pending'] ||
	1 !== (int) $auto_failure_status['automatic_failed'] ||
	empty( get_post_meta( $auto_failure_id, ILSWQ_META_LAST_ERROR, true ) )
) {
	ilswq_smoke_fail( 'An exhausted automatic conversion was not retained for administrator review.' );
}

$auto_recovery_job = $queue->start_job( array( $auto_failure_id ), $settings );
if ( is_wp_error( $auto_recovery_job ) || 0 !== (int) $auto_recovery_job['automatic_failed'] ) {
	ilswq_smoke_fail( 'A manual job did not take ownership of a failed automatic conversion.' );
}

$auto_recovery_result = $queue->process_next_batch();
if ( 'completed' !== $auto_recovery_result['queue']['state'] || 1 !== (int) $auto_recovery_result['queue']['converted'] ) {
	ilswq_smoke_fail( 'A failed automatic conversion could not be recovered manually.' );
}

$auto_failure_map = ilswq_smoke_validate_map( $auto_failure_id, 'Recovered automatic JPEG' );
if ( metadata_exists( 'post', $auto_failure_id, ILSWQ_META_LAST_ERROR ) ) {
	ilswq_smoke_fail( 'A recovered automatic conversion retained its previous error.' );
}

$settings['serve_webp'] = 1;
ILSWQ_Settings::save( $settings );

$thumbnail = wp_get_attachment_image_src( $jpeg_id, 'thumbnail' );
if ( isset( $jpeg_map['thumbnail'] ) && ( ! is_array( $thumbnail ) || false === strpos( (string) $thumbnail[0], '.webp' ) ) ) {
	ilswq_smoke_fail( 'Optional frontend serving did not return a WebP thumbnail URL.' );
}

$webp_paths = array();
foreach ( array_merge( $jpeg_map, $png_map, $auto_jpeg_map, $auto_failure_map, $retry_map ) as $entry ) {
	$webp_paths[] = $entry['webp'];
}

$blocked_path   = $jpeg_map['full']['webp'];
$block_deletion = static function ( $delete_path ) use ( $blocked_path ) {
	if ( wp_normalize_path( $delete_path ) === wp_normalize_path( $blocked_path ) ) {
		return $delete_path . '.blocked';
	}

	return $delete_path;
};
add_filter( 'wp_delete_file', $block_deletion );

$cleanup_runs = 0;
$cleanup_failed = 0;
do {
	$cleanup_result = $converter->cleanup_generated( 10 );
	$cleanup_failed += isset( $cleanup_result['failed'] ) ? (int) $cleanup_result['failed'] : 0;
	++$cleanup_runs;
	if ( $cleanup_runs > 100 ) {
		ilswq_smoke_fail( 'Cleanup did not finish within 100 batches.' );
	}
} while ( ! empty( $cleanup_result['hasMore'] ) );

remove_filter( 'wp_delete_file', $block_deletion );

if ( $cleanup_failed < 1 || ! file_exists( $blocked_path ) ) {
	ilswq_smoke_fail( 'Cleanup did not report the simulated file deletion failure.' );
}

if ( ! metadata_exists( 'post', $jpeg_id, ILSWQ_META_WEBP_FILES ) ) {
	ilswq_smoke_fail( 'Cleanup discarded generated-file ownership after deletion failed.' );
}

$cleanup_runs = 0;
do {
	$cleanup_result = $converter->cleanup_generated( 10 );
	++$cleanup_runs;
	if ( $cleanup_runs > 100 ) {
		ilswq_smoke_fail( 'Retry cleanup did not finish within 100 batches.' );
	}
} while ( ! empty( $cleanup_result['hasMore'] ) );

if ( metadata_exists( 'post', $jpeg_id, ILSWQ_META_WEBP_FILES ) ) {
	ilswq_smoke_fail( 'Retry cleanup did not clear generated-file ownership after deletion succeeded.' );
}

foreach ( $webp_paths as $path ) {
	if ( file_exists( $path ) ) {
		ilswq_smoke_fail( 'Cleanup left a generated WebP file behind.' );
	}
}

wp_delete_attachment( $jpeg_id, true );
wp_delete_attachment( $png_id, true );
wp_delete_attachment( $auto_jpeg_id, true );
wp_delete_attachment( $auto_failure_id, true );
wp_delete_attachment( $retry_id, true );
wp_delete_file( $foreign_webp );
wp_delete_attachment( $foreign_id, true );
delete_option( ILSWQ_Queue::JOB_OPTION );
delete_option( ILSWQ_Queue::AUTO_OPTION );
delete_option( ILSWQ_Queue::LOCK_OPTION );
delete_option( ILSWQ_OPTION_ORPHAN_WEBPS );
wp_clear_scheduled_hook( ILSWQ_Queue::CRON_HOOK );
ILSWQ_Settings::save( $original_settings );

echo sprintf(
	"Smoke test passed with %s. Persistent jobs, retries, queued uploads, fingerprints, lifecycle cleanup, and foreign-sidecar protection passed. JPEG WebPs: %d. PNG WebPs: %d. Auto upload WebPs: %d.\n",
	'' !== $expected_editor ? $expected_editor : 'the preferred editor',
	count( $jpeg_map ),
	count( $png_map ),
	count( $auto_jpeg_map )
);

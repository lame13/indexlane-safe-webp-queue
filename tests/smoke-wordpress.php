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

if ( ! ILSWQ_Capabilities::has_webp_writer() ) {
	fwrite( STDERR, "No WebP writer is available in this WordPress environment.\n" );
	exit( 2 );
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
 * @return int
 */
function ilswq_smoke_insert_attachment( $path, $mime ) {
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

	$metadata = wp_generate_attachment_metadata( $attachment_id, $path );
	wp_update_attachment_metadata( $attachment_id, $metadata );

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
	}
}

$uploads = wp_upload_dir();
if ( ! empty( $uploads['error'] ) ) {
	ilswq_smoke_fail( $uploads['error'] );
}

$jpeg_path      = trailingslashit( $uploads['path'] ) . 'ilswq-smoke-photo.jpg';
$png_path       = trailingslashit( $uploads['path'] ) . 'ilswq-smoke-transparent.png';
$auto_jpeg_path = trailingslashit( $uploads['path'] ) . 'ilswq-smoke-auto-photo.jpg';

ilswq_smoke_create_jpeg( $jpeg_path );
ilswq_smoke_create_png( $png_path );
ilswq_smoke_create_jpeg( $auto_jpeg_path );

$jpeg_id = ilswq_smoke_insert_attachment( $jpeg_path, 'image/jpeg' );
$png_id  = ilswq_smoke_insert_attachment( $png_path, 'image/png' );

$settings                = ILSWQ_Settings::get();
$settings['skip_larger'] = 0;
$settings['serve_webp']  = 0;
$settings['auto_uploads'] = 0;
ILSWQ_Settings::save( $settings );
delete_option( ILSWQ_OPTION_CLEANUP_PAGE );

$scanner   = new ILSWQ_Scanner();
$converter = new ILSWQ_Converter( $scanner );

$converter->convert_attachment( $jpeg_id, $settings );
$converter->convert_attachment( $png_id, $settings );

$jpeg_map = ilswq_smoke_validate_map( $jpeg_id, 'JPEG' );
$png_map  = ilswq_smoke_validate_map( $png_id, 'PNG' );

ilswq_smoke_validate_relative_storage( $jpeg_id, 'JPEG' );
ilswq_smoke_validate_relative_storage( $png_id, 'PNG' );

if ( count( $jpeg_map ) < 2 ) {
	ilswq_smoke_fail( 'JPEG did not convert any generated intermediate sizes.' );
}

$settings['auto_uploads'] = 1;
ILSWQ_Settings::save( $settings );

$auto_jpeg_id  = ilswq_smoke_insert_attachment( $auto_jpeg_path, 'image/jpeg' );
$auto_jpeg_map = ilswq_smoke_validate_map( $auto_jpeg_id, 'Automatic upload JPEG' );
ilswq_smoke_validate_relative_storage( $auto_jpeg_id, 'Automatic upload JPEG' );

$settings['serve_webp'] = 1;
ILSWQ_Settings::save( $settings );

$thumbnail = wp_get_attachment_image_src( $jpeg_id, 'thumbnail' );
if ( isset( $jpeg_map['thumbnail'] ) && ( ! is_array( $thumbnail ) || false === strpos( (string) $thumbnail[0], '.webp' ) ) ) {
	ilswq_smoke_fail( 'Optional frontend serving did not return a WebP thumbnail URL.' );
}

$webp_paths = array();
foreach ( array_merge( $jpeg_map, $png_map, $auto_jpeg_map ) as $entry ) {
	$webp_paths[] = $entry['webp'];
}

$cleanup_runs = 0;
do {
	$cleanup_result = $converter->cleanup_generated( 10 );
	++$cleanup_runs;
	if ( $cleanup_runs > 100 ) {
		ilswq_smoke_fail( 'Cleanup did not finish within 100 batches.' );
	}
} while ( ! empty( $cleanup_result['hasMore'] ) );

foreach ( $webp_paths as $path ) {
	if ( file_exists( $path ) ) {
		ilswq_smoke_fail( 'Cleanup left a generated WebP file behind.' );
	}
}

wp_delete_attachment( $jpeg_id, true );
wp_delete_attachment( $png_id, true );
wp_delete_attachment( $auto_jpeg_id, true );

echo sprintf(
	"Smoke test passed. JPEG WebPs: %d. PNG WebPs: %d. Auto upload WebPs: %d.\n",
	count( $jpeg_map ),
	count( $png_map ),
	count( $auto_jpeg_map )
);

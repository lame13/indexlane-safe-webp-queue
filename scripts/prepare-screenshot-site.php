<?php
/**
 * Prepare an isolated local WordPress copy for directory screenshots.
 *
 * Usage: php scripts/prepare-screenshot-site.php /private/tmp/ilswq-wporg.XXXXXX
 *
 * @package IndexLaneSafeWebPQueue
 */

$wp_path  = isset( $argv[1] ) ? rtrim( (string) $argv[1], '/\\' ) : '';
$base_url = rtrim( (string) ( getenv( 'ILSWQ_BASE_URL' ) ?: 'http://127.0.0.1:8070' ), '/' );
$username = (string) ( getenv( 'ILSWQ_WP_USER' ) ?: 'ilswqadmin' );
$password = (string) ( getenv( 'ILSWQ_WP_PASS' ) ?: 'password' );
$realpath = realpath( $wp_path );
$host     = (string) parse_url( $base_url, PHP_URL_HOST );

if (
	false === $realpath ||
	0 !== strpos( $realpath, '/private/tmp/ilswq-wporg.' ) ||
	! file_exists( $realpath . '/wp-load.php' )
) {
	fwrite( STDERR, "An isolated WordPress copy under /private/tmp/ilswq-wporg.* is required.\n" );
	exit( 1 );
}

if ( ! in_array( $host, array( '127.0.0.1', 'localhost', '::1' ), true ) ) {
	fwrite( STDERR, "ILSWQ_BASE_URL must use a loopback host.\n" );
	exit( 1 );
}

define( 'WP_USE_THEMES', false );
if ( ! defined( 'WP_ENVIRONMENT_TYPE' ) ) {
	define( 'WP_ENVIRONMENT_TYPE', 'local' );
}

require $realpath . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

update_option( 'siteurl', $base_url );
update_option( 'home', $base_url );
update_option( 'blogname', 'IndexLane WebP Demo' );
update_option( 'blogdescription', 'Safe local image conversion' );
update_option( 'active_plugins', array( 'sqlite-database-integration/load.php' ) );

$activation = activate_plugin( 'indexlane-safe-webp-queue/indexlane-safe-webp-queue.php' );
if ( is_wp_error( $activation ) ) {
	fwrite( STDERR, $activation->get_error_message() . "\n" );
	exit( 1 );
}

if ( ! ILSWQ_Capabilities::has_webp_writer() ) {
	fwrite( STDERR, "The isolated WordPress copy does not have a WebP writer.\n" );
	exit( 2 );
}

$user_id = username_exists( $username );
if ( ! $user_id ) {
	$user_id = wp_create_user( $username, $password, $username . '@example.invalid' );
}

if ( is_wp_error( $user_id ) ) {
	fwrite( STDERR, $user_id->get_error_message() . "\n" );
	exit( 1 );
}

wp_set_password( $password, (int) $user_id );
wp_update_user(
	array(
		'ID'           => (int) $user_id,
		'display_name' => 'IndexLane',
		'nickname'     => 'IndexLane',
	)
);
( new WP_User( (int) $user_id ) )->set_role( 'administrator' );

update_option( 'thumbnail_size_w', 150 );
update_option( 'thumbnail_size_h', 150 );
update_option( 'thumbnail_crop', 1 );
update_option( 'medium_size_w', 300 );
update_option( 'medium_size_h', 300 );
update_option( 'medium_large_size_w', 768 );
update_option( 'medium_large_size_h', 0 );
update_option( 'large_size_w', 1024 );
update_option( 'large_size_h', 1024 );

$cleanup = new ILSWQ_Converter( new ILSWQ_Scanner() );
do {
	$cleanup_result = $cleanup->cleanup_generated( 10 );
} while ( ! empty( $cleanup_result['hasMore'] ) );

foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1 ) ) as $attachment ) {
	$source_path = get_attached_file( (int) $attachment->ID );
	if ( is_string( $source_path ) && '' !== $source_path ) {
		$sidecar_path = ILSWQ_Scanner::output_path( $source_path );
		if ( file_exists( $sidecar_path ) ) {
			wp_delete_file( $sidecar_path );
		}
	}
	wp_delete_attachment( (int) $attachment->ID, true );
}

ILSWQ_Settings::save( ILSWQ_Settings::defaults() );
delete_option( ILSWQ_OPTION_CLEANUP_PAGE );

$uploads = wp_upload_dir();
if ( ! empty( $uploads['error'] ) ) {
	fwrite( STDERR, (string) $uploads['error'] . "\n" );
	exit( 1 );
}

/**
 * Create a colorful JPEG fixture.
 *
 * @param string $path Output path.
 * @param int    $offset Palette offset.
 * @return void
 */
function ilswq_screenshot_create_jpeg( $path, $offset ) {
	$image = imagecreatetruecolor( 1200, 800 );
	for ( $y = 0; $y < 800; ++$y ) {
		$red   = ( $offset + (int) ( $y / 5 ) ) % 255;
		$green = ( 80 + $offset + (int) ( $y / 3 ) ) % 255;
		$blue  = ( 160 + $offset + (int) ( $y / 2 ) ) % 255;
		$color = imagecolorallocate( $image, $red, $green, $blue );
		imageline( $image, 0, $y, 1199, $y, $color );
	}
	imagejpeg( $image, $path, 90 );
	imagedestroy( $image );
}

/**
 * Create a transparent PNG fixture.
 *
 * @param string $path Output path.
 * @return void
 */
function ilswq_screenshot_create_png( $path ) {
	$image = imagecreatetruecolor( 600, 400 );
	imagealphablending( $image, false );
	imagesavealpha( $image, true );
	$transparent = imagecolorallocatealpha( $image, 0, 0, 0, 127 );
	imagefilledrectangle( $image, 0, 0, 599, 399, $transparent );
	imagealphablending( $image, true );
	$indigo = imagecolorallocatealpha( $image, 79, 70, 229, 10 );
	$teal   = imagecolorallocatealpha( $image, 20, 163, 151, 18 );
	imagefilledellipse( $image, 240, 190, 310, 230, $indigo );
	imagefilledrectangle( $image, 275, 115, 525, 325, $teal );
	imagepng( $image, $path );
	imagedestroy( $image );
}

/**
 * Add one fixture to the Media Library.
 *
 * @param string $path Image path.
 * @param string $mime MIME type.
 * @param string $title Attachment title.
 * @param bool   $generate_metadata Whether to create sub-sizes.
 * @return int
 */
function ilswq_screenshot_insert_attachment( $path, $mime, $title, $generate_metadata = true ) {
	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => $mime,
			'post_title'     => $title,
			'post_status'    => 'inherit',
		),
		$path
	);

	if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
		fwrite( STDERR, "Could not insert screenshot fixture.\n" );
		exit( 1 );
	}

	if ( $generate_metadata ) {
		$metadata = wp_generate_attachment_metadata( $attachment_id, $path );
		wp_update_attachment_metadata( $attachment_id, $metadata );
	}

	return (int) $attachment_id;
}

$mountain_path = trailingslashit( $uploads['path'] ) . 'mountain-landscape.jpg';
$artwork_path  = trailingslashit( $uploads['path'] ) . 'transparent-brand-artwork.png';
$editorial_path = trailingslashit( $uploads['path'] ) . 'editorial-photo.jpg';

ilswq_screenshot_create_jpeg( $mountain_path, 15 );
ilswq_screenshot_create_png( $artwork_path );
ilswq_screenshot_create_jpeg( $editorial_path, 105 );

ilswq_screenshot_insert_attachment( $mountain_path, 'image/jpeg', 'Mountain Landscape' );
ilswq_screenshot_insert_attachment( $artwork_path, 'image/png', 'Transparent Brand Artwork' );
ilswq_screenshot_insert_attachment( $editorial_path, 'image/jpeg', 'Editorial Photo', false );

$source = imagecreatefromjpeg( $editorial_path );
if ( false === $source || ! imagewebp( $source, ILSWQ_Scanner::output_path( $editorial_path ), 82 ) ) {
	fwrite( STDERR, "Could not create the protected sibling WebP fixture.\n" );
	exit( 1 );
}
imagedestroy( $source );

$checked_plugins = array();
foreach ( get_plugins() as $plugin_file => $plugin_data ) {
	$checked_plugins[ $plugin_file ] = isset( $plugin_data['Version'] ) ? (string) $plugin_data['Version'] : '';
}
set_site_transient(
	'update_plugins',
	(object) array(
		'last_checked' => time(),
		'checked'      => $checked_plugins,
		'response'     => array(),
		'no_update'    => array(),
		'translations' => array(),
	)
);
set_site_transient(
	'update_core',
	(object) array(
		'updates'         => array(),
		'last_checked'    => time(),
		'version_checked' => get_bloginfo( 'version' ),
	)
);
wp_cache_flush();

echo "Prepared isolated screenshot site at {$base_url}.\n";

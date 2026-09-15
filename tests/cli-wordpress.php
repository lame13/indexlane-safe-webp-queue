<?php
/**
 * WP-CLI smoke test for Safe WebP Queue.
 *
 * Usage:
 * php tests/cli-wordpress.php /path/to/wordpress
 *
 * @package IndexLaneSafeWebPQueue
 */

namespace WP_CLI\Utils {
	function format_items( $format, $items, $fields ) {
		\WP_CLI::$items[] = array( 'format' => $format, 'items' => $items, 'fields' => $fields );
	}
}

namespace {

$wp_path = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : rtrim( (string) getenv( 'WP_PATH' ), '/\\' );

if ( '' === $wp_path || ! file_exists( $wp_path . '/wp-load.php' ) ) {
	fwrite( STDERR, "WordPress path is required.\n" );
	exit( 1 );
}

/**
 * Fail the CLI smoke test.
 *
 * @param string $message Message.
 * @return void
 */
function ilswq_cli_fail( $message ) {
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

/**
 * Minimal WP-CLI stand-in so the plugin registers its commands.
 */
class WP_CLI {
	/**
	 * Registered commands.
	 *
	 * @var array<string, string>
	 */
	public static $commands = array();

	/**
	 * Captured table output.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public static $items = array();

	/**
	 * Captured plain output.
	 *
	 * @var array<int, string>
	 */
	public static $lines = array();

	/**
	 * Captured success messages.
	 *
	 * @var array<int, string>
	 */
	public static $success = array();

	/**
	 * Captured warnings.
	 *
	 * @var array<int, string>
	 */
	public static $warnings = array();

	/**
	 * Register a command.
	 *
	 * @param string $name Command name.
	 * @param string $class Command class.
	 * @return bool
	 */
	public static function add_command( $name, $class ) {
		self::$commands[ $name ] = $class;

		return true;
	}

	/**
	 * Capture a plain line.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public static function line( $message ) {
		self::$lines[] = (string) $message;
	}

	/**
	 * Capture a log line.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public static function log( $message ) {
		self::$lines[] = (string) $message;
	}

	/**
	 * Capture a success message.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public static function success( $message ) {
		self::$success[] = (string) $message;
	}

	/**
	 * Capture a warning.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public static function warning( $message ) {
		self::$warnings[] = (string) $message;
	}

	/**
	 * Fail a command.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public static function error( $message ) {
		throw new RuntimeException( (string) $message );
	}

	/**
	 * Auto-confirm prompts.
	 *
	 * @param string               $question Question.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return bool
	 */
	public static function confirm( $question, $assoc_args = array() ) {
		unset( $question, $assoc_args );

		return true;
	}

	/**
	 * Clear captured output.
	 *
	 * @return void
	 */
	public static function reset_output() {
		self::$items    = array();
		self::$lines    = array();
		self::$success  = array();
		self::$warnings = array();
	}

	/**
	 * Return the last captured formatted table.
	 *
	 * @return array<string, mixed>
	 */
	public static function last_items() {
		return empty( self::$items ) ? array() : self::$items[ count( self::$items ) - 1 ];
	}

	/**
	 * Return whether any captured line contains a fragment.
	 *
	 * @param string $fragment Fragment.
	 * @return bool
	 */
	public static function has_line( $fragment ) {
		foreach ( self::$lines as $line ) {
			if ( false !== strpos( $line, $fragment ) ) {
				return true;
			}
		}

		return false;
	}
}

define( 'WP_CLI', true );
define( 'WP_USE_THEMES', false );

require $wp_path . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

if ( ! is_plugin_active( 'indexlane-safe-webp-queue/indexlane-safe-webp-queue.php' ) ) {
	ilswq_cli_fail( 'The plugin under test is not active in this WordPress install.' );
}

if ( ! class_exists( 'ILSWQ_CLI' ) || ! isset( WP_CLI::$commands['ilswq'] ) ) {
	ilswq_cli_fail( 'The plugin did not register the ilswq WP-CLI command.' );
}

if ( ! ILSWQ_Capabilities::has_webp_writer() ) {
	fwrite( STDERR, "No WebP writer is available in this WordPress environment.\n" );
	exit( 2 );
}

/**
 * Create a test JPEG.
 *
 * @param string $path Path.
 * @return void
 */
function ilswq_cli_create_jpeg( $path ) {
	$image = imagecreatetruecolor( 900, 600 );
	for ( $y = 0; $y < 600; ++$y ) {
		$color = imagecolorallocate( $image, ( 40 + $y ) % 255, ( $y * 2 ) % 255, ( 120 + $y ) % 255 );
		imageline( $image, 0, $y, 899, $y, $color );
	}
	imagejpeg( $image, $path, 88 );
	imagedestroy( $image );
}

/**
 * Insert an image attachment with generated metadata.
 *
 * @param string $path Path.
 * @param string $mime MIME type.
 * @return int
 */
function ilswq_cli_insert_attachment( $path, $mime ) {
	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => $mime,
			'post_title'     => sanitize_file_name( wp_basename( $path ) ),
			'post_status'    => 'inherit',
		),
		$path
	);

	if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
		ilswq_cli_fail( 'Could not insert the CLI fixture attachment.' );
	}

	$metadata = wp_generate_attachment_metadata( $attachment_id, $path );
	wp_update_attachment_metadata( $attachment_id, $metadata );

	return (int) $attachment_id;
}

$uploads = wp_upload_dir();
if ( ! empty( $uploads['error'] ) ) {
	ilswq_cli_fail( $uploads['error'] );
}

$original_settings        = ILSWQ_Settings::get();
$settings                 = $original_settings;
$settings['skip_larger']  = 0;
$settings['auto_uploads'] = 0;
ILSWQ_Settings::save( $settings );

delete_option( ILSWQ_Queue::JOB_OPTION );
delete_option( ILSWQ_Queue::AUTO_OPTION );
delete_option( ILSWQ_Queue::LOCK_OPTION );
delete_option( ILSWQ_OPTION_CLEANUP_PAGE );
delete_option( ILSWQ_OPTION_ORPHAN_WEBPS );
ILSWQ_Totals::reset();
wp_clear_scheduled_hook( ILSWQ_Queue::CRON_HOOK );

$jpeg_path  = trailingslashit( $uploads['path'] ) . wp_unique_filename( $uploads['path'], 'ilswq-cli-photo.jpg' );
$dry_path   = trailingslashit( $uploads['path'] ) . wp_unique_filename( $uploads['path'], 'ilswq-cli-dry-run.jpg' );
ilswq_cli_create_jpeg( $jpeg_path );
ilswq_cli_create_jpeg( $dry_path );

$jpeg_id = ilswq_cli_insert_attachment( $jpeg_path, 'image/jpeg' );
$dry_id  = ilswq_cli_insert_attachment( $dry_path, 'image/jpeg' );

$cli = new ILSWQ_CLI();

WP_CLI::reset_output();
$cli->status( array(), array( 'format' => 'json' ) );
$status_items = WP_CLI::last_items();

if ( 'json' !== $status_items['format'] || empty( $status_items['items'] ) ) {
	ilswq_cli_fail( 'wp ilswq status did not render a JSON table.' );
}

$status_labels = array();
foreach ( $status_items['items'] as $row ) {
	$status_labels[] = (string) $row['label'];
}

foreach ( array( 'Plugin version', 'Generated WebP files', 'Queue state' ) as $expected_label ) {
	if ( ! in_array( $expected_label, $status_labels, true ) ) {
		ilswq_cli_fail( 'wp ilswq status omitted the ' . $expected_label . ' row.' );
	}
}

WP_CLI::reset_output();
$cli->scan( array(), array( 'status' => 'eligible', 'limit' => '5' ) );
$scan_items = WP_CLI::last_items();

if ( empty( $scan_items['items'] ) || count( $scan_items['items'] ) > 5 ) {
	ilswq_cli_fail( 'wp ilswq scan did not respect the eligible filter or the row limit.' );
}

foreach ( $scan_items['items'] as $row ) {
	if ( 'Eligible' !== $row['status'] ) {
		ilswq_cli_fail( 'wp ilswq scan printed a row outside the requested status.' );
	}
}

if ( ! WP_CLI::has_line( 'Scanned ' ) ) {
	ilswq_cli_fail( 'wp ilswq scan did not report a scan summary.' );
}

WP_CLI::reset_output();
$cli->convert( array( (string) $jpeg_id ), array() );

if ( empty( ILSWQ_Scanner::get_webp_map( $jpeg_id ) ) ) {
	ilswq_cli_fail( 'wp ilswq convert did not generate WebP files for the requested attachment.' );
}

if ( ! WP_CLI::has_line( 'Processed ' ) || empty( WP_CLI::$success ) ) {
	ilswq_cli_fail( 'wp ilswq convert did not report its progress and completion.' );
}

$converted_totals = ILSWQ_Totals::summary();
if ( (int) $converted_totals['files'] < 1 || (int) $converted_totals['saved_bytes'] <= 0 ) {
	ilswq_cli_fail( 'wp ilswq convert did not record savings totals.' );
}

WP_CLI::reset_output();
$cli->convert( array( (string) $dry_id ), array( 'dry-run' => '1' ) );

if ( metadata_exists( 'post', $dry_id, ILSWQ_META_WEBP_FILES ) ) {
	ilswq_cli_fail( 'wp ilswq convert --dry-run wrote WebP files.' );
}

if ( ! WP_CLI::has_line( 'Dry run' ) ) {
	ilswq_cli_fail( 'wp ilswq convert --dry-run did not report its plan.' );
}

$test_queue = new ILSWQ_Queue( new ILSWQ_Converter( new ILSWQ_Scanner() ) );
$test_queue->start_job( array( $dry_id ), ILSWQ_Settings::get() );
$test_queue->pause_job();
try {
	$cli->cleanup( array(), array( 'yes' => true ) );
	ilswq_cli_fail( 'CLI cleanup accepted an active paused job.' );
} catch ( RuntimeException $exception ) {
	// Expected: paused jobs still own pending files.
}
$test_queue->cancel_job();
$auto_ids = array();
for ( $i = 0; $i < 4; ++$i ) {
	$id = wp_insert_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image/jpeg', 'post_title' => 'CLI pending fixture' ) );
	$auto_ids[] = $id;
	$test_queue->enqueue_upload( $id, ILSWQ_Settings::get() );
}
$auto_queue = get_option( ILSWQ_Queue::AUTO_OPTION, array() );
foreach ( $auto_queue as &$item ) {
	$item['available_at'] = time() - 1;
}
unset( $item );
update_option( ILSWQ_Queue::AUTO_OPTION, $auto_queue, false );
$cli->queue( array(), array( 'process' => true ) );
if ( $test_queue->has_automatic_work() ) {
	ilswq_cli_fail( 'CLI queue --process returned before draining automatic batches.' );
}
foreach ( $auto_ids as $id ) {
	wp_delete_attachment( $id, true );
}

WP_CLI::reset_output();
$cli->convert( array(), array( 'all' => '1', 'batch' => '2' ) );

if ( ! WP_CLI::has_line( 'Queued the whole Media Library' ) ) {
	ilswq_cli_fail( 'wp ilswq convert --all did not queue a whole-library job.' );
}

if ( empty( ILSWQ_Scanner::get_webp_map( $dry_id ) ) ) {
	ilswq_cli_fail( 'wp ilswq convert --all did not convert a pending library image.' );
}

WP_CLI::reset_output();
$cli->totals( array(), array( 'recalculate' => '1', 'format' => 'csv' ) );
$totals_items = WP_CLI::last_items();

if ( 'csv' !== $totals_items['format'] || WP_CLI::has_line( 'Rebuilt savings' ) ) {
	ilswq_cli_fail( 'wp ilswq totals --recalculate did not rebuild and render savings.' );
}

if ( ILSWQ_Totals::rebuild_active() ) {
	ilswq_cli_fail( 'wp ilswq totals --recalculate left its rebuild cursor behind.' );
}

WP_CLI::reset_output();
$cli->queue( array(), array() );

if ( ! WP_CLI::has_line( 'Queue state:' ) ) {
	ilswq_cli_fail( 'wp ilswq queue did not report the queue state.' );
}

WP_CLI::reset_output();

try {
	$cli->convert( array(), array() );
	ilswq_cli_fail( 'wp ilswq convert accepted a call without attachment IDs or --all.' );
} catch ( RuntimeException $exception ) {
	// Expected: the command must fail with a usage error.
}

WP_CLI::reset_output();
$cli->cleanup( array(), array( 'dry-run' => '1' ) );

if ( ! WP_CLI::has_line( 'would be deleted' ) ) {
	ilswq_cli_fail( 'wp ilswq cleanup --dry-run did not report the generated file count.' );
}

if ( empty( ILSWQ_Scanner::get_webp_map( $jpeg_id ) ) ) {
	ilswq_cli_fail( 'wp ilswq cleanup --dry-run deleted generated WebP files.' );
}

WP_CLI::reset_output();
$cli->cleanup( array(), array( 'yes' => '1' ) );

if ( ! WP_CLI::has_line( 'Deleted ' ) ) {
	ilswq_cli_fail( 'wp ilswq cleanup did not report the deleted file count.' );
}

if ( ! empty( ILSWQ_Scanner::get_webp_map( $jpeg_id ) ) ) {
	ilswq_cli_fail( 'wp ilswq cleanup left generated WebP metadata behind.' );
}

foreach ( array( $jpeg_id, $dry_id ) as $attachment_id ) {
	$map = ILSWQ_Scanner::get_webp_map( $attachment_id );
	foreach ( $map as $entry ) {
		if ( ! empty( $entry['webp'] ) && file_exists( (string) $entry['webp'] ) ) {
			ilswq_cli_fail( 'wp ilswq cleanup left a generated WebP file on disk.' );
		}
	}
}

$cleanup_totals = ILSWQ_Totals::get();
if ( 0 !== (int) $cleanup_totals['files'] ) {
	ilswq_cli_fail( 'wp ilswq cleanup did not reduce the stored savings totals.' );
}

wp_delete_attachment( $jpeg_id, true );
wp_delete_attachment( $dry_id, true );
delete_option( ILSWQ_Queue::JOB_OPTION );
delete_option( ILSWQ_Queue::AUTO_OPTION );
delete_option( ILSWQ_Queue::LOCK_OPTION );
delete_option( ILSWQ_OPTION_CLEANUP_PAGE );
delete_option( ILSWQ_OPTION_ORPHAN_WEBPS );
ILSWQ_Totals::reset();
wp_clear_scheduled_hook( ILSWQ_Queue::CRON_HOOK );
ILSWQ_Settings::save( $original_settings );

echo "WP-CLI smoke test passed. status, scan, convert (single, dry-run, whole library), totals, queue, and cleanup commands passed.\n";

}

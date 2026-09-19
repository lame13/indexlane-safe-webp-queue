<?php
/**
 * Plugin Name: IndexLane Safe WebP Queue
 * Plugin URI: https://indexlane.dev/plugins/safe-webp-queue
 * Description: Convert images to WebP on your hosting or in your browser. Keep your originals. No cloud service, API key, or conversion credits.
 * Version: 1.0.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: IndexLane
 * Author URI: https://indexlane.dev
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: indexlane-safe-webp-queue
 *
 * @package IndexLaneSafeWebPQueue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ILSWQ_VERSION', '1.0.1' );
define( 'ILSWQ_FILE', __FILE__ );
define( 'ILSWQ_DIR', plugin_dir_path( __FILE__ ) );
define( 'ILSWQ_URL', plugin_dir_url( __FILE__ ) );

define( 'ILSWQ_META_WEBP_PATH', '_ilswq_generated_webp_path' );
define( 'ILSWQ_META_WEBP_FILES', '_ilswq_generated_webp_files' );
define( 'ILSWQ_META_WEBP_SIZE', '_ilswq_generated_webp_size' );
define( 'ILSWQ_META_SOURCE_SIZE', '_ilswq_generated_webp_source_size' );
define( 'ILSWQ_META_EDITOR', '_ilswq_generated_webp_editor' );
define( 'ILSWQ_META_CREATED', '_ilswq_generated_webp_created' );
define( 'ILSWQ_META_VERSION', '_ilswq_generated_webp_version' );
define( 'ILSWQ_META_LAST_ERROR', '_ilswq_last_error' );
define( 'ILSWQ_META_EXCLUDE', '_ilswq_excluded' );
define( 'ILSWQ_OPTION_CLEANUP_PAGE', 'ilswq_cleanup_page' );
define( 'ILSWQ_OPTION_ORPHAN_WEBPS', 'ilswq_orphan_webps' );

require_once ILSWQ_DIR . 'includes/class-ilswq-settings.php';
require_once ILSWQ_DIR . 'includes/class-ilswq-capabilities.php';
require_once ILSWQ_DIR . 'includes/class-ilswq-scanner.php';
require_once ILSWQ_DIR . 'includes/class-ilswq-totals.php';
require_once ILSWQ_DIR . 'includes/class-ilswq-converter.php';
require_once ILSWQ_DIR . 'includes/class-ilswq-queue.php';
require_once ILSWQ_DIR . 'includes/class-ilswq-serving.php';
require_once ILSWQ_DIR . 'includes/class-ilswq-media.php';
require_once ILSWQ_DIR . 'includes/class-ilswq-browser.php';
require_once ILSWQ_DIR . 'includes/class-ilswq-plugin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once ILSWQ_DIR . 'includes/class-ilswq-cli.php';
	WP_CLI::add_command( 'ilswq', 'ILSWQ_CLI' );
}

add_action(
	'plugins_loaded',
	static function () {
		ILSWQ_Plugin::instance();
		ILSWQ_Browser::init();
	}
);

register_activation_hook( ILSWQ_FILE, array( 'ILSWQ_Queue', 'activate' ) );
register_deactivation_hook( ILSWQ_FILE, array( 'ILSWQ_Queue', 'deactivate' ) );

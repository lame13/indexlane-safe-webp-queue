<?php
/**
 * Plugin Name: Safe WebP Queue
 * Plugin URI: https://indexlane.dev/plugins/safe-webp-queue/
 * Description: Convert selected WordPress media images to local WebP copies in small, safety-first batches.
 * Version: 0.1.2
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: IndexLane
 * Author URI: https://indexlane.dev
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: indexlane-safe-webp-queue
 * Update URI: https://indexlane.dev/plugins/safe-webp-queue/
 *
 * @package IndexLaneSafeWebPQueue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ILSWQ_VERSION', '0.1.2' );
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
define( 'ILSWQ_OPTION_CLEANUP_PAGE', 'ilswq_cleanup_page' );

require_once ILSWQ_DIR . 'includes/class-ilswq-settings.php';
require_once ILSWQ_DIR . 'includes/class-ilswq-capabilities.php';
require_once ILSWQ_DIR . 'includes/class-ilswq-scanner.php';
require_once ILSWQ_DIR . 'includes/class-ilswq-converter.php';
require_once ILSWQ_DIR . 'includes/class-ilswq-serving.php';
require_once ILSWQ_DIR . 'includes/class-ilswq-plugin.php';

add_action(
	'plugins_loaded',
	static function () {
		ILSWQ_Plugin::instance();
	}
);

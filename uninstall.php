<?php
/**
 * Plugin uninstall handler.
 *
 * Generated WebP files are not deleted automatically on uninstall. Use the
 * explicit cleanup button in Tools -> Safe WebP Queue before uninstalling when
 * you want those files removed.
 *
 * @package IndexLaneSafeWebPQueue
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'ilswq_settings' );
delete_option( 'ilswq_cleanup_page' );

<?php
/**
 * Plugin uninstall handler.
 *
 * Generated image files are not deleted automatically on uninstall. Use the
 * explicit cleanup button in Tools -> IndexLane Safe WebP & AVIF Queue before uninstalling when
 * you want those files removed.
 *
 * @package IndexLaneSafeWebPQueue
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'ilswq_settings' );
delete_option( 'ilswq_cleanup_page' );
delete_option( 'ilswq_queue_job' );
delete_option( 'ilswq_auto_queue' );
delete_option( 'ilswq_queue_lock' );
delete_option( 'ilswq_orphan_webps' );
delete_option( 'ilswq_totals' );
delete_option( 'ilswq_totals_rebuild' );
delete_option( 'ilswq_media_review' );
delete_post_meta_by_key( '_ilswq_media_review' );
delete_post_meta_by_key( '_ilswq_media_review_time' );
delete_post_meta_by_key( '_ilswq_media_review_state' );
wp_clear_scheduled_hook( 'ilswq_process_queue' );

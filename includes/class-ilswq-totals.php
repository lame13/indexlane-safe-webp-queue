<?php
/**
 * Stored savings totals.
 *
 * @package IndexLaneSafeWebPQueue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks generated WebP files and byte sizes without rescanning the library.
 *
 * Totals are adjusted incrementally whenever a generated file is created,
 * replaced, or deleted. Administrators can rebuild the stored values from
 * attachment metadata in bounded steps.
 */
class ILSWQ_Totals {
	const OPTION           = 'ilswq_totals';
	const REBUILD_OPTION   = 'ilswq_totals_rebuild';
	const REBUILD_PER_PAGE = 25;

	/**
	 * Return an empty total set.
	 *
	 * @return array<string, int>
	 */
	public static function zero() {
		return array(
			'files'        => 0,
			'source_bytes' => 0,
			'webp_bytes'   => 0,
			'updated_at'   => 0,
		);
	}

	/**
	 * Return stored totals.
	 *
	 * @return array<string, int>
	 */
	public static function get() {
		$stored = get_option( self::OPTION, array() );

		return self::normalize( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Return stored totals with display labels.
	 *
	 * @return array<string, mixed>
	 */
	public static function summary() {
		return self::summarize( self::get() );
	}

	/**
	 * Add a newly generated entry, replacing any previous entry for the source.
	 *
	 * @param array<string, mixed> $previous Previous stored entry, if any.
	 * @param array<string, mixed> $current Newly stored entry.
	 * @return void
	 */
	public static function record( $previous, $current ) {
		$before = self::entry_amounts( is_array( $previous ) ? $previous : array() );
		$after  = self::entry_amounts( is_array( $current ) ? $current : array() );

		self::apply(
			(int) $after['files'] - (int) $before['files'],
			(int) $after['source_bytes'] - (int) $before['source_bytes'],
			(int) $after['webp_bytes'] - (int) $before['webp_bytes']
		);
	}

	/**
	 * Remove a deleted entry from the stored totals.
	 *
	 * @param array<string, mixed> $entry Deleted stored entry.
	 * @return void
	 */
	public static function forget( $entry ) {
		$amounts = self::entry_amounts( is_array( $entry ) ? $entry : array() );

		self::apply(
			-1 * (int) $amounts['files'],
			-1 * (int) $amounts['source_bytes'],
			-1 * (int) $amounts['webp_bytes']
		);
	}

	/**
	 * Clear stored totals.
	 *
	 * @return void
	 */
	public static function reset() {
		delete_option( self::OPTION );
		delete_option( self::REBUILD_OPTION );
	}

	/**
	 * Rebuild stored totals from attachment metadata in one bounded step.
	 *
	 * @param bool $restart Whether to restart the rebuild from the first page.
	 * @return array<string, mixed>
	 */
	public static function rebuild_step( $restart ) {
		$state  = $restart ? self::fresh_state() : self::get_rebuild_state();
		$result = self::accumulate_page( $state );

		$state['processed'] = max( 0, (int) $state['processed'] ) + max( 0, (int) $result['processed'] );

		if ( empty( $result['done'] ) ) {
			update_option( self::REBUILD_OPTION, $state, false );

			return array(
				'done'      => false,
				'processed' => (int) $state['processed'],
				'totals'    => null,
			);
		}

		$orphans = get_option( ILSWQ_OPTION_ORPHAN_WEBPS, array() );
		foreach ( is_array( $orphans ) ? $orphans : array() as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['source_size'], $entry['webp_size'], $entry['relative'] ) ) {
				continue;
			}
			$entry['webp'] = ILSWQ_Scanner::relative_to_path( $entry['relative'] );
			$amounts = self::entry_amounts( $entry );
			foreach ( $amounts as $key => $amount ) {
				$state['totals'][ $key ] += $amount;
			}
		}
		$totals               = self::normalize( $state['totals'] );
		$totals['updated_at'] = time();
		update_option( self::OPTION, $totals, false );
		delete_option( self::REBUILD_OPTION );

		return array(
			'done'      => true,
			'processed' => (int) $state['processed'],
			'totals'    => self::summary(),
		);
	}

	/**
	 * Return whether a rebuild is currently in progress.
	 *
	 * @return bool
	 */
	public static function rebuild_active() {
		$state = get_option( self::REBUILD_OPTION, array() );

		return is_array( $state ) && ! empty( $state['phase'] );
	}

	/**
	 * Convert raw totals into a normalized set.
	 *
	 * @param array<string, mixed> $totals Raw totals.
	 * @return array<string, int>
	 */
	private static function normalize( $totals ) {
		$defaults = self::zero();

		return array(
			'files'        => isset( $totals['files'] ) ? max( 0, (int) $totals['files'] ) : $defaults['files'],
			'source_bytes' => isset( $totals['source_bytes'] ) ? max( 0, (int) $totals['source_bytes'] ) : $defaults['source_bytes'],
			'webp_bytes'   => isset( $totals['webp_bytes'] ) ? max( 0, (int) $totals['webp_bytes'] ) : $defaults['webp_bytes'],
			'updated_at'   => isset( $totals['updated_at'] ) ? max( 0, (int) $totals['updated_at'] ) : $defaults['updated_at'],
		);
	}

	/**
	 * Add display labels to a total set.
	 *
	 * @param array<string, int> $totals Normalized totals.
	 * @return array<string, mixed>
	 */
	private static function summarize( $totals ) {
		$totals    = self::normalize( $totals );
		$saved     = max( 0, (int) $totals['source_bytes'] - (int) $totals['webp_bytes'] );
		$percent   = (int) $totals['source_bytes'] > 0 ? round( ( $saved / (int) $totals['source_bytes'] ) * 100, 1 ) : 0;
		$decimals  = $percent > 0 && $percent < 100 ? 1 : 0;
		$updated   = (int) $totals['updated_at'];

		return array(
			'files'         => (int) $totals['files'],
			'source_bytes'  => (int) $totals['source_bytes'],
			'webp_bytes'    => (int) $totals['webp_bytes'],
			'saved_bytes'   => $saved,
			'saved_percent' => $percent,
			'updated_at'    => $updated,
			'is_empty'      => (int) $totals['files'] <= 0,
			'labels'        => array(
				'files'    => number_format_i18n( (int) $totals['files'] ),
				'source'   => ILSWQ_Scanner::format_bytes( (int) $totals['source_bytes'] ),
				'webp'     => ILSWQ_Scanner::format_bytes( (int) $totals['webp_bytes'] ),
				'saved'    => ILSWQ_Scanner::format_bytes( $saved ),
				'percent'  => sprintf(
					/* translators: %s: percentage of saved image bytes. */
					__( '%s%%', 'indexlane-safe-webp-queue' ),
					number_format_i18n( $percent, $decimals )
				),
				'updated'  => $updated > 0 ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $updated ) : '',
			),
		);
	}

	/**
	 * Apply a change to the stored totals.
	 *
	 * @param int $files File delta.
	 * @param int $source_bytes Source byte delta.
	 * @param int $webp_bytes WebP byte delta.
	 * @return void
	 */
	private static function apply( $files, $source_bytes, $webp_bytes ) {
		if ( 0 === $files && 0 === $source_bytes && 0 === $webp_bytes ) {
			return;
		}

		$totals                 = self::get();
		$totals['files']        = max( 0, (int) $totals['files'] + (int) $files );
		$totals['source_bytes'] = max( 0, (int) $totals['source_bytes'] + (int) $source_bytes );
		$totals['webp_bytes']   = max( 0, (int) $totals['webp_bytes'] + (int) $webp_bytes );
		$totals['updated_at']   = time();

		update_option( self::OPTION, $totals, false );
		// Restart if conversion or deletion changed an earlier rebuild page.
		delete_option( self::REBUILD_OPTION );
	}

	/**
	 * Return the countable amounts for one stored entry.
	 *
	 * @param array<string, mixed> $entry Stored entry.
	 * @return array<string, int>
	 */
	private static function entry_amounts( $entry ) {
		$files  = ! empty( $entry['webp'] ) ? 1 : 0;
		$source = isset( $entry['source_size'] ) ? max( 0, (int) $entry['source_size'] ) : 0;
		$webp   = isset( $entry['webp_size'] ) ? max( 0, (int) $entry['webp_size'] ) : 0;

		return array(
			'files'        => $files,
			'source_bytes' => $files > 0 ? $source : 0,
			'webp_bytes'   => $files > 0 ? $webp : 0,
		);
	}

	/**
	 * Return a fresh rebuild state.
	 *
	 * @return array<string, mixed>
	 */
	private static function fresh_state() {
		return array(
			'phase'     => 'map',
			'page'      => 1,
			'processed' => 0,
			'totals'    => self::zero(),
		);
	}

	/**
	 * Return the stored rebuild state.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_rebuild_state() {
		$stored = get_option( self::REBUILD_OPTION, array() );
		if ( ! is_array( $stored ) || empty( $stored['phase'] ) ) {
			return self::fresh_state();
		}

		return array(
			'phase'     => 'legacy' === $stored['phase'] ? 'legacy' : 'map',
			'page'      => isset( $stored['page'] ) ? max( 1, (int) $stored['page'] ) : 1,
			'processed' => isset( $stored['processed'] ) ? max( 0, (int) $stored['processed'] ) : 0,
			'totals'    => self::normalize( isset( $stored['totals'] ) && is_array( $stored['totals'] ) ? $stored['totals'] : array() ),
		);
	}

	/**
	 * Accumulate one page of stored metadata into the rebuild state.
	 *
	 * @param array<string, mixed> $state Rebuild state, by reference.
	 * @return array<string, int|bool>
	 */
	private static function accumulate_page( &$state ) {
		$query = new WP_Query( self::rebuild_query_args( $state ) );
		$seen  = 0;

		foreach ( $query->posts as $attachment_id ) {
			++$seen;
			foreach ( self::entries_for_attachment( (int) $attachment_id, $state['phase'] ) as $entry ) {
				$amounts = self::entry_amounts( $entry );
				$state['totals']['files']        += (int) $amounts['files'];
				$state['totals']['source_bytes'] += (int) $amounts['source_bytes'];
				$state['totals']['webp_bytes']   += (int) $amounts['webp_bytes'];
			}
		}

		$total_pages = max( 1, (int) $query->max_num_pages );
		if ( (int) $state['page'] < $total_pages ) {
			++$state['page'];

			return array(
				'processed' => $seen,
				'done'      => false,
			);
		}

		if ( 'map' === $state['phase'] && self::has_legacy_entries() ) {
			$state['phase'] = 'legacy';
			$state['page']  = 1;

			return array(
				'processed' => $seen,
				'done'      => false,
			);
		}

		return array(
			'processed' => $seen,
			'done'      => true,
		);
	}

	/**
	 * Return query arguments for one rebuild phase.
	 *
	 * @param array<string, mixed> $state Rebuild state.
	 * @return array<string, mixed>
	 */
	private static function rebuild_query_args( $state ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'fields'         => 'ids',
			'posts_per_page' => self::REBUILD_PER_PAGE,
			'paged'          => (int) $state['page'],
			'orderby'        => 'ID',
			'order'          => 'ASC',
		);

		if ( 'legacy' === $state['phase'] ) {
			$args['meta_query'] = array(
				array(
					'key'     => ILSWQ_META_WEBP_PATH,
					'compare' => 'EXISTS',
				),
				array(
					'key'     => ILSWQ_META_WEBP_FILES,
					'compare' => 'NOT EXISTS',
				),
			);

			return $args;
		}

		$args['meta_key'] = ILSWQ_META_WEBP_FILES;

		return $args;
	}

	/**
	 * Return stored entries for one attachment in the current phase.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $phase Rebuild phase.
	 * @return array<int, array<string, mixed>>
	 */
	private static function entries_for_attachment( $attachment_id, $phase ) {
		if ( 'legacy' === $phase ) {
			$legacy = (string) get_post_meta( $attachment_id, ILSWQ_META_WEBP_PATH, true );
			if ( '' === $legacy ) {
				return array();
			}

			return array(
				array(
					'webp'        => $legacy,
					'webp_size'   => (int) get_post_meta( $attachment_id, ILSWQ_META_WEBP_SIZE, true ),
					'source_size' => (int) get_post_meta( $attachment_id, ILSWQ_META_SOURCE_SIZE, true ),
				),
			);
		}

		return array_values( ILSWQ_Scanner::get_webp_map( $attachment_id ) );
	}

	/**
	 * Return whether any attachment still stores only the legacy WebP path.
	 *
	 * @return bool
	 */
	private static function has_legacy_entries() {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'     => ILSWQ_META_WEBP_PATH,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => ILSWQ_META_WEBP_FILES,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		return ! empty( $query->posts );
	}
}

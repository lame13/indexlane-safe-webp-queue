<?php
/**
 * Standalone checks for the AVIF container validators and format helpers.
 *
 * The browser backend accepts an AVIF upload only after this walk proves the
 * bytes are a still AVIF image. These cases run without WordPress: the helpers
 * touch no WordPress function, and building a broken file here is deliberate.
 *
 * Usage:
 * php tests/avif-container.php
 *
 * @package IndexLaneSafeWebPQueue
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// The format helpers are pure except for WordPress's key sanitiser, so stand in
// with the same lower-case/underscore/hyphen rule the real function applies.
if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Minimal sanitize_key() stand-in.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

require __DIR__ . '/../includes/class-ilswq-scanner.php';
require __DIR__ . '/../includes/class-ilswq-browser.php';

/**
 * Fail the test.
 *
 * @param string $message Message.
 * @return void
 */
function ilswq_avif_fail( $message ) {
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
function ilswq_avif_assert( $condition, $message ) {
	if ( ! $condition ) {
		ilswq_avif_fail( $message );
	}
}

/**
 * Build a minimal AVIF file with one ispe box.
 *
 * @param string $brand Major brand.
 * @param int    $width Width.
 * @param int    $height Height.
 * @param bool   $with_compatible_avif Add an avif compatible brand.
 * @return string
 */
function ilswq_avif_build( $brand, $width, $height, $with_compatible_avif = true ) {
	$compat = $with_compatible_avif ? 'avif' : 'mif1';
	$ftyp   = pack( 'N', 20 ) . 'ftyp' . $brand . pack( 'N', 0 ) . $compat;
	$ispe   = pack( 'N', 20 ) . 'ispe' . pack( 'N', 0 ) . pack( 'N', $width ) . pack( 'N', $height );
	$ipco   = pack( 'N', 8 + strlen( $ispe ) ) . 'ipco' . $ispe;
	$iprp   = pack( 'N', 8 + strlen( $ipco ) ) . 'iprp' . $ipco;
	$meta   = pack( 'N', 8 + 4 + strlen( $iprp ) ) . 'meta' . pack( 'N', 0 ) . $iprp;

	return $ftyp . $meta . pack( 'N', 9 ) . 'mdat' . "\x00";
}

$still = ilswq_avif_build( 'avif', 640, 480 );
ilswq_avif_assert( array( 640, 480 ) === ILSWQ_Browser::avif_dimensions( $still ), 'A still AVIF file did not yield its dimensions.' );
ilswq_avif_assert( array( 640, 480 ) === ILSWQ_Browser::output_dimensions( $still, 'avif' ), 'The format router did not reach the AVIF walk.' );
ilswq_avif_assert( 'avif' === ILSWQ_Scanner::avif_brand( $still ), 'The AVIF brand was not reported.' );

$mif1 = ilswq_avif_build( 'mif1', 32, 32, true );
ilswq_avif_assert( array( 32, 32 ) === ILSWQ_Browser::avif_dimensions( $mif1 ), 'A generic HEIF brand that lists avif was rejected.' );

$mif1_only = ilswq_avif_build( 'mif1', 32, 32, false );
ilswq_avif_assert( null === ILSWQ_Browser::avif_dimensions( $mif1_only ), 'A HEIF file without an avif compatible brand was accepted.' );

$heic = ilswq_avif_build( 'heic', 32, 32, false );
ilswq_avif_assert( null === ILSWQ_Browser::avif_dimensions( $heic ), 'A HEIC file was accepted as AVIF.' );

$avis = ilswq_avif_build( 'avis', 32, 32, true );
ilswq_avif_assert( null === ILSWQ_Browser::avif_dimensions( $avis ), 'An animated AVIF sequence brand was accepted.' );

// An animated file additionally carries a moov box; refuse it even when the
// major brand looks like a still image.
$moov = pack( 'N', 16 ) . 'moov' . pack( 'N', 0 );
ilswq_avif_assert( null === ILSWQ_Browser::avif_dimensions( $still . $moov ), 'A moov box was accepted in a still AVIF file.' );

// A box that overruns the file, a missing meta box, and a zero-length box must
// all be rejected instead of read out of bounds.
$truncated = substr( $still, 0, -4 );
ilswq_avif_assert( null === ILSWQ_Browser::avif_dimensions( $truncated ), 'A truncated AVIF file was accepted.' );
ilswq_avif_assert( null === ILSWQ_Browser::avif_dimensions( $still . "\x00" ), 'Trailing partial box bytes were accepted.' );
$animated_compat = substr_replace( $still, 'avis', 16, 4 );
ilswq_avif_assert( null === ILSWQ_Browser::avif_dimensions( $animated_compat ), 'An animation compatible brand was accepted.' );
$minor_brand = substr_replace( $mif1_only, 'avif', 12, 4 );
ilswq_avif_assert( null === ILSWQ_Browser::avif_dimensions( $minor_brand ), 'The minor-version field was mistaken for an AVIF brand.' );
$bad_ftyp = substr_replace( $still, pack( 'N', 21 ), 0, 4 );
ilswq_avif_assert( null === ILSWQ_Browser::avif_dimensions( $bad_ftyp ), 'An unaligned compatible-brand list was accepted.' );
$too_many = substr( $still, 0, 20 ) . str_repeat( pack( 'N', 8 ) . 'free', 64 ) . substr( $still, 20 );
ilswq_avif_assert( null === ILSWQ_Browser::avif_dimensions( $too_many ), 'The bounded box walk accepted an uninspected file tail.' );
$no_data = substr( $still, 0, -9 );
ilswq_avif_assert( null === ILSWQ_Browser::avif_dimensions( $no_data ), 'A container without image data was accepted.' );
ilswq_avif_assert( null === ILSWQ_Browser::avif_dimensions( '' ), 'An empty file was accepted.' );
ilswq_avif_assert( null === ILSWQ_Browser::avif_dimensions( pack( 'N', 0 ) . 'meta' . pack( 'N', 0 ) ), 'A zero-length box was accepted.' );

$too_tall = ilswq_avif_build( 'avif', 100, 9000 );
ilswq_avif_assert( null === ILSWQ_Browser::avif_dimensions( $too_tall ), 'An image above the browser axis limit was accepted.' );

// Format helpers and the local-file check.
ilswq_avif_assert( '.avif' === ILSWQ_Scanner::format_extension( 'AVIF' ), 'The AVIF extension helper is wrong.' );
ilswq_avif_assert( '.webp' === ILSWQ_Scanner::format_extension( 'webp' ), 'The WebP extension helper is wrong.' );
ilswq_avif_assert( 'image/avif' === ILSWQ_Scanner::format_mime( 'avif' ), 'The AVIF MIME helper is wrong.' );
ilswq_avif_assert( 'full__avif' === ILSWQ_Scanner::map_entry_key( 'full', 'avif' ), 'The AVIF map key is wrong.' );
ilswq_avif_assert( 'full' === ILSWQ_Scanner::map_entry_key( 'full', 'webp' ), 'The WebP map key changed.' );
ilswq_avif_assert( 'webp' === ILSWQ_Scanner::entry_format( array() ), 'A legacy entry without a format did not default to webp.' );

$path = tempnam( sys_get_temp_dir(), 'ilswq-avif-' );
file_put_contents( $path, $still );
ilswq_avif_assert( ILSWQ_Scanner::is_valid_generated_file( $path, 'avif' ), 'A generated AVIF file was reported invalid.' );
ilswq_avif_assert( ! ILSWQ_Scanner::is_valid_generated_file( $path, 'webp' ), 'An AVIF file was reported as a valid WebP file.' );
foreach ( array( $truncated, $mif1_only, $animated_compat, $bad_ftyp, $no_data ) as $invalid_bytes ) {
	file_put_contents( $path, $invalid_bytes );
	clearstatcache( true, $path );
	ilswq_avif_assert( ! ILSWQ_Scanner::is_valid_generated_file( $path, 'avif' ), 'A damaged or non-AVIF stored container was accepted.' );
}
file_put_contents( $path, 'not an image' );
ilswq_avif_assert( ! ILSWQ_Scanner::is_valid_generated_file( $path, 'avif' ), 'A non-image file was reported as a valid AVIF file.' );
unlink( $path );
ilswq_avif_assert( ! ILSWQ_Scanner::is_valid_generated_file( $path, 'avif' ), 'A missing file was reported as a valid AVIF file.' );

print "AVIF container regression tests passed.\n";

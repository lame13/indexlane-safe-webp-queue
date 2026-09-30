<?php
/**
 * Pure-PHP conversion backend checks that need no WordPress install.
 *
 * Run with: php tests/php-encoder.php
 *
 * @package IndexLaneSafeWebPQueue
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

// ILSWQ_TEST_ROOT lets the same checks run against an extracted release
// package, which proves the shipped directory layout and the bundled library
// are the ones the autoloader expects.
$ilswq_test_root = getenv( 'ILSWQ_TEST_ROOT' );
define( 'ILSWQ_DIR', $ilswq_test_root ? rtrim( $ilswq_test_root, '/' ) . '/' : dirname( __DIR__ ) . '/' );

/**
 * Minimal WP_Error stand-in.
 */
class WP_Error {
	/** @var string */
	private $code;
	/** @var string */
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = (string) $code;
		$this->message = (string) $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

$GLOBALS['ilswq_options'] = array();

function get_option( $name, $default = false ) {
	return $GLOBALS['ilswq_options'][ $name ] ?? $default;
}

function update_option( $name, $value ) {
	$GLOBALS['ilswq_options'][ $name ] = $value;

	return true;
}

function absint( $value ) {
	return abs( (int) $value );
}

function apply_filters( $hook, $value ) {
	return $value;
}

function __( $text ) {
	return $text;
}

/**
 * Tiny helper so the runtime check reads clearly in the output.
 */
final class ILSWQ_Capabilities_Support {
	public static function php_runtime_ok(): bool {
		return version_compare( PHP_VERSION, '8.1.0', '>=' ) && extension_loaded( 'zlib' );
	}
}

require_once ILSWQ_DIR . 'includes/class-ilswq-settings.php';
require_once ILSWQ_DIR . 'includes/class-ilswq-php-encoder.php';

$failures = 0;

/**
 * Assert a condition.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 */
function check( $condition, $message ) {
	global $failures;
	if ( $condition ) {
		printf( "ok   %s\n", $message );
		return;
	}
	++$failures;
	printf( "FAIL %s\n", $message );
}

/**
 * Build a small PNG without GD.
 *
 * @param int    $width Width.
 * @param int    $height Height.
 * @param string $icc Optional ICC profile to embed as an iCCP chunk.
 * @return string PNG bytes.
 */
function make_png( $width, $height, $icc = '' ) {
	$raw = '';
	for ( $y = 0; $y < $height; $y++ ) {
		$raw .= "\x00";
		for ( $x = 0; $x < $width; $x++ ) {
			$raw .= chr( ( $x * 23 ) % 256 ) . chr( ( $y * 17 ) % 256 ) . chr( ( ( $x + $y ) * 11 ) % 256 );
		}
	}

	$chunk = static function ( $type, $data ) {
		return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
	};

	$png = "\x89PNG\r\n\x1a\n" . $chunk( 'IHDR', pack( 'NNCCCCC', $width, $height, 8, 2, 0, 0, 0 ) );

	if ( '' !== $icc ) {
		// iCCP: profile name, NUL, compression method 0, deflated profile.
		$png .= $chunk( 'iCCP', "test-profile\x00\x00" . gzcompress( $icc, 6 ) );
	}

	return $png
		. $chunk( 'IDAT', gzcompress( $raw, 6 ) )
		. $chunk( 'IEND', '' );
}

// Older PHP versions keep the plugin available without loading the optional
// library, even if its setting was enabled before the server was downgraded.
if ( ! ILSWQ_Capabilities_Support::php_runtime_ok() ) {
	$GLOBALS['ilswq_options']['ilswq_settings'] = array( 'php_conversion' => 1 );
	check( ! ILSWQ_PHP_Encoder::available(), 'The optional encoder is unavailable on this runtime' );
	check( ! ILSWQ_PHP_Encoder::is_enabled(), 'An incompatible runtime automatically disables the encoder' );
	check( '' !== ILSWQ_PHP_Encoder::unavailable_reason(), 'The runtime requirement is explained' );
	check( ! class_exists( 'PicoWebP\\Vp8\\Vp8LossyEncoder' ), 'The incompatible library is never autoloaded' );
	$unavailable = ILSWQ_PHP_Encoder::encode( '', 80 );
	check( $unavailable instanceof WP_Error && 'ilswq_php_unavailable' === $unavailable->get_error_code(), 'Encoding reports unavailability without parsing the library' );
	exit( $failures > 0 ? 1 : 0 );
}

$tmp_dir  = sys_get_temp_dir();
$png_path = $tmp_dir . '/ilswq-test-' . getmypid() . '.png';
$icc_path = $tmp_dir . '/ilswq-test-icc-' . getmypid() . '.png';
$webp_path = $tmp_dir . '/ilswq-test-' . getmypid() . '.webp';
$junk_path = $tmp_dir . '/ilswq-test-' . getmypid() . '.bin';

file_put_contents( $png_path, make_png( 24, 16 ) );
// A minimal, structurally valid ICC profile (128-byte header plus an empty
// tag table) so libpng accepts it without complaint.
$profile = str_repeat( "\x00", 128 );
$profile = substr_replace( $profile, pack( 'N', 132 ), 0, 4 );
$profile = substr_replace( $profile, 'acsp', 36, 4 );
$profile .= pack( 'N', 0 );
file_put_contents( $icc_path, make_png( 24, 16, $profile ) );
file_put_contents( $junk_path, 'this is not an image at all' );

check( ILSWQ_Capabilities_Support::php_runtime_ok(), 'PHP ' . PHP_VERSION . ' with zlib' );
check( ILSWQ_PHP_Encoder::available(), 'The bundled encoder is available' );
check( '' === ILSWQ_PHP_Encoder::unavailable_reason(), 'No unavailable reason when available' );
check( ILSWQ_PHP_Encoder::encode( '', 80 ) instanceof WP_Error, 'A missing path reports an error without throwing' );
check( class_exists( 'PicoWebP\\Vp8\\Vp8LossyEncoder' ), 'The bundled autoloader loads the encoder' );

// Exercise the bundled readers even on development machines with GD.
\PicoWebP\Vp8\ImageInput::$gdAvailable = false;

// Disabled by default.
$GLOBALS['ilswq_options']['ilswq_settings'] = array();
check( ! ILSWQ_PHP_Encoder::is_enabled(), 'The backend is off by default' );

$GLOBALS['ilswq_options']['ilswq_settings'] = array( 'php_conversion' => 1, 'max_pixels' => 16000000 );
check( ILSWQ_PHP_Encoder::is_enabled(), 'The backend turns on with the setting' );
check( 2000000 === ILSWQ_PHP_Encoder::max_pixels(), 'The pixel cap stays at the encoder ceiling' );

$GLOBALS['ilswq_options']['ilswq_settings'] = array( 'php_conversion' => 1, 'max_pixels' => 1000000 );
check( 1000000 === ILSWQ_PHP_Encoder::max_pixels(), 'The site max-pixels setting can only lower the cap' );

check( ILSWQ_PHP_Encoder::source_within_limits( array( 'width' => 1200, 'height' => 800 ) ), 'A 0.96 MP source is within limits' );
check( ! ILSWQ_PHP_Encoder::source_within_limits( array( 'width' => 4000, 'height' => 3000 ) ), 'A 12 MP source is outside the cap' );
check( ! ILSWQ_PHP_Encoder::source_within_limits( array( 'width' => 16384, 'height' => 1 ) ), 'A dimension that cannot fit the VP8 header is rejected' );

$encoded = ILSWQ_PHP_Encoder::encode( $png_path, 80 );
check( is_array( $encoded ) && ! empty( $encoded['bytes'] ), 'A PNG encodes to bytes' );
check( is_array( $encoded ) && 24 === $encoded['width'] && 16 === $encoded['height'], 'The encoded dimensions match the source' );
check( is_array( $encoded ) && 'bundled' === $encoded['reader'], 'PNG decoding works without GD' );

$bytes = is_array( $encoded ) ? (string) $encoded['bytes'] : '';
check( 'RIFF' === substr( $bytes, 0, 4 ) && 'WEBP' === substr( $bytes, 8, 4 ), 'The output is a WebP container' );
check( unpack( 'V', substr( $bytes, 4, 4 ) )[1] === strlen( $bytes ) - 8, 'The RIFF size field matches the file length' );
check( array_slice( getimagesizefromstring( $bytes ), 0, 2 ) === array( 24, 16 ), 'An independent image reader recognizes the dimensions' );

// The file header, rather than stale attachment dimensions, must enforce the
// pixel cap before decoding. No pixel payload is needed for this refusal.
$large_path = $tmp_dir . '/ilswq-test-large-' . getmypid() . '.png';
file_put_contents( $large_path, "\x89PNG\r\n\x1a\n" . pack( 'N', 13 ) . 'IHDR' . pack( 'NNCCCCC', 2000, 1500, 8, 2, 0, 0, 0 ) . str_repeat( "\x00", 4 ) );
$too_large = ILSWQ_PHP_Encoder::encode( $large_path, 80 );
check( $too_large instanceof WP_Error && 'ilswq_php_limits' === $too_large->get_error_code(), 'Actual source dimensions are checked before decoding' );

if ( function_exists( 'imagejpeg' ) ) {
	$jpeg_path = $tmp_dir . '/ilswq-test-jpeg-' . getmypid() . '.jpg';
	$image = imagecreatetruecolor( 24, 16 );
	imagefilledrectangle( $image, 0, 0, 23, 15, imagecolorallocate( $image, 70, 150, 210 ) );
	imagejpeg( $image, $jpeg_path, 90 );
	imagedestroy( $image );
	$jpeg = (string) file_get_contents( $jpeg_path );
	$jpeg_encoded = ILSWQ_PHP_Encoder::encode( $jpeg_path, 80 );
	check( is_array( $jpeg_encoded ) && 'bundled' === $jpeg_encoded['reader'], 'Baseline JPEG decoding works without GD' );

	foreach ( array( 'II', 'MM' ) as $endian ) {
		foreach ( array( 1, 2, 6 ) as $orientation ) {
			$little = 'II' === $endian;
			$exif = "Exif\x00\x00" . $endian
				. pack( $little ? 'vVv' : 'nNn', 42, 8, 1 )
				. pack( $little ? 'vvVv' : 'nnNn', 0x0112, 3, 1, $orientation )
				. "\x00\x00" . pack( 'V', 0 );
			file_put_contents( $jpeg_path, substr( $jpeg, 0, 2 ) . "\xff\xe1" . pack( 'n', strlen( $exif ) + 2 ) . $exif . substr( $jpeg, 2 ) );
			$oriented = ILSWQ_PHP_Encoder::encode( $jpeg_path, 80 );
			check( 1 === $orientation ? is_array( $oriented ) : $oriented instanceof WP_Error && 'ilswq_php_unsupported' === $oriented->get_error_code(), $endian . ' EXIF orientation ' . $orientation . ' is handled safely' );
		}
	}
	$xmp = 'http://ns.adobe.com/xap/1.0/' . "\x00<x:xmpmeta></x:xmpmeta>";
	$segments = "\xff\xe1" . pack( 'n', strlen( $xmp ) + 2 ) . $xmp
		. "\xff\xe1" . pack( 'n', strlen( $exif ) + 2 ) . $exif;
	file_put_contents( $jpeg_path, substr( $jpeg, 0, 2 ) . $segments . substr( $jpeg, 2 ) );
	$oriented = ILSWQ_PHP_Encoder::encode( $jpeg_path, 80 );
	check( $oriented instanceof WP_Error && 'ilswq_php_unsupported' === $oriented->get_error_code(), 'An earlier XMP marker cannot hide EXIF orientation' );
	unlink( $jpeg_path );
}

// A source ICC profile is carried through to an ICCP chunk. The browser
// backend's stricter container walk rejects ancillary chunks, so this is the
// case that proves the pure-PHP path must not reuse it.
$icc_encoded = ILSWQ_PHP_Encoder::encode( $icc_path, 80 );
check( is_array( $icc_encoded ) && ! empty( $icc_encoded['bytes'] ), 'A PNG with an ICC profile encodes' );
check( is_array( $icc_encoded ) && false !== strpos( (string) $icc_encoded['bytes'], 'ICCP' ), 'The ICC profile is carried into the WebP output' );

// A WebP source is skipped rather than re-encoded.
file_put_contents( $webp_path, $bytes );
$skipped = ILSWQ_PHP_Encoder::encode( $webp_path, 80 );
check( $skipped instanceof WP_Error && 'ilswq_php_skipped_webp' === $skipped->get_error_code(), 'A WebP source is reported as already converted' );

// An unreadable file is routed to another backend, not reported as a failure.
$unsupported = ILSWQ_PHP_Encoder::encode( $junk_path, 80 );
check( $unsupported instanceof WP_Error, 'A non-image is rejected' );
check( ! in_array( $unsupported->get_error_code(), array( 'ilswq_php_encode_failed', 'ilswq_php_read_failed' ), true ), 'A non-image is an unsupported route, not an encode failure' );

// PHP 8.1+ is genuinely required: the library uses match(), readonly and
// constructor promotion, so the guard has to hold on older runtimes.
check( version_compare( ILSWQ_PHP_Encoder::MIN_PHP, '8.1.0', '>=' ), 'The minimum PHP version is 8.1 or newer' );

foreach ( array( $png_path, $icc_path, $webp_path, $junk_path, $large_path ) as $path ) {
	@unlink( $path );
}

if ( $failures > 0 ) {
	printf( "\n%d check(s) failed.\n", $failures );
	exit( 1 );
}

print "\nAll pure-PHP encoder checks passed.\n";

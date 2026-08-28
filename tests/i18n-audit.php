<?php
/**
 * Static internationalization guardrails for production PHP and JavaScript.
 *
 * Run with: php tests/i18n-audit.php
 */

declare(strict_types=1);

$repository_root = dirname( __DIR__ );
$plugin_path     = $repository_root . '/indexlane-safe-webp-queue.php';
$script_path     = $repository_root . '/assets/admin.js';
$domain          = 'indexlane-safe-webp-queue';
$plugin_source   = file_get_contents( $plugin_path );
$script_source   = file_get_contents( $script_path );
$php_paths       = array_merge( array( $plugin_path ), glob( $repository_root . '/includes/*.php' ) ?: array() );

if ( false === $plugin_source || false === $script_source ) {
	fwrite( STDERR, "Could not read the production plugin files.\n" );
	exit( 1 );
}

if ( ! preg_match( '/^[ \t]*\*[ \t]*Text Domain:[ \t]*' . preg_quote( $domain, '/' ) . '[ \t]*$/m', $plugin_source ) ) {
	fwrite( STDERR, "The plugin header must declare the exact WordPress.org text domain.\n" );
	exit( 1 );
}

$functions = array(
	'__'         => array( 'literal_arguments' => array( 0 ), 'domain_argument' => 1 ),
	'_e'         => array( 'literal_arguments' => array( 0 ), 'domain_argument' => 1 ),
	'_x'         => array( 'literal_arguments' => array( 0, 1 ), 'domain_argument' => 2 ),
	'_ex'        => array( 'literal_arguments' => array( 0, 1 ), 'domain_argument' => 2 ),
	'_n'         => array( 'literal_arguments' => array( 0, 1 ), 'domain_argument' => 3 ),
	'_nx'        => array( 'literal_arguments' => array( 0, 1, 3 ), 'domain_argument' => 4 ),
	'esc_html__' => array( 'literal_arguments' => array( 0 ), 'domain_argument' => 1 ),
	'esc_html_e' => array( 'literal_arguments' => array( 0 ), 'domain_argument' => 1 ),
	'esc_html_x' => array( 'literal_arguments' => array( 0, 1 ), 'domain_argument' => 2 ),
	'esc_attr__' => array( 'literal_arguments' => array( 0 ), 'domain_argument' => 1 ),
	'esc_attr_e' => array( 'literal_arguments' => array( 0 ), 'domain_argument' => 1 ),
	'esc_attr_x' => array( 'literal_arguments' => array( 0, 1 ), 'domain_argument' => 2 ),
);

/**
 * Remove whitespace and comments from one parsed call argument.
 *
 * @param array<int,mixed> $argument Argument tokens.
 * @return array<int,mixed>
 */
function ilswq_i18n_significant_tokens( array $argument ): array {
	return array_values(
		array_filter(
			$argument,
			static function ( $token ): bool {
				return ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
			}
		)
	);
}

/**
 * Return a literal token value, or null when an expression was used.
 *
 * @param array<int,mixed> $argument Argument tokens.
 * @return string|null
 */
function ilswq_i18n_literal_value( array $argument ): ?string {
	$tokens = ilswq_i18n_significant_tokens( $argument );
	if ( 1 !== count( $tokens ) || ! is_array( $tokens[0] ) || T_CONSTANT_ENCAPSED_STRING !== $tokens[0][0] ) {
		return null;
	}

	return substr( $tokens[0][1], 1, -1 );
}

$errors                 = array();
$translation_call_count = 0;

foreach ( $php_paths as $php_path ) {
	$source = file_get_contents( $php_path );
	if ( false === $source ) {
		$errors[] = 'Could not read ' . $php_path . '.';
		continue;
	}

	$tokens                  = token_get_all( $source );
	$last_translator_comment = null;
	$last_comment_end_line   = 0;

	for ( $index = 0, $token_count = count( $tokens ); $index < $token_count; $index++ ) {
		$token = $tokens[ $index ];
		if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			if ( false !== stripos( $token[1], 'translators:' ) ) {
				$last_translator_comment = $token[1];
				$last_comment_end_line   = $token[2] + substr_count( $token[1], "\n" );
			}
			continue;
		}

		if ( ! is_array( $token ) || T_STRING !== $token[0] || ! isset( $functions[ strtolower( $token[1] ) ] ) ) {
			continue;
		}

		$function_name = strtolower( $token[1] );
		$open_index    = $index + 1;
		while ( $open_index < $token_count && is_array( $tokens[ $open_index ] ) && T_WHITESPACE === $tokens[ $open_index ][0] ) {
			++$open_index;
		}
		if ( $open_index >= $token_count || '(' !== $tokens[ $open_index ] ) {
			continue;
		}

		$arguments = array( array() );
		$depth     = 1;
		for ( $cursor = $open_index + 1; $cursor < $token_count; $cursor++ ) {
			$current = $tokens[ $cursor ];
			if ( is_string( $current ) ) {
				if ( in_array( $current, array( '(', '[', '{' ), true ) ) {
					++$depth;
				} elseif ( in_array( $current, array( ')', ']', '}' ), true ) ) {
					--$depth;
					if ( 0 === $depth ) {
						break;
					}
				} elseif ( ',' === $current && 1 === $depth ) {
					$arguments[] = array();
					continue;
				}
			}
			$arguments[ count( $arguments ) - 1 ][] = $current;
		}

		++$translation_call_count;
		$requirements = $functions[ $function_name ];
		foreach ( $requirements['literal_arguments'] as $argument_index ) {
			if ( ! isset( $arguments[ $argument_index ] ) || null === ilswq_i18n_literal_value( $arguments[ $argument_index ] ) ) {
				$errors[] = sprintf( '%s:%d %s() must use a literal string for argument %d.', basename( $php_path ), $token[2], $function_name, $argument_index + 1 );
			}
		}

		$domain_index = $requirements['domain_argument'];
		$call_domain  = isset( $arguments[ $domain_index ] ) ? ilswq_i18n_literal_value( $arguments[ $domain_index ] ) : null;
		if ( $domain !== $call_domain ) {
			$errors[] = sprintf( '%s:%d %s() must use the literal text domain %s.', basename( $php_path ), $token[2], $function_name, $domain );
		}

		$primary_literal = isset( $arguments[0] ) ? ilswq_i18n_literal_value( $arguments[0] ) : null;
		if ( null !== $primary_literal && preg_match( '/(?<!%)%(?:[0-9]+\$)?[-+0-9.]*[bcdeEfFgGosuxX]/', $primary_literal ) ) {
			if ( null === $last_translator_comment || $token[2] - $last_comment_end_line > 2 ) {
				$errors[] = sprintf( '%s:%d %s() has placeholders without an adjacent translators comment.', basename( $php_path ), $token[2], $function_name );
			}
		}

		$index = $cursor;
	}
}

$forbidden_script_fragments = array(
	"'Request failed.'",
	"'Attachment ID'",
	"'Source Files'",
	"'MIME Type'",
	"'Estimated Memory'",
	"aria-label=\"Select ",
	"row.status === 'Converted'",
	"row.status === 'Skipped'",
	"row.status === 'Failed'",
	"row.status === 'Needs review'",
);

foreach ( $forbidden_script_fragments as $fragment ) {
	if ( false !== strpos( $script_source, $fragment ) ) {
		$errors[] = 'The browser controller contains a non-localized visible string or status comparison: ' . $fragment;
	}
}

if ( false === strpos( $script_source, 'ILSWQ_Admin.csvHeaders' ) || false === strpos( $script_source, 'ILSWQ_Admin.strings.selectAttachment' ) ) {
	$errors[] = 'The browser controller must consume PHP-localized CSV headers and selection labels.';
}

if ( $translation_call_count < 1 ) {
	$errors[] = 'No production translation calls were found.';
}

if ( $errors ) {
	fwrite( STDERR, implode( "\n", $errors ) . "\n" );
	exit( 1 );
}

fwrite( STDOUT, sprintf( "Internationalization source audit passed (%d translation calls).\n", $translation_call_count ) );

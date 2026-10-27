<?php
/**
 * WordPress integration test for optional AI Media Review.
 *
 * Usage:
 * php tests/ai-wordpress.php /path/to/wordpress
 *
 * Provider HTTP responses are mocked. No external API call is made.
 *
 * @package IndexLaneSafeWebPQueue
 */

$wp_path = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : rtrim( (string) getenv( 'WP_PATH' ), '/\\' );

if ( '' === $wp_path || ! file_exists( $wp_path . '/wp-load.php' ) ) {
	fwrite( STDERR, "WordPress path is required.\n" );
	exit( 1 );
}

define( 'WP_USE_THEMES', false );
require $wp_path . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

if ( ! is_plugin_active( 'indexlane-safe-webp-queue/indexlane-safe-webp-queue.php' ) ) {
	$result = activate_plugin( 'indexlane-safe-webp-queue/indexlane-safe-webp-queue.php' );
	if ( is_wp_error( $result ) ) {
		fwrite( STDERR, $result->get_error_message() . "\n" );
		exit( 1 );
	}
}

if ( ! class_exists( 'ILSWQ_AI' ) ) {
	fwrite( STDERR, "Media Review is not loaded.\n" );
	exit( 1 );
}

if ( ! function_exists( 'imagecreatetruecolor' ) ) {
	fwrite( STDERR, "GD is required to build Media Review fixtures.\n" );
	exit( 3 );
}

$expected_editor = (string) getenv( 'ILSWQ_AI_EDITOR' );
if ( in_array( $expected_editor, array( 'GD', 'Imagick' ), true ) ) {
	add_filter(
		'wp_image_editors',
		static function () use ( $expected_editor ) {
			return array( 'WP_Image_Editor_' . $expected_editor );
		}
	);
}

/**
 * Fail the test.
 *
 * @param string $message Message.
 * @return void
 */
function ilswq_ai_fail( $message ) {
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
function ilswq_ai_assert( $condition, $message ) {
	if ( ! $condition ) {
		ilswq_ai_fail( $message );
	}
}

/**
 * Create a test JPEG.
 *
 * @param string $path Path.
 * @return void
 */
function ilswq_ai_create_jpeg( $path ) {
	$image = imagecreatetruecolor( 1000, 600 );
	for ( $y = 0; $y < 600; ++$y ) {
		$color = imagecolorallocate( $image, ( 30 + $y ) % 255, ( $y * 2 ) % 255, 150 );
		imageline( $image, 0, $y, 999, $y, $color );
	}
	imagejpeg( $image, $path, 88 );
	imagedestroy( $image );
	$bytes   = file_get_contents( $path );
	$comment = 'PRIVATE-REVIEW-METADATA';
	file_put_contents( $path, substr( $bytes, 0, 2 ) . "\xff\xfe" . pack( 'n', strlen( $comment ) + 2 ) . $comment . substr( $bytes, 2 ) );
}

/**
 * Insert a test image attachment.
 *
 * @param string $path File path.
 * @param int    $parent Parent post ID.
 * @return int
 */
function ilswq_ai_insert_attachment( $path, $parent = 0 ) {
	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => 'Media Review fixture',
			'post_status'    => 'inherit',
			'post_parent'    => $parent,
		),
		$path
	);
	if ( is_wp_error( $attachment_id ) || $attachment_id <= 0 ) {
		ilswq_ai_fail( 'Could not create a Media Review attachment.' );
	}

	$metadata = wp_generate_attachment_metadata( $attachment_id, $path );
	wp_update_attachment_metadata( $attachment_id, $metadata );

	return (int) $attachment_id;
}

/**
 * Build a mocked WordPress HTTP response.
 *
 * @param array<string,mixed> $body Body.
 * @return array<string,mixed>
 */
function ilswq_ai_http_response( $body ) {
	return array(
		'headers'  => array(),
		'body'     => wp_json_encode( $body ),
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'cookies'  => array(),
		'filename' => null,
	);
}

$admin_ids = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
if ( empty( $admin_ids ) ) {
	ilswq_ai_fail( 'The test WordPress site needs an administrator.' );
}
wp_set_current_user( (int) $admin_ids[0] );

$original_option = get_option( ILSWQ_AI::OPTION );
$created_posts   = array();
$uploads         = wp_upload_dir();
if ( ! empty( $uploads['error'] ) ) {
	ilswq_ai_fail( $uploads['error'] );
}

$page_id = wp_insert_post(
	array(
		'post_title'   => 'Autumn Product Guide',
		'post_content' => 'A guide to the new autumn collection and its weatherproof materials.',
		'post_status'  => 'publish',
		'post_type'    => 'page',
	)
);
if ( is_wp_error( $page_id ) || ! $page_id ) {
	ilswq_ai_fail( 'Could not create the linked page fixture.' );
}
$created_posts[] = (int) $page_id;

$image_path = trailingslashit( $uploads['path'] ) . wp_unique_filename( $uploads['path'], 'ilswq-ai-review.jpg' );
ilswq_ai_create_jpeg( $image_path );
$attachment_id = ilswq_ai_insert_attachment( $image_path, (int) $page_id );
$created_posts[] = $attachment_id;
$original_alt = '  Old C:\\photos\\product photo  ';
update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_slash( $original_alt ) );

$settings = ILSWQ_AI::save_settings(
	array(
		'enabled'     => 1,
		'provider'    => 'openai',
		'model'       => 'gpt-test-vision',
		'api_key'     => 'sk-test-secret-value',
		'use_context' => 1,
		'batch_size'  => 5,
	)
);
ilswq_ai_assert( ! empty( $settings['configured'] ), 'Media Review was not configured.' );
ilswq_ai_assert( false === strpos( (string) wp_json_encode( $settings ), 'sk-test-secret-value' ), 'The API key leaked through browser configuration.' );
$stored_settings = get_option( ILSWQ_AI::OPTION );
ilswq_ai_assert( false === strpos( (string) wp_json_encode( $stored_settings ), 'sk-test-secret-value' ), 'The API key was stored as plaintext.' );
ilswq_ai_assert( ! empty( $stored_settings['key_encrypted'] ), 'The API key was not encrypted.' );

$review_result = array(
	'alt_text'        => 'Navy jacket beside a C:\\photos\\catalog label',
	'decorative'      => false,
	'context_match'   => 'match',
	'context_note'    => 'The jacket fits the autumn product guide.',
	'privacy_flags'   => array(),
	'privacy_note'    => '',
	'review_priority' => 'routine',
);
$http_calls = array();
$mock_body  = array(
	'output' => array(
		array(
			'content' => array(
				array(
					'type' => 'output_text',
					'text' => wp_json_encode( $review_result ),
				),
			),
		),
	),
);

$during_request = null;
$mock_status    = 200;
$mock_http = static function ( $pre, $args, $url ) use ( &$http_calls, &$mock_body, &$during_request, &$mock_status ) {
	$http_calls[] = array(
		'url'  => $url,
		'args' => $args,
	);
	if ( is_callable( $during_request ) ) {
		$during_request();
	}
	$response = ilswq_ai_http_response( $mock_body );
	$response['response']['code'] = $mock_status;
	return $response;
};
add_filter( 'pre_http_request', $mock_http, 10, 3 );

$review = ILSWQ_AI::review_attachment( $attachment_id );
if ( is_wp_error( $review ) ) {
	ilswq_ai_fail( 'OpenAI-compatible review failed: ' . $review->get_error_message() );
}

ilswq_ai_assert( 'pending' === $review['status'], 'The review was not left pending.' );
ilswq_ai_assert( $review_result['alt_text'] === $review['alt_text'], 'The proposed alternative text was not returned.' );
ilswq_ai_assert( 1 === count( $http_calls ), 'The review made an unexpected number of HTTP requests.' );
ilswq_ai_assert( 'https://api.openai.com/v1/responses' === $http_calls[0]['url'], 'OpenAI reviewed through the wrong endpoint.' );
ilswq_ai_assert( 'Bearer sk-test-secret-value' === $http_calls[0]['args']['headers']['Authorization'], 'The OpenAI key was not sent in the authorization header.' );
$request_body = json_decode( (string) $http_calls[0]['args']['body'], true );
ilswq_ai_assert( ! empty( $request_body['store'] ) === false, 'The OpenAI request did not disable stored responses.' );
ilswq_ai_assert( 'json_schema' === $request_body['text']['format']['type'], 'The OpenAI request did not use structured output.' );
ilswq_ai_assert( false !== strpos( (string) $request_body['input'][0]['content'][0]['text'], 'Autumn Product Guide' ), 'The linked page context was not sent.' );
ilswq_ai_assert( 0 === strpos( (string) $request_body['input'][0]['content'][1]['image_url'], 'data:image/' ), 'The review did not send a temporary image preview.' );
$preview_data = base64_decode( explode( ',', $request_body['input'][0]['content'][1]['image_url'], 2 )[1] );
ilswq_ai_assert( false === strpos( $preview_data, 'PRIVATE-REVIEW-METADATA' ), 'Private source metadata reached the provider.' );
$stored_review = get_post_meta( $attachment_id, ILSWQ_AI::REVIEW_META, true );
ilswq_ai_assert( $review_result['alt_text'] === $stored_review['alt_text'], 'Proposal storage removed backslashes.' );
ilswq_ai_assert( $original_alt === $stored_review['alt_at_review'], 'Proposal storage changed the original alternative text.' );

$scanner = new ILSWQ_Scanner();
$row     = $scanner->scan_attachment( $attachment_id, ILSWQ_Settings::get() );
ilswq_ai_assert( 'pending' === $row['ai_review_status'], 'The scan report did not expose the pending review.' );
ilswq_ai_assert( true === $row['has_alt_text'], 'The scan report did not include the current alternative text.' );

$applied = ILSWQ_AI::apply_review( $attachment_id, $review['review_id'] );
if ( is_wp_error( $applied ) ) {
	ilswq_ai_fail( 'Applying the review failed: ' . $applied->get_error_message() );
}
ilswq_ai_assert( 'applied' === $applied['status'], 'The reviewed alternative text was not marked applied.' );
ilswq_ai_assert( $review_result['alt_text'] === ILSWQ_AI::current_alt( $attachment_id ), 'The reviewed alternative text was not saved.' );

$restored = ILSWQ_AI::undo_review( $attachment_id, $review['review_id'] );
if ( is_wp_error( $restored ) ) {
	ilswq_ai_fail( 'Restoring the review failed: ' . $restored->get_error_message() );
}
ilswq_ai_assert( 'restored' === $restored['status'], 'The review was not marked restored.' );
ilswq_ai_assert( $original_alt === ILSWQ_AI::current_alt( $attachment_id ), 'The previous alternative text was not restored exactly.' );

// Replaced images and concurrent edits must invalidate the pending proposal.
$review = ILSWQ_AI::review_attachment( $attachment_id );
ilswq_ai_assert( ! is_wp_error( $review ), 'Could not prepare the stale-image regression.' );
$original_bytes = file_get_contents( $image_path );
file_put_contents( $image_path, $original_bytes . 'changed' );
$stale = ILSWQ_AI::apply_review( $attachment_id, $review['review_id'] );
ilswq_ai_assert( is_wp_error( $stale ) && 'ilswq_ai_conflict' === $stale->get_error_code(), 'A proposal was applied to a replaced source image.' );
file_put_contents( $image_path, $original_bytes );
update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Newer manual text' );
$stale = ILSWQ_AI::apply_review( $attachment_id, $review['review_id'] );
ilswq_ai_assert( is_wp_error( $stale ), 'A proposal overwrote a newer manual edit.' );
update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_slash( $original_alt ) );

$during_request = static function () use ( $attachment_id ) {
	update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Edited during request' );
};
$stale = ILSWQ_AI::review_attachment( $attachment_id );
ilswq_ai_assert( is_wp_error( $stale ), 'A review accepted alternative text edited during its request.' );
$during_request = static function () use ( $image_path, $original_bytes ) {
	file_put_contents( $image_path, $original_bytes . 'changed during request' );
};
$stale = ILSWQ_AI::review_attachment( $attachment_id );
ilswq_ai_assert( is_wp_error( $stale ), 'A review accepted a source replaced during its request.' );
$during_request = null;
file_put_contents( $image_path, $original_bytes );
update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_slash( $original_alt ) );

// Malformed provider output must not become an empty or misleading proposal.
$valid_body = $mock_body;
foreach ( array( array( 'alt_text', null ), array( 'alt_text', '' ), array( 'context_note', array() ), array( 'privacy_note', false ), array( 'privacy_flags', array( 'unsupported' ) ) ) as $invalid ) {
	$bad_result = $review_result;
	$bad_result[ $invalid[0] ] = $invalid[1];
	$mock_body['output'][0]['content'][0]['text'] = wp_json_encode( $bad_result );
	$bad_review = ILSWQ_AI::review_attachment( $attachment_id );
	ilswq_ai_assert( is_wp_error( $bad_review ) && 'ilswq_ai_schema' === $bad_review->get_error_code(), 'Invalid provider field accepted: ' . $invalid[0] );
}
$mock_body = $valid_body;
$mock_body['status'] = 'incomplete';
ilswq_ai_assert( is_wp_error( ILSWQ_AI::review_attachment( $attachment_id ) ), 'Incomplete provider output became a proposal.' );
$mock_status = 401;
$mock_body = array( 'error' => array( 'message' => 'Invalid API key: sk-test-secret-value' ) );
$error = ILSWQ_AI::review_attachment( $attachment_id );
ilswq_ai_assert( is_wp_error( $error ) && false === strpos( $error->get_error_message(), 'sk-test-secret-value' ), 'Provider error exposed the API key.' );
$mock_status = 200;
$mock_body = $valid_body;

$before_switch = get_option( ILSWQ_AI::OPTION );
try {
	ILSWQ_AI::save_settings( array( 'enabled' => 1, 'provider' => 'xai', 'model' => 'grok-test-vision', 'api_key' => '' ) );
	ilswq_ai_fail( 'Switching providers reused the previous provider key.' );
} catch ( InvalidArgumentException $exception ) {
	ilswq_ai_assert( $before_switch === get_option( ILSWQ_AI::OPTION ), 'A failed provider switch changed the saved settings.' );
}

$settings = ILSWQ_AI::save_settings(
	array(
		'enabled'     => 1,
		'provider'    => 'xai',
		'model'       => 'grok-test-vision',
		'api_key'     => 'xai-test-secret-value',
		'use_context' => 0,
		'batch_size'  => 5,
	)
);
ilswq_ai_assert( 'xai' === $settings['provider'], 'The Grok provider was not saved.' );
$xai_review = ILSWQ_AI::review_attachment( $attachment_id );
if ( is_wp_error( $xai_review ) ) {
	ilswq_ai_fail( 'Grok-compatible review failed: ' . $xai_review->get_error_message() );
}
$last_call = $http_calls[ count( $http_calls ) - 1 ];
ilswq_ai_assert( 'https://api.x.ai/v1/responses' === $last_call['url'], 'Grok reviewed through the wrong endpoint.' );
ilswq_ai_assert( 'Bearer xai-test-secret-value' === $last_call['args']['headers']['Authorization'], 'The new provider did not receive its own key.' );
$xai_body = json_decode( $last_call['args']['body'], true );
ilswq_ai_assert( false === strpos( $xai_body['input'][0]['content'][0]['text'], 'Autumn Product Guide' ), 'Context was sent while context sharing was disabled.' );

$dismissed = ILSWQ_AI::dismiss_review( $attachment_id, $xai_review['review_id'] );
if ( is_wp_error( $dismissed ) ) {
	ilswq_ai_fail( 'Dismissing the Grok review failed: ' . $dismissed->get_error_message() );
}
ilswq_ai_assert( 'dismissed' === $dismissed['status'], 'The Grok review was not dismissed.' );

$settings = ILSWQ_AI::save_settings(
	array(
		'enabled'     => 1,
		'provider'    => 'anthropic',
		'model'       => 'claude-test-vision',
		'api_key'     => 'claude-test-secret-value',
		'use_context' => 1,
		'batch_size'  => 5,
	)
);
ilswq_ai_assert( 'anthropic' === $settings['provider'], 'The Claude provider was not saved.' );
$mock_body = array(
	'content' => array(
		array(
			'type'  => 'tool_use',
			'name'  => 'record_media_review',
			'input' => array(
				'alt_text'        => '',
				'decorative'      => true,
				'context_match'   => 'not_applicable',
				'context_note'    => '',
				'privacy_flags'   => array( 'email' ),
				'privacy_note'    => 'An email address is visible in the image.',
				'review_priority' => 'attention',
			),
		),
	),
);
$claude_review = ILSWQ_AI::review_attachment( $attachment_id );
if ( is_wp_error( $claude_review ) ) {
	ilswq_ai_fail( 'Claude review failed: ' . $claude_review->get_error_message() );
}
$last_call = $http_calls[ count( $http_calls ) - 1 ];
$claude_body = json_decode( (string) $last_call['args']['body'], true );
ilswq_ai_assert( 'https://api.anthropic.com/v1/messages' === $last_call['url'], 'Claude reviewed through the wrong endpoint.' );
ilswq_ai_assert( 'claude-test-secret-value' === $last_call['args']['headers']['x-api-key'], 'The Claude x-api-key header was not set.' );
ilswq_ai_assert( 'record_media_review' === $claude_body['tool_choice']['name'], 'Claude did not force the structured review tool.' );
ilswq_ai_assert( true === $claude_review['decorative'] && '' === $claude_review['alt_text'], 'The decorative image result was not preserved.' );
ilswq_ai_assert( true === $claude_review['needs_attention'], 'The privacy finding did not need attention.' );

$applied = ILSWQ_AI::apply_review( $attachment_id, $claude_review['review_id'] );
ilswq_ai_assert( ! is_wp_error( $applied ) && '' === ILSWQ_AI::current_alt( $attachment_id ), 'A decorative proposal did not clear alternative text.' );
update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Keep this newer text' );
ilswq_ai_assert( is_wp_error( ILSWQ_AI::undo_review( $attachment_id, $claude_review['review_id'] ) ), 'Restore overwrote a later manual edit.' );

// A broken encrypted key must not leak a temporary preview or leave a lock.
$broken_settings = get_option( ILSWQ_AI::OPTION );
$broken_settings['key_encrypted'] = 'damaged';
update_option( ILSWQ_AI::OPTION, $broken_settings, false );
$previews_before = glob( get_temp_dir() . 'ilswq-ai-review-*' );
$broken_review = ILSWQ_AI::review_attachment( $attachment_id );
ilswq_ai_assert( is_wp_error( $broken_review ), 'A damaged key was accepted.' );
ilswq_ai_assert( $previews_before === glob( get_temp_dir() . 'ilswq-ai-review-*' ), 'A failed key lookup left a temporary preview.' );
ilswq_ai_assert( false === get_option( ILSWQ_AI::REVIEW_LOCK . $attachment_id ), 'A failed review left its lock.' );

ILSWQ_AI::save_settings( array( 'provider' => 'openai', 'enabled' => 0 ) );
ilswq_ai_assert( empty( get_option( ILSWQ_AI::OPTION )['key_encrypted'] ), 'A disabled provider switch retained another provider key.' );

remove_filter( 'pre_http_request', $mock_http, 10 );

foreach ( array_reverse( $created_posts ) as $post_id ) {
	if ( 'attachment' === get_post_type( $post_id ) ) {
		wp_delete_attachment( $post_id, true );
	} else {
		wp_delete_post( $post_id, true );
	}
}

if ( false === $original_option ) {
	delete_option( ILSWQ_AI::OPTION );
} else {
	update_option( ILSWQ_AI::OPTION, $original_option, false );
}

fwrite( STDOUT, "Media Review WordPress tests passed.\n" );

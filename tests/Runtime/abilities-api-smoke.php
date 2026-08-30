<?php
/**
 * Native REST smoke proof for PreviewShare Abilities API registration.
 *
 * Run through WP-CLI inside an isolated wp-env instance.
 *
 * @package PreviewShare
 */

/**
 * Stop the runtime proof with a concise assertion error.
 *
 * @param bool   $condition Assertion result.
 * @param string $message Assertion failure message.
 * @return void
 */
function previewshare_abilities_runtime_assert( $condition, $message ) {
	if ( $condition ) {
		return;
	}

	fwrite( STDERR, "PreviewShare Abilities runtime proof failed: {$message}\n" );
	exit( 1 );
}

/**
 * Dispatch an Abilities REST request through WordPress Core.
 *
 * @param string     $method HTTP method.
 * @param string     $route REST route.
 * @param array|null $input Ability input.
 * @return WP_REST_Response
 */
function previewshare_abilities_runtime_request( $method, $route, $input = null ) {
	$request = new WP_REST_Request( $method, $route );

	if ( 'POST' === $method ) {
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'input' => $input ] ) );
	} elseif ( null !== $input ) {
		$request->set_query_params( [ 'input' => $input ] );
	}

	$response = rest_do_request( $request );

	if ( is_wp_error( $response ) ) {
		return rest_convert_error_to_response( $response );
	}

	return $response;
}

/**
 * Assert an expected REST status code.
 *
 * @param WP_REST_Response $response REST response.
 * @param int              $status Expected status code.
 * @param string           $context Assertion context.
 * @return void
 */
function previewshare_abilities_runtime_assert_status( $response, $status, $context ) {
	previewshare_abilities_runtime_assert(
		$status === $response->get_status(),
		"{$context} returned {$response->get_status()}, expected {$status}."
	);
}

/**
 * Extract a generated token from a PreviewShare URL for redaction assertions.
 *
 * @param string $url Generated preview URL.
 * @return string
 */
function previewshare_abilities_runtime_token_from_url( $url ) {
	$parts = wp_parse_url( $url );
	$query = [];

	if ( isset( $parts['query'] ) ) {
		parse_str( $parts['query'], $query );
	}

	if ( isset( $query['previewshare_token'] ) ) {
		return (string) $query['previewshare_token'];
	}

	return basename( trim( $parts['path'] ?? '', '/' ) );
}

rest_get_server();

if ( version_compare( get_bloginfo( 'version' ), '6.9', '<' ) ) {
	previewshare_abilities_runtime_assert(
		! class_exists( 'WP_Ability' ) && ! function_exists( 'wp_register_ability' ),
		'Abilities API unexpectedly exists before WordPress 6.9.'
	);

	$absent_response = previewshare_abilities_runtime_request(
		'GET',
		'/wp-abilities/v1/abilities'
	);
	previewshare_abilities_runtime_assert_status(
		$absent_response,
		404,
		'Absent Abilities API endpoint'
	);

	echo 'PREVIEWSHARE_ABILITIES_RUNTIME_RECEIPT=' . wp_json_encode(
		[
			'assertions' => [ 'core_api_absent', 'abilities_route_404' ],
			'mode'       => 'compatibility',
			'wordpress'  => get_bloginfo( 'version' ),
		]
	) . "\n";
	return;
}

previewshare_abilities_runtime_assert(
	class_exists( 'WP_Ability' ) && function_exists( 'wp_has_ability' ),
	'Core Abilities API is unavailable on a supported WordPress version.'
);
previewshare_abilities_runtime_assert(
	wp_has_ability( 'previewshare/generate-preview-link' ),
	'PreviewShare generate ability was not registered.'
);

$admins = get_users(
	[
		'role'   => 'administrator',
		'number' => 1,
	]
);
previewshare_abilities_runtime_assert( ! empty( $admins ), 'Fixture has no administrator.' );

$admin_id      = (int) $admins[0]->ID;
$run_identifier = wp_generate_uuid4();
$editor_id     = wp_create_user(
	'previewshare-runtime-editor-' . $run_identifier,
	wp_generate_password( 32, true, true ),
	'previewshare-runtime-editor-' . $run_identifier . '@example.test'
);
$subscriber_id = wp_create_user(
	'previewshare-runtime-subscriber-' . $run_identifier,
	wp_generate_password( 32, true, true ),
	'previewshare-runtime-subscriber-' . $run_identifier . '@example.test'
);
previewshare_abilities_runtime_assert(
	! is_wp_error( $editor_id ) && ! is_wp_error( $subscriber_id ),
	'Could not create isolated proof users.'
);

( new WP_User( $editor_id ) )->set_role( 'editor' );
( new WP_User( $subscriber_id ) )->set_role( 'subscriber' );

$post_id = wp_insert_post(
	[
		'post_author'  => $admin_id,
		'post_content' => 'PreviewShare Abilities API runtime proof.',
		'post_status'  => 'draft',
		'post_title'   => 'PreviewShare Abilities runtime proof',
		'post_type'    => 'post',
	],
	true
);
previewshare_abilities_runtime_assert( ! is_wp_error( $post_id ), 'Could not create draft post.' );

wp_set_current_user( 0 );
$anonymous_response = previewshare_abilities_runtime_request(
	'GET',
	'/wp-abilities/v1/abilities'
);
previewshare_abilities_runtime_assert_status(
	$anonymous_response,
	401,
	'Anonymous ability discovery'
);

wp_set_current_user( $subscriber_id );
$subscriber_response = previewshare_abilities_runtime_request(
	'POST',
	'/wp-abilities/v1/abilities/previewshare/generate-preview-link/run',
	[ 'post_id' => $post_id ]
);
previewshare_abilities_runtime_assert_status(
	$subscriber_response,
	403,
	'Insufficient user generation'
);

wp_set_current_user( $admin_id );
$category_response = previewshare_abilities_runtime_request(
	'GET',
	'/wp-abilities/v1/categories/previewshare'
);
previewshare_abilities_runtime_assert_status(
	$category_response,
	200,
	'PreviewShare ability category discovery'
);
previewshare_abilities_runtime_assert(
	'previewshare' === $category_response->get_data()['slug'],
	'PreviewShare category slug was not returned.'
);

$schema_response = previewshare_abilities_runtime_request(
	'GET',
	'/wp-abilities/v1/abilities/previewshare/generate-preview-link'
);
previewshare_abilities_runtime_assert_status(
	$schema_response,
	200,
	'PreviewShare generate ability discovery'
);
$schema = $schema_response->get_data();
previewshare_abilities_runtime_assert(
	isset( $schema['input_schema']['required'] ) && in_array( 'post_id', $schema['input_schema']['required'], true ),
	'Generate ability schema does not require post_id.'
);
previewshare_abilities_runtime_assert(
	isset( $schema['meta']['show_in_rest'] ) && $schema['meta']['show_in_rest'],
	'Generate ability is not exposed through the REST API.'
);

wp_set_current_user( $editor_id );
$generate_response = previewshare_abilities_runtime_request(
	'POST',
	'/wp-abilities/v1/abilities/previewshare/generate-preview-link/run',
	[
		'label'   => 'Runtime proof',
		'post_id' => $post_id,
	]
);
previewshare_abilities_runtime_assert_status(
	$generate_response,
	200,
	'Editor preview generation'
);
$generated = $generate_response->get_data();
previewshare_abilities_runtime_assert(
	isset( $generated['token_id'], $generated['url'] ),
	'Generate ability did not return link data.'
);
$raw_token = previewshare_abilities_runtime_token_from_url( $generated['url'] );
previewshare_abilities_runtime_assert( '' !== $raw_token, 'Generated URL has no preview token.' );

$editor_list_response = previewshare_abilities_runtime_request(
	'GET',
	'/wp-abilities/v1/abilities/previewshare/list-preview-links/run',
	[
		'page'     => 1,
		'per_page' => 10,
	]
);
previewshare_abilities_runtime_assert_status(
	$editor_list_response,
	403,
	'Editor inventory access'
);

wp_set_current_user( $admin_id );
$list_response = previewshare_abilities_runtime_request(
	'GET',
	'/wp-abilities/v1/abilities/previewshare/list-preview-links/run',
	[
		'page'     => 1,
		'per_page' => 10,
	]
);
previewshare_abilities_runtime_assert_status(
	$list_response,
	200,
	'Administrator inventory access'
);
$inventory = $list_response->get_data();
previewshare_abilities_runtime_assert(
	false === strpos( wp_json_encode( $inventory ), $raw_token ),
	'Inventory response exposes the raw preview token.'
);

$revoke_response = previewshare_abilities_runtime_request(
	'DELETE',
	'/wp-abilities/v1/abilities/previewshare/revoke-preview-link/run',
	[ 'token_id' => $generated['token_id'] ]
);
previewshare_abilities_runtime_assert_status(
	$revoke_response,
	200,
	'Administrator link revocation'
);
$revoked = $revoke_response->get_data();
previewshare_abilities_runtime_assert(
	! empty( $revoked['revoked'] ) && 'revoked' === $revoked['status'],
	'Revoke ability did not return a revoked result.'
);
previewshare_abilities_runtime_assert(
	false === strpos( wp_json_encode( $revoked ), $raw_token ),
	'Revocation response exposes the raw preview token.'
);

echo 'PREVIEWSHARE_ABILITIES_RUNTIME_RECEIPT=' . wp_json_encode(
	[
		'assertions' => [
			'anonymous_denied',
			'insufficient_user_denied',
			'category_discovered',
			'ability_schema_discovered',
			'editor_generated_preview',
			'editor_inventory_denied',
			'administrator_inventory_redacted',
			'administrator_revocation_redacted',
		],
		'mode'       => 'native',
		'wordpress'  => get_bloginfo( 'version' ),
	]
) . "\n";

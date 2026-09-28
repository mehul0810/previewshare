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
$run_identifier = wp_generate_password( 12, false, false );
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
		'post_author'  => $editor_id,
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

$list_schema_response = previewshare_abilities_runtime_request(
	'GET',
	'/wp-abilities/v1/abilities/previewshare/list-preview-links'
);
previewshare_abilities_runtime_assert_status(
	$list_schema_response,
	200,
	'PreviewShare list ability discovery'
);
$list_schema = $list_schema_response->get_data();
previewshare_abilities_runtime_assert(
	isset( $list_schema['input_schema']['properties']['post_id'] )
		&& isset( $list_schema['input_schema']['properties']['status'] ),
	'List ability schema is missing post-scoped or status filters.'
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
		'post_id'  => $post_id,
		'status'   => 'active',
	]
);
previewshare_abilities_runtime_assert_status(
	$editor_list_response,
	200,
	'Editor post-scoped inventory access'
);
$editor_inventory = $editor_list_response->get_data();
previewshare_abilities_runtime_assert(
	isset( $editor_inventory['items'][0]['token_id'] )
		&& $editor_inventory['items'][0]['token_id'] === $generated['token_id'],
	'Editor inventory did not return the link for the editable post.'
);
previewshare_abilities_runtime_assert(
	false === strpos( wp_json_encode( $editor_inventory ), $raw_token ),
	'Editor inventory response exposes the raw preview token.'
);

$editor_global_list_response = previewshare_abilities_runtime_request(
	'GET',
	'/wp-abilities/v1/abilities/previewshare/list-preview-links/run',
	[
		'page'     => 1,
		'per_page' => 10,
	]
);
previewshare_abilities_runtime_assert_status(
	$editor_global_list_response,
	403,
	'Editor global inventory access'
);

wp_set_current_user( $subscriber_id );
$foreign_list_response = previewshare_abilities_runtime_request(
	'GET',
	'/wp-abilities/v1/abilities/previewshare/list-preview-links/run',
	[
		'post_id'  => $post_id,
		'page'     => 1,
		'per_page' => 10,
	]
);
previewshare_abilities_runtime_assert_status(
	$foreign_list_response,
	403,
	'User without post access inventory request'
);

wp_set_current_user( $editor_id );
$editor_revoke_response = previewshare_abilities_runtime_request(
	'DELETE',
	'/wp-abilities/v1/abilities/previewshare/revoke-preview-link/run',
	[
		'post_id'  => $post_id,
		'token_id' => $generated['token_id'],
	]
);
previewshare_abilities_runtime_assert_status(
	$editor_revoke_response,
	200,
	'Editor owner-post link revocation'
);
$editor_revoked = $editor_revoke_response->get_data();
previewshare_abilities_runtime_assert(
	! empty( $editor_revoked['revoked'] ) && 'revoked' === $editor_revoked['status'],
	'Editor revoke ability did not return a revoked result.'
);
previewshare_abilities_runtime_assert(
	false === strpos( wp_json_encode( $editor_revoked ), $raw_token ),
	'Editor revocation response exposes the raw preview token.'
);

wp_set_current_user( $admin_id );
$admin_generate_response = previewshare_abilities_runtime_request(
	'POST',
	'/wp-abilities/v1/abilities/previewshare/generate-preview-link/run',
	[
		'label'   => 'Administrator runtime proof',
		'post_id' => $post_id,
	]
);
previewshare_abilities_runtime_assert_status(
	$admin_generate_response,
	200,
	'Administrator preview generation'
);
$admin_generated = $admin_generate_response->get_data();
$admin_raw_token = previewshare_abilities_runtime_token_from_url( $admin_generated['url'] );

$status_probe_ids = [];
foreach ( [
	'active-marker'  => '日本語 s:7:"revoked";i:1; s:10:"expires_at";i:1;',
	'expired-boundary' => 'Expired status boundary',
	'revoked-boundary' => 'Revoked status boundary',
] as $probe => $label ) {
	$probe_response = previewshare_abilities_runtime_request(
		'POST',
		'/wp-abilities/v1/abilities/previewshare/generate-preview-link/run',
		[ 'label' => $label, 'post_id' => $post_id ]
	);
	previewshare_abilities_runtime_assert_status( $probe_response, 200, "{$probe} status fixture generation" );
	$status_probe_ids[ $probe ] = $probe_response->get_data()['token_id'];
}

$expired_meta_key = '_previewshare_token:' . $status_probe_ids['expired-boundary'];
$expired_detail   = get_post_meta( $post_id, $expired_meta_key, true );
previewshare_abilities_runtime_assert( is_array( $expired_detail ), 'Expired status fixture metadata is missing.' );
$expired_detail['expires_at'] = time() - 1;
$expired_detail['revoked']    = 0;
update_post_meta( $post_id, $expired_meta_key, $expired_detail );

$revoked_meta_key = '_previewshare_token:' . $status_probe_ids['revoked-boundary'];
$revoked_detail   = get_post_meta( $post_id, $revoked_meta_key, true );
previewshare_abilities_runtime_assert( is_array( $revoked_detail ), 'Revoked status fixture metadata is missing.' );
$revoked_detail['expires_at'] = time() - 1;
$revoked_detail['revoked']    = 1;
update_post_meta( $post_id, $revoked_meta_key, $revoked_detail );

$active_filtered = previewshare_abilities_runtime_request(
	'GET',
	'/wp-abilities/v1/abilities/previewshare/list-preview-links/run',
	[ 'page' => 1, 'per_page' => 1, 'status' => 'active' ]
);
previewshare_abilities_runtime_assert_status( $active_filtered, 200, 'Administrator active filtered inventory' );
$active_data = $active_filtered->get_data();
previewshare_abilities_runtime_assert(
	2 === $active_data['total']
		&& 1 === count( $active_data['items'] )
		&& $status_probe_ids['active-marker'] === $active_data['items'][0]['token_id'],
	'Active filtered page/count did not handle the crafted serialized marker label.'
);
$active_page_two = previewshare_abilities_runtime_request(
	'GET',
	'/wp-abilities/v1/abilities/previewshare/list-preview-links/run',
	[ 'page' => 2, 'per_page' => 1, 'status' => 'active' ]
);
previewshare_abilities_runtime_assert(
	2 === $active_page_two->get_data()['total']
		&& 1 === count( $active_page_two->get_data()['items'] )
		&& $admin_generated['token_id'] === $active_page_two->get_data()['items'][0]['token_id'],
	'Active filtered pagination did not return the second matching link.'
);
$expired_filtered = previewshare_abilities_runtime_request(
	'GET',
	'/wp-abilities/v1/abilities/previewshare/list-preview-links/run',
	[ 'page' => 1, 'per_page' => 1, 'status' => 'expired' ]
);
previewshare_abilities_runtime_assert(
	1 === $expired_filtered->get_data()['total']
		&& $status_probe_ids['expired-boundary'] === $expired_filtered->get_data()['items'][0]['token_id'],
	'Expired status boundary filter or total is incorrect.'
);
$revoked_filtered = previewshare_abilities_runtime_request(
	'GET',
	'/wp-abilities/v1/abilities/previewshare/list-preview-links/run',
	[ 'page' => 1, 'per_page' => 1, 'status' => 'revoked' ]
);
$revoked_data = $revoked_filtered->get_data();
previewshare_abilities_runtime_assert(
	2 === $revoked_data['total']
		&& 1 === count( $revoked_data['items'] )
		&& $status_probe_ids['revoked-boundary'] === $revoked_data['items'][0]['token_id']
		&& ! in_array( $status_probe_ids['revoked-boundary'], array_column( $expired_filtered->get_data()['items'], 'token_id' ), true ),
	'Revocation did not take precedence over an expired timestamp.'
);
$revoked_page_two = previewshare_abilities_runtime_request(
	'GET',
	'/wp-abilities/v1/abilities/previewshare/list-preview-links/run',
	[ 'page' => 2, 'per_page' => 1, 'status' => 'revoked' ]
);
previewshare_abilities_runtime_assert(
	2 === $revoked_page_two->get_data()['total']
		&& 1 === count( $revoked_page_two->get_data()['items'] )
		&& $generated['token_id'] === $revoked_page_two->get_data()['items'][0]['token_id'],
	'Revoked filtered pagination did not return the second matching link.'
);

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
	false === strpos( wp_json_encode( $inventory ), $raw_token )
		&& false === strpos( wp_json_encode( $inventory ), $admin_raw_token ),
	'Inventory response exposes a raw preview token.'
);

$malformed_revoke_response = previewshare_abilities_runtime_request(
	'DELETE',
	'/wp-abilities/v1/abilities/previewshare/revoke-preview-link/run',
	[ 'token_id' => 'not-a-hash' ]
);
previewshare_abilities_runtime_assert_status(
	$malformed_revoke_response,
	400,
	'Malformed token identifier revocation'
);

$revoke_response = previewshare_abilities_runtime_request(
	'DELETE',
	'/wp-abilities/v1/abilities/previewshare/revoke-preview-link/run',
	[ 'token_id' => $admin_generated['token_id'] ]
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
	false === strpos( wp_json_encode( $revoked ), $admin_raw_token ),
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
			'editor_post_scoped_inventory',
			'editor_global_inventory_denied',
			'foreign_post_inventory_denied',
			'editor_owner_post_revocation',
			'malformed_token_identifier_rejected',
			'administrator_inventory_redacted',
			'status_filtered_page_count',
			'expired_revoked_precedence',
			'utf8_serialized_marker_label',
			'revoked_filtered_pagination',
			'administrator_revocation_redacted',
		],
		'mode'       => 'native',
		'wordpress'  => get_bloginfo( 'version' ),
	]
) . "\n";

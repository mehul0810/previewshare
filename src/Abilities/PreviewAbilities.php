<?php
/**
 * WordPress Abilities API integration for PreviewShare.
 *
 * @package PreviewShare
 */

namespace PreviewShare\Abilities;

use PreviewShare\Services\PostMetaStorage;
use PreviewShare\Services\TokenService;

// Abort if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the optional PreviewShare abilities.
 *
 * The Abilities API is available from WordPress 6.9. This class deliberately
 * has no dependency on its classes so older WordPress versions continue to
 * load PreviewShare without registering hooks or calling unavailable APIs.
 */
final class PreviewAbilities {

	/**
	 * PreviewShare ability category.
	 */
	private const CATEGORY = 'previewshare';

	/**
	 * Storage driver.
	 *
	 * @var PostMetaStorage
	 */
	private $storage;

	/**
	 * Token service.
	 *
	 * @var TokenService
	 */
	private $token_service;

	/**
	 * Set up Abilities API registration when WordPress supports it.
	 *
	 * @param TokenService    $token_service Token helper.
	 * @param PostMetaStorage $storage Storage driver.
	 */
	public function __construct( TokenService $token_service, PostMetaStorage $storage ) {
		$this->token_service = $token_service;
		$this->storage       = $storage;

		if ( ! self::is_available() ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
	}

	/**
	 * Check whether the WordPress Abilities API can be used safely.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return function_exists( 'wp_register_ability_category' )
			&& function_exists( 'wp_register_ability' )
			&& class_exists( 'WP_Ability' );
	}

	/**
	 * Register the PreviewShare ability category.
	 *
	 * @return void
	 */
	public function register_category(): void {
		if ( ! self::is_available() ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			[
				'label'       => __( 'PreviewShare', 'previewshare' ),
				'description' => __( 'Generate, inventory, and revoke secure PreviewShare links.', 'previewshare' ),
			]
		);
	}

	/**
	 * Register PreviewShare abilities.
	 *
	 * @return void
	 */
	public function register_abilities(): void {
		if ( ! self::is_available() ) {
			return;
		}

		wp_register_ability(
			'previewshare/generate-preview-link',
			[
				'label'               => __( 'Generate Preview Link', 'previewshare' ),
				'description'         => __( 'Generates a secure PreviewShare link for an enabled post type.', 'previewshare' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->generate_input_schema(),
				'output_schema'       => $this->generate_output_schema(),
				'execute_callback'    => [ $this, 'generate_preview_link' ],
				'permission_callback' => [ $this, 'can_generate_preview_link' ],
				'meta'                => [
					'annotations' => [
						'readonly'    => false,
						'destructive' => false,
					],
					'public'      => true,
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'previewshare/list-preview-links',
			[
				'label'               => __( 'List Preview Links', 'previewshare' ),
				'description'         => __( 'Lists PreviewShare links available in the administrator inventory.', 'previewshare' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->list_input_schema(),
				'output_schema'       => $this->list_output_schema(),
				'execute_callback'    => [ $this, 'list_preview_links' ],
				'permission_callback' => [ $this, 'can_list_preview_links' ],
				'meta'                => [
					'annotations' => [
						'readonly' => true,
					],
					'public'      => true,
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'previewshare/revoke-preview-link',
			[
				'label'               => __( 'Revoke Preview Link', 'previewshare' ),
				'description'         => __( 'Revokes a PreviewShare link using its opaque inventory identifier.', 'previewshare' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->revoke_input_schema(),
				'output_schema'       => $this->revoke_output_schema(),
				'execute_callback'    => [ $this, 'revoke_preview_link' ],
				'permission_callback' => [ $this, 'can_revoke_preview_link' ],
				'meta'                => [
					'annotations' => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
					],
					'public'      => true,
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Check permission to generate a link for a post.
	 *
	 * @param mixed $input Ability input.
	 * @return bool|\WP_Error
	 */
	public function can_generate_preview_link( $input ) {
		$post_id = $this->get_post_id_from_input( $input );

		if ( ! $post_id ) {
			return $this->error( 'previewshare_invalid_post', __( 'A valid post ID is required.', 'previewshare' ), 400 );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return $this->error( 'previewshare_forbidden', __( 'You cannot generate a preview link for this post.', 'previewshare' ), 403 );
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return $this->error( 'previewshare_invalid_post', __( 'The requested post does not exist.', 'previewshare' ), 400 );
		}

		if ( ! \previewshare_is_supported_post_type( $post->post_type ) ) {
			return $this->error( 'previewshare_unsupported_post_type', __( 'PreviewShare is not enabled for this post type.', 'previewshare' ), 400 );
		}

		return true;
	}

	/**
	 * Check administrator permission for the existing token inventory actions.
	 *
	 * @param mixed $input Ability input.
	 * @return bool|\WP_Error
	 */
	public function can_manage_preview_links( $input = null ) {
		unset( $input );

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return $this->error( 'previewshare_forbidden', __( 'You cannot manage PreviewShare links.', 'previewshare' ), 403 );
	}

	/**
	 * Check permission for a post-scoped or administrator-wide inventory.
	 *
	 * @param mixed $input Ability input.
	 * @return bool|\\WP_Error
	 */
	public function can_list_preview_links( $input ) {
		if ( ! is_array( $input ) ) {
			return $this->error( 'previewshare_invalid_input', __( 'Preview link list input must be an object.', 'previewshare' ), 400 );
		}

		if ( ! array_key_exists( 'post_id', $input ) ) {
			return $this->can_manage_preview_links();
		}

		$post_id = $this->get_post_id_from_input( $input );

		if ( ! $post_id ) {
			return $this->error( 'previewshare_invalid_post', __( 'A valid post ID is required.', 'previewshare' ), 400 );
		}

		if ( ! get_post( $post_id ) ) {
			return $this->error( 'previewshare_invalid_post', __( 'The requested post does not exist.', 'previewshare' ), 404 );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return $this->error( 'previewshare_forbidden', __( 'You cannot list links for this post.', 'previewshare' ), 403 );
		}

		return true;
	}

	/**
	 * Check permission to revoke a link owned by an editable post or by an administrator.
	 *
	 * @param mixed $input Ability input.
	 * @return bool|\\WP_Error
	 */
	public function can_revoke_preview_link( $input ) {
		if ( ! is_array( $input ) || ! isset( $input['token_id'] ) || ! $this->is_valid_token_id( $input['token_id'] ) ) {
			return $this->error( 'previewshare_invalid_token_id', __( 'The preview link identifier is invalid.', 'previewshare' ), 400 );
		}

		$link = $this->storage->get_token_context_by_id( $input['token_id'] );

		if ( ! $link ) {
			return $this->error( 'previewshare_preview_link_not_found', __( 'The preview link could not be found.', 'previewshare' ), 404 );
		}

		if ( array_key_exists( 'post_id', $input ) && $this->get_post_id_from_input( $input ) !== (int) $link['post_id'] ) {
			return $this->error( 'previewshare_preview_link_not_found', __( 'The preview link could not be found.', 'previewshare' ), 404 );
		}

		if ( current_user_can( 'edit_post', (int) $link['post_id'] ) || current_user_can( 'manage_options' ) ) {
			return true;
		}

		return $this->error( 'previewshare_forbidden', __( 'You cannot revoke this preview link.', 'previewshare' ), 403 );
	}


	/**
	 * Generate a preview link through the existing domain service.
	 *
	 * @param mixed $input Ability input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function generate_preview_link( $input ) {
		$post_id = $this->get_post_id_from_input( $input );

		if ( ! $post_id ) {
			return $this->error( 'previewshare_invalid_post', __( 'A valid post ID is required.', 'previewshare' ), 400 );
		}

		$input  = is_array( $input ) ? $input : [];
		$ttl    = array_key_exists( 'ttl_hours', $input ) ? absint( $input['ttl_hours'] ) : null;
		$label  = isset( $input['label'] ) ? (string) $input['label'] : '';
		$result = \previewshare_generate_preview_link( $post_id, $ttl, $label );

		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		$token_id = $this->token_service->hash( $result['token'] );
		$link     = $this->storage->get_token_context_by_id( $token_id );

		if ( ! $link ) {
			return $this->error( 'previewshare_token_lookup_failed', __( 'The generated preview link could not be verified.', 'previewshare' ), 500 );
		}

		return [
			'url'        => (string) $result['url'],
			'token_id'   => $link['id'],
			'post_id'    => $link['post_id'],
			'status'     => $link['status'],
			'expires_at' => $link['expires_at'],
			'label'      => $link['label'],
		];
	}

	/**
	 * List the existing administrator token inventory.
	 *
	 * @param mixed $input Ability input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function list_preview_links( $input ) {
		if ( ! is_array( $input ) ) {
			return $this->error( 'previewshare_invalid_input', __( 'Preview link list input must be an object.', 'previewshare' ), 400 );
		}

		$permission = $this->can_list_preview_links( $input );

		if ( true !== $permission ) {
			return $permission;
		}

		$requested_per_page = isset( $input['per_page'] ) ? absint( $input['per_page'] ) : 50;
		$requested_page     = isset( $input['page'] ) ? absint( $input['page'] ) : 1;
		$per_page           = min( 100, $requested_per_page ? $requested_per_page : 50 );
		$page               = $requested_page ? $requested_page : 1;
		$post_id            = $this->get_post_id_from_input( $input );
		$status             = isset( $input['status'] ) ? (string) $input['status'] : '';
		$allowed_statuses   = [ 'active', 'expired', 'revoked' ];

		if ( '' !== $status && ! in_array( $status, $allowed_statuses, true ) ) {
			return $this->error( 'previewshare_invalid_status', __( 'The requested preview link status is invalid.', 'previewshare' ), 400 );
		}

		$offset = ( $page - 1 ) * $per_page;
		$items  = [];
		$total  = 0;

		if ( $post_id ) {
			$metadata = $this->storage->get_token_meta( $post_id );
			$rows     = $metadata && isset( $metadata['links'] ) ? $metadata['links'] : [];

			foreach ( $rows as $row ) {
				$item = $this->format_link_list_item( $row, $post_id );

				if ( '' !== $status && $status !== $item['status'] ) {
					continue;
				}

				if ( $total >= $offset && count( $items ) < $per_page ) {
					$items[] = $item;
				}

				++$total;
			}
		} elseif ( '' === $status ) {
			$rows = $this->storage->list_tokens( $per_page, $page );

			foreach ( $rows as $row ) {
				$items[] = $this->format_link_list_item( $row, (int) ( $row['post_id'] ?? 0 ) );
			}

			$total = $this->storage->count_tokens();
		} else {
			// Status is derived from serialized per-link metadata and expiry time.
			// Scan bounded database pages so the returned total and page are exact.
			$inventory_total = $this->storage->count_tokens();
			$batch_pages     = (int) ceil( $inventory_total / 100 );

			for ( $batch_page = 1; $batch_page <= $batch_pages; ++$batch_page ) {
				$rows = $this->storage->list_tokens( 100, $batch_page );

				foreach ( $rows as $row ) {
					$item = $this->format_link_list_item( $row, (int) ( $row['post_id'] ?? 0 ) );

					if ( $status !== $item['status'] ) {
						continue;
					}

					if ( $total >= $offset && count( $items ) < $per_page ) {
						$items[] = $item;
					}

					++$total;
				}
			}
		}

		return [
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		];
	}

	/**
	 * Revoke one link through the existing administrator revoke path.
	 *
	 * @param mixed $input Ability input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function revoke_preview_link( $input ) {
		$permission = $this->can_revoke_preview_link( $input );

		if ( true !== $permission ) {
			return $permission;
		}

		$token_id = $input['token_id'];
		$link     = $this->storage->get_token_context_by_id( $token_id );

		if ( ! $link ) {
			return $this->error( 'previewshare_preview_link_not_found', __( 'The preview link could not be found.', 'previewshare' ), 404 );
		}

		if ( ! $this->storage->revoke_token_by_id( $token_id ) ) {
			return $this->error( 'previewshare_revoke_failed', __( 'The preview link could not be revoked.', 'previewshare' ), 500 );
		}

		return [
			'token_id' => $link['id'],
			'post_id'  => $link['post_id'],
			'status'   => 'revoked',
			'revoked'  => true,
		];
	}

	/**
	 * Build the generation input schema.
	 *
	 * @return array<string,mixed>
	 */
	private function generate_input_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'post_id'   => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the post to share.', 'previewshare' ),
				],
				'ttl_hours' => [
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'Hours before expiry. Zero means no expiry.', 'previewshare' ),
				],
				'label'      => [
					'type'        => 'string',
					'description' => __( 'Optional label for the preview link.', 'previewshare' ),
				],
			],
			'required'             => [ 'post_id' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Build the generation output schema.
	 *
	 * @return array<string,mixed>
	 */
	private function generate_output_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => array_merge(
				$this->link_output_properties(),
				[
					'url' => [
						'type'        => 'string',
						'format'      => 'uri',
						'description' => __( 'Secure preview URL.', 'previewshare' ),
					],
				]
			),
			'required'             => [ 'url', 'token_id', 'post_id', 'status', 'expires_at', 'label' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Build the list input schema.
	 *
	 * @return array<string,mixed>
	 */
	private function list_input_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'post_id'  => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Limit the inventory to a post the current user can edit.', 'previewshare' ),
				],
				'status'   => [
					'type' => 'string',
					'enum' => [ 'active', 'expired', 'revoked' ],
				],
				'page'     => [
					'type'    => 'integer',
					'minimum' => 1,
				],
				'per_page' => [
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
				],
			],
			'additionalProperties' => false,
		];
	}

	/**
	 * Build the list output schema.
	 *
	 * @return array<string,mixed>
	 */
	private function list_output_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'items'    => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'properties'           => $this->link_output_properties(),
						'required'             => [ 'token_id', 'post_id', 'status', 'expires_at', 'label', 'created_at' ],
						'additionalProperties' => false,
					],
				],
				'total'    => [
					'type'    => 'integer',
					'minimum' => 0,
				],
				'page'     => [
					'type'    => 'integer',
					'minimum' => 1,
				],
				'per_page' => [
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
				],
			],
			'required'             => [ 'items', 'total', 'page', 'per_page' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Build the revoke input schema.
	 *
	 * @return array<string,mixed>
	 */
	private function revoke_input_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'token_id' => [
					'type'        => 'string',
					'pattern'     => '^[a-f0-9]{64}
			],
			'required'             => [ 'token_id' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Build the revoke output schema.
	 *
	 * @return array<string,mixed>
	 */
	private function revoke_output_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'token_id' => [
					'type' => 'string',
				],
				'post_id'  => [
					'type'    => 'integer',
					'minimum' => 1,
				],
				'status'   => [
					'type' => 'string',
					'enum' => [ 'revoked' ],
				],
				'revoked'  => [
					'type' => 'boolean',
				],
			],
			'required'             => [ 'token_id', 'post_id', 'status', 'revoked' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Get the reusable schema fields for an opaque preview link reference.
	 *
	 * @return array<string,mixed>
	 */
	private function link_output_properties(): array {
		return [
			'token_id'   => [
				'type'        => 'string',
				'pattern'     => '^[a-f0-9]{64}
			'post_id'    => [
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'ID of the post associated with the link.', 'previewshare' ),
			],
			'label'      => [
				'type'        => 'string',
				'description' => __( 'Preview link label.', 'previewshare' ),
			],
			'created_at' => [
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Unix timestamp when the link was created.', 'previewshare' ),
			],
			'expires_at' => [
				'type'        => [ 'integer', 'null' ],
				'description' => __( 'Unix expiry timestamp, or null when the link does not expire.', 'previewshare' ),
			],
			'status'     => [
				'type' => 'string',
				'enum' => [ 'active', 'expired', 'revoked' ],
			],
		];
	}

	/**
	 * Validate an opaque stored token identifier without normalizing caller input.
	 *
	 * @param mixed $token_id Link identifier.
	 * @return bool
	 */
	private function is_valid_token_id( $token_id ): bool {
		return is_string( $token_id ) && 1 === preg_match( '/\\A[a-f0-9]{64}\\z/', $token_id );
	}

	/**
	 * Format a storage record using the public ability schema.
	 *
	 * @param array<string,mixed> $row Link storage record.
	 * @param int                 $post_id Owning post ID.
	 * @return array<string,mixed>
	 */
	private function format_link_list_item( array $row, int $post_id ): array {
		return [
			'token_id'   => isset( $row['id'] ) ? (string) $row['id'] : (string) ( $row['token_id'] ?? '' ),
			'post_id'    => $post_id,
			'label'      => isset( $row['label'] ) ? (string) $row['label'] : '',
			'created_at' => isset( $row['created_at'] ) ? (int) $row['created_at'] : 0,
			'expires_at' => array_key_exists( 'expires_at', $row ) ? $row['expires_at'] : null,
			'status'     => isset( $row['status'] ) ? (string) $row['status'] : 'active',
		];
	}

	/**
	 * Get a post ID from an ability input object.
	 *
	 * @param mixed $input Ability input.
	 * @return int
	 */
	private function get_post_id_from_input( $input ): int {
		if ( ! is_array( $input ) || ! isset( $input['post_id'] ) ) {
			return 0;
		}

		return absint( $input['post_id'] );
	}

	/**
	 * Build a standard structured error response.
	 *
	 * @param string $code Error code.
	 * @param string $message Error message.
	 * @param int    $status HTTP-style error status.
	 * @return \WP_Error
	 */
	private function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
,
					'description' => __( 'Opaque 64-character lowercase hexadecimal PreviewShare link identifier.', 'previewshare' ),
				],
			],
			'required'             => [ 'token_id' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Build the revoke output schema.
	 *
	 * @return array<string,mixed>
	 */
	private function revoke_output_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'token_id' => [
					'type' => 'string',
				],
				'post_id'  => [
					'type'    => 'integer',
					'minimum' => 1,
				],
				'status'   => [
					'type' => 'string',
					'enum' => [ 'revoked' ],
				],
				'revoked'  => [
					'type' => 'boolean',
				],
			],
			'required'             => [ 'token_id', 'post_id', 'status', 'revoked' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Get the reusable schema fields for an opaque preview link reference.
	 *
	 * @return array<string,mixed>
	 */
	private function link_output_properties(): array {
		return [
			'token_id'   => [
				'type'        => 'string',
				'description' => __( 'Opaque PreviewShare link identifier.', 'previewshare' ),
			],
			'post_id'    => [
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'ID of the post associated with the link.', 'previewshare' ),
			],
			'label'      => [
				'type'        => 'string',
				'description' => __( 'Preview link label.', 'previewshare' ),
			],
			'created_at' => [
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Unix timestamp when the link was created.', 'previewshare' ),
			],
			'expires_at' => [
				'type'        => [ 'integer', 'null' ],
				'description' => __( 'Unix expiry timestamp, or null when the link does not expire.', 'previewshare' ),
			],
			'status'     => [
				'type' => 'string',
				'enum' => [ 'active', 'expired', 'revoked' ],
			],
		];
	}

	/**
	 * Get a post ID from an ability input object.
	 *
	 * @param mixed $input Ability input.
	 * @return int
	 */
	private function get_post_id_from_input( $input ): int {
		if ( ! is_array( $input ) || ! isset( $input['post_id'] ) ) {
			return 0;
		}

		return absint( $input['post_id'] );
	}

	/**
	 * Build a standard structured error response.
	 *
	 * @param string $code Error code.
	 * @param string $message Error message.
	 * @param int    $status HTTP-style error status.
	 * @return \WP_Error
	 */
	private function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
,
				'description' => __( 'Opaque 64-character lowercase hexadecimal PreviewShare link identifier.', 'previewshare' ),
			],
			'post_id'    => [
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'ID of the post associated with the link.', 'previewshare' ),
			],
			'label'      => [
				'type'        => 'string',
				'description' => __( 'Preview link label.', 'previewshare' ),
			],
			'created_at' => [
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Unix timestamp when the link was created.', 'previewshare' ),
			],
			'expires_at' => [
				'type'        => [ 'integer', 'null' ],
				'description' => __( 'Unix expiry timestamp, or null when the link does not expire.', 'previewshare' ),
			],
			'status'     => [
				'type' => 'string',
				'enum' => [ 'active', 'expired', 'revoked' ],
			],
		];
	}

	/**
	 * Get a post ID from an ability input object.
	 *
	 * @param mixed $input Ability input.
	 * @return int
	 */
	private function get_post_id_from_input( $input ): int {
		if ( ! is_array( $input ) || ! isset( $input['post_id'] ) ) {
			return 0;
		}

		return absint( $input['post_id'] );
	}

	/**
	 * Build a standard structured error response.
	 *
	 * @param string $code Error code.
	 * @param string $message Error message.
	 * @param int    $status HTTP-style error status.
	 * @return \WP_Error
	 */
	private function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
,
					'description' => __( 'Opaque 64-character lowercase hexadecimal PreviewShare link identifier.', 'previewshare' ),
				],
			],
			'required'             => [ 'token_id' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Build the revoke output schema.
	 *
	 * @return array<string,mixed>
	 */
	private function revoke_output_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'token_id' => [
					'type' => 'string',
				],
				'post_id'  => [
					'type'    => 'integer',
					'minimum' => 1,
				],
				'status'   => [
					'type' => 'string',
					'enum' => [ 'revoked' ],
				],
				'revoked'  => [
					'type' => 'boolean',
				],
			],
			'required'             => [ 'token_id', 'post_id', 'status', 'revoked' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Get the reusable schema fields for an opaque preview link reference.
	 *
	 * @return array<string,mixed>
	 */
	private function link_output_properties(): array {
		return [
			'token_id'   => [
				'type'        => 'string',
				'description' => __( 'Opaque PreviewShare link identifier.', 'previewshare' ),
			],
			'post_id'    => [
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'ID of the post associated with the link.', 'previewshare' ),
			],
			'label'      => [
				'type'        => 'string',
				'description' => __( 'Preview link label.', 'previewshare' ),
			],
			'created_at' => [
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Unix timestamp when the link was created.', 'previewshare' ),
			],
			'expires_at' => [
				'type'        => [ 'integer', 'null' ],
				'description' => __( 'Unix expiry timestamp, or null when the link does not expire.', 'previewshare' ),
			],
			'status'     => [
				'type' => 'string',
				'enum' => [ 'active', 'expired', 'revoked' ],
			],
		];
	}

	/**
	 * Get a post ID from an ability input object.
	 *
	 * @param mixed $input Ability input.
	 * @return int
	 */
	private function get_post_id_from_input( $input ): int {
		if ( ! is_array( $input ) || ! isset( $input['post_id'] ) ) {
			return 0;
		}

		return absint( $input['post_id'] );
	}

	/**
	 * Build a standard structured error response.
	 *
	 * @param string $code Error code.
	 * @param string $message Error message.
	 * @param int    $status HTTP-style error status.
	 * @return \WP_Error
	 */
	private function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, [ 'status' => $status ] );
	}
}

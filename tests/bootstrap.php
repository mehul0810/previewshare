<?php
/**
 * Unit test bootstrap.
 *
 * @package PreviewShare
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		/** @var string */
		private $code;

		/** @var string */
		private $message;

		/** @var mixed */
		private $data;

		/**
		 * @param string $code Error code.
		 * @param string $message Error message.
		 * @param mixed  $data Error data.
		 */
		public function __construct( string $code = '', string $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		/** @var mixed */
		private $data;

		/** @var int */
		private $status;

		/**
		 * @param mixed $data Response data.
		 * @param int   $status HTTP status.
		 */
		public function __construct( $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_data() {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request implements ArrayAccess {
		/** @var array<string,mixed> */
		private $params;

		/**
		 * @param array<string,mixed> $params Request parameters.
		 */
		public function __construct( array $params = [] ) {
			$this->params = $params;
		}

		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function offsetExists( mixed $offset ): bool {
			return isset( $this->params[ $offset ] );
		}

		public function offsetGet( mixed $offset ): mixed {
			return $this->params[ $offset ] ?? null;
		}

		public function offsetSet( mixed $offset, mixed $value ): void {
			$this->params[ $offset ] = $value;
		}

		public function offsetUnset( mixed $offset ): void {
			unset( $this->params[ $offset ] );
		}
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		/** @var int[] */
		public static $next_posts = [];

		/** @var int[] */
		public $posts = [];

		/** @var array<string,mixed> */
		public $query_vars = [];

		/** @var bool */
		public $is_home = false;

		/** @var bool */
		public $is_archive = false;

		/** @var bool */
		public $is_search = false;

		/** @var bool */
		public $is_singular = false;

		/** @var bool */
		public $is_page = false;

		/** @var bool */
		public $is_single = false;

		/** @var mixed */
		public $queried_object;

		/** @var int */
		public $queried_object_id = 0;

		/** @var int */
		public $found_posts = 0;

		/** @var int */
		public $post_count = 0;

		/**
		 * @param array<string,mixed> $query Query args.
		 */
		public function __construct( array $query = [] ) {
			$this->query_vars = $query;
			$this->posts      = self::$next_posts;
		}

		/**
		 * @param string $key Query var.
		 * @param mixed  $value Query value.
		 */
		public function set( string $key, $value ): void {
			$this->query_vars[ $key ] = $value;
		}

		public function is_main_query(): bool {
			return true;
		}
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		/** @var int */
		public $ID;

		/** @var string */
		public $post_type;

		/** @var string */
		public $post_status;

		/**
		 * @param array<string,mixed> $data Post data.
		 */
		public function __construct( array $data = [] ) {
			$this->ID          = (int) ( $data['ID'] ?? 0 );
			$this->post_type   = (string) ( $data['post_type'] ?? 'post' );
			$this->post_status = (string) ( $data['post_status'] ?? 'draft' );
		}
	}
}

require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/src/functions.php';
require __DIR__ . '/Unit/TestCase.php';

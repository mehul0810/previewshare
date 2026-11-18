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

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
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

if ( ! class_exists( 'WP_Ability' ) ) {
	class WP_Ability {}
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

		/** @var string */
		public $request = '';

		/** @var array<string,array<int,array{callback:callable,priority:int,args:int}>> */
		public static $test_filters = [];

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

		/** @var bool */
		public $is_feed = false;

		/** @var bool */
		public $is_embed = false;

		/** @var bool */
		public $is_trackback = false;

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
			if ( ! empty( $query['meta_key'] ) && '_previewshare_reviewer_email' === $query['meta_key'] ) {
				$this->query( $query );
			}
		}

		/**
		 * Run the focused privacy-export test query.
		 *
		 * @param array<string,mixed> $query Query vars.
		 * @return int[]|object[]
		 */
		public function query( array $query = [] ): array {
			$this->query_vars = $query;
			if ( ! empty( $query['meta_key'] ) && '_previewshare_reviewer_email' === $query['meta_key'] ) {
				$this->request = 'SELECT previewshare privacy export';
				$posts = null;
				$filters       = self::$test_filters['posts_pre_query'] ?? [];
				usort( $filters, static fn( array $left, array $right ): int => $left['priority'] <=> $right['priority'] );
				foreach ( $filters as $filter ) {
					$posts = ( $filter['callback'] )( $posts, $this );
				}
				$this->posts = $posts;
				return null === $this->posts ? self::$next_posts : $this->posts;
			}
			$this->posts = self::$next_posts;
			return $this->posts;
		}

		/**
		 * Read one query var.
		 *
		 * @param string $key Query var.
		 * @return mixed
		 */
		public function get( string $key ) {
			return $this->query_vars[ $key ] ?? null;
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

		/** @var int */
		public $post_parent;

		/** @var string */
		public $post_title;

		/** @var string */
		public $post_content;

		/** @var string */
		public $post_date_gmt;

		/** @var string */
		public $post_date;

		/** @var string */
		public $filter;

		/** @var string */
		public $post_excerpt;

		/**
		 * @param array<string,mixed>|object $data Post data.
		 */
		public function __construct( $data = [] ) {
			if ( is_object( $data ) ) {
				$data = get_object_vars( $data );
			}
			$this->ID          = (int) ( $data['ID'] ?? 0 );
			$this->post_type   = (string) ( $data['post_type'] ?? 'post' );
			$this->post_status = (string) ( $data['post_status'] ?? 'draft' );
			$this->post_parent = (int) ( $data['post_parent'] ?? 0 );
			$this->post_title   = (string) ( $data['post_title'] ?? '' );
			$this->post_content = (string) ( $data['post_content'] ?? '' );
			$this->post_excerpt = (string) ( $data['post_excerpt'] ?? '' );
			$this->post_date_gmt = (string) ( $data['post_date_gmt'] ?? '0000-00-00 00:00:00' );
			$this->post_date     = (string) ( $data['post_date'] ?? '0000-00-00 00:00:00' );
			$this->filter        = (string) ( $data['filter'] ?? '' );
		}
	}
}

require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/src/functions.php';
require __DIR__ . '/Unit/TestCase.php';

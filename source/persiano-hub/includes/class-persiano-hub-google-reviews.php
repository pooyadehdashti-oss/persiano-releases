<?php
/**
 * Google Business Profile reviews imported through Make.com.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Persiano_Hub_Google_Reviews {
	const POST_TYPE     = 'batchly_review';
	const OPTION_SECRET = 'batchly_google_reviews_secret';
	const MENU_SLUG     = 'persiano-hub-google-reviews';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ), 62 );
		add_action( 'admin_post_batchly_generate_review_secret', array( __CLASS__, 'generate_secret' ) );
		add_action( 'admin_post_batchly_review_action', array( __CLASS__, 'handle_review_action' ) );
		add_shortcode( 'batchly_reviews', array( __CLASS__, 'render_shortcode' ) );
	}

	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels' => array(
					'name'          => __( 'Google Reviews', 'persiano-hub' ),
					'singular_name' => __( 'Google Review', 'persiano-hub' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'supports'            => array( 'title', 'editor' ),
				'exclude_from_search' => true,
				'rewrite'             => false,
				'query_var'           => false,
			)
		);
	}

	public static function register_admin_page() {
		add_submenu_page(
			'persiano-hub',
			__( 'Google Reviews', 'persiano-hub' ),
			__( 'Google Reviews', 'persiano-hub' ),
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render_admin_page' )
		);
	}

	public static function register_routes() {
		register_rest_route(
			'batchly/v1',
			'/google-reviews',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'receive_review' ),
				'permission_callback' => array( __CLASS__, 'authorize_request' ),
			)
		);

		register_rest_route(
			'batchly/v1',
			'/google-reviews/bulk',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'receive_bulk_reviews' ),
				'permission_callback' => array( __CLASS__, 'authorize_request' ),
			)
		);

		register_rest_route(
			'batchly/v1',
			'/google-reviews/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => array( __CLASS__, 'authorize_request' ),
			)
		);
	}

	private static function get_secret() {
		return (string) get_option( self::OPTION_SECRET, '' );
	}

	public static function authorize_request( WP_REST_Request $request ) {
		$secret = self::get_secret();

		if ( '' === $secret ) {
			return new WP_Error(
				'batchly_reviews_not_configured',
				__( 'The Batchly Google Reviews connection has not been configured.', 'persiano-hub' ),
				array( 'status' => 503 )
			);
		}

		$authorization = trim( (string) $request->get_header( 'authorization' ) );
		$provided      = '';

		if ( 0 === stripos( $authorization, 'Bearer ' ) ) {
			$provided = trim( substr( $authorization, 7 ) );
		}

		if ( '' === $provided ) {
			$provided = trim( (string) $request->get_header( 'x-batchly-secret' ) );
		}

		if ( '' === $provided || ! hash_equals( $secret, $provided ) ) {
			return new WP_Error(
				'batchly_reviews_unauthorized',
				__( 'Invalid Batchly review webhook secret.', 'persiano-hub' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	public static function generate_secret() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this connection.', 'persiano-hub' ) );
		}

		check_admin_referer( 'batchly_generate_review_secret' );

		update_option(
			self::OPTION_SECRET,
			wp_generate_password( 48, false, false ),
			false
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => self::MENU_SLUG,
					'generated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private static function normalize_rating( $value ) {
		if ( is_numeric( $value ) ) {
			return max( 1, min( 5, (int) round( (float) $value ) ) );
		}

		$value = strtoupper( sanitize_text_field( (string) $value ) );
		$map   = array(
			'ONE'   => 1,
			'TWO'   => 2,
			'THREE' => 3,
			'FOUR'  => 4,
			'FIVE'  => 5,
			'STAR_RATING_UNSPECIFIED' => 0,
		);

		if ( isset( $map[ $value ] ) ) {
			return $map[ $value ];
		}

		if ( preg_match( '/([1-5])/', $value, $matches ) ) {
			return (int) $matches[1];
		}

		return 0;
	}

	private static function normalize_payload( array $data ) {
		$reviewer = isset( $data['reviewer'] ) && is_array( $data['reviewer'] )
			? $data['reviewer']
			: array();

		$reply = isset( $data['reviewReply'] ) && is_array( $data['reviewReply'] )
			? $data['reviewReply']
			: array();

		$review_id = $data['review_id']
			?? $data['reviewId']
			?? $data['name']
			?? '';

		if ( false !== strpos( (string) $review_id, '/reviews/' ) ) {
			$parts     = explode( '/reviews/', (string) $review_id );
			$review_id = end( $parts );
		}

		return array(
			'review_id'          => sanitize_text_field( (string) $review_id ),
			'location_id'        => sanitize_text_field( (string) ( $data['location_id'] ?? $data['locationId'] ?? '' ) ),
			'reviewer_name'      => sanitize_text_field(
				(string) (
					$data['reviewer_name']
					?? $data['reviewerName']
					?? $reviewer['displayName']
					?? $reviewer['name']
					?? __( 'Google customer', 'persiano-hub' )
				)
			),
			'reviewer_photo_url' => esc_url_raw(
				(string) (
					$data['reviewer_photo_url']
					?? $data['reviewerPhotoUrl']
					?? $reviewer['profilePhotoUrl']
					?? ''
				)
			),
			'rating'             => self::normalize_rating(
				$data['rating']
				?? $data['starRating']
				?? 0
			),
			'comment'            => sanitize_textarea_field(
				(string) (
					$data['comment']
					?? $data['text']
					?? ''
				)
			),
			'create_time'        => sanitize_text_field(
				(string) (
					$data['create_time']
					?? $data['createTime']
					?? ''
				)
			),
			'update_time'        => sanitize_text_field(
				(string) (
					$data['update_time']
					?? $data['updateTime']
					?? ''
				)
			),
			'reply'              => sanitize_textarea_field(
				(string) (
					$data['reply']
					?? $reply['comment']
					?? ''
				)
			),
			'source'             => 'google',
		);
	}

	private static function find_review_post( $review_id ) {
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_batchly_google_review_id',
				'meta_value'     => $review_id,
			)
		);

		return ! empty( $posts ) ? (int) $posts[0] : 0;
	}

	private static function save_review( array $raw ) {
		$data = self::normalize_payload( $raw );

		if ( '' === $data['review_id'] ) {
			return new WP_Error(
				'batchly_review_missing_id',
				__( 'The review_id field is required.', 'persiano-hub' ),
				array( 'status' => 400 )
			);
		}

		$post_id = self::find_review_post( $data['review_id'] );
		$is_new  = ! $post_id;

		$postarr = array(
			'post_type'    => self::POST_TYPE,
			'post_title'   => $data['reviewer_name'],
			'post_content' => $data['comment'],
			'post_status'  => $is_new ? 'publish' : get_post_status( $post_id ),
		);

		if ( $post_id ) {
			$postarr['ID'] = $post_id;
			$result        = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$result = wp_insert_post( wp_slash( $postarr ), true );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post_id = (int) $result;

		$meta = array(
			'_batchly_google_review_id'     => $data['review_id'],
			'_batchly_review_location_id'   => $data['location_id'],
			'_batchly_reviewer_name'        => $data['reviewer_name'],
			'_batchly_reviewer_photo_url'   => $data['reviewer_photo_url'],
			'_batchly_review_rating'        => $data['rating'],
			'_batchly_review_create_time'   => $data['create_time'],
			'_batchly_review_update_time'   => $data['update_time'],
			'_batchly_review_reply'         => $data['reply'],
			'_batchly_review_source'        => 'google',
			'_batchly_review_last_received' => current_time( 'mysql', true ),
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		if ( '' === get_post_meta( $post_id, '_batchly_review_featured', true ) ) {
			update_post_meta( $post_id, '_batchly_review_featured', '0' );
		}

		return array(
			'post_id'   => $post_id,
			'review_id' => $data['review_id'],
			'created'   => $is_new,
			'updated'   => ! $is_new,
		);
	}

	public static function receive_review( WP_REST_Request $request ) {
		$data   = $request->get_json_params();
		$result = self::save_review( is_array( $data ) ? $data : array() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'review'  => $result,
			),
			$result['created'] ? 201 : 200
		);
	}

	public static function receive_bulk_reviews( WP_REST_Request $request ) {
		$data    = $request->get_json_params();
		$reviews = isset( $data['reviews'] ) && is_array( $data['reviews'] )
			? $data['reviews']
			: ( is_array( $data ) ? $data : array() );

		$results = array();
		$errors  = array();

		foreach ( $reviews as $index => $review ) {
			if ( ! is_array( $review ) ) {
				continue;
			}

			$result = self::save_review( $review );

			if ( is_wp_error( $result ) ) {
				$errors[] = array(
					'index'   => $index,
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				);
			} else {
				$results[] = $result;
			}
		}

		return rest_ensure_response(
			array(
				'success'  => empty( $errors ),
				'imported' => count( $results ),
				'errors'   => $errors,
				'results'  => $results,
			)
		);
	}

	public static function status() {
		$counts = wp_count_posts( self::POST_TYPE );

		return rest_ensure_response(
			array(
				'configured' => '' !== self::get_secret(),
				'published'  => isset( $counts->publish ) ? (int) $counts->publish : 0,
				'hidden'     => isset( $counts->draft ) ? (int) $counts->draft : 0,
				'endpoint'   => rest_url( 'batchly/v1/google-reviews' ),
			)
		);
	}

	public static function handle_review_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage reviews.', 'persiano-hub' ) );
		}

		$review_id = isset( $_GET['review_id'] ) ? absint( $_GET['review_id'] ) : 0;
		$action    = isset( $_GET['review_action'] ) ? sanitize_key( wp_unslash( $_GET['review_action'] ) ) : '';

		check_admin_referer( 'batchly_review_action_' . $review_id );

		if ( $review_id && self::POST_TYPE === get_post_type( $review_id ) ) {
			if ( 'hide' === $action ) {
				wp_update_post(
					array(
						'ID'          => $review_id,
						'post_status' => 'draft',
					)
				);
			} elseif ( 'publish' === $action ) {
				wp_update_post(
					array(
						'ID'          => $review_id,
						'post_status' => 'publish',
					)
				);
			} elseif ( 'feature' === $action ) {
				update_post_meta( $review_id, '_batchly_review_featured', '1' );
			} elseif ( 'unfeature' === $action ) {
				update_post_meta( $review_id, '_batchly_review_featured', '0' );
			}
		}

		wp_safe_redirect(
			add_query_arg(
				'page',
				self::MENU_SLUG,
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private static function action_url( $post_id, $action ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'        => 'batchly_review_action',
					'review_id'     => $post_id,
					'review_action' => $action,
				),
				admin_url( 'admin-post.php' )
			),
			'batchly_review_action_' . $post_id
		);
	}

	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$secret  = self::get_secret();
		$reviews = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 100,
				'orderby'        => 'meta_value',
				'meta_key'       => '_batchly_review_update_time',
				'order'          => 'DESC',
			)
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Google Reviews via Make', 'persiano-hub' ); ?></h1>

			<p>
				<?php esc_html_e( 'Make.com watches your Google Business Profile reviews and sends them securely to Batchly for local storage and website display.', 'persiano-hub' ); ?>
			</p>

			<div class="card" style="max-width:1000px">
				<h2><?php esc_html_e( 'Make.com connection', 'persiano-hub' ); ?></h2>

				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Webhook endpoint', 'persiano-hub' ); ?></th>
						<td><code><?php echo esc_html( rest_url( 'batchly/v1/google-reviews' ) ); ?></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Bulk import endpoint', 'persiano-hub' ); ?></th>
						<td><code><?php echo esc_html( rest_url( 'batchly/v1/google-reviews/bulk' ) ); ?></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Authorization header', 'persiano-hub' ); ?></th>
						<td>
							<?php if ( $secret ) : ?>
								<code>Authorization: Bearer <?php echo esc_html( $secret ); ?></code>
							<?php else : ?>
								<em><?php esc_html_e( 'Generate a webhook secret first.', 'persiano-hub' ); ?></em>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<p>
					<a class="button button-secondary"
					   href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=batchly_generate_review_secret' ), 'batchly_generate_review_secret' ) ); ?>">
						<?php echo $secret ? esc_html__( 'Regenerate secret', 'persiano-hub' ) : esc_html__( 'Generate secret', 'persiano-hub' ); ?>
					</a>
				</p>

				<?php if ( $secret ) : ?>
					<p class="description">
						<?php esc_html_e( 'Regenerating the secret immediately invalidates the old Make.com connection.', 'persiano-hub' ); ?>
					</p>
				<?php endif; ?>
			</div>

			<div class="card" style="max-width:1000px">
				<h2><?php esc_html_e( 'Website display', 'persiano-hub' ); ?></h2>
				<p><code>[batchly_reviews]</code></p>
				<p><code>[batchly_reviews limit="6" min_rating="4" layout="grid"]</code></p>
			</div>

			<h2><?php esc_html_e( 'Imported reviews', 'persiano-hub' ); ?></h2>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Reviewer', 'persiano-hub' ); ?></th>
						<th><?php esc_html_e( 'Rating', 'persiano-hub' ); ?></th>
						<th><?php esc_html_e( 'Review', 'persiano-hub' ); ?></th>
						<th><?php esc_html_e( 'Updated', 'persiano-hub' ); ?></th>
						<th><?php esc_html_e( 'Status', 'persiano-hub' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'persiano-hub' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $reviews ) ) : ?>
						<tr>
							<td colspan="6"><?php esc_html_e( 'No Google reviews have been imported yet.', 'persiano-hub' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $reviews as $review ) : ?>
							<?php
							$rating   = (int) get_post_meta( $review->ID, '_batchly_review_rating', true );
							$updated  = get_post_meta( $review->ID, '_batchly_review_update_time', true );
							$featured = '1' === get_post_meta( $review->ID, '_batchly_review_featured', true );
							?>
							<tr>
								<td><strong><?php echo esc_html( get_post_meta( $review->ID, '_batchly_reviewer_name', true ) ); ?></strong></td>
								<td><?php echo esc_html( str_repeat( '★', $rating ) ); ?></td>
								<td style="max-width:460px"><?php echo esc_html( wp_trim_words( $review->post_content, 35 ) ); ?></td>
								<td><?php echo esc_html( $updated ); ?></td>
								<td>
									<?php echo 'publish' === $review->post_status ? esc_html__( 'Published', 'persiano-hub' ) : esc_html__( 'Hidden', 'persiano-hub' ); ?>
									<?php if ( $featured ) : ?>
										<br><strong><?php esc_html_e( 'Featured', 'persiano-hub' ); ?></strong>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( 'publish' === $review->post_status ) : ?>
										<a href="<?php echo esc_url( self::action_url( $review->ID, 'hide' ) ); ?>"><?php esc_html_e( 'Hide', 'persiano-hub' ); ?></a>
									<?php else : ?>
										<a href="<?php echo esc_url( self::action_url( $review->ID, 'publish' ) ); ?>"><?php esc_html_e( 'Publish', 'persiano-hub' ); ?></a>
									<?php endif; ?>
									|
									<?php if ( $featured ) : ?>
										<a href="<?php echo esc_url( self::action_url( $review->ID, 'unfeature' ) ); ?>"><?php esc_html_e( 'Unfeature', 'persiano-hub' ); ?></a>
									<?php else : ?>
										<a href="<?php echo esc_url( self::action_url( $review->ID, 'feature' ) ); ?>"><?php esc_html_e( 'Feature', 'persiano-hub' ); ?></a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public static function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'limit'       => 6,
				'min_rating'  => 1,
				'layout'      => 'grid',
				'featured'    => 'no',
				'show_reply'  => 'yes',
				'show_photo'  => 'yes',
			),
			$atts,
			'batchly_reviews'
		);

		$limit      = max( 1, min( 50, absint( $atts['limit'] ) ) );
		$min_rating = max( 1, min( 5, absint( $atts['min_rating'] ) ) );
		$featured   = 'yes' === strtolower( (string) $atts['featured'] );

		$meta_query = array(
			array(
				'key'     => '_batchly_review_rating',
				'value'   => $min_rating,
				'type'    => 'NUMERIC',
				'compare' => '>=',
			),
		);

		if ( $featured ) {
			$meta_query[] = array(
				'key'   => '_batchly_review_featured',
				'value' => '1',
			);
		}

		$reviews = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'meta_query'     => $meta_query,
				'orderby'        => 'meta_value',
				'meta_key'       => '_batchly_review_update_time',
				'order'          => 'DESC',
			)
		);

		if ( empty( $reviews ) ) {
			return '';
		}

		$layout = in_array( $atts['layout'], array( 'grid', 'list' ), true )
			? $atts['layout']
			: 'grid';

		ob_start();
		?>
		<div class="batchly-reviews batchly-reviews--<?php echo esc_attr( $layout ); ?>">
			<?php foreach ( $reviews as $review ) : ?>
				<?php
				$name   = get_post_meta( $review->ID, '_batchly_reviewer_name', true );
				$photo  = get_post_meta( $review->ID, '_batchly_reviewer_photo_url', true );
				$rating = (int) get_post_meta( $review->ID, '_batchly_review_rating', true );
				$reply  = get_post_meta( $review->ID, '_batchly_review_reply', true );
				?>
				<article class="batchly-review">
					<header class="batchly-review__header">
						<?php if ( 'yes' === strtolower( (string) $atts['show_photo'] ) && $photo ) : ?>
							<img class="batchly-review__photo" src="<?php echo esc_url( $photo ); ?>" alt="">
						<?php endif; ?>
						<div>
							<strong class="batchly-review__name"><?php echo esc_html( $name ); ?></strong>
							<div class="batchly-review__stars" aria-label="<?php echo esc_attr( sprintf( __( '%d out of 5 stars', 'persiano-hub' ), $rating ) ); ?>">
								<?php echo esc_html( str_repeat( '★', $rating ) . str_repeat( '☆', 5 - $rating ) ); ?>
							</div>
						</div>
					</header>

					<div class="batchly-review__comment"><?php echo wp_kses_post( wpautop( esc_html( $review->post_content ) ) ); ?></div>

					<?php if ( 'yes' === strtolower( (string) $atts['show_reply'] ) && $reply ) : ?>
						<div class="batchly-review__reply">
							<strong><?php esc_html_e( 'Response from the business', 'persiano-hub' ); ?></strong>
							<?php echo wp_kses_post( wpautop( esc_html( $reply ) ) ); ?>
						</div>
					<?php endif; ?>
				</article>
			<?php endforeach; ?>
		</div>

		<style>
			.batchly-reviews {
				display: grid;
				gap: 1rem;
				margin: 1.5rem 0;
			}
			.batchly-reviews--grid {
				grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
			}
			.batchly-reviews--list {
				grid-template-columns: 1fr;
			}
			.batchly-review {
				border: 1px solid rgba(0,0,0,.12);
				border-radius: 12px;
				padding: 1.1rem;
				background: #fff;
				box-shadow: 0 4px 18px rgba(0,0,0,.06);
			}
			.batchly-review__header {
				display: flex;
				align-items: center;
				gap: .75rem;
				margin-bottom: .75rem;
			}
			.batchly-review__photo {
				width: 44px;
				height: 44px;
				border-radius: 50%;
				object-fit: cover;
			}
			.batchly-review__stars {
				color: #f5a623;
				letter-spacing: .08em;
			}
			.batchly-review__comment p:last-child,
			.batchly-review__reply p:last-child {
				margin-bottom: 0;
			}
			.batchly-review__reply {
				margin-top: 1rem;
				padding-top: .8rem;
				border-top: 1px solid rgba(0,0,0,.1);
				font-size: .92em;
			}
		</style>
		<?php
		return ob_get_clean();
	}
}

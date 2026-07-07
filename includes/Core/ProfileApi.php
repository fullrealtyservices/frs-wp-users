<?php
/**
 * Profile REST API
 *
 * REST API endpoints for profile CRUD operations and webhooks.
 *
 * @package FRSUsers
 * @subpackage Core
 * @since 1.0.0
 */

namespace FRSUsers\Core;

use FRSUsers\Models\Profile;
use FRSUsers\Traits\Base;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Class ProfileApi
 *
 * REST API for profile CRUD operations with webhook support.
 *
 * @package FRSUsers\Core
 */
class ProfileApi {

	use Base;

	/**
	 * API namespace
	 *
	 * @var string
	 */
	private $namespace = 'frs-users/v1';

	/**
	 * Initialize API
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST API routes
	 *
	 * @return void
	 */
	public function register_routes() {
		// List profiles (GET /profiles)
		register_rest_route(
			$this->namespace,
			'/profiles',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_profiles' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'page'            => array(
						'required' => false,
						'type'     => 'integer',
						'default'  => 1,
					),
					'per_page'        => array(
						'required' => false,
						'type'     => 'integer',
						'default'  => 20,
					),
					'search'          => array(
						'required' => false,
						'type'     => 'string',
					),
					'status'          => array(
						'required' => false,
						'type'     => 'string',
					),
					'with_users_only' => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
				),
			)
		);

		// Create profile (POST /profiles)
		register_rest_route(
			$this->namespace,
			'/profiles',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_profile' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'email'        => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
					),
					'first_name'   => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'last_name'    => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'phone_number' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'nmls'         => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Get single profile (GET /profiles/{id})
		register_rest_route(
			$this->namespace,
			'/profiles/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_profile' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		// Update profile (PUT /profiles/{id})
		register_rest_route(
			$this->namespace,
			'/profiles/(?P<id>\d+)',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_profile' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		// Delete profile (DELETE /profiles/{id})
		register_rest_route(
			$this->namespace,
			'/profiles/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_profile' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		// Get available fields
		register_rest_route(
			$this->namespace,
			'/profiles/fields',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_available_fields' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		// Webhook settings endpoints
		register_rest_route(
			$this->namespace,
			'/webhooks',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_webhooks' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_webhook' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'url'    => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'esc_url_raw',
						),
						'events' => array(
							'required' => true,
							'type'     => 'array',
							'items'    => array(
								'type' => 'string',
								'enum' => array( 'profile.created', 'profile.updated', 'profile.deleted', 'arrive.generated' ),
							),
						),
						'secret' => array(
							'required' => false,
							'type'     => 'string',
						),
					),
				),
			)
		);

		// Delete webhook (DELETE /webhooks/{id})
		register_rest_route(
			$this->namespace,
			'/webhooks/(?P<id>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_webhook' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);
	}

	/**
	 * Check user permissions
	 *
	 * @return bool
	 */
	public function check_permissions() {
		return current_user_can( 'edit_users' );
	}

	/**
	 * Get profiles list endpoint
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_profiles( $request ) {
		$page            = (int) ( $request->get_param( 'page' ) ?: 1 );
		$per_page        = (int) ( $request->get_param( 'per_page' ) ?: 20 );
		$search          = $request->get_param( 'search' );
		$status          = $request->get_param( 'status' );
		$with_users_only = $request->get_param( 'with_users_only' );

		// Fetch all profiles using WordPress-native query
		$all_profiles = Profile::get_all();

		// Convert to arrays for filtering
		$results = array_map( function( $p ) {
			return $p->toArray();
		}, $all_profiles );

		// Search filter
		if ( $search ) {
			$search_lower = strtolower( $search );
			$results = array_filter( $results, function( $p ) use ( $search_lower ) {
				$haystack = strtolower(
					( $p['first_name'] ?? '' ) . ' ' .
					( $p['last_name'] ?? '' ) . ' ' .
					( $p['email'] ?? '' ) . ' ' .
					( $p['nmls'] ?? '' )
				);
				return strpos( $haystack, $search_lower ) !== false;
			} );
		}

		// Status filter
		if ( $status ) {
			$results = array_filter( $results, function( $p ) use ( $status ) {
				return ( $p['status'] ?? '' ) === $status;
			} );
		}

		// Users only filter
		if ( $with_users_only ) {
			$results = array_filter( $results, function( $p ) {
				return ! empty( $p['user_id'] );
			} );
		}

		$results = array_values( $results );
		$total   = count( $results );

		// Pagination
		$offset   = ( $page - 1 ) * $per_page;
		$profiles = array_slice( $results, $offset, $per_page );

		return new WP_REST_Response(
			array(
				'success'     => true,
				'profiles'    => $profiles,
				'total'       => $total,
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			),
			200
		);
	}

	/**
	 * Get single profile endpoint
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_profile( $request ) {
		$profile_id = $request->get_param( 'id' );
		$profile    = Profile::find( $profile_id );

		if ( ! $profile ) {
			return new WP_Error( 'profile_not_found', 'Profile not found', array( 'status' => 404 ) );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'profile' => $profile,
			),
			200
		);
	}

	/**
	 * Create profile endpoint
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_profile( $request ) {
		$email = $request->get_param( 'email' );

		// Check if profile already exists
		$existing = Profile::get_by_email( $email );
		if ( $existing ) {
			return new WP_Error( 'profile_exists', 'Profile with this email already exists', array( 'status' => 409 ) );
		}

		// Create new profile
		try {
			$profile = new Profile();

			// Set all provided fields
			$allowed_fields = $this->get_allowed_fields();
			foreach ( $allowed_fields as $field ) {
				$value = $request->get_param( $field );
				if ( null !== $value ) {
					$profile->$field = $value;
				}
			}

			// Auto-generate arrive link if NMLS provided
			if ( empty( $profile->arrive ) && ! empty( $profile->nmls ) ) {
				$profile->arrive = $this->generate_arrive_link( $profile->nmls );
				$arrive_generated = true;
			}

			$profile->save();

			// Trigger webhooks
			$this->trigger_global_webhooks( 'profile.created', array(
				'profile_id' => $profile->id,
				'email'      => $profile->email,
			) );

			if ( ! empty( $arrive_generated ) ) {
				$this->trigger_global_webhooks( 'arrive.generated', array(
					'profile_id' => $profile->id,
					'arrive_url' => $profile->arrive,
				) );
			}

			return new WP_REST_Response(
				array(
					'success' => true,
					'profile' => $profile,
					'message' => 'Profile created successfully',
				),
				201
			);

		} catch ( \Exception $e ) {
			return new WP_Error( 'create_failed', 'Failed to create profile: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Update profile endpoint
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_profile( $request ) {
		$profile_id = $request->get_param( 'id' );
		$profile    = Profile::find( $profile_id );

		if ( ! $profile ) {
			return new WP_Error( 'profile_not_found', 'Profile not found', array( 'status' => 404 ) );
		}

		try {
			// Update fields
			$allowed_fields = $this->get_allowed_fields();
			$updated_fields = array();

			foreach ( $allowed_fields as $field ) {
				$value = $request->get_param( $field );
				if ( null !== $value && $field !== 'id' && $field !== 'created_at' ) {
					$profile->$field = $value;
					$updated_fields[] = $field;
				}
			}

			// Auto-generate arrive link if NMLS provided and arrive is empty
			if ( empty( $profile->arrive ) && ! empty( $profile->nmls ) ) {
				$profile->arrive = $this->generate_arrive_link( $profile->nmls );
				$arrive_generated = true;
			}

			$profile->save();

			// Trigger webhooks
			$this->trigger_global_webhooks( 'profile.updated', array(
				'profile_id'     => $profile->id,
				'email'          => $profile->email,
				'updated_fields' => $updated_fields,
			) );

			if ( ! empty( $arrive_generated ) ) {
				$this->trigger_global_webhooks( 'arrive.generated', array(
					'profile_id' => $profile->id,
					'arrive_url' => $profile->arrive,
				) );
			}

			return new WP_REST_Response(
				array(
					'success' => true,
					'profile' => $profile,
					'message' => 'Profile updated successfully',
				),
				200
			);

		} catch ( \Exception $e ) {
			return new WP_Error( 'update_failed', 'Failed to update profile: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Delete profile endpoint
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_profile( $request ) {
		$profile_id = $request->get_param( 'id' );
		$profile    = Profile::find( $profile_id );

		if ( ! $profile ) {
			return new WP_Error( 'profile_not_found', 'Profile not found', array( 'status' => 404 ) );
		}

		try {
			$profile_data = array(
				'profile_id' => $profile->id,
				'email'      => $profile->email,
			);

			$profile->delete();

			// Trigger webhooks
			$this->trigger_global_webhooks( 'profile.deleted', $profile_data );

			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => 'Profile deleted successfully',
				),
				200
			);

		} catch ( \Exception $e ) {
			return new WP_Error( 'delete_failed', 'Failed to delete profile: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get available fields endpoint
	 *
	 * @return WP_REST_Response
	 */
	public function get_available_fields() {
		$fields = $this->get_allowed_fields();

		return new WP_REST_Response(
			array(
				'success' => true,
				'fields'  => $fields,
				'total'   => count( $fields ),
			),
			200
		);
	}

	/**
	 * Get allowed fields for create/update
	 *
	 * @return array
	 */
	private function get_allowed_fields() {
		return array(
			'user_id', 'first_name', 'last_name', 'email', 'secondary_email', 'phone_number',
			'mobile_number', 'office', 'headshot_id', 'job_title', 'biography',
			'date_of_birth', 'nmls', 'nmls_number', 'license_number', 'dre_license',
			'brand', 'city_state', 'region', 'facebook_url', 'instagram_url',
			'linkedin_url', 'twitter_url', 'youtube_url', 'tiktok_url', 'arrive',
			'canva_folder_link', 'status',
			'specialties_lo', 'namb_certifications', 'service_areas',
			'telegram_username',
			'booking_url',
		);
	}

	/**
	 * Get webhooks endpoint
	 *
	 * @return WP_REST_Response
	 */
	public function get_webhooks() {
		$webhooks = get_option( 'frs_users_webhooks', array() );

		return new WP_REST_Response(
			array(
				'success'  => true,
				'webhooks' => array_values( $webhooks ),
				'total'    => count( $webhooks ),
			),
			200
		);
	}

	/**
	 * Create webhook endpoint
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function create_webhook( $request ) {
		$url    = $request->get_param( 'url' );
		$events = $request->get_param( 'events' );
		$secret = $request->get_param( 'secret' );

		$webhooks = get_option( 'frs_users_webhooks', array() );

		$webhook_id = uniqid( 'webhook_' );
		$webhooks[ $webhook_id ] = array(
			'id'         => $webhook_id,
			'url'        => $url,
			'events'     => $events,
			'secret'     => $secret ? $secret : wp_generate_password( 32, false ),
			'created_at' => current_time( 'mysql' ),
			'status'     => 'active',
		);

		update_option( 'frs_users_webhooks', $webhooks );

		return new WP_REST_Response(
			array(
				'success' => true,
				'webhook' => $webhooks[ $webhook_id ],
				'message' => 'Webhook created successfully',
			),
			201
		);
	}

	/**
	 * Delete webhook endpoint
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_webhook( $request ) {
		$webhook_id = $request->get_param( 'id' );
		$webhooks   = get_option( 'frs_users_webhooks', array() );

		if ( ! isset( $webhooks[ $webhook_id ] ) ) {
			return new WP_Error( 'webhook_not_found', 'Webhook not found', array( 'status' => 404 ) );
		}

		unset( $webhooks[ $webhook_id ] );
		update_option( 'frs_users_webhooks', $webhooks );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => 'Webhook deleted successfully',
			),
			200
		);
	}

	/**
	 * Generate arrive link from NMLS number
	 *
	 * @param string|int $nmls NMLS number.
	 * @return string
	 */
	private function generate_arrive_link( $nmls ) {
		if ( empty( $nmls ) ) {
			return '';
		}

		return 'https://21stcenturylending.my1003app.com/' . sanitize_text_field( $nmls ) . '/register';
	}

	/**
	 * Trigger global webhooks for an event
	 *
	 * @param string $event Event name.
	 * @param array  $data  Event data.
	 * @return void
	 */
	private function trigger_global_webhooks( $event, $data ) {
		$webhooks = get_option( 'frs_users_webhooks', array() );

		foreach ( $webhooks as $webhook ) {
			if ( 'active' !== $webhook['status'] ) {
				continue;
			}

			if ( in_array( $event, $webhook['events'], true ) ) {
				$payload = array(
					'event'     => $event,
					'data'      => $data,
					'timestamp' => current_time( 'c' ),
				);

				// Add signature if secret exists
				if ( ! empty( $webhook['secret'] ) ) {
					$payload['signature'] = hash_hmac( 'sha256', wp_json_encode( $payload ), $webhook['secret'] );
				}

				wp_remote_post(
					$webhook['url'],
					array(
						'body'    => wp_json_encode( $payload ),
						'headers' => array(
							'Content-Type'        => 'application/json',
							'X-FRS-Webhook-ID'    => $webhook['id'],
							'X-FRS-Webhook-Event' => $event,
						),
						'timeout' => 15,
					)
				);
			}
		}
	}
}

<?php
/**
 * Profile Sync
 *
 * Handles synchronization of profiles between hub and marketing sites.
 * - Hub sends webhooks when profiles are updated
 * - Marketing sites receive webhooks and update local users
 *
 * @package FRSUsers
 * @subpackage Core
 * @since 3.0.0
 */

namespace FRSUsers\Core;

use FRSUsers\Models\Profile;

/**
 * Class ProfileSync
 *
 * Manages profile synchronization via webhooks.
 */
class ProfileSync {

	/**
	 * Initialize sync hooks
	 *
	 * @return void
	 */
	public static function init() {
		// Only send webhooks if this is the hub (editing enabled)
		if ( Roles::is_profile_editing_enabled() ) {
			add_action( 'frs_profile_saved', array( __CLASS__, 'send_webhook_on_save' ), 10, 2 );
			add_action( 'profile_update', array( __CLASS__, 'send_webhook_on_user_update' ), 10, 2 );
		}

		// Register webhook receiver endpoint
		add_action( 'rest_api_init', array( __CLASS__, 'register_webhook_endpoint' ) );
	}

	/**
	 * Register webhook receiver REST endpoint
	 *
	 * @return void
	 */
	public static function register_webhook_endpoint() {
		register_rest_route(
			'frs-users/v1',
			'/webhook/profile-updated',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_webhook' ),
				'permission_callback' => array( __CLASS__, 'verify_webhook_signature' ),
			)
		);
	}

	/**
	 * Send webhook when profile is saved via FRS
	 *
	 * @param int   $profile_id Profile/User ID.
	 * @param array $profile_data Profile data that was saved.
	 * @return void
	 */
	public static function send_webhook_on_save( $profile_id, $profile_data ) {
		// Use the snapshot captured at the moment Profile::save() finished,
		// rather than re-reading the DB: MetaKeyAlias mirrors each field as
		// save() writes it, so a fresh re-hydrate here can race that cascade
		// and pick up transiently-inconsistent values instead of the correct
		// ones save() just computed.
		self::send_profile_webhook( $profile_id, $profile_data );
	}

	/**
	 * Send webhook when WordPress user profile is updated
	 *
	 * @param int      $user_id User ID.
	 * @param \WP_User $old_user_data Old user data.
	 * @return void
	 */
	public static function send_webhook_on_user_update( $user_id, $old_user_data ) {
		// Only send for users with FRS roles
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$frs_roles = Roles::get_wp_role_slugs();
		$user_roles = (array) $user->roles;

		if ( ! array_intersect( $frs_roles, $user_roles ) ) {
			return;
		}

		self::send_profile_webhook( $user_id );
	}

	/**
	 * Send profile data to all configured webhook endpoints
	 *
	 * @param int        $user_id      User ID.
	 * @param array|null $profile_data Pre-built profile array to send. If null,
	 *                                 hydrates fresh from the DB (used when no
	 *                                 in-memory snapshot is available, e.g. a
	 *                                 generic core `profile_update`).
	 * @return void
	 */
	private static function send_profile_webhook( $user_id, $profile_data = null ) {
		// Use network-wide site option so blog 1 + blog 2 + any future subsite
		// all share the same endpoint config. Falls back to per-blog option for
		// pre-migration compatibility.
		$endpoints = is_multisite()
			? get_site_option( 'frs_webhook_endpoints', get_option( 'frs_webhook_endpoints', array() ) )
			: get_option( 'frs_webhook_endpoints', array() );

		if ( empty( $endpoints ) ) {
			return;
		}

		if ( null === $profile_data ) {
			$user = get_userdata( $user_id );
			if ( ! $user ) {
				return;
			}

			$profile      = Profile::hydrate_from_user( $user );
			$profile_data = $profile->toArray();
		}

		$payload = array(
			'event'     => 'profile_updated',
			'timestamp' => time(),
			'profile'   => $profile_data,
		);

		$secret = is_multisite()
			? get_site_option( 'frs_webhook_secret', get_option( 'frs_webhook_secret', '' ) )
			: get_option( 'frs_webhook_secret', '' );

		foreach ( $endpoints as $endpoint ) {
			self::send_webhook( $endpoint, $payload, $secret );
		}
	}

	/**
	 * Send webhook to a single endpoint
	 *
	 * @param string $url     Endpoint URL.
	 * @param array  $payload Data to send.
	 * @param string $secret  Webhook secret for signing.
	 * @return bool Success or failure.
	 */
	private static function send_webhook( $url, $payload, $secret ) {
		$body = wp_json_encode( $payload );

		$headers = array(
			'Content-Type' => 'application/json',
		);

		// Sign the payload
		if ( $secret ) {
			$signature = hash_hmac( 'sha256', $body, $secret );
			$headers['X-FRS-Signature'] = $signature;
		}

		$response = wp_remote_post( $url, array(
			'timeout' => 30,
			'headers' => $headers,
			'body'    => $body,
		) );

		if ( is_wp_error( $response ) ) {
			error_log( sprintf(
				'[FRS Sync] Webhook failed to %s: %s',
				$url,
				$response->get_error_message()
			) );
			return false;
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			error_log( sprintf(
				'[FRS Sync] Webhook to %s returned HTTP %d',
				$url,
				$status
			) );
			return false;
		}

		return true;
	}

	/**
	 * Verify webhook signature
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return bool|\WP_Error True if valid, WP_Error if not.
	 */
	public static function verify_webhook_signature( $request ) {
		$secret = is_multisite()
			? get_site_option( 'frs_webhook_secret', get_option( 'frs_webhook_secret', '' ) )
			: get_option( 'frs_webhook_secret', '' );

		// If no secret configured, reject all webhooks
		if ( empty( $secret ) ) {
			return new \WP_Error(
				'webhook_not_configured',
				__( 'Webhook secret not configured on this site.', 'frs-users' ),
				array( 'status' => 403 )
			);
		}

		$signature = $request->get_header( 'X-FRS-Signature' );

		if ( empty( $signature ) ) {
			return new \WP_Error(
				'missing_signature',
				__( 'Missing webhook signature.', 'frs-users' ),
				array( 'status' => 401 )
			);
		}

		$body = $request->get_body();
		$expected = hash_hmac( 'sha256', $body, $secret );

		if ( ! hash_equals( $expected, $signature ) ) {
			return new \WP_Error(
				'invalid_signature',
				__( 'Invalid webhook signature.', 'frs-users' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Handle incoming webhook
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public static function handle_webhook( $request ) {
		$payload = $request->get_json_params();

		if ( empty( $payload['event'] ) ) {
			return new \WP_Error(
				'invalid_payload',
				__( 'Invalid webhook payload.', 'frs-users' ),
				array( 'status' => 400 )
			);
		}

		switch ( $payload['event'] ) {
			case 'profile_updated':
				return self::handle_profile_updated( $payload );

			case 'profile_deleted':
				return self::handle_profile_deleted( $payload );

			default:
				return new \WP_Error(
					'unknown_event',
					__( 'Unknown webhook event.', 'frs-users' ),
					array( 'status' => 400 )
				);
		}
	}

	/**
	 * Handle profile_updated webhook event
	 *
	 * @param array $payload Webhook payload.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	private static function handle_profile_updated( $payload ) {
		if ( empty( $payload['profile'] ) || empty( $payload['profile']['email'] ) ) {
			return new \WP_Error(
				'missing_profile_data',
				__( 'Missing profile data in webhook.', 'frs-users' ),
				array( 'status' => 400 )
			);
		}

		$profile_data = $payload['profile'];
		$email = trim( (string) ( $profile_data['email'] ?? '' ) );
		$nmls  = trim( (string) ( $profile_data['nmls'] ?? '' ) );

		// Check if this profile's company role is active for this site
		$company_role = $profile_data['select_person_type'] ?? '';
		if ( $company_role && ! Roles::is_company_role_active( $company_role ) ) {
			// Skip profiles not relevant to this site
			return new \WP_REST_Response(
				array(
					'success' => true,
					'message' => 'Profile skipped (company role not active for this site).',
				),
				200
			);
		}

		// Marketing-site eligibility gate (2026-04-29):
		// To appear on a non-editing (marketing) site a profile MUST have
		// both an NMLS number and an Arrive link. If missing either, skip.
		// If a local user already matches by NMLS, deactivate them.
		if ( ! Roles::is_profile_editing_enabled() ) {
			$arrive = trim( (string) ( $profile_data['arrive'] ?? '' ) );

			if ( $nmls === '' || $arrive === '' ) {
				$existing_id = self::find_user_id_by_nmls( $nmls );
				if ( ! $existing_id && $email ) {
					$by_email   = get_user_by( 'email', $email );
					$existing_id = $by_email ? (int) $by_email->ID : 0;
				}
				if ( $existing_id ) {
					update_user_meta( $existing_id, 'frs_is_active', 0 );
					return new \WP_REST_Response(
						array(
							'success' => true,
							'action'  => 'deactivated',
							'user_id' => $existing_id,
							'message' => 'Profile deactivated — missing NMLS and/or Arrive link.',
						),
						200
					);
				}
				return new \WP_REST_Response(
					array(
						'success' => true,
						'action'  => 'skipped',
						'message' => 'Profile not synced — requires both NMLS and Arrive link.',
					),
					200
				);
			}
		}

		// Match-by-NMLS first (canonical key — never duplicate on marketing).
		// Fall back to email match for legacy / pre-NMLS profiles on the hub.
		$user_id = $nmls ? self::find_user_id_by_nmls( $nmls ) : 0;
		if ( ! $user_id && $email ) {
			$by_email = get_user_by( 'email', $email );
			$user_id  = $by_email ? (int) $by_email->ID : 0;
		}

		if ( $user_id ) {
			// Update existing user — including email so renames carry over.
			$update_args = array(
				'ID'           => $user_id,
				'first_name'   => $profile_data['first_name'] ?? '',
				'last_name'    => $profile_data['last_name'] ?? '',
				'display_name' => $profile_data['display_name'] ?? '',
			);
			if ( $email ) {
				$existing_for_email = get_user_by( 'email', $email );
				// Only update email if it's free or already this user's.
				if ( ! $existing_for_email || (int) $existing_for_email->ID === $user_id ) {
					$update_args['user_email'] = $email;
				}
			}
			wp_update_user( $update_args );
			$action = 'updated';
		} else {
			// Create new user (allowed on every site context now).
			$first_name = $profile_data['first_name'] ?? '';
			$last_name  = $profile_data['last_name'] ?? '';
			$username   = sanitize_user( strtolower( $first_name . '.' . $last_name ) );
			$username   = str_replace( ' ', '', $username );

			if ( username_exists( $username ) ) {
				$username .= wp_rand( 1, 999 );
			}

			$user_id = wp_insert_user( array(
				'user_login'   => $username,
				'user_email'   => $email,
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'display_name' => $profile_data['display_name'] ?? trim( $first_name . ' ' . $last_name ),
				'user_pass'    => wp_generate_password(),
				'role'         => 'subscriber',
			) );

			if ( is_wp_error( $user_id ) ) {
				return new \WP_Error(
					'user_creation_failed',
					$user_id->get_error_message(),
					array( 'status' => 500 )
				);
			}

			$action = 'created';
		}

		// Sync ALL profile fields as frs_* user meta. The Profile model's
		// toArray() output is the canonical hub→marketing payload — every
		// scalar/array key written here as frs_<key> meta. Removes the
		// previous curated allowlist that silently dropped fields like
		// `specialties_lo`, `custom_links`, `namb_certifications`, etc.
		$skip_keys = array(
			'id', 'user_id', 'email',
			'first_name', 'last_name', 'display_name',
			'headshot_url', // handled separately by sync_remote_image()
			'avatar', 'avatar_url',
		);
		foreach ( $profile_data as $key => $value ) {
			if ( ! is_string( $key ) || in_array( $key, $skip_keys, true ) ) {
				continue;
			}
			if ( ! is_scalar( $value ) && ! is_array( $value ) && $value !== null ) {
				continue;
			}
			$meta_key = 'frs_' . $key;
			update_user_meta( $user_id, $meta_key, $value );
		}

		// Set frs_company_role (singular, multi-value meta) from company_roles or select_person_type.
		// The rest of the codebase queries frs_company_role (singular) as multi-value rows.
		$roles_to_set = array();
		if ( ! empty( $profile_data['company_roles'] ) && is_array( $profile_data['company_roles'] ) ) {
			$roles_to_set = $profile_data['company_roles'];
		} elseif ( ! empty( $profile_data['select_person_type'] ) ) {
			$roles_to_set = array( $profile_data['select_person_type'] );
		}
		if ( ! empty( $roles_to_set ) ) {
			delete_user_meta( $user_id, 'frs_company_role' );
			foreach ( $roles_to_set as $role ) {
				add_user_meta( $user_id, 'frs_company_role', $role );
			}
		}

		// Sync headshot/avatar image from hub.
		// Always use URL download — headshot_id from hub is a hub-side attachment ID
		// that does not exist in this site's media library.
		if ( ! empty( $profile_data['headshot_url'] ) ) {
			$attachment_id = self::sync_remote_image( $profile_data['headshot_url'] );
			if ( $attachment_id ) {
				Avatar::set( $user_id, $attachment_id );
			}
		}

		// Set WordPress role.
		if ( ! empty( $profile_data['select_person_type'] ) ) {
			$wp_role = Roles::get_wp_role_for_company_role( $profile_data['select_person_type'] );
			if ( $wp_role ) {
				$user_obj = new \WP_User( $user_id );
				// Remove any existing FRS roles first.
				$frs_roles = array_keys( Roles::get_wp_roles() );
				foreach ( $frs_roles as $frs_role ) {
					$user_obj->remove_role( $frs_role );
				}
				$user_obj->add_role( $wp_role );

				// On non-hub sites, synced users are directory-only — strip subscriber so they cannot log in.
				if ( ! Roles::is_profile_editing_enabled() ) {
					$user_obj->remove_role( 'subscriber' );
				}
			}
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'action'  => $action,
				'user_id' => $user_id,
				'message' => sprintf( 'Profile %s successfully.', $action ),
			),
			200
		);
	}

	/**
	 * Handle profile_deleted webhook event
	 *
	 * @param array $payload Webhook payload.
	 * @return \WP_REST_Response Response.
	 */
	private static function handle_profile_deleted( $payload ) {
		$email = $payload['email'] ?? '';

		if ( empty( $email ) ) {
			return new \WP_Error(
				'missing_email',
				__( 'Missing email in delete webhook.', 'frs-users' ),
				array( 'status' => 400 )
			);
		}

		$user = get_user_by( 'email', $email );

		if ( ! $user ) {
			return new \WP_REST_Response(
				array(
					'success' => true,
					'message' => 'User not found (already deleted or never existed).',
				),
				200
			);
		}

		// Instead of deleting, just deactivate
		update_user_meta( $user->ID, 'frs_is_active', 0 );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => 'Profile deactivated.',
				'user_id' => $user->ID,
			),
			200
		);
	}

	/**
	 * Look up a user by their FRS NMLS meta value.
	 *
	 * NMLS is the canonical key for loan-officer profiles — match-by-NMLS
	 * prevents duplicates on the marketing site even when email changes.
	 *
	 * @param string $nmls NMLS number.
	 * @return int User ID, or 0 if no match.
	 */
	private static function find_user_id_by_nmls( $nmls ) {
		$nmls = trim( (string) $nmls );
		if ( $nmls === '' ) {
			return 0;
		}
		$matches = get_users( array(
			'meta_key'   => 'frs_nmls',
			'meta_value' => $nmls,
			'number'     => 1,
			'fields'     => 'ID',
		) );
		return $matches ? (int) $matches[0] : 0;
	}

	/**
	 * Download a remote image and import into the local media library.
	 *
	 * Uses URL hash for deduplication so images aren't re-downloaded on every webhook.
	 *
	 * @param string $image_url Remote image URL.
	 * @return int|false Local attachment ID, or false on failure.
	 */
	public static function sync_remote_image( $image_url ) {
		if ( empty( $image_url ) ) {
			return false;
		}

		// Fix protocol-relative URLs (//example.com/...) → https://example.com/...
		if ( strpos( $image_url, '//' ) === 0 ) {
			$image_url = 'https:' . $image_url;
		}

		$url_hash = md5( $image_url );

		// Check if image already exists locally
		$existing = get_posts( array(
			'post_type'      => 'attachment',
			'meta_query'     => array(
				array(
					'key'     => '_frs_image_url_hash',
					'value'   => $url_hash,
					'compare' => '=',
				),
			),
			'posts_per_page' => 1,
		) );

		if ( $existing ) {
			return $existing[0]->ID;
		}

		// Transient lock to prevent race condition (concurrent webhooks downloading same image)
		$lock_key = 'frs_img_sync_' . $url_hash;
		if ( get_transient( $lock_key ) ) {
			// Another process is downloading this image — wait briefly then check again
			sleep( 2 );
			$existing = get_posts( array(
				'post_type'      => 'attachment',
				'meta_query'     => array(
					array(
						'key'     => '_frs_image_url_hash',
						'value'   => $url_hash,
						'compare' => '=',
					),
				),
				'posts_per_page' => 1,
			) );
			return $existing ? $existing[0]->ID : false;
		}
		set_transient( $lock_key, true, 60 );

		// Download and sideload the image
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $image_url );
		if ( is_wp_error( $tmp ) ) {
			error_log( sprintf( '[FRS Sync] Failed to download image %s: %s', $image_url, $tmp->get_error_message() ) );
			return false;
		}

		$filename   = basename( parse_url( $image_url, PHP_URL_PATH ) );
		$file_array = array(
			'name'     => $filename ?: 'headshot-' . $url_hash . '.jpg',
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file_array, 0 );

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			delete_transient( $lock_key );
			error_log( sprintf( '[FRS Sync] Failed to sideload image %s: %s', $image_url, $attachment_id->get_error_message() ) );
			return false;
		}

		update_post_meta( $attachment_id, '_frs_image_url_hash', $url_hash );
		update_post_meta( $attachment_id, '_frs_original_url', $image_url );
		delete_transient( $lock_key );

		return $attachment_id;
	}

	/**
	 * Add a webhook endpoint to the list
	 *
	 * @param string $url Endpoint URL.
	 * @return bool Success.
	 */
	public static function add_webhook_endpoint( $url ) {
		$endpoints = get_option( 'frs_webhook_endpoints', array() );

		if ( ! in_array( $url, $endpoints, true ) ) {
			$endpoints[] = esc_url_raw( $url );
			return update_option( 'frs_webhook_endpoints', $endpoints );
		}

		return true;
	}

	/**
	 * Remove a webhook endpoint from the list
	 *
	 * @param string $url Endpoint URL.
	 * @return bool Success.
	 */
	public static function remove_webhook_endpoint( $url ) {
		$endpoints = get_option( 'frs_webhook_endpoints', array() );
		$endpoints = array_filter( $endpoints, function( $ep ) use ( $url ) {
			return $ep !== $url;
		} );

		return update_option( 'frs_webhook_endpoints', array_values( $endpoints ) );
	}

	/**
	 * Get all configured webhook endpoints
	 *
	 * @return array Endpoint URLs.
	 */
	public static function get_webhook_endpoints() {
		return get_option( 'frs_webhook_endpoints', array() );
	}
}

<?php
/**
 * FRS Users REST API Routes
 *
 * Registers REST API routes for profile management.
 *
 * @package FRSUsers
 * @subpackage Routes
 * @since 1.0.0
 */

namespace FRSUsers\Routes;

use FRSUsers\Controllers\Profiles\Actions;

/**
 * Class Api
 *
 * Handles REST API route registration for profiles.
 *
 * @package FRSUsers\Routes
 */
class Api {

	/**
	 * API namespace
	 *
	 * @var string
	 */
	private static $namespace = 'frs-users/v1';

	/**
	 * Actions controller instance
	 *
	 * @var Actions
	 */
	private static $actions;

	/**
	 * Initialize API routes
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		self::$actions = new Actions();
	}

	/**
	 * Register all REST API routes
	 *
	 * @return void
	 */
	public static function register_routes() {
		// Get all profiles
		register_rest_route(
			self::$namespace,
			'/profiles',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::$actions, 'get_profiles' ),
				'permission_callback' => array( self::$actions, 'check_read_permissions' ),
				'args'                => array(
					'type'         => array(
						'description' => 'Filter by profile type',
						'type'        => 'string',
						'required'    => false,
					),
					'company_role' => array(
						'description'       => 'Filter by company role (alias for type)',
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'search'       => array(
						'description'       => 'Search profiles by name, email, or job title',
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'letter'       => array(
						'description'       => 'Filter by first letter of last name',
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'orderby'      => array(
						'description' => 'Field to order by',
						'type'        => 'string',
						'default'     => 'last_name',
						'enum'        => array( 'last_name', 'first_name', 'display_name' ),
					),
					'order'        => array(
						'description' => 'Sort direction',
						'type'        => 'string',
						'default'     => 'asc',
						'enum'        => array( 'asc', 'desc' ),
					),
					'guests_only'  => array(
						'description' => 'Get only guest profiles',
						'type'        => 'boolean',
						'required'    => false,
					),
					'per_page'     => array(
						'description'       => 'Number of profiles per page',
						'type'              => 'integer',
						'default'           => 50,
						'minimum'           => 1,
						'maximum'           => 1000,
						'sanitize_callback' => 'absint',
					),
					'page'         => array(
						'description'       => 'Page number',
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Create profile
		register_rest_route(
			self::$namespace,
			'/profiles',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::$actions, 'create_profile' ),
				'permission_callback' => array( self::$actions, 'check_write_permissions' ),
			)
		);

		// Get single profile
		register_rest_route(
			self::$namespace,
			'/profiles/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::$actions, 'get_profile' ),
				'permission_callback' => array( self::$actions, 'check_read_permissions' ),
				'args'                => array(
					'id' => array(
						'description'       => 'Profile ID',
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Update profile
		register_rest_route(
			self::$namespace,
			'/profiles/(?P<id>\d+)',
			array(
				'methods'             => 'PUT',
				'callback'            => array( self::$actions, 'update_profile' ),
				'permission_callback' => array( self::$actions, 'check_write_permissions' ),
				'args'                => array(
					'id' => array(
						'description'       => 'Profile ID',
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Delete profile
		register_rest_route(
			self::$namespace,
			'/profiles/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( self::$actions, 'delete_profile' ),
				'permission_callback' => array( self::$actions, 'check_write_permissions' ),
				'args'                => array(
					'id' => array(
						'description'       => 'Profile ID',
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Get / update profile by user ID ("me" resolves to the current user)
		register_rest_route(
			self::$namespace,
			'/profiles/user/(?P<user_id>[\w-]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( self::$actions, 'get_profile_by_user' ),
					'permission_callback' => array( self::$actions, 'check_read_permissions' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( self::$actions, 'update_profile_by_user' ),
					'permission_callback' => array( self::$actions, 'check_update_own_profile' ),
				),
				'args'                => array(
					'user_id' => array(
						'description' => 'WordPress user ID or "me" for current user',
						'type'        => 'string',
						'required'    => true,
					),
				),
			)
		);

		// Get profile by slug (public endpoint)
		register_rest_route(
			self::$namespace,
			'/profiles/slug/(?P<slug>[a-z0-9-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::$actions, 'get_profile_by_slug' ),
				'permission_callback' => '__return_true', // Public endpoint
				'args'                => array(
					'slug' => array(
						'description'       => 'Profile slug',
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_title',
					),
				),
			)
		);

		// Create user account for guest profile
		register_rest_route(
			self::$namespace,
			'/profiles/(?P<id>\d+)/create-user',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::$actions, 'create_user_account' ),
				'permission_callback' => array( self::$actions, 'check_write_permissions' ),
				'args'                => array(
					'id'         => array(
						'description'       => 'Profile ID',
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'username'   => array(
						'description'       => 'Username for new user (auto-generated if not provided)',
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_user',
					),
					'send_email' => array(
						'description' => 'Send password reset email to new user',
						'type'        => 'boolean',
						'default'     => true,
					),
					'roles'      => array(
						'description' => 'Additional WordPress roles to assign',
						'type'        => 'array',
						'default'     => array(),
						'items'       => array(
							'type' => 'string',
						),
					),
				),
			)
		);

		// Bulk create user accounts
		register_rest_route(
			self::$namespace,
			'/profiles/bulk-create-users',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::$actions, 'bulk_create_users' ),
				'permission_callback' => array( self::$actions, 'check_write_permissions' ),
				'args'                => array(
					'profile_ids' => array(
						'description' => 'Array of profile IDs to create users for',
						'type'        => 'array',
						'required'    => true,
						'items'       => array(
							'type' => 'integer',
						),
					),
					'send_email'  => array(
						'description' => 'Send password reset emails to new users',
						'type'        => 'boolean',
						'default'     => true,
					),
				),
			)
		);

		// Get sync settings
		register_rest_route(
			self::$namespace,
			'/sync-settings',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::$actions, 'get_sync_settings' ),
				'permission_callback' => array( self::$actions, 'check_write_permissions' ),
			)
		);

		// Save sync settings
		register_rest_route(
			self::$namespace,
			'/sync-settings',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::$actions, 'save_sync_settings' ),
				'permission_callback' => array( self::$actions, 'check_write_permissions' ),
			)
		);

		// Get sync statistics
		register_rest_route(
			self::$namespace,
			'/sync-stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::$actions, 'get_sync_stats' ),
				'permission_callback' => array( self::$actions, 'check_read_permissions' ),
			)
		);

		// Trigger manual sync
		register_rest_route(
			self::$namespace,
			'/trigger-sync',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::$actions, 'trigger_sync' ),
				'permission_callback' => array( self::$actions, 'check_write_permissions' ),
			)
		);

		// Meeting request (public endpoint for contact form)
		register_rest_route(
			self::$namespace,
			'/meeting-request',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::$actions, 'submit_meeting_request' ),
				'permission_callback' => '__return_true', // Public endpoint
				'args'                => array(
					'profile_id'    => array(
						'description'       => 'Profile ID to send request to',
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'profile_email' => array(
						'description'       => 'Profile email address',
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
					),
					'profile_name'  => array(
						'description'       => 'Profile owner name',
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'name'          => array(
						'description'       => 'Requester name',
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'email'         => array(
						'description'       => 'Requester email',
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
					),
					'phone'         => array(
						'description'       => 'Requester phone',
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'message'       => array(
						'description'       => 'Meeting request message',
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		// Get all unique service areas (public endpoint for directory filtering)
		register_rest_route(
			self::$namespace,
			'/service-areas',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::$actions, 'get_service_areas' ),
				'permission_callback' => '__return_true', // Public endpoint
			)
		);

		// Download vCard for a profile (public endpoint)
		register_rest_route(
			self::$namespace,
			'/vcard/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_vcard' ),
				'permission_callback' => '__return_true', // Public endpoint
				'args'                => array(
					'id' => array(
						'description'       => 'User ID for vCard generation',
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Get all user settings for current user
		register_rest_route(
			self::$namespace,
			'/profiles/me/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( self::$actions, 'get_user_settings' ),
					'permission_callback' => array( self::$actions, 'check_authenticated' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( self::$actions, 'update_user_settings' ),
					'permission_callback' => array( self::$actions, 'check_authenticated' ),
				),
			)
		);

		// Notification settings
		register_rest_route(
			self::$namespace,
			'/profiles/me/settings/notifications',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( self::$actions, 'get_notification_settings' ),
					'permission_callback' => array( self::$actions, 'check_authenticated' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( self::$actions, 'update_notification_settings' ),
					'permission_callback' => array( self::$actions, 'check_authenticated' ),
				),
			)
		);

		// Privacy settings
		register_rest_route(
			self::$namespace,
			'/profiles/me/settings/privacy',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( self::$actions, 'get_privacy_settings' ),
					'permission_callback' => array( self::$actions, 'check_authenticated' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( self::$actions, 'update_privacy_settings' ),
					'permission_callback' => array( self::$actions, 'check_authenticated' ),
				),
			)
		);

		// Get integrations overview for current user
		register_rest_route(
			self::$namespace,
			'/profiles/me/integrations',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::$actions, 'get_integrations' ),
				'permission_callback' => array( self::$actions, 'check_authenticated' ),
			)
		);
		// Activity feed for a user profile (company-level visibility)
		register_rest_route(
			self::$namespace,
			'/profiles/(?P<id>\d+)/activity',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_activity_feed' ),
				'permission_callback' => array( self::$actions, 'check_authenticated' ),
				'args'                => array(
					'id'       => array(
						'description'       => 'User ID',
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'page'     => array(
						'description'       => 'Page number',
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'description'       => 'Items per page',
						'type'              => 'integer',
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Activity feed for current user (shorthand)
		register_rest_route(
			self::$namespace,
			'/profiles/me/activity',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_my_activity_feed' ),
				'permission_callback' => array( self::$actions, 'check_authenticated' ),
				'args'                => array(
					'page'     => array(
						'description'       => 'Page number',
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'description'       => 'Items per page',
						'type'              => 'integer',
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Create post draft for composer.
		register_rest_route(
			self::$namespace,
			'/posts/create-draft',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_post_draft' ),
				'permission_callback' => array( self::$actions, 'check_authenticated' ),
				'args'                => array(
					'format' => array(
						'description'       => 'Post format (standard, image, video, audio, link, quote, gallery, status, aside, chat)',
						'type'              => 'string',
						'required'          => false,
						'default'           => 'standard',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// vCard sharing settings for current user
		register_rest_route(
			self::$namespace,
			'/settings/vcard',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_vcard_settings' ),
					'permission_callback' => array( self::$actions, 'check_authenticated' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( __CLASS__, 'update_vcard_settings' ),
					'permission_callback' => array( self::$actions, 'check_authenticated' ),
				),
			)
		);

		// Allow hooks to add more custom API routes
		do_action( 'frs_users_api_routes', self::$namespace );
	}

	/**
	 * Generate and return vCard for a user
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error Response with vCard or error.
	 */
	public static function get_vcard( $request ) {
		$user_id = $request->get_param( 'id' );

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new \WP_Error(
				'user_not_found',
				__( 'User not found.', 'frs-users' ),
				array( 'status' => 404 )
			);
		}

		// Check if user is active
		$is_active = get_user_meta( $user_id, 'frs_is_active', true );
		if ( ! $is_active ) {
			return new \WP_Error(
				'profile_inactive',
				__( 'This profile is not active.', 'frs-users' ),
				array( 'status' => 403 )
			);
		}

		// Generate vCard
		$vcard = \FRSUsers\Core\VCardGenerator::generate( $user_id );

		// Get user name for filename
		$first_name = get_user_meta( $user_id, 'first_name', true ) ?: 'contact';
		$last_name  = get_user_meta( $user_id, 'last_name', true ) ?: 'card';
		$filename   = sanitize_file_name( $first_name . '-' . $last_name . '.vcf' );

		// Return vCard with proper headers
		$response = new \WP_REST_Response( $vcard );
		$response->header( 'Content-Type', 'text/vcard; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename="' . $filename . '"' );
		$response->header( 'Cache-Control', 'no-cache, no-store, must-revalidate' );

		return $response;
	}

	/**
	 * Create an auto-draft post for the frontend composer
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create_post_draft( $request ) {
		$user_id = get_current_user_id();

		if ( ! $user_id || ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error(
				'unauthorized',
				__( 'You do not have permission to create posts.', 'frs-users' ),
				array( 'status' => 403 )
			);
		}

		$format = $request->get_param( 'format' ) ?: 'standard';

		// Get the PFBT pattern content for the selected format.
		$content = self::get_format_pattern_content( $format );

		// Create auto-draft.
		$post_id = wp_insert_post(
			array(
				'post_status' => 'auto-draft',
				'post_type'   => 'post',
				'post_author' => $user_id,
				'post_title'  => __( 'Auto Draft', 'frs-users' ),
				'post_content' => $content,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return new \WP_Error(
				'draft_creation_failed',
				$post_id->get_error_message(),
				array( 'status' => 500 )
			);
		}

		// Set post format if not standard.
		if ( 'standard' !== $format ) {
			set_post_format( $post_id, $format );
		}

		$editor_url = admin_url( 'admin.php?page=frs-post-composer&post_id=' . $post_id . '&frs_composer=1&frs_format=' . rawurlencode( $format ) );

		return new \WP_REST_Response(
			array(
				'post_id'    => $post_id,
				'editor_url' => $editor_url,
			),
			201
		);
	}

	/**
	 * Get block pattern content for a post format.
	 *
	 * @param string $format Post format slug.
	 * @return string Block content.
	 */
	private static function get_format_pattern_content( $format ) {
		$patterns = self::get_format_patterns();

		if ( isset( $patterns[ $format ] ) ) {
			return $patterns[ $format ];
		}

		return $patterns['standard'];
	}

	/**
	 * Get all format patterns keyed by format slug.
	 *
	 * @return array<string, string>
	 */
	public static function get_format_patterns() {
		return array(
			'standard' => "<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->",
			'image'    => "<!-- wp:image {\"sizeSlug\":\"large\"} -->\n<figure class=\"wp-block-image size-large\"><img alt=\"\"/></figure>\n<!-- /wp:image -->\n\n<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->",
			'gallery'  => "<!-- wp:gallery {\"linkTo\":\"none\"} -->\n<figure class=\"wp-block-gallery has-nested-images columns-default is-cropped\"></figure>\n<!-- /wp:gallery -->\n\n<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->",
			'video'    => "<!-- wp:video -->\n<figure class=\"wp-block-video\"><video controls></video></figure>\n<!-- /wp:video -->\n\n<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->",
			'audio'    => "<!-- wp:audio -->\n<figure class=\"wp-block-audio\"><audio controls></audio></figure>\n<!-- /wp:audio -->\n\n<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->",
			'quote'    => "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">\n<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->\n</blockquote>\n<!-- /wp:quote -->\n\n<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->",
			'link'     => "<!-- wp:paragraph {\"className\":\"link-format-fallback\",\"fontSize\":\"large\"} -->\n<p class=\"link-format-fallback has-large-font-size\"><a href=\"#\"></a></p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->",
		);
	}

	/**
	 * Default vCard sharing settings.
	 *
	 * @return array
	 */
	private static function get_vcard_defaults(): array {
		return array(
			'include_office_phone'    => true,
			'include_mobile'          => true,
			'include_email'           => true,
			'include_biography'       => false,
			'include_social_linkedin' => true,
			'include_social_facebook' => false,
			'include_social_instagram' => false,
			'include_photo'           => true,
			'include_nmls'            => true,
		);
	}

	/**
	 * Get vCard sharing settings for current user
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public static function get_vcard_settings( $request ) {
		$user_id  = get_current_user_id();
		$defaults = self::get_vcard_defaults();

		$raw = get_user_meta( $user_id, 'frs_vcard_settings', true );
		if ( is_string( $raw ) && ! empty( $raw ) ) {
			$saved = json_decode( $raw, true ) ?: array();
		} elseif ( is_array( $raw ) ) {
			$saved = $raw;
		} else {
			$saved = array();
		}

		// Merge saved on top of defaults
		$settings = array_merge( $defaults, $saved );

		return new \WP_REST_Response( $settings, 200 );
	}

	/**
	 * Update vCard sharing settings for current user
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update_vcard_settings( $request ) {
		$user_id  = get_current_user_id();
		$defaults = self::get_vcard_defaults();
		$params   = $request->get_json_params();

		// Only allow known keys, cast to bool
		$settings = array();
		foreach ( array_keys( $defaults ) as $key ) {
			if ( isset( $params[ $key ] ) ) {
				$settings[ $key ] = (bool) $params[ $key ];
			}
		}

		update_user_meta( $user_id, 'frs_vcard_settings', wp_json_encode( $settings ) );

		// Return merged result
		$merged = array_merge( $defaults, $settings );
		return new \WP_REST_Response(
			array(
				'success'  => true,
				'settings' => $merged,
			),
			200
		);
	}

	/**
	 * Get activity feed for a user profile
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_activity_feed( $request ) {
		$user_id  = $request->get_param( 'id' );
		$page     = $request->get_param( 'page' ) ?: 1;
		$per_page = $request->get_param( 'per_page' ) ?: 20;

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new \WP_Error(
				'user_not_found',
				__( 'User not found.', 'frs-users' ),
				array( 'status' => 404 )
			);
		}

		$result = \FRSUsers\Models\ActivityLog::get_for_user( $user_id, $page, $per_page );

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Get activity feed for current user
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_my_activity_feed( $request ) {
		$request->set_param( 'id', get_current_user_id() );
		return self::get_activity_feed( $request );
	}
}

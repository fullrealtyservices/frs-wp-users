<?php
/**
 * Profile Model
 *
 * WordPress-native user profile model using wp_users + wp_usermeta.
 *
 * @package FRSUsers
 * @subpackage Models
 * @since 1.0.0
 */

namespace FRSUsers\Models;

/**
 * Class Profile
 *
 * Represents a user profile stored in WordPress users + usermeta.
 * All profiles are linked to WordPress users (no guest profiles).
 *
 * @package FRSUsers\Models
 */
class Profile {

	/**
	 * Profile/User ID.
	 *
	 * @var int
	 */
	public $id;

	/**
	 * WordPress User ID (same as $id).
	 *
	 * @var int
	 */
	public $user_id;

	/**
	 * User email.
	 *
	 * @var string
	 */
	public $email;

	/**
	 * Secondary/alternate email address. Shown alongside the primary
	 * email (which stays first) rather than creating a second account
	 * for the same person.
	 *
	 * @var string
	 */
	public $secondary_email;

	/**
	 * Display name.
	 *
	 * @var string
	 */
	public $display_name;

	/**
	 * First name.
	 *
	 * @var string
	 */
	public $first_name;

	/**
	 * Last name.
	 *
	 * @var string
	 */
	public $last_name;

	/**
	 * Phone number.
	 *
	 * @var string
	 */
	public $phone_number;

	/**
	 * Mobile number.
	 *
	 * @var string
	 */
	public $mobile_number;

	/**
	 * Office.
	 *
	 * @var string
	 */
	public $office;

	/**
	 * Company name.
	 *
	 * @var string
	 */
	public $company_name;

	/**
	 * Company logo attachment ID.
	 *
	 * @var int
	 */
	public $company_logo_id;

	/**
	 * Company website.
	 *
	 * @var string
	 */
	public $company_website;

	/**
	 * Headshot attachment ID.
	 *
	 * @var int
	 */
	public $headshot_id;

	/**
	 * Headshot focal point (CSS object-position, e.g. "50% 30%").
	 *
	 * Used by templates to keep the face centered when the headshot is
	 * cropped at different aspect ratios. Free-form string.
	 *
	 * @var string
	 */
	public $headshot_focal_point;

	/**
	 * Job title.
	 *
	 * @var string
	 */
	public $job_title;

	/**
	 * Biography.
	 *
	 * @var string
	 */
	public $biography;

	/**
	 * Date of birth.
	 *
	 * @var string
	 */
	public $date_of_birth;

	/**
	 * Person type (loan_originator, broker_associate, etc.).
	 *
	 * @var string
	 */
	public $select_person_type;

	/**
	 * NMLS ID.
	 *
	 * @var string
	 */
	public $nmls;

	/**
	 * NMLS number.
	 *
	 * @var string
	 */
	public $nmls_number;

	/**
	 * License number.
	 *
	 * @var string
	 */
	public $license_number;

	/**
	 * DRE license.
	 *
	 * @var string
	 */
	public $dre_license;

	/**
	 * Loan officer specialties.
	 *
	 * @var array
	 */
	public $specialties_lo = array();

	/**
	 * Specialties.
	 *
	 * @var array
	 */
	public $specialties = array();

	/**
	 * Service areas (state abbreviations).
	 *
	 * @var array
	 */
	public $service_areas = array();

	/**
	 * Languages.
	 *
	 * @var array
	 */
	public $languages = array();

	/**
	 * Awards.
	 *
	 * @var array
	 */
	public $awards = array();

	/**
	 * NAR designations.
	 *
	 * @var array
	 */
	public $nar_designations = array();

	/**
	 * NAMB certifications.
	 *
	 * @var array
	 */
	public $namb_certifications = array();

	/**
	 * Brand.
	 *
	 * @var string
	 */
	public $brand;

	/**
	 * Status.
	 *
	 * @var string
	 */
	public $status;

	/**
	 * City/State.
	 *
	 * @var string
	 */
	public $city_state;

	/**
	 * Region.
	 *
	 * @var string
	 */
	public $region;

	/**
	 * Facebook URL.
	 *
	 * @var string
	 */
	public $facebook_url;

	/**
	 * Instagram URL.
	 *
	 * @var string
	 */
	public $instagram_url;

	/**
	 * LinkedIn URL.
	 *
	 * @var string
	 */
	public $linkedin_url;

	/**
	 * Twitter URL.
	 *
	 * @var string
	 */
	public $twitter_url;

	/**
	 * YouTube URL.
	 *
	 * @var string
	 */
	public $youtube_url;

	/**
	 * TikTok URL.
	 *
	 * @var string
	 */
	public $tiktok_url;

	/**
	 * Telegram username.
	 *
	 * @var string
	 */
	public $telegram_username;

	/**
	 * Century21 URL.
	 *
	 * @var string
	 */
	public $century21_url;

	/**
	 * Zillow URL.
	 *
	 * @var string
	 */
	public $zillow_url;

	/**
	 * Arrive URL.
	 *
	 * @var string
	 */
	public $arrive;

	/**
	 * Canva folder link.
	 *
	 * @var string
	 */
	public $canva_folder_link;

	/**
	 * Niche bio content.
	 *
	 * @var string
	 */
	public $niche_bio_content;

	/**
	 * Personal branding images.
	 *
	 * @var array
	 */
	public $personal_branding_images = array();

	/**
	 * Linked loan officer profile ID.
	 *
	 * @var int
	 */
	public $loan_officer_profile;

	/**
	 * Linked loan officer user ID.
	 *
	 * @var int
	 */
	public $loan_officer_user;

	/**
	 * Profile slug.
	 *
	 * @var string
	 */
	public $profile_slug;

	/**
	 * Profile headline.
	 *
	 * @var string
	 */
	public $profile_headline;

	/**
	 * Profile visibility settings.
	 *
	 * @var array
	 */
	public $profile_visibility = array();

	/**
	 * Profile theme.
	 *
	 * @var string
	 */
	public $profile_theme;

	/**
	 * Custom links.
	 *
	 * @var array
	 */
	public $custom_links = array();

	/**
	 * Directory button type.
	 *
	 * @var string
	 */
	public $directory_button_type;

	/**
	 * FluentBooking booking page URL.
	 *
	 * @var string
	 */
	public $booking_url;

	/**
	 * QR code data/URL.
	 *
	 * @var string
	 */
	public $qr_code_data;

	/**
	 * Is active flag.
	 *
	 * @var bool
	 */
	public $is_active = true;

	/**
	 * FRS Agent ID.
	 *
	 * @var string
	 */
	public $frs_agent_id;

	/**
	 * Company roles (multiple).
	 *
	 * @var array
	 */
	public $company_roles = array();

	/**
	 * Synced to FluentCRM timestamp.
	 *
	 * @var string
	 */
	public $synced_to_fluentcrm_at;

	/**
	 * Created at timestamp.
	 *
	 * @var string
	 */
	public $created_at;

	/**
	 * Follow Up Boss API key.
	 *
	 * @var string
	 */
	public $followupboss_api_key;

	/**
	 * Follow Up Boss integration status.
	 *
	 * @var array
	 */
	public $followupboss_status;

	/**
	 * User notification settings.
	 *
	 * @var array
	 */
	public $notification_settings;

	/**
	 * User privacy settings.
	 *
	 * @var array
	 */
	public $privacy_settings;

	/**
	 * Updated at timestamp.
	 *
	 * @var string
	 */
	public $updated_at;

	/**
	 * Whether this profile exists in the database.
	 *
	 * @var bool
	 */
	public $exists = false;

	/**
	 * Get all active profiles.
	 *
	 * @param array $args Optional arguments (limit, offset, type).
	 * @return array Array of Profile objects.
	 */
	public static function get_all( $args = array() ) {
		$wp_args = array(
			'role__in' => array(
				'loan_officer',
				're_agent',
				'escrow_officer',
				'property_manager',
				'dual_license',
				'partner',
				'staff',
				'leadership',
				'assistant',
			),
			'orderby'  => 'meta_value',
			'meta_key' => 'first_name',
			'order'    => 'ASC',
		);

		// Handle type filter (filter by frs_company_role meta)
		if ( ! empty( $args['type'] ) ) {
			$wp_args['meta_query'] = array(
				array(
					'key'   => 'frs_company_role',
					'value' => $args['type'],
				),
			);
		}

		// Pagination
		if ( isset( $args['limit'] ) ) {
			$wp_args['number'] = $args['limit'];
		}
		if ( isset( $args['offset'] ) ) {
			$wp_args['offset'] = $args['offset'];
		}

		$users = get_users( $wp_args );

		return array_map( array( static::class, 'hydrate_from_user' ), $users );
	}

	/**
	 * Get profiles by type/role.
	 *
	 * @param string $type Profile type (loan_originator, broker_associate, etc.).
	 * @param array  $args Optional arguments (limit, offset).
	 * @return array Array of Profile objects.
	 */
	public static function get_by_type( $type, $args = array() ) {
		$wp_args = array(
			'meta_query' => array(
				array(
					'key'   => 'frs_company_role',
					'value' => $type,
				),
			),
			'orderby'  => 'meta_value',
			'meta_key' => 'first_name',
			'order'    => 'ASC',
		);

		if ( isset( $args['limit'] ) ) {
			$wp_args['number'] = $args['limit'];
		}
		if ( isset( $args['offset'] ) ) {
			$wp_args['offset'] = $args['offset'];
		}

		$users = get_users( $wp_args );

		return array_map( array( static::class, 'hydrate_from_user' ), $users );
	}

	/**
	 * Get profile by user ID.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return Profile|null
	 */
	public static function get_by_user_id( $user_id ) {
		return static::find( $user_id );
	}

	/**
	 * Get profile by email.
	 *
	 * @param string $email Email address.
	 * @return Profile|null
	 */
	public static function get_by_email( $email ) {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return null;
		}
		return static::hydrate_from_user( $user );
	}

	/**
	 * Get profile by slug.
	 *
	 * @param string $slug Profile slug (user_nicename).
	 * @return Profile|null
	 */
	public static function get_by_slug( $slug ) {
		$user = get_user_by( 'slug', $slug );
		if ( $user ) {
			return static::hydrate_from_user( $user );
		}

		// Fallback to custom profile_slug meta
		$users = get_users( array(
			'meta_key'   => 'frs_profile_slug',
			'meta_value' => $slug,
			'number'     => 1,
		) );

		if ( ! empty( $users ) ) {
			return static::hydrate_from_user( $users[0] );
		}

		return null;
	}

	/**
	 * Get profile by FRS agent ID.
	 *
	 * @param string $frs_agent_id FRS Agent ID.
	 * @return Profile|null
	 */
	public static function get_by_frs_agent_id( $frs_agent_id ) {
		$users = get_users( array(
			'meta_key'   => 'frs_frs_agent_id',
			'meta_value' => $frs_agent_id,
			'number'     => 1,
		) );

		if ( empty( $users ) ) {
			return null;
		}

		return static::hydrate_from_user( $users[0] );
	}

	/**
	 * Find profile by ID.
	 *
	 * @param int $id User ID.
	 * @return Profile|null
	 */
	public static function find( $id ) {
		$user = get_userdata( $id );

		// If not found, check if it's a legacy profile ID
		if ( ! $user ) {
			$user_id = get_option( "frs_legacy_profile_{$id}" );
			if ( $user_id ) {
				$user = get_userdata( $user_id );
			}
		}

		if ( ! $user ) {
			return null;
		}

		return static::hydrate_from_user( $user );
	}

	/**
	 * Check if email exists.
	 *
	 * @param string   $email      Email to check.
	 * @param int|null $exclude_id User ID to exclude from check.
	 * @return bool
	 */
	public static function email_exists( $email, $exclude_id = null ) {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return false;
		}
		if ( $exclude_id && $user->ID === $exclude_id ) {
			return false;
		}
		return true;
	}

	/**
	 * Check if slug exists.
	 *
	 * @param string   $slug       Slug to check.
	 * @param int|null $exclude_id User ID to exclude from check.
	 * @return bool
	 */
	public static function slug_exists( $slug, $exclude_id = null ) {
		$user = get_user_by( 'slug', $slug );
		if ( ! $user ) {
			return false;
		}
		if ( $exclude_id && $user->ID === $exclude_id ) {
			return false;
		}
		return true;
	}

	/**
	 * Generate a unique profile slug.
	 *
	 * @param string   $first_name First name.
	 * @param string   $last_name  Last name.
	 * @param int|null $exclude_id User ID to exclude from uniqueness check.
	 * @return string
	 */
	public static function generate_unique_slug( $first_name, $last_name, $exclude_id = null ) {
		$base_slug = sanitize_title( trim( $first_name . ' ' . $last_name ) );
		$slug      = $base_slug;
		$counter   = 1;
		$max       = 100;

		while ( static::slug_exists( $slug, $exclude_id ) && $counter <= $max ) {
			$slug = $base_slug . '-' . $counter;
			$counter++;
		}

		return $slug;
	}

	/**
	 * Save profile to WordPress users.
	 *
	 * @return bool
	 */
	public function save() {
		if ( ! $this->user_id ) {
			return false;
		}

		// Update wp_users core fields
		$user_data = array(
			'ID'           => $this->user_id,
			'user_email'   => $this->email,
			'display_name' => $this->display_name,
		);

		$result = wp_update_user( $user_data );
		if ( is_wp_error( $result ) ) {
			return false;
		}

		// Update all meta fields
		update_user_meta( $this->user_id, 'first_name', $this->first_name );
		update_user_meta( $this->user_id, 'last_name', $this->last_name );
		update_user_meta( $this->user_id, 'frs_secondary_email', $this->secondary_email );
		update_user_meta( $this->user_id, 'frs_phone_number', $this->phone_number );
		update_user_meta( $this->user_id, 'frs_mobile_number', $this->mobile_number );
		update_user_meta( $this->user_id, 'frs_office', $this->office );
		update_user_meta( $this->user_id, 'frs_company_name', $this->company_name );
		update_user_meta( $this->user_id, 'frs_company_logo_id', $this->company_logo_id );
		update_user_meta( $this->user_id, 'frs_company_website', $this->company_website );
		// Use Avatar helper (single source of truth)
		\FRSUsers\Core\Avatar::set( $this->user_id, $this->headshot_id );
		update_user_meta( $this->user_id, 'frs_job_title', $this->job_title );
		update_user_meta( $this->user_id, 'frs_biography', $this->biography );
		update_user_meta( $this->user_id, 'frs_date_of_birth', $this->date_of_birth );
		update_user_meta( $this->user_id, 'frs_nmls', $this->nmls );
		update_user_meta( $this->user_id, 'frs_nmls_number', $this->nmls_number );
		update_user_meta( $this->user_id, 'frs_license_number', $this->license_number );
		update_user_meta( $this->user_id, 'frs_dre_license', $this->dre_license );
		update_user_meta( $this->user_id, 'frs_brand', $this->brand );
		update_user_meta( $this->user_id, 'frs_status', $this->status );
		update_user_meta( $this->user_id, 'frs_city_state', $this->city_state );
		update_user_meta( $this->user_id, 'frs_region', $this->region );
		update_user_meta( $this->user_id, 'frs_facebook_url', $this->facebook_url );
		update_user_meta( $this->user_id, 'frs_instagram_url', $this->instagram_url );
		update_user_meta( $this->user_id, 'frs_linkedin_url', $this->linkedin_url );
		update_user_meta( $this->user_id, 'frs_twitter_url', $this->twitter_url );
		update_user_meta( $this->user_id, 'frs_youtube_url', $this->youtube_url );
		update_user_meta( $this->user_id, 'frs_tiktok_url', $this->tiktok_url );
		update_user_meta( $this->user_id, 'frs_telegram_username', $this->telegram_username );
		update_user_meta( $this->user_id, 'frs_century21_url', $this->century21_url );
		update_user_meta( $this->user_id, 'frs_zillow_url', $this->zillow_url );
		update_user_meta( $this->user_id, 'frs_arrive', $this->arrive );
		update_user_meta( $this->user_id, 'frs_canva_folder_link', $this->canva_folder_link );
		update_user_meta( $this->user_id, 'frs_niche_bio_content', $this->niche_bio_content );
		update_user_meta( $this->user_id, 'frs_loan_officer_profile', $this->loan_officer_profile );
		update_user_meta( $this->user_id, 'frs_loan_officer_user', $this->loan_officer_user );
		update_user_meta( $this->user_id, 'frs_profile_headline', $this->profile_headline );
		update_user_meta( $this->user_id, 'frs_profile_theme', $this->profile_theme );
		update_user_meta( $this->user_id, 'frs_directory_button_type', $this->directory_button_type );
		update_user_meta( $this->user_id, 'frs_booking_url', $this->booking_url );
		update_user_meta( $this->user_id, 'frs_qr_code_data', $this->qr_code_data );
		update_user_meta( $this->user_id, 'frs_is_active', $this->is_active );
		update_user_meta( $this->user_id, 'frs_frs_agent_id', $this->frs_agent_id );

		// JSON fields
		update_user_meta( $this->user_id, 'frs_specialties_lo', wp_json_encode( $this->specialties_lo ?: array() ) );
		update_user_meta( $this->user_id, 'frs_specialties', wp_json_encode( $this->specialties ?: array() ) );
		update_user_meta( $this->user_id, 'frs_languages', wp_json_encode( $this->languages ?: array() ) );
		update_user_meta( $this->user_id, 'frs_awards', wp_json_encode( $this->awards ?: array() ) );
		update_user_meta( $this->user_id, 'frs_nar_designations', wp_json_encode( $this->nar_designations ?: array() ) );
		update_user_meta( $this->user_id, 'frs_namb_certifications', wp_json_encode( $this->namb_certifications ?: array() ) );
		update_user_meta( $this->user_id, 'frs_personal_branding_images', wp_json_encode( $this->personal_branding_images ?: array() ) );
		update_user_meta( $this->user_id, 'frs_profile_visibility', wp_json_encode( $this->profile_visibility ?: array() ) );
		update_user_meta( $this->user_id, 'frs_custom_links', wp_json_encode( $this->custom_links ?: array() ) );
		update_user_meta( $this->user_id, 'frs_service_areas', wp_json_encode( $this->service_areas ?: array() ) );

		// Update timestamp
		update_user_meta( $this->user_id, 'frs_updated_at', current_time( 'mysql' ) );
		update_user_meta( $this->user_id, 'frs_synced_to_fluentcrm_at', $this->synced_to_fluentcrm_at );

		// Fire hook for backwards compatibility
		do_action( 'frs_profile_saved', $this->id, $this->toArray() );

		return true;
	}

	/**
	 * Get the profile's full name.
	 *
	 * @return string
	 */
	public function get_full_name() {
		return trim( $this->first_name . ' ' . $this->last_name );
	}

	/**
	 * Magic getter for full_name attribute.
	 *
	 * @param string $name Property name.
	 * @return mixed
	 */
	public function __get( $name ) {
		if ( $name === 'full_name' ) {
			return $this->get_full_name();
		}
		if ( $name === 'headshot_url' ) {
			return $this->get_headshot_url();
		}
		return null;
	}

	/**
	 * Get headshot URL.
	 *
	 * Uses Avatar helper (single source of truth).
	 *
	 * @return string|null
	 */
	public function get_headshot_url() {
		if ( ! $this->user_id ) {
			return null;
		}
		return \FRSUsers\Core\Avatar::get_url( $this->user_id ) ?: null;
	}

	/**
	 * Get avatar URL for this profile.
	 *
	 * @param int $size Avatar size in pixels.
	 * @return string
	 */
	public function get_avatar_url( $size = 96 ) {
		if ( $this->user_id ) {
			return get_avatar_url( $this->user_id, array( 'size' => $size ) );
		}
		return get_avatar_url( $this->email, array( 'size' => $size ) );
	}

	/**
	 * Convert the model to an array for API responses.
	 *
	 * @return array
	 */
	public function toArray() {
		$array = array(
			'id'                      => $this->id,
			'user_id'                 => $this->user_id,
			'email'                   => $this->email,
			'secondary_email'         => $this->secondary_email,
			'first_name'              => $this->first_name,
			'last_name'               => $this->last_name,
			'display_name'            => $this->display_name,
			'phone_number'            => $this->phone_number,
			'mobile_number'           => $this->mobile_number,
			'office'                  => $this->office,
			'company_name'            => $this->company_name,
			'company_logo_id'         => $this->company_logo_id,
			'company_website'         => $this->company_website,
			'headshot_id'             => $this->headshot_id,
			'headshot_focal_point'    => $this->headshot_focal_point,
			'job_title'               => $this->job_title,
			'biography'               => $this->biography,
			'date_of_birth'           => $this->date_of_birth,
			'select_person_type'      => $this->select_person_type,
			'nmls'                    => $this->nmls,
			'nmls_number'             => $this->nmls_number,
			'license_number'          => $this->license_number,
			'dre_license'             => $this->dre_license,
			'specialties_lo'          => $this->specialties_lo,
			'specialties'             => $this->specialties,
			'service_areas'           => $this->service_areas,
			'languages'               => $this->languages,
			'awards'                  => $this->awards,
			'nar_designations'        => $this->nar_designations,
			'namb_certifications'     => $this->namb_certifications,
			'brand'                   => $this->brand,
			'status'                  => $this->status,
			'city_state'              => $this->city_state,
			'region'                  => $this->region,
			'facebook_url'            => $this->facebook_url,
			'instagram_url'           => $this->instagram_url,
			'linkedin_url'            => $this->linkedin_url,
			'twitter_url'             => $this->twitter_url,
			'youtube_url'             => $this->youtube_url,
			'tiktok_url'              => $this->tiktok_url,
			'telegram_username'       => $this->telegram_username,
			'century21_url'           => $this->century21_url,
			'zillow_url'              => $this->zillow_url,
			'arrive'                  => $this->arrive,
			'canva_folder_link'       => $this->canva_folder_link,
			'niche_bio_content'       => $this->niche_bio_content,
			'personal_branding_images' => $this->personal_branding_images,
			'loan_officer_profile'    => $this->loan_officer_profile,
			'loan_officer_user'       => $this->loan_officer_user,
			'profile_slug'            => $this->profile_slug,
			'profile_headline'        => $this->profile_headline,
			'profile_visibility'      => $this->profile_visibility,
			'profile_theme'           => $this->profile_theme,
			'custom_links'            => $this->custom_links,
			'directory_button_type'   => $this->directory_button_type,
			'booking_url'             => $this->booking_url,
			'qr_code_data'            => $this->qr_code_data,
			'is_active'               => $this->is_active,
			'frs_agent_id'            => $this->frs_agent_id,
			'synced_to_fluentcrm_at'  => $this->synced_to_fluentcrm_at,
			'created_at'              => $this->created_at,
			'updated_at'              => $this->updated_at,
		);

		// Add computed attributes
		$array['full_name']     = $this->get_full_name();
		$array['headshot_url']  = $this->get_headshot_url();
		$array['avatar_url']    = $this->get_avatar_url( 512 );
		$array['is_guest']      = false; // No guest profiles in WP-native mode
		$array['company_roles'] = $this->company_roles;

		// Set defaults for profile linking (in case user is deleted)
		$array['user_nicename'] = '';
		$array['profile_url']   = '';

		// Add user_nicename and profile_url for profile linking
		if ( $this->user_id ) {
			$user = get_userdata( $this->user_id );
			if ( $user ) {
				$array['user_nicename'] = $user->user_nicename;
				$array['profile_url']   = get_author_posts_url( $this->user_id );
			}
		}

		return $array;
	}

	/**
	 * Parse states from a region string.
	 *
	 * @param string $region Region string.
	 * @return array Array of state abbreviations.
	 */
	public static function parse_states_from_region( $region ) {
		$states       = array();
		$region_lower = strtolower( $region );

		// California regions
		$ca_regions = array(
			'greater la', 'los angeles', 'orange county', 'san diego', 'bay area',
			'inland empire', 'inand empire', 'inland empre', 'sacramento', 'central coast',
			'san francisco', 'san jose', 'headquarters', 'california',
		);

		foreach ( $ca_regions as $ca ) {
			if ( strpos( $region_lower, $ca ) !== false ) {
				$states[] = 'CA';
				break;
			}
		}

		// State name to abbreviation mapping
		$state_map = array(
			'alabama' => 'AL', 'alaska' => 'AK', 'arizona' => 'AZ', 'arkansas' => 'AR',
			'colorado' => 'CO', 'connecticut' => 'CT', 'delaware' => 'DE', 'florida' => 'FL',
			'georgia' => 'GA', 'hawaii' => 'HI', 'idaho' => 'ID', 'illinois' => 'IL',
			'indiana' => 'IN', 'iowa' => 'IA', 'kansas' => 'KS', 'kentucky' => 'KY',
			'louisiana' => 'LA', 'maine' => 'ME', 'maryland' => 'MD', 'massachusetts' => 'MA',
			'michigan' => 'MI', 'minnesota' => 'MN', 'mississippi' => 'MS', 'missouri' => 'MO',
			'montana' => 'MT', 'nebraska' => 'NE', 'nevada' => 'NV', 'new hampshire' => 'NH',
			'new jersey' => 'NJ', 'new mexico' => 'NM', 'new york' => 'NY', 'north carolina' => 'NC',
			'north dakota' => 'ND', 'ohio' => 'OH', 'oklahoma' => 'OK', 'oregon' => 'OR',
			'pennsylvania' => 'PA', 'pennslyvania' => 'PA', 'rhode island' => 'RI',
			'south carolina' => 'SC', 'south dakota' => 'SD', 'tennessee' => 'TN', 'tennesee' => 'TN',
			'texas' => 'TX', 'utah' => 'UT', 'vermont' => 'VT', 'virginia' => 'VA', 'virgina' => 'VA',
			'washington' => 'WA', 'west virginia' => 'WV', 'wisconsin' => 'WI', 'wyoming' => 'WY',
		);

		foreach ( $state_map as $name => $abbr ) {
			if ( preg_match( '/\b' . preg_quote( $name, '/' ) . '\b/', $region_lower ) && ! in_array( $abbr, $states, true ) ) {
				$states[] = $abbr;
			}
		}

		return array_unique( $states );
	}

	/**
	 * Decode array value from meta.
	 *
	 * @param mixed $value Meta value.
	 * @return array
	 */
	protected static function maybe_decode_array( $value ) {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( empty( $value ) ) {
			return array();
		}
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			return is_array( $decoded ) ? $decoded : array();
		}
		return array();
	}

	/**
	 * Get user meta with fallback to legacy key.
	 *
	 * @param int         $user_id      User ID.
	 * @param string      $key          Primary meta key.
	 * @param string|null $fallback_key Fallback meta key.
	 * @return mixed
	 */
	protected static function get_meta_with_fallback( $user_id, $key, $fallback_key = null ) {
		$value = get_user_meta( $user_id, $key, true );
		if ( empty( $value ) && $fallback_key ) {
			$value = get_user_meta( $user_id, $fallback_key, true );
		}
		return $value;
	}

	/**
	 * Hydrate Profile object from WordPress user.
	 *
	 * @param \WP_User $user WordPress user object.
	 * @return Profile
	 */
	public static function hydrate_from_user( $user ) {
		$profile = new static();

		// Core fields
		$profile->id           = $user->ID;
		$profile->user_id      = $user->ID;
		$profile->email        = $user->user_email;
		$profile->secondary_email = get_user_meta( $user->ID, 'frs_secondary_email', true );
		$profile->display_name = $user->display_name;

		// Meta fields with fallbacks
		$profile->first_name      = get_user_meta( $user->ID, 'first_name', true );
		$profile->last_name       = get_user_meta( $user->ID, 'last_name', true );
		$profile->phone_number    = static::get_meta_with_fallback( $user->ID, 'frs_phone_number', 'phone_number' );
		$profile->mobile_number   = static::get_meta_with_fallback( $user->ID, 'frs_mobile_number', 'mobile_number' );
		$profile->office          = static::get_meta_with_fallback( $user->ID, 'frs_office', 'office' );
		$profile->company_name    = static::get_meta_with_fallback( $user->ID, 'frs_company_name', 'company_name' );
		$profile->company_logo_id = (int) static::get_meta_with_fallback( $user->ID, 'frs_company_logo_id', 'company_logo_id' );
		$profile->company_website = static::get_meta_with_fallback( $user->ID, 'frs_company_website', 'company_website' );
		$profile->headshot_id          = (int) static::get_meta_with_fallback( $user->ID, 'frs_headshot_id', 'headshot_id' );
		$profile->headshot_focal_point = (string) get_user_meta( $user->ID, 'frs_headshot_focal_point', true );
		$profile->job_title       = static::get_meta_with_fallback( $user->ID, 'frs_job_title', 'job_title' );
		$profile->biography       = static::get_meta_with_fallback( $user->ID, 'frs_biography', 'biography' );
		$profile->date_of_birth   = static::get_meta_with_fallback( $user->ID, 'frs_date_of_birth', 'date_of_birth' ) ?: null;
		$profile->nmls            = static::get_meta_with_fallback( $user->ID, 'frs_nmls', 'nmls_id' );
		$profile->nmls_number     = static::get_meta_with_fallback( $user->ID, 'frs_nmls_number', 'nmls_number' );
		$profile->license_number  = static::get_meta_with_fallback( $user->ID, 'frs_license_number', 'license_number' );
		$profile->dre_license     = static::get_meta_with_fallback( $user->ID, 'frs_dre_license', 'dre_license' );
		$profile->brand           = static::get_meta_with_fallback( $user->ID, 'frs_brand', 'brand' );
		$profile->status          = static::get_meta_with_fallback( $user->ID, 'frs_status', 'status' );
		$profile->city_state      = static::get_meta_with_fallback( $user->ID, 'frs_city_state', 'city_state' );
		$profile->region          = static::get_meta_with_fallback( $user->ID, 'frs_region', 'region' );

		// Social media
		$profile->facebook_url  = get_user_meta( $user->ID, 'frs_facebook_url', true );
		$profile->instagram_url = get_user_meta( $user->ID, 'frs_instagram_url', true );
		$profile->linkedin_url  = get_user_meta( $user->ID, 'frs_linkedin_url', true );
		$profile->twitter_url   = get_user_meta( $user->ID, 'frs_twitter_url', true );
		$profile->youtube_url   = get_user_meta( $user->ID, 'frs_youtube_url', true );
		$profile->tiktok_url          = get_user_meta( $user->ID, 'frs_tiktok_url', true );
		$profile->telegram_username   = get_user_meta( $user->ID, 'frs_telegram_username', true );
		$profile->century21_url = get_user_meta( $user->ID, 'frs_century21_url', true );
		$profile->zillow_url    = get_user_meta( $user->ID, 'frs_zillow_url', true );

		// Additional fields
		$profile->arrive                = get_user_meta( $user->ID, 'frs_arrive', true );
		$profile->canva_folder_link     = get_user_meta( $user->ID, 'frs_canva_folder_link', true );
		$profile->niche_bio_content     = get_user_meta( $user->ID, 'frs_niche_bio_content', true );
		$profile->loan_officer_profile  = (int) get_user_meta( $user->ID, 'frs_loan_officer_profile', true );
		$profile->loan_officer_user     = (int) get_user_meta( $user->ID, 'frs_loan_officer_user', true );
		$profile->profile_slug          = $user->user_nicename;
		$profile->profile_headline      = get_user_meta( $user->ID, 'frs_profile_headline', true );
		$profile->profile_theme         = get_user_meta( $user->ID, 'frs_profile_theme', true );
		$profile->directory_button_type = get_user_meta( $user->ID, 'frs_directory_button_type', true );
		$profile->booking_url           = get_user_meta( $user->ID, 'frs_booking_url', true );
		$profile->qr_code_data          = get_user_meta( $user->ID, 'frs_qr_code_data', true );
		$profile->is_active             = (bool) get_user_meta( $user->ID, 'frs_is_active', true );
		$profile->frs_agent_id          = get_user_meta( $user->ID, 'frs_frs_agent_id', true );

		// JSON/array fields
		$profile->specialties_lo          = static::maybe_decode_array( get_user_meta( $user->ID, 'frs_specialties_lo', true ) );
		$profile->specialties             = static::maybe_decode_array( get_user_meta( $user->ID, 'frs_specialties', true ) );
		$profile->languages               = static::maybe_decode_array( get_user_meta( $user->ID, 'frs_languages', true ) );
		$profile->awards                  = static::maybe_decode_array( get_user_meta( $user->ID, 'frs_awards', true ) );
		$profile->nar_designations        = static::maybe_decode_array( get_user_meta( $user->ID, 'frs_nar_designations', true ) );
		$profile->namb_certifications     = static::maybe_decode_array( get_user_meta( $user->ID, 'frs_namb_certifications', true ) );
		$profile->personal_branding_images = static::maybe_decode_array( get_user_meta( $user->ID, 'frs_personal_branding_images', true ) );
		$profile->profile_visibility      = static::maybe_decode_array( get_user_meta( $user->ID, 'frs_profile_visibility', true ) );
		$profile->custom_links            = static::maybe_decode_array( get_user_meta( $user->ID, 'frs_custom_links', true ) );
		$profile->service_areas           = static::maybe_decode_array( get_user_meta( $user->ID, 'frs_service_areas', true ) );

		// Derive service_areas from region if not set
		if ( empty( $profile->service_areas ) && ! empty( $profile->region ) ) {
			$profile->service_areas = static::parse_states_from_region( $profile->region );
		}

		// Timestamps
		$profile->synced_to_fluentcrm_at = get_user_meta( $user->ID, 'frs_synced_to_fluentcrm_at', true );
		$profile->created_at             = $user->user_registered;
		$profile->updated_at             = get_user_meta( $user->ID, 'frs_updated_at', true ) ?: $user->user_registered;

		// Company roles
		$company_roles         = get_user_meta( $user->ID, 'frs_company_role', false );
		$profile->company_roles = ! empty( $company_roles ) ? $company_roles : array();

		// select_person_type from company roles or WordPress role
		if ( ! empty( $company_roles ) ) {
			$profile->select_person_type = $company_roles[0];
		} else {
			$roles = $user->roles ?? array();
			if ( in_array( 'loan_officer', $roles, true ) ) {
				$profile->select_person_type = 'loan_originator';
				$profile->company_roles      = array( 'loan_originator' );
			} elseif ( in_array( 'broker_associate', $roles, true ) ) {
				$profile->select_person_type = 'broker_associate';
				$profile->company_roles      = array( 'broker_associate' );
			} elseif ( in_array( 'sales_associate', $roles, true ) ) {
				$profile->select_person_type = 'sales_associate';
				$profile->company_roles      = array( 'sales_associate' );
			} elseif ( in_array( 'dual_license', $roles, true ) ) {
				$profile->select_person_type = 'dual_license';
				$profile->company_roles      = array( 'dual_license' );
			} elseif ( in_array( 'leadership', $roles, true ) ) {
				$profile->select_person_type = 'leadership';
				$profile->company_roles      = array( 'leadership' );
			} elseif ( in_array( 'staff', $roles, true ) || in_array( 'assistant', $roles, true ) ) {
				$profile->select_person_type = 'staff';
				$profile->company_roles      = array( 'staff' );
			}
		}

		$profile->exists = true;

		return $profile;
	}
}

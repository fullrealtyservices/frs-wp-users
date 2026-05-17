<?php
/**
 * Meta-key dual-write aliasing.
 *
 * Purpose: during the transition from `frs_*` user_meta keys to canonical
 * keys (native WP keys where they exist, vendor-prefixed for external
 * integrations, bare names for generic concepts), we need BOTH keys to
 * always have the same value. That lets us rewrite plugins one at a time:
 * each plugin keeps reading its current key, and we incrementally migrate
 * each to read the canonical key. Once every consumer is migrated, the
 * dual-write is disabled and the legacy `frs_*` rows are cleaned up.
 *
 * Approach: hook into `added_user_meta` / `updated_user_meta`. When one
 * side of a pair changes, mirror to the other. A static re-entry guard
 * (`$mirroring`) prevents the mirror from re-firing the hook.
 *
 * Add a pair: extend self::PAIRS or hook the `frs_meta_key_alias_pairs`
 * filter. Remove a pair: delete it from the map AND run cleanup_legacy()
 * to drop the legacy rows.
 *
 * Multi-value (JSON) fields are mirrored as raw strings — the canonical
 * key holds the same JSON the legacy key holds. Consumers should
 * `json_decode()` the same way regardless of which key they read.
 *
 * @package FRSUsers\Sync
 * @since 2.2.0
 */

namespace FRSUsers\Sync;

defined( 'ABSPATH' ) || exit;

class MetaKeyAlias {

	/**
	 * Pair map: legacy_key => canonical_key.
	 *
	 * RULES (per agreed policy):
	 *   - If WP/BP has a native key for the concept, canonical IS the native key.
	 *   - External service data → vendor_name_field (e.g. courted_id).
	 *   - Industry-standard or generic concepts → bare name (no prefix).
	 *
	 * Pairs are bidirectional: writing either key mirrors to the other.
	 */
	const PAIRS = [
		// frs_biography ↔ description (WP-native)
		'frs_biography'              => 'description',

		// Social URLs ↔ WP contact-method natives
		'frs_facebook_url'           => 'facebook',
		'frs_instagram_url'          => 'instagram',
		'frs_linkedin_url'           => 'linkedin',
		'frs_twitter_url'            => 'twitter',
		'frs_youtube_url'            => 'youtube',
		'frs_tiktok_url'             => 'tiktok',

		// Industry-standard identifiers
		'frs_nmls'                   => 'nmls',
		'frs_nmls_number'            => 'nmls',
		'frs_dre_license'            => 'dre_license',
		'frs_license_number'         => 'license_number',
		'frs_license_state'          => 'license_state',
		'frs_license_type'           => 'license_type',
		'frs_namb_certifications'    => 'namb_certifications',
		'frs_nar_designations'       => 'nar_designations',

		// Vendor-specific external integrations
		'frs_courted_id'             => 'courted_id',
		'frs_courted_data'           => 'courted_data',
		'frs_canva_folder_link'      => 'canva_folder_link',
		'frs_realsatisfied_vanity'   => 'realsatisfied_vanity',
		'frs_arrive_link'            => 'arrive_link',
		'frs_arrive'                 => 'arrive_link', // collapse: many users wrote here
		'frs_telegram_username'      => 'telegram_username',
		'frs_twenty_crm_id'          => 'twenty_crm_id',
		'frs_twenty_crm_last_sync'   => 'twenty_crm_last_sync',
		'frs_synced_to_fluentcrm_at' => 'fluentcrm_synced_at',
		'frs_century21_url'          => 'century21_url',
		'frs_zillow_url'             => 'zillow_url',
		'frs_realtor_url'            => 'realtor_url',
		'frs_ylopo_domain'           => 'ylopo_domain',
		'frs_moxi_domain'            => 'moxi_domain',
		'frs_booking_url'            => 'booking_url',

		// Generic profile concepts
		'frs_phone_number'           => 'phone_number',
		'frs_mobile_number'          => 'mobile_number',
		'frs_business_email'         => 'business_email',
		'frs_middle_name'            => 'middle_name',
		'frs_date_of_birth'          => 'date_of_birth',
		'frs_job_title'              => 'job_title',
		// frs_company_role is a CACHE of bp_member_type (slug). Removed from
		// the alias map so it's not propagated; consumers should call
		// bp_get_member_type($uid) instead. The legacy column may still be
		// dropped via `wp frs-users meta-alias --cleanup-legacy` after
		// consumers are updated.
		'frs_company_name'           => 'company_name',
		'frs_company_website'        => 'company_website',
		'frs_company_logo_id'        => 'company_logo_id',
		'frs_brand'                  => 'brand',
		'frs_office'                 => 'office',
		'frs_region'                 => 'region',
		'frs_department'             => 'department',
		'frs_city_state'             => 'city_state',
		'frs_aor_regional_director'  => 'aor_regional_director',
		'frs_aor_regional_advisor'   => 'aor_regional_advisor',
		'frs_status'                 => 'status',
		'frs_first_login'            => 'first_login',
		'frs_is_active'              => 'is_active',
		'frs_languages'              => 'languages',
		'frs_specialties'            => 'specialties',
		'frs_specialties_lo'         => 'specialties_lo',
		'frs_awards'                 => 'awards',
		'frs_service_areas'          => 'service_areas',
		'frs_profile_headline'       => 'profile_headline',
		'frs_profile_slug'           => 'profile_slug',
		'frs_profile_visibility'     => 'profile_visibility',
		'frs_profile_theme'          => 'profile_theme',
		'frs_personal_branding_images' => 'personal_branding_images',
		'frs_niche_bio_content'      => 'niche_bio_content',
		'frs_loan_officer_user'      => 'loan_officer_user',
		'frs_loan_officer_profile'   => 'loan_officer_profile',
		'frs_directory_button_type'  => 'directory_button_type',
		'frs_vcard_settings'         => 'vcard_settings',
		'frs_custom_links'           => 'custom_links',
		'frs_qr_code_data'           => 'qr_code_data',
		'frs_headshot_id'            => 'headshot_id',
		'frs_headshot_url'           => 'headshot_url',
		'frs_headshot_local_path'    => 'headshot_local_path',
		'frs_avatar'                 => 'avatar',
		'frs_select_person_type'     => 'select_person_type',
		'frs_updated_at'             => 'updated_at',
		'frs_website'                => 'website',
		'frs_mw_search_history'      => 'mw_search_history',
	];

	/**
	 * Re-entry guard. Holds the user_id + meta_key currently being mirrored
	 * so the hook callback can short-circuit when it sees the mirror write.
	 *
	 * @var array<string, bool>
	 */
	private static $mirroring = [];

	/**
	 * Master switch. Set to false (via filter) once all consumers are migrated
	 * and the legacy `frs_*` rows have been cleaned up.
	 *
	 * @var bool|null cached
	 */
	private static $enabled = null;

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'added_user_meta',   [ __CLASS__, 'on_meta_change' ], 10, 4 );
		add_action( 'updated_user_meta', [ __CLASS__, 'on_meta_change' ], 10, 4 );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'frs-users meta-alias', [ __CLASS__, 'cli_handler' ] );
		}
	}

	/**
	 * Whether dual-write is active. Filter `frs_meta_key_alias_enabled` to flip.
	 */
	public static function is_enabled(): bool {
		if ( null === self::$enabled ) {
			self::$enabled = (bool) apply_filters( 'frs_meta_key_alias_enabled', true );
		}
		return self::$enabled;
	}

	/**
	 * Resolved pair map (legacy → canonical), filterable.
	 *
	 * @return array<string,string>
	 */
	public static function pairs(): array {
		return (array) apply_filters( 'frs_meta_key_alias_pairs', self::PAIRS );
	}

	/**
	 * Hook callback for added_user_meta + updated_user_meta.
	 *
	 * @param int    $meta_id    Meta row ID (unused).
	 * @param int    $user_id    User ID.
	 * @param string $meta_key   The key that was written.
	 * @param mixed  $meta_value The value that was written.
	 */
	public static function on_meta_change( $meta_id, $user_id, $meta_key, $meta_value ): void {
		if ( ! self::is_enabled() ) {
			return;
		}
		if ( ! $user_id || ! $meta_key ) {
			return;
		}

		$pairs = self::pairs();
		// Find the partner key (legacy → canonical OR canonical → legacy).
		$partner = self::partner_key( (string) $meta_key, $pairs );
		if ( null === $partner ) {
			return;
		}

		// Re-entry guard.
		$guard_key = $user_id . ':' . $partner;
		if ( ! empty( self::$mirroring[ $guard_key ] ) ) {
			return;
		}

		// Skip if the partner already holds the same value (cheap no-op).
		$existing = get_user_meta( (int) $user_id, $partner, true );
		if ( self::values_equal( $existing, $meta_value ) ) {
			return;
		}

		self::$mirroring[ $user_id . ':' . $meta_key ] = true;
		update_user_meta( (int) $user_id, $partner, $meta_value );
		unset( self::$mirroring[ $user_id . ':' . $meta_key ] );
	}

	/**
	 * Resolve the partner key for a given side of the pair.
	 *
	 * @param string                $meta_key The key that just changed.
	 * @param array<string,string>  $pairs    Pair map (legacy → canonical).
	 * @return string|null Partner key, or null if no pair.
	 */
	private static function partner_key( string $meta_key, array $pairs ): ?string {
		// Legacy → canonical.
		if ( isset( $pairs[ $meta_key ] ) ) {
			return $pairs[ $meta_key ];
		}
		// Canonical → legacy (search reverse).
		foreach ( $pairs as $legacy => $canonical ) {
			if ( $canonical === $meta_key ) {
				return $legacy;
			}
		}
		return null;
	}

	/**
	 * Compare values for equality with a few tolerances (serialized arrays
	 * round-trip differently, scalar coercion).
	 */
	private static function values_equal( $a, $b ): bool {
		if ( is_scalar( $a ) && is_scalar( $b ) ) {
			return (string) $a === (string) $b;
		}
		return $a === $b;
	}

	/**
	 * WP-CLI handler.
	 *
	 * ## OPTIONS
	 *
	 * [--migrate-existing]
	 * : One-time backfill: copy every legacy value to the canonical key (and
	 * vice versa where the canonical has a value but the legacy doesn't).
	 *
	 * [--cleanup-legacy]
	 * : After consumers are migrated, drop all legacy rows. DESTRUCTIVE.
	 *
	 * [--audit]
	 * : Report counts per pair: how many users have one key, the other, both,
	 * and whether values agree.
	 *
	 * @when after_wp_load
	 */
	public static function cli_handler( $args, $assoc_args ): void {
		if ( isset( $assoc_args['audit'] ) ) {
			self::cli_audit();
		} elseif ( isset( $assoc_args['migrate-existing'] ) ) {
			self::cli_migrate_existing();
		} elseif ( isset( $assoc_args['cleanup-legacy'] ) ) {
			self::cli_cleanup_legacy();
		} else {
			\WP_CLI::error( 'Specify --audit, --migrate-existing, or --cleanup-legacy.' );
		}
	}

	private static function cli_audit(): void {
		global $wpdb;
		\WP_CLI::log( sprintf( "%-32s %8s %8s %8s %8s", 'pair (legacy → canonical)', 'legacy', 'canon', 'both', 'differ' ) );
		\WP_CLI::log( str_repeat( '-', 80 ) );
		foreach ( self::pairs() as $legacy => $canonical ) {
			$legacy_users = $wpdb->get_col( $wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value != ''", $legacy
			) );
			$canon_users  = $wpdb->get_col( $wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value != ''", $canonical
			) );
			$lset = array_flip( $legacy_users );
			$cset = array_flip( $canon_users );
			$both = array_intersect_key( $lset, $cset );
			$differ = 0;
			foreach ( array_keys( $both ) as $uid ) {
				$lv = get_user_meta( (int) $uid, $legacy, true );
				$cv = get_user_meta( (int) $uid, $canonical, true );
				if ( ! self::values_equal( $lv, $cv ) ) {
					$differ++;
				}
			}
			\WP_CLI::log( sprintf(
				"%-32s %8d %8d %8d %8d",
				"$legacy → $canonical",
				count( $legacy_users ),
				count( $canon_users ),
				count( $both ),
				$differ
			) );
		}
	}

	private static function cli_migrate_existing(): void {
		global $wpdb;
		$copied = 0;
		foreach ( self::pairs() as $legacy => $canonical ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value != ''",
				$legacy
			) );
			foreach ( $rows as $r ) {
				$existing = get_user_meta( (int) $r->user_id, $canonical, true );
				if ( '' !== (string) $existing ) {
					continue; // canonical already has a value; don't overwrite during migration
				}
				update_user_meta( (int) $r->user_id, $canonical, $r->meta_value );
				$copied++;
			}
		}
		\WP_CLI::success( "Migrated $copied legacy values to canonical keys." );
	}

	private static function cli_cleanup_legacy(): void {
		global $wpdb;
		$deleted = 0;
		foreach ( self::pairs() as $legacy => $canonical ) {
			$n = $wpdb->delete( $wpdb->usermeta, [ 'meta_key' => $legacy ] );
			if ( $n ) {
				$deleted += (int) $n;
			}
		}
		\WP_CLI::success( "Deleted $deleted legacy meta rows." );
	}
}

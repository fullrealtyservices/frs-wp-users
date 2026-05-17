<?php
/**
 * BuddyPress XProfile Schema
 *
 * Defines and registers BuddyPress XProfile field groups + fields that mirror a
 * curated subset of the live `frs_*` user_meta keys, gated by FRS Member Type.
 *
 * Idempotent — safe to run on every load. Installation is version-gated via the
 * `frs_bp_xprofile_schema_version` site option compared against the
 * SCHEMA_VERSION class constant. Re-running is a no-op when versions match.
 *
 * Member-type gating is applied via `bp_xprofile_update_field_group_meta` with
 * the `member_type` meta key, which BuddyPress consumes natively to scope group
 * visibility to the listed Member Types. Groups without that meta are visible
 * to all member types.
 *
 * Field IDs are persisted as site options keyed `frs_bp_xprofile_map_<frs_meta_key>`
 * so the sync layer (XProfile <-> usermeta) can resolve canonical mappings without
 * re-querying field names.
 *
 * Schema can be amended without editing this class by hooking the
 * `frs_bp_xprofile_schema` filter.
 *
 * @package FRSUsers\Integrations
 * @since 2.1.0
 */

namespace FRSUsers\Integrations;

defined( 'ABSPATH' ) || exit;

class BuddyPressXProfileSchema {

	/**
	 * Schema version. Bump to force re-installation of any new groups/fields.
	 *
	 * 2.0.0 — Expanded from 4 to 9 field groups (~54 fields) covering the full
	 * onboarding intake: Personal & Contact, Bio, Career & License (RE),
	 * Career & License (Lending), MLS & Association, Specialties (RE),
	 * Languages & Social, Safety & Liability, Acknowledgments & Commission.
	 *
	 * 2.1.0 — Expanded to 11 field groups to cover every field returned by the
	 * base.frs.works/api/agents API. Added Business Email + Full Name to
	 * Personal & Contact; added Century 21 / Ylopo / Moxi / RealSatisfied
	 * vanity URLs to Languages & Social; added Group 10 (Reporting & Org)
	 * and Group 11 (Legacy System References).
	 *
	 * 2.2.0 — Added Group 12 (RE Production — sales_associate only, sourced
	 * from Courted) and Group 13 (LO Production — loan_originator only,
	 * sourced from Modex CSV imports). Added bidirectional sync hooks so
	 * a BP profile edit updates the underlying user_meta and a user_meta
	 * change reflects in the xprofile field. Re-entry guarded.
	 */
	const SCHEMA_VERSION = '2.2.0';

	/**
	 * Site option key tracking the installed schema version.
	 */
	const VERSION_OPTION = 'frs_bp_xprofile_schema_version';

	/**
	 * Site option prefix for persisted field-id mappings.
	 *
	 * Final key: frs_bp_xprofile_map_<frs_meta_key>
	 */
	const MAP_OPTION_PREFIX = 'frs_bp_xprofile_map_';

	/**
	 * Initialize the integration.
	 *
	 * Safe to call from `plugins_loaded`. Defers actual registration to `bp_init`
	 * (priority 20) so BuddyPress core, XProfile component, and Member Types are
	 * all fully loaded before we touch the schema.
	 */
	public static function init(): void {
		if ( ! function_exists( 'xprofile_insert_field_group' ) ) {
			return;
		}

		add_action( 'bp_init', [ __CLASS__, 'maybe_install_schema' ], 20 );

		// Bidirectional sync between BP xprofile data and WP user_meta.
		// A profile edit on BP writes to xprofile → we mirror to user_meta.
		// A user_meta update (e.g. by LocalAggregateSync, FluentCRM,
		// Courted enrichment) → we mirror to the matching xprofile field.
		add_action( 'xprofile_data_after_save', [ __CLASS__, 'on_xprofile_save' ], 10, 1 );
		add_action( 'updated_user_meta',         [ __CLASS__, 'on_user_meta_change' ], 10, 4 );
		add_action( 'added_user_meta',           [ __CLASS__, 'on_user_meta_change' ], 10, 4 );
	}

	/** Re-entry guard so the two hooks don't trigger each other indefinitely. */
	private static $mirroring = [];

	/**
	 * xprofile_data_after_save handler.
	 * Mirror the saved xprofile value into the corresponding user_meta key.
	 */
	public static function on_xprofile_save( $xprofile_data ): void {
		if ( ! is_object( $xprofile_data ) ) {
			return;
		}
		$field_id = (int) ( $xprofile_data->field_id ?? 0 );
		$user_id  = (int) ( $xprofile_data->user_id ?? 0 );
		$value    = $xprofile_data->value ?? null;
		if ( ! $field_id || ! $user_id ) {
			return;
		}

		// Reverse-lookup the meta_key by scanning the persisted mappings.
		// Mappings are keyed `frs_bp_xprofile_map_<meta_key>` → field_id.
		$meta_key = self::meta_key_for_field( $field_id );
		if ( '' === $meta_key ) {
			return;
		}

		$guard = $user_id . ':' . $meta_key;
		if ( ! empty( self::$mirroring[ $guard ] ) ) {
			return;
		}
		self::$mirroring[ $guard ] = true;
		update_user_meta( $user_id, $meta_key, $value );
		unset( self::$mirroring[ $guard ] );
	}

	/**
	 * user_meta change handler.
	 * If the meta_key is one we mirror, push the new value into xprofile.
	 */
	public static function on_user_meta_change( $meta_id, $user_id, $meta_key, $meta_value ): void {
		if ( ! $user_id || ! $meta_key ) {
			return;
		}
		// Look up the xprofile field this meta_key maps to.
		$field_id = (int) get_site_option( self::MAP_OPTION_PREFIX . $meta_key );
		if ( ! $field_id ) {
			return;
		}

		$guard = $user_id . ':' . $meta_key;
		if ( ! empty( self::$mirroring[ $guard ] ) ) {
			return;
		}
		self::$mirroring[ $guard ] = true;
		if ( function_exists( 'xprofile_set_field_data' ) ) {
			xprofile_set_field_data( $field_id, $user_id, $meta_value );
		}
		unset( self::$mirroring[ $guard ] );
	}

	/**
	 * Reverse-lookup: given an xprofile field_id, find the meta_key it maps to.
	 * Caches in-process; the mapping is small.
	 *
	 * @var array<int,string>|null
	 */
	private static $field_to_meta_cache = null;

	private static function meta_key_for_field( int $field_id ): string {
		if ( null === self::$field_to_meta_cache ) {
			global $wpdb;
			self::$field_to_meta_cache = [];
			$prefix    = self::MAP_OPTION_PREFIX;
			$prefix_lk = $wpdb->esc_like( $prefix ) . '%';
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$prefix_lk
			) );
			foreach ( (array) $rows as $r ) {
				$mk  = substr( $r->option_name, strlen( $prefix ) );
				$fid = (int) $r->option_value;
				if ( $fid > 0 && '' !== $mk ) {
					self::$field_to_meta_cache[ $fid ] = $mk;
				}
			}
		}
		return self::$field_to_meta_cache[ $field_id ] ?? '';
	}

	/**
	 * Version-gated entry point. Skips installation when the persisted schema
	 * version already matches SCHEMA_VERSION. Otherwise installs and stamps
	 * the option so subsequent loads are no-ops.
	 */
	public static function maybe_install_schema(): void {
		if ( ! function_exists( 'xprofile_insert_field_group' ) ) {
			return;
		}

		$installed_version = get_site_option( self::VERSION_OPTION, '' );
		if ( self::SCHEMA_VERSION === $installed_version ) {
			return;
		}

		self::install_schema();

		update_site_option( self::VERSION_OPTION, self::SCHEMA_VERSION );
	}

	/**
	 * Walk the schema definition, ensure each group + field exists, and persist
	 * field-id mappings for downstream consumers.
	 */
	public static function install_schema(): void {
		$schema = self::get_schema();

		foreach ( $schema as $group_def ) {
			$group_id = self::ensure_field_group( $group_def );
			if ( ! $group_id ) {
				continue;
			}

			// Apply member-type gating, if defined.
			if ( ! empty( $group_def['member_types'] ) && is_array( $group_def['member_types'] ) ) {
				self::apply_member_type_gate( $group_id, $group_def['member_types'] );
			}

			$fields = isset( $group_def['fields'] ) && is_array( $group_def['fields'] )
				? $group_def['fields']
				: [];

			foreach ( $fields as $field_def ) {
				self::ensure_field( $group_id, $field_def );
			}
		}
	}

	/**
	 * Ensure a field group exists by name; create if missing.
	 *
	 * BP_XProfile_Group::get() is the canonical way to look up groups; matching
	 * by name keeps installation idempotent across re-runs even if IDs shift.
	 *
	 * @param array $group_def Group definition (keys: name, description, can_delete, ...).
	 * @return int Group ID, or 0 on failure.
	 */
	protected static function ensure_field_group( array $group_def ): int {
		if ( ! class_exists( '\BP_XProfile_Group' ) || empty( $group_def['name'] ) ) {
			return 0;
		}

		$existing = \BP_XProfile_Group::get( [ 'fetch_fields' => false ] );
		if ( is_array( $existing ) ) {
			// BP stores names HTML-entity-encoded (e.g. "Identity &amp; Contact"),
			// so a literal === against the schema name (with raw "&") never matches
			// and we'd insert a duplicate group every load. Decode both sides.
			$wanted = html_entity_decode( $group_def['name'], ENT_QUOTES, 'UTF-8' );
			foreach ( $existing as $group ) {
				if ( ! isset( $group->name ) ) {
					continue;
				}
				$candidate = html_entity_decode( $group->name, ENT_QUOTES, 'UTF-8' );
				if ( $candidate === $wanted ) {
					return (int) $group->id;
				}
			}
		}

		$args = [
			'name'        => $group_def['name'],
			'description' => isset( $group_def['description'] ) ? $group_def['description'] : '',
			'can_delete'  => isset( $group_def['can_delete'] ) ? (bool) $group_def['can_delete'] : true,
		];

		$group_id = xprofile_insert_field_group( $args );

		return $group_id ? (int) $group_id : 0;
	}

	/**
	 * Apply member-type gating to a group.
	 *
	 * Uses `bp_xprofile_update_field_group_meta` (BP 2.4+) with the `member_type`
	 * meta key, which BuddyPress consumes natively to scope group visibility on
	 * the profile edit screen. If the helper is unavailable (very old BP), this
	 * is a no-op — gating must then be handled at the theme/template layer.
	 *
	 * @param int   $group_id     Field group ID.
	 * @param array $member_types List of Member Type slugs.
	 */
	protected static function apply_member_type_gate( int $group_id, array $member_types ): void {
		if ( ! $group_id || empty( $member_types ) ) {
			return;
		}

		if ( ! function_exists( 'bp_xprofile_update_field_group_meta' ) ) {
			return;
		}

		bp_xprofile_update_field_group_meta( $group_id, 'member_type', array_values( $member_types ) );
	}

	/**
	 * Ensure a field exists within a group; create if missing. Persists the
	 * field id to a site option keyed by frs_meta_key.
	 *
	 * For multiselectbox/selectbox/radio/checkbox fields, default options are
	 * created as child fields with type=option and a sequential field_order, per
	 * the documented BP XProfile pattern:
	 * https://codex.buddypress.org/extending/xprofile/
	 *
	 * @param int   $group_id  Parent field group ID.
	 * @param array $field_def Field definition (keys: name, type, frs_meta_key,
	 *                         description, is_required, options).
	 * @return int Field ID, or 0 on failure.
	 */
	protected static function ensure_field( int $group_id, array $field_def ): int {
		if ( ! $group_id || empty( $field_def['name'] ) || empty( $field_def['type'] ) ) {
			return 0;
		}

		$field_id = 0;

		if ( function_exists( 'xprofile_get_field_id_from_name' ) ) {
			$existing_id = xprofile_get_field_id_from_name( $field_def['name'] );
			if ( $existing_id ) {
				$field_id = (int) $existing_id;
			}
		}

		if ( ! $field_id ) {
			$args = [
				'field_group_id' => $group_id,
				'name'           => $field_def['name'],
				'description'    => isset( $field_def['description'] ) ? $field_def['description'] : '',
				'type'           => $field_def['type'],
				'is_required'    => ! empty( $field_def['is_required'] ),
				'can_delete'     => true,
			];

			$inserted = xprofile_insert_field( $args );
			if ( $inserted ) {
				$field_id = (int) $inserted;
			}
		}

		if ( ! $field_id ) {
			return 0;
		}

		// Persist the canonical mapping for the sync layer.
		if ( ! empty( $field_def['frs_meta_key'] ) ) {
			update_site_option(
				self::MAP_OPTION_PREFIX . $field_def['frs_meta_key'],
				$field_id
			);
		}

		// Install default options for choice-type fields as child fields.
		if ( ! empty( $field_def['options'] ) && is_array( $field_def['options'] ) ) {
			self::ensure_field_options( $group_id, $field_id, $field_def['options'] );
		}

		return $field_id;
	}

	/**
	 * Ensure default options exist for a choice-type field. Options are stored
	 * by BP as child fields with type=option and parent_id pointing at the
	 * parent field; field_order controls display order.
	 *
	 * Idempotent: skips any option whose name already exists under the parent.
	 *
	 * @param int   $group_id  Parent field group ID.
	 * @param int   $parent_id Parent field ID.
	 * @param array $options   List of option labels (strings).
	 */
	protected static function ensure_field_options( int $group_id, int $parent_id, array $options ): void {
		if ( ! $group_id || ! $parent_id || empty( $options ) ) {
			return;
		}

		// get_children() is an INSTANCE method on BP_XProfile_Field — must
		// instantiate via xprofile_get_field() first. Calling it statically
		// fatal-ed bp_init across the whole site (admin + frontend).
		$existing_names = [];
		if ( function_exists( 'xprofile_get_field' ) ) {
			$parent_field = xprofile_get_field( $parent_id );
			if ( $parent_field instanceof \BP_XProfile_Field ) {
				$children = $parent_field->get_children();
				if ( is_array( $children ) ) {
					foreach ( $children as $child ) {
						if ( isset( $child->name ) ) {
							$existing_names[] = $child->name;
						}
					}
				}
			}
		}

		$order = 1;
		foreach ( $options as $label ) {
			if ( ! is_string( $label ) || '' === $label ) {
				continue;
			}

			if ( in_array( $label, $existing_names, true ) ) {
				$order++;
				continue;
			}

			xprofile_insert_field( [
				'field_group_id' => $group_id,
				'parent_id'      => $parent_id,
				'type'           => 'option',
				'name'           => $label,
				'field_order'    => $order,
				'can_delete'     => true,
			] );

			$order++;
		}
	}

	/**
	 * Return the schema definition.
	 *
	 * Filterable via `frs_bp_xprofile_schema` so the schema can be amended
	 * without editing this class.
	 *
	 * Member Types referenced in gating (registered by BuddyPressBootstrap):
	 *   sales_associate, loan_originator, broker_associate, regional_lead,
	 *   department_lead, staff, partner.
	 *
	 * @return array<int, array> List of group definitions.
	 */
	public static function get_schema(): array {
		$schema = [
			// ---------------------------------------------------------------
			// Group 1: Personal & Contact — visible to ALL member types.
			// ---------------------------------------------------------------
			[
				'name'         => 'Personal & Contact',
				'description'  => 'Core identity, contact, and address information.',
				'member_types' => [], // Empty = visible to all member types.
				'fields'       => [
					[
						'name'         => 'Middle Name',
						'type'         => 'textbox',
						'description'  => 'Middle Name',
						'frs_meta_key' => 'frs_middle_name',
						'is_required'  => false,
					],
					[
						'name'         => 'Full Name',
						'type'         => 'textbox',
						'description'  => 'Full Name (computed)',
						'frs_meta_key' => 'frs_full_name',
						'is_required'  => false,
					],
					[
						'name'         => 'Business Email',
						'type'         => 'textbox',
						'description'  => 'Primary Business Email',
						'frs_meta_key' => 'frs_business_email',
						'is_required'  => false,
					],
					[
						'name'         => 'Phone',
						'type'         => 'textbox',
						'description'  => 'Phone Number',
						'frs_meta_key' => 'frs_phone_number',
						'is_required'  => false,
					],
					[
						'name'         => 'Mobile',
						'type'         => 'textbox',
						'description'  => 'Mobile Number',
						'frs_meta_key' => 'frs_mobile_number',
						'is_required'  => false,
					],
					[
						'name'         => 'US Citizen',
						'type'         => 'radio',
						'description'  => 'US Citizen',
						'frs_meta_key' => 'frs_us_citizen',
						'is_required'  => false,
						'options'      => [ 'Yes', 'No' ],
					],
					[
						'name'         => 'Date of Birth',
						'type'         => 'datebox',
						'description'  => 'Date of Birth',
						'frs_meta_key' => 'frs_date_of_birth',
						'is_required'  => false,
					],
					[
						'name'         => 'Street Address',
						'type'         => 'textbox',
						'description'  => 'Street Address',
						'frs_meta_key' => 'frs_street_address',
						'is_required'  => false,
					],
					[
						'name'         => 'City',
						'type'         => 'textbox',
						'description'  => 'City',
						'frs_meta_key' => 'frs_city',
						'is_required'  => false,
					],
					[
						'name'         => 'State',
						'type'         => 'selectbox',
						'description'  => 'State',
						'frs_meta_key' => 'frs_state',
						'is_required'  => false,
						'options'      => [ 'California', 'Arizona', 'Nevada' ],
					],
					[
						'name'         => 'ZIP',
						'type'         => 'textbox',
						'description'  => 'ZIP',
						'frs_meta_key' => 'frs_zip',
						'is_required'  => false,
					],
					[
						'name'         => 'Office',
						'type'         => 'textbox',
						'description'  => 'Office (display only)',
						'frs_meta_key' => 'frs_office',
						'is_required'  => false,
					],
					[
						'name'         => 'Job Title',
						'type'         => 'textbox',
						'description'  => 'Job Title',
						'frs_meta_key' => 'frs_job_title',
						'is_required'  => false,
					],
					[
						'name'         => 'FRS Agent ID',
						'type'         => 'textbox',
						'description'  => 'FRS Agent ID',
						'frs_meta_key' => 'frs_agent_id',
						'is_required'  => false,
					],
					[
						'name'         => 'Brand',
						'type'         => 'textbox',
						'description'  => 'Brand',
						'frs_meta_key' => 'frs_brand',
						'is_required'  => false,
					],
				],
			],

			// ---------------------------------------------------------------
			// Group 2: Bio — visible to ALL member types.
			// ---------------------------------------------------------------
			[
				'name'         => 'Bio',
				'description'  => 'Free-form biography and professional summary.',
				'member_types' => [],
				'fields'       => [
					[
						'name'         => 'Biography',
						'type'         => 'textarea',
						'description'  => 'Biography',
						'frs_meta_key' => 'frs_biography',
						'is_required'  => false,
					],
				],
			],

			// ---------------------------------------------------------------
			// Group 3: Career & License (Real Estate) — gated to
			// sales_associate, broker_associate.
			// ---------------------------------------------------------------
			[
				'name'         => 'Career & License (Real Estate)',
				'description'  => 'Real estate licensure and career history.',
				'member_types' => [ 'sales_associate', 'broker_associate' ],
				'fields'       => [
					[
						'name'         => 'DRE License',
						'type'         => 'textbox',
						'description'  => 'DRE License Number',
						'frs_meta_key' => 'frs_dre_license',
						'is_required'  => false,
					],
					[
						'name'         => 'License Type',
						'type'         => 'radio',
						'description'  => 'License Type',
						'frs_meta_key' => 'frs_license_type',
						'is_required'  => false,
						'options'      => [ 'Salesperson', 'Broker' ],
					],
					[
						'name'         => 'Date Issued',
						'type'         => 'datebox',
						'description'  => 'License Date Issued',
						'frs_meta_key' => 'frs_license_issued',
						'is_required'  => false,
					],
					[
						'name'         => 'Date Expiring',
						'type'         => 'datebox',
						'description'  => 'License Expiration',
						'frs_meta_key' => 'frs_license_expires',
						'is_required'  => false,
					],
					[
						'name'         => 'Previous Affiliation',
						'type'         => 'textbox',
						'description'  => 'Previous Affiliation',
						'frs_meta_key' => 'frs_previous_affiliation',
						'is_required'  => false,
					],
					[
						'name'         => 'License Number',
						'type'         => 'textbox',
						'description'  => 'License Number (state-issued)',
						'frs_meta_key' => 'frs_license_number',
						'is_required'  => false,
					],
					[
						'name'         => 'License State',
						'type'         => 'selectbox',
						'description'  => 'License State',
						'frs_meta_key' => 'frs_license_state',
						'is_required'  => false,
						'options'      => [ 'California', 'Arizona', 'Nevada' ],
					],
				],
			],

			// ---------------------------------------------------------------
			// Group 4: Career & License (Lending) — gated to loan_originator.
			// ---------------------------------------------------------------
			[
				'name'         => 'Career & License (Lending)',
				'description'  => 'Lending licensure and product specialties.',
				'member_types' => [ 'loan_originator' ],
				'fields'       => [
					[
						'name'         => 'NMLS',
						'type'         => 'textbox',
						'description'  => 'NMLS ID',
						'frs_meta_key' => 'frs_nmls',
						'is_required'  => false,
					],
					[
						'name'         => 'NMLS Number',
						'type'         => 'textbox',
						'description'  => 'NMLS Number',
						'frs_meta_key' => 'frs_nmls_number',
						'is_required'  => false,
					],
					[
						'name'         => 'Lending Specialties',
						'type'         => 'multiselectbox',
						'description'  => 'Lending Specialties',
						'frs_meta_key' => 'frs_specialties_lo',
						'is_required'  => false,
						'options'      => [
							'Conventional',
							'FHA',
							'VA',
							'USDA',
							'Jumbo',
							'Non-QM',
							'Reverse',
							'HELOC',
							'Construction',
							'Commercial',
						],
					],
					[
						'name'         => 'NAMB Certifications',
						'type'         => 'multiselectbox',
						'description'  => 'NAMB Certifications',
						'frs_meta_key' => 'frs_namb_certifications',
						'is_required'  => false,
						'options'      => [
							'CMC (Certified Mortgage Consultant)',
							'CRMS (Certified Residential Mortgage Specialist)',
							'GMA (General Mortgage Associate)',
						],
					],
				],
			],

			// ---------------------------------------------------------------
			// Group 5: MLS & Association — gated to sales_associate, broker_associate.
			// ---------------------------------------------------------------
			[
				'name'         => 'MLS & Association',
				'description'  => 'Primary MLS and REALTOR association membership.',
				'member_types' => [ 'sales_associate', 'broker_associate' ],
				'fields'       => [
					[
						'name'         => 'Primary MLS Name',
						'type'         => 'textbox',
						'description'  => 'Primary MLS Name',
						'frs_meta_key' => 'frs_primary_mls_name',
						'is_required'  => false,
					],
					[
						'name'         => 'Primary MLS ID',
						'type'         => 'textbox',
						'description'  => 'Primary MLS ID',
						'frs_meta_key' => 'frs_primary_mls_id',
						'is_required'  => false,
					],
					[
						'name'         => 'Primary Association',
						'type'         => 'textbox',
						'description'  => 'Primary Association',
						'frs_meta_key' => 'frs_primary_association',
						'is_required'  => false,
					],
				],
			],

			// ---------------------------------------------------------------
			// Group 6: Specialties (Real Estate) — gated to
			// sales_associate, broker_associate.
			// ---------------------------------------------------------------
			[
				'name'         => 'Specialties (Real Estate)',
				'description'  => 'Real estate market specialties, designations, and service areas.',
				'member_types' => [ 'sales_associate', 'broker_associate' ],
				'fields'       => [
					[
						'name'         => 'Real Estate Specialties',
						'type'         => 'multiselectbox',
						'description'  => 'Real Estate Specialties',
						'frs_meta_key' => 'frs_specialties',
						'is_required'  => false,
						'options'      => [
							'Residential',
							'Commercial',
							'Luxury',
							'First-Time Buyers',
							'Investment',
							'Land',
							'Rentals',
							'Relocation',
							'Short Sales',
							'Foreclosures',
							'REO',
						],
					],
					[
						'name'         => 'NAR Designations',
						'type'         => 'multiselectbox',
						'description'  => 'NAR Designations',
						'frs_meta_key' => 'frs_nar_designations',
						'is_required'  => false,
						'options'      => [
							'ABR (Accredited Buyer\'s Representative)',
							'AHWD (At Home with Diversity)',
							'CRS (Certified Residential Specialist)',
							'GRI (Graduate, REALTOR Institute)',
							'SRS (Seller Representative Specialist)',
							'CIPS (Certified International Property Specialist)',
						],
					],
					[
						'name'         => 'Service Areas',
						'type'         => 'textbox',
						'description'  => 'Service Areas (comma-separated)',
						'frs_meta_key' => 'frs_service_areas',
						'is_required'  => false,
					],
				],
			],

			// ---------------------------------------------------------------
			// Group 7: Languages & Social — visible to ALL member types.
			//
			// Note: BP core has no native "url" field type. We use textbox and
			// rely on form-layer URL validation. If a downstream extension
			// registers a `url` xprofile type, the schema can be amended via
			// the `frs_bp_xprofile_schema` filter.
			// ---------------------------------------------------------------
			[
				'name'         => 'Languages & Social',
				'description'  => 'Languages spoken and public social profiles.',
				'member_types' => [],
				'fields'       => [
					[
						'name'         => 'Languages',
						'type'         => 'multiselectbox',
						'description'  => 'Languages Spoken',
						'frs_meta_key' => 'frs_languages',
						'is_required'  => false,
						'options'      => [
							'English',
							'Spanish',
							'Mandarin',
							'Cantonese',
							'Vietnamese',
							'Korean',
							'Tagalog',
							'Russian',
							'Portuguese',
							'Arabic',
							'French',
							'German',
							'Italian',
							'Japanese',
						],
					],
					[
						'name'         => 'Website',
						'type'         => 'textbox',
						'description'  => 'Website',
						'frs_meta_key' => 'frs_website',
						'is_required'  => false,
					],
					[
						'name'         => 'Facebook',
						'type'         => 'textbox',
						'description'  => 'Facebook URL',
						'frs_meta_key' => 'frs_facebook_url',
						'is_required'  => false,
					],
					[
						'name'         => 'Instagram',
						'type'         => 'textbox',
						'description'  => 'Instagram URL',
						'frs_meta_key' => 'frs_instagram_url',
						'is_required'  => false,
					],
					[
						'name'         => 'LinkedIn',
						'type'         => 'textbox',
						'description'  => 'LinkedIn URL',
						'frs_meta_key' => 'frs_linkedin_url',
						'is_required'  => false,
					],
					[
						'name'         => 'Twitter',
						'type'         => 'textbox',
						'description'  => 'Twitter / X URL',
						'frs_meta_key' => 'frs_twitter_url',
						'is_required'  => false,
					],
					[
						'name'         => 'YouTube',
						'type'         => 'textbox',
						'description'  => 'YouTube URL',
						'frs_meta_key' => 'frs_youtube_url',
						'is_required'  => false,
					],
					[
						'name'         => 'TikTok',
						'type'         => 'textbox',
						'description'  => 'TikTok URL',
						'frs_meta_key' => 'frs_tiktok_url',
						'is_required'  => false,
					],
					[
						'name'         => 'Zillow',
						'type'         => 'textbox',
						'description'  => 'Zillow Profile URL',
						'frs_meta_key' => 'frs_zillow_url',
						'is_required'  => false,
					],
					[
						'name'         => 'Century 21 URL',
						'type'         => 'textbox',
						'description'  => 'Century 21 URL',
						'frs_meta_key' => 'frs_century21_url',
						'is_required'  => false,
					],
					[
						'name'         => 'Century 21 Com URL',
						'type'         => 'textbox',
						'description'  => 'Century21.com URL',
						'frs_meta_key' => 'frs_century21_com_url',
						'is_required'  => false,
					],
					[
						'name'         => 'Ylopo Domain',
						'type'         => 'textbox',
						'description'  => 'Ylopo Domain',
						'frs_meta_key' => 'frs_ylopo_domain',
						'is_required'  => false,
					],
					[
						'name'         => 'Moxi Domain',
						'type'         => 'textbox',
						'description'  => 'Moxi Domain',
						'frs_meta_key' => 'frs_moxi_domain',
						'is_required'  => false,
					],
					[
						'name'         => 'Real Satisfied Vanity',
						'type'         => 'textbox',
						'description'  => 'RealSatisfied Vanity',
						'frs_meta_key' => 'frs_realsatisfied_vanity',
						'is_required'  => false,
					],
				],
			],

			// ---------------------------------------------------------------
			// Group 8: Safety & Liability — visible to ALL member types.
			//
			// These are sensitive fields (emergency contacts, insurance) — the
			// group is visible to all member types but admins should configure
			// per-field visibility (admins-only) via the BP XProfile admin UI.
			// ---------------------------------------------------------------
			[
				'name'         => 'Safety & Liability',
				'description'  => 'Emergency contact and auto insurance details.',
				'member_types' => [],
				'fields'       => [
					[
						'name'         => 'Emergency Contact Name',
						'type'         => 'textbox',
						'description'  => 'Emergency Contact Name',
						'frs_meta_key' => 'frs_emergency_contact_name',
						'is_required'  => false,
					],
					[
						'name'         => 'Emergency Contact Relationship',
						'type'         => 'textbox',
						'description'  => 'Relationship',
						'frs_meta_key' => 'frs_emergency_contact_rel',
						'is_required'  => false,
					],
					[
						'name'         => 'Emergency Contact Phone',
						'type'         => 'textbox',
						'description'  => 'Emergency Contact Phone',
						'frs_meta_key' => 'frs_emergency_contact_phone',
						'is_required'  => false,
					],
					[
						'name'         => 'Car Insurance Provider',
						'type'         => 'textbox',
						'description'  => 'Car Insurance Provider',
						'frs_meta_key' => 'frs_car_insurance_provider',
						'is_required'  => false,
					],
					[
						'name'         => 'Car Insurance Expiration',
						'type'         => 'datebox',
						'description'  => 'Car Insurance Expiration',
						'frs_meta_key' => 'frs_car_insurance_expires',
						'is_required'  => false,
					],
				],
			],

			// ---------------------------------------------------------------
			// Group 9: Acknowledgments & Commission — admin-managed context.
			//
			// Most fields are timestamps recording when the user acknowledged
			// each policy/disclosure. Commission Exceptions is free-text for
			// ops to record per-deal carve-outs.
			// ---------------------------------------------------------------
			[
				'name'         => 'Acknowledgments & Commission',
				'description'  => 'Policy acknowledgments and commission exceptions (admin-managed).',
				'member_types' => [],
				'fields'       => [
					[
						'name'         => 'Commission Exceptions',
						'type'         => 'textarea',
						'description'  => 'Commission Exceptions',
						'frs_meta_key' => 'frs_commission_exceptions',
						'is_required'  => false,
					],
					[
						'name'         => 'Acked Policy Manual',
						'type'         => 'datebox',
						'description'  => 'Office Policy Manual Acknowledged',
						'frs_meta_key' => 'frs_ack_policy_manual_at',
						'is_required'  => false,
					],
					[
						'name'         => 'Acked ICA',
						'type'         => 'datebox',
						'description'  => 'Independent Contractor Agreement Acknowledged',
						'frs_meta_key' => 'frs_ack_ica_at',
						'is_required'  => false,
					],
					[
						'name'         => 'Acked License',
						'type'         => 'datebox',
						'description'  => 'License Compliance Acknowledged',
						'frs_meta_key' => 'frs_ack_license_at',
						'is_required'  => false,
					],
					[
						'name'         => 'Acked Auto Insurance',
						'type'         => 'datebox',
						'description'  => 'Auto Insurance Acknowledged',
						'frs_meta_key' => 'frs_ack_auto_insurance_at',
						'is_required'  => false,
					],
					[
						'name'         => 'Acked E&O Fees',
						'type'         => 'datebox',
						'description'  => 'E&O Fees Acknowledged',
						'frs_meta_key' => 'frs_ack_eo_fees_at',
						'is_required'  => false,
					],
					[
						'name'         => 'Acked SkySlope',
						'type'         => 'datebox',
						'description'  => 'SkySlope Compliance Acknowledged',
						'frs_meta_key' => 'frs_ack_skyslope_at',
						'is_required'  => false,
					],
					[
						'name'         => 'Acked Wire Fraud',
						'type'         => 'datebox',
						'description'  => 'Wire Fraud Advisory Acknowledged',
						'frs_meta_key' => 'frs_ack_wire_fraud_at',
						'is_required'  => false,
					],
					[
						'name'         => 'Acked Antitrust',
						'type'         => 'datebox',
						'description'  => 'Antitrust Policy Acknowledged',
						'frs_meta_key' => 'frs_ack_antitrust_at',
						'is_required'  => false,
					],
				],
			],

			// ---------------------------------------------------------------
			// Group 10: Reporting & Org — visible to ALL member types.
			//
			// Org-chart and brand affiliation context surfaced by the
			// base.frs.works/api/agents API. AOR (Area of Responsibility)
			// fields are free-text mirrors of the legacy reporting hierarchy
			// until we replace them with proper user-reference fields.
			// ---------------------------------------------------------------
			[
				'name'         => 'Reporting & Org',
				'description'  => 'Org-chart context, AOR reporting, and brand affiliations.',
				'member_types' => [],
				'fields'       => [
					[
						'name'         => 'Department',
						'type'         => 'textbox',
						'description'  => 'Department',
						'frs_meta_key' => 'frs_department',
						'is_required'  => false,
					],
					[
						'name'         => 'AOR Regional Director',
						'type'         => 'textbox',
						'description'  => 'AOR Regional Director',
						'frs_meta_key' => 'frs_aor_regional_director',
						'is_required'  => false,
					],
					[
						'name'         => 'AOR Regional Advisor',
						'type'         => 'textbox',
						'description'  => 'AOR Regional Advisor',
						'frs_meta_key' => 'frs_aor_regional_advisor',
						'is_required'  => false,
					],
					[
						'name'         => 'Brand Affiliations',
						'type'         => 'multiselectbox',
						'description'  => 'Brand Affiliations',
						'frs_meta_key' => 'frs_brand_affiliations',
						'is_required'  => false,
						'options'      => [
							'Century 21 Masters',
							'21st Century Lending',
							'Full Realty Services',
							'Liberty Business Advisors',
						],
					],
				],
			],

			// ---------------------------------------------------------------
			// Group 11: Legacy System References — visible to ALL but
			// admin-managed. Stores legacy system IDs and timestamps so we can
			// trace records back to the source system after migration.
			//
			// Note: legacy timestamps are textbox (not datebox) because the
			// upstream payload includes timezone info that BP datebox can't
			// parse (BP datebox stores Y-m-d only).
			// ---------------------------------------------------------------
			[
				'name'         => 'Legacy System References',
				'description'  => 'Legacy IDs and source-system timestamps (admin-managed).',
				'member_types' => [],
				'fields'       => [
					[
						'name'         => 'Legacy UUID',
						'type'         => 'textbox',
						'description'  => 'Legacy UUID',
						'frs_meta_key' => 'frs_legacy_uuid',
						'is_required'  => false,
					],
					[
						'name'         => 'Legacy ID',
						'type'         => 'textbox',
						'description'  => 'Legacy Numeric ID',
						'frs_meta_key' => 'frs_legacy_id',
						'is_required'  => false,
					],
					[
						'name'         => 'Legacy Region ID',
						'type'         => 'textbox',
						'description'  => 'Legacy Region ID',
						'frs_meta_key' => 'frs_legacy_region_id',
						'is_required'  => false,
					],
					[
						'name'         => 'Legacy Office ID',
						'type'         => 'textbox',
						'description'  => 'Legacy Office ID',
						'frs_meta_key' => 'frs_legacy_office_id',
						'is_required'  => false,
					],
					[
						'name'         => 'Legacy Created At',
						'type'         => 'textbox',
						'description'  => 'Source Created At (legacy)',
						'frs_meta_key' => 'frs_legacy_created_at',
						'is_required'  => false,
					],
					[
						'name'         => 'Legacy Updated At',
						'type'         => 'textbox',
						'description'  => 'Source Updated At (legacy)',
						'frs_meta_key' => 'frs_legacy_updated_at',
						'is_required'  => false,
					],
					[
						'name'         => 'Account',
						'type'         => 'textbox',
						'description'  => 'Account (legacy)',
						'frs_meta_key' => 'frs_account',
						'is_required'  => false,
					],
				],
			],

			// ---------------------------------------------------------------
			// Group 12: RE Production — sales_associate only.
			// Sourced from Courted enrichment. Industry-standard naming
			// (LTM = Last Twelve Months). The `frs_meta_key` here is the
			// canonical user_meta key (no frs_ prefix, no vendor prefix).
			// ---------------------------------------------------------------
			[
				'name'         => 'RE Production',
				'description'  => 'Real estate production stats sourced from MLS/Courted.',
				'member_types' => [ 'sales_associate' ],
				'fields'       => [
					[ 'name' => 'LTM Sales Volume',            'type' => 'number',  'description' => 'Sales volume (last 12 months, dollars)',          'frs_meta_key' => 'ltm_sales_volume' ],
					[ 'name' => 'LTM Closed Transactions',     'type' => 'number',  'description' => 'Closed transactions (last 12 months)',            'frs_meta_key' => 'ltm_closed_transactions' ],
					[ 'name' => 'LTM Closed Units',            'type' => 'number',  'description' => 'Closed units (last 12 months)',                   'frs_meta_key' => 'ltm_closed_units' ],
					[ 'name' => 'LTM Average Sale Price',      'type' => 'number',  'description' => 'Average sale price (last 12 months)',             'frs_meta_key' => 'ltm_avg_sale_price' ],
					[ 'name' => 'LTM Est. GCI',                'type' => 'number',  'description' => 'Estimated Gross Commission Income (last 12 mo)',  'frs_meta_key' => 'ltm_est_gci' ],
					[ 'name' => 'Prev LTM Sales Volume',       'type' => 'number',  'description' => 'Prior 12-month sales volume (YoY comparator)',    'frs_meta_key' => 'prev_ltm_sales_volume' ],
					[ 'name' => 'LTM Sales Volume Change %',   'type' => 'number',  'description' => 'YoY sales volume change (%)',                     'frs_meta_key' => 'ltm_sales_volume_change' ],
					[ 'name' => 'Active Listings',             'type' => 'number',  'description' => 'Currently active listings',                       'frs_meta_key' => 'active_listings' ],
					[ 'name' => 'Pending Listings',            'type' => 'number',  'description' => 'Currently pending listings',                      'frs_meta_key' => 'pending_listings' ],
					[ 'name' => 'Agent Tenure',                'type' => 'number',  'description' => 'Years in real estate',                            'frs_meta_key' => 'agent_tenure' ],
					[ 'name' => 'Time at Current Office',      'type' => 'number',  'description' => 'Years at current office',                         'frs_meta_key' => 'time_at_current_office' ],
					[ 'name' => 'Office Rank',                 'type' => 'number',  'description' => 'Rank within current office',                      'frs_meta_key' => 'office_rank' ],
					[ 'name' => 'Most Transacted City',        'type' => 'textbox', 'description' => 'City with the most transactions',                 'frs_meta_key' => 'most_transacted_city' ],
					[ 'name' => 'Likelihood to Move',          'type' => 'textbox', 'description' => 'Recruiting signal: likelihood to switch brokerages','frs_meta_key' => 'likelihood_to_move' ],
					[ 'name' => 'Future Growth %',             'type' => 'textbox', 'description' => 'Predicted future growth tag',                     'frs_meta_key' => 'future_growth_perc' ],
					[ 'name' => 'Agent Type Tags',             'type' => 'textarea','description' => 'Standardized type tags (JSON list)',              'frs_meta_key' => 'agent_type_tags' ],
				],
			],

			// ---------------------------------------------------------------
			// Group 13: LO Production — loan_originator only.
			// Sourced from periodic Modex CSV imports.
			// ---------------------------------------------------------------
			[
				'name'         => 'LO Production',
				'description'  => 'Lending production stats sourced from Modex.',
				'member_types' => [ 'loan_originator' ],
				'fields'       => [
					[ 'name' => 'LTM Loan Volume',         'type' => 'number',  'description' => 'Loan volume (last 12 months, dollars)',  'frs_meta_key' => 'ltm_loan_volume' ],
					[ 'name' => 'LTM Loan Units',          'type' => 'number',  'description' => 'Loan units closed (last 12 months)',     'frs_meta_key' => 'ltm_loan_units' ],
					[ 'name' => 'LTM Average Loan Amount', 'type' => 'number',  'description' => 'Average loan amount (last 12 months)',   'frs_meta_key' => 'ltm_avg_loan_amount' ],
					[ 'name' => 'LTM Average Monthly Volume','type' => 'number','description' => 'Average monthly volume (last 12 months)','frs_meta_key' => 'ltm_avg_monthly_volume' ],
					[ 'name' => 'Loan Types',              'type' => 'textarea','description' => 'Loan types handled (JSON list)',         'frs_meta_key' => 'loan_types' ],
					[ 'name' => 'Banked or Brokered',      'type' => 'textbox', 'description' => 'Banked / Brokered',                      'frs_meta_key' => 'banked_or_brokered' ],
					[ 'name' => 'Transaction Types',       'type' => 'textarea','description' => 'Transaction types (JSON list)',          'frs_meta_key' => 'transaction_types' ],
					[ 'name' => 'Property Types',          'type' => 'textarea','description' => 'Property types (JSON list)',             'frs_meta_key' => 'property_types' ],
					[ 'name' => 'Does Reverse Mortgage',   'type' => 'radio',   'description' => 'Originates reverse mortgages',           'frs_meta_key' => 'does_reverse_mortgage', 'options' => [ 'Yes', 'No' ] ],
					[ 'name' => 'Lender Beneficiaries',    'type' => 'textarea','description' => 'Lender beneficiaries (JSON list)',       'frs_meta_key' => 'lender_beneficiaries' ],
					[ 'name' => 'LTM Non-QM Volume',       'type' => 'number',  'description' => 'Non-QM volume (last 12 months)',         'frs_meta_key' => 'ltm_nonqm_volume' ],
					[ 'name' => 'LTM Non-QM Units',        'type' => 'number',  'description' => 'Non-QM units (last 12 months)',          'frs_meta_key' => 'ltm_nonqm_units' ],
					[ 'name' => 'Time at Current Employer','type' => 'number',  'description' => 'Years at current employer',              'frs_meta_key' => 'time_at_current_employer' ],
					[ 'name' => 'Industry Tenure',         'type' => 'textbox', 'description' => 'Total time in lending industry',         'frs_meta_key' => 'industry_tenure' ],
					[ 'name' => 'Jobs Last 10 Years',      'type' => 'number',  'description' => 'Number of jobs in the past 10 years',    'frs_meta_key' => 'jobs_last_10yr' ],
					[ 'name' => 'Modex Score',             'type' => 'number',  'description' => 'Modex recruit-quality score',            'frs_meta_key' => 'modex_score' ],
				],
			],
		];

		/**
		 * Filter the FRS BuddyPress XProfile schema before installation.
		 *
		 * Allows downstream code to add/remove groups, fields, options, or
		 * member-type gating without editing this class.
		 *
		 * @param array $schema List of group definitions.
		 */
		return apply_filters( 'frs_bp_xprofile_schema', $schema );
	}
}

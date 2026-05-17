<?php
/**
 * BuddyPress Bootstrap
 *
 * Registers FRS Member Types (sales_associate, loan_originator, broker_associate,
 * regional_lead, department_lead, staff, partner) and Group Types (region, office,
 * department, project) with BuddyPress.
 *
 * Idempotent — safe to call multiple times; BuddyPress silently ignores re-registration
 * of an existing type. Downstream code can extend either set via the
 * `frs_bp_member_types` and `frs_bp_group_types` filters.
 *
 * Region groups serve as containers for Office groups via BP's native `parent_id`
 * relationship; this class only registers the type vocabulary. Parent/child
 * relationships between groups are user-managed in the Groups admin.
 *
 * Also wires `bp_set_member_type` to auto-add a corresponding WP role when a
 * member type is assigned. The mapping is filterable via
 * `frs_bp_member_type_role_map`. Multi-role is by design — existing roles are
 * preserved; only the mapped role is added (idempotently).
 *
 * @package FRSUsers\Integrations
 */

namespace FRSUsers\Integrations;

defined( 'ABSPATH' ) || exit;

class BuddyPressBootstrap {

	/**
	 * Default mapping of BP Member Type → WP role to auto-add.
	 *
	 * Filterable via `frs_bp_member_type_role_map`.
	 */
	const MEMBER_TYPE_ROLE_MAP = [
		'sales_associate'  => 're_agent',
		'loan_originator'  => 'loan_officer',
		'broker_associate' => 're_agent',
		'regional_lead'    => 'leadership',
		'department_lead'  => 'leadership',
		'staff'            => 'staff',
		'partner'          => 'realtor_partner',
	];

	/**
	 * Initialize the integration.
	 *
	 * Safe to call from `plugins_loaded`. The BP registration actions only fire
	 * after BuddyPress has fully loaded, so we just attach callbacks here.
	 */
	public static function init(): void {
		if ( ! function_exists( 'bp_register_member_type' ) ) {
			return;
		}

		add_action( 'bp_register_member_types', [ __CLASS__, 'register_member_types' ] );
		add_action( 'bp_groups_register_group_types', [ __CLASS__, 'register_group_types' ] );
		add_action( 'bp_set_member_type', [ __CLASS__, 'auto_add_role_for_member_type' ], 10, 3 );
	}

	/**
	 * Register FRS Member Types with BuddyPress.
	 */
	public static function register_member_types(): void {
		if ( ! function_exists( 'bp_register_member_type' ) ) {
			return;
		}

		$types = [
			'sales_associate'  => [
				'labels'        => [
					'name'          => 'Sales Associates',
					'singular_name' => 'Sales Associate',
				],
				'has_directory' => 'sales-associates',
			],
			'loan_originator'  => [
				'labels'        => [
					'name'          => 'Loan Originators',
					'singular_name' => 'Loan Originator',
				],
				'has_directory' => 'loan-originators',
			],
			'broker_associate' => [
				'labels'        => [
					'name'          => 'Broker Associates',
					'singular_name' => 'Broker Associate',
				],
				'has_directory' => 'broker-associates',
			],
			'regional_lead'    => [
				'labels'        => [
					'name'          => 'Regional Leads',
					'singular_name' => 'Regional Lead',
				],
				'has_directory' => 'regional-leads',
			],
			'department_lead'  => [
				'labels'        => [
					'name'          => 'Department Leads',
					'singular_name' => 'Department Lead',
				],
				'has_directory' => 'department-leads',
			],
			'staff'            => [
				'labels'        => [
					'name'          => 'Staff',
					'singular_name' => 'Staff',
				],
				'has_directory' => 'staff',
			],
			'partner'          => [
				'labels'        => [
					'name'          => 'Partners',
					'singular_name' => 'Partner',
				],
				'has_directory' => 'partners',
			],
		];

		/**
		 * Filter the FRS Member Types registered with BuddyPress.
		 *
		 * @param array $types Map of type_id => args (labels, has_directory).
		 */
		$types = apply_filters( 'frs_bp_member_types', $types );

		foreach ( $types as $type_id => $args ) {
			if ( function_exists( 'bp_get_member_type_object' ) && bp_get_member_type_object( $type_id ) ) {
				continue;
			}

			bp_register_member_type( $type_id, $args );
		}
	}

	/**
	 * Register FRS Group Types with BuddyPress.
	 */
	public static function register_group_types(): void {
		if ( ! function_exists( 'bp_groups_register_group_type' ) ) {
			return;
		}

		$types = [
			'region'     => [
				'labels'                => [
					'name'          => 'Regions',
					'singular_name' => 'Region',
				],
				'has_directory'         => 'regions',
				'show_in_create_screen' => true,
				'show_in_list'          => true,
			],
			'office'     => [
				'labels'                => [
					'name'          => 'Offices',
					'singular_name' => 'Office',
				],
				'has_directory'         => 'offices',
				'show_in_create_screen' => true,
				'show_in_list'          => true,
			],
			'department' => [
				'labels'                => [
					'name'          => 'Departments',
					'singular_name' => 'Department',
				],
				'has_directory'         => 'departments',
				'show_in_create_screen' => true,
				'show_in_list'          => true,
			],
			'project'    => [
				'labels'                => [
					'name'          => 'Projects',
					'singular_name' => 'Project',
				],
				'has_directory'         => 'projects',
				'show_in_create_screen' => true,
				'show_in_list'          => true,
			],
		];

		/**
		 * Filter the FRS Group Types registered with BuddyPress.
		 *
		 * @param array $types Map of type_id => args (labels, has_directory, show_in_create_screen, show_in_list).
		 */
		$types = apply_filters( 'frs_bp_group_types', $types );

		foreach ( $types as $type_id => $args ) {
			if ( function_exists( 'bp_groups_get_group_type_object' ) && bp_groups_get_group_type_object( $type_id ) ) {
				continue;
			}

			bp_groups_register_group_type( $type_id, $args );
		}
	}

	/**
	 * Auto-add a WP role when a BP member type is assigned to a user.
	 *
	 * Hooked to `bp_set_member_type`. Idempotent: returns early if the user
	 * already has the mapped role. Multi-role is by design — existing roles are
	 * preserved.
	 *
	 * @param int    $user_id     The ID of the user receiving the member type.
	 * @param string $member_type The member type being assigned.
	 * @param bool   $append      Whether the type is being appended (BP-provided, unused).
	 */
	public static function auto_add_role_for_member_type( $user_id, $member_type, $append = false ): void {
		if ( ! $user_id || ! $member_type ) {
			return;
		}

		/**
		 * Filter the BP Member Type → WP role auto-add map.
		 *
		 * @param array $map Map of member_type => wp_role slug.
		 */
		$map = apply_filters( 'frs_bp_member_type_role_map', self::MEMBER_TYPE_ROLE_MAP );

		if ( empty( $map[ $member_type ] ) ) {
			return;
		}

		$role = $map[ $member_type ];
		$user = get_user_by( 'id', $user_id );

		if ( ! $user || in_array( $role, (array) $user->roles, true ) ) {
			return;
		}

		// Verify role exists before adding (avoid adding non-existent roles).
		if ( ! get_role( $role ) ) {
			return;
		}

		$user->add_role( $role );
	}
}

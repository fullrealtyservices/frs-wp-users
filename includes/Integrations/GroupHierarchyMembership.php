<?php
/**
 * Group Hierarchy Membership
 *
 * Standing BuddyPress hook: whenever a user joins a group, automatically
 * join every ancestor group up the `parent_id` chain too. Membership in a
 * child (office) implies membership in the parent (region) and any
 * grandparent (national / business unit).
 *
 * BuddyPress core supports `parent_id` for groups but does NOT propagate
 * membership up the tree. This class fills that gap so the FRS directory
 * can rely on region membership being a function of office membership —
 * no separate roster needed for regions.
 *
 * Fires on every join path: WP-CLI sync, BP UI, REST API, custom code.
 * Idempotent: skips joins the user already has. Leaves are NOT propagated
 * (a user in two offices in the same region must stay in the region when
 * leaving just one office).
 *
 * @package FRSUsers\Integrations
 */

namespace FRSUsers\Integrations;

defined( 'ABSPATH' ) || exit;

class GroupHierarchyMembership {

	/** Re-entry guard so propagated joins don't trigger more propagated joins recursively. */
	private static $propagating = false;

	/** Hard cap on chain depth (defense against parent_id cycles). */
	const MAX_DEPTH = 20;

	/**
	 * Group types whose membership is admin-managed only — no self-join,
	 * no request-membership UI, no self-leave. The roster owns who is in
	 * each office/region/department; agents do not self-serve.
	 */
	const ADMIN_ONLY_TYPES = [ 'office', 'region', 'department' ];

	public static function init(): void {
		add_action( 'groups_join_group', [ __CLASS__, 'on_join' ], 20, 2 );

		// Suppress the join / request-membership / leave buttons on
		// admin-managed group types. Sync code and admin UI still work
		// (those bypass the front-end button). This only kills the
		// public-facing self-service UI.
		add_filter( 'bp_get_group_join_button', [ __CLASS__, 'maybe_suppress_join_button' ], 20, 2 );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'frs-users backfill-hierarchy', [ __CLASS__, 'cli_backfill' ] );
		}
	}

	/**
	 * Filter `bp_get_group_join_button` — returns false to render no button
	 * when the group is an office / region / department. BP renders nothing
	 * for falsy returns.
	 *
	 * @param array|false       $button BP-prepared button args (or false).
	 * @param \BP_Groups_Group  $group  The group being rendered.
	 * @return array|false
	 */
	public static function maybe_suppress_join_button( $button, $group ) {
		if ( ! $group || empty( $group->id ) || ! function_exists( 'bp_groups_get_group_type' ) ) {
			return $button;
		}
		$type = bp_groups_get_group_type( $group->id );
		if ( $type && in_array( $type, self::ADMIN_ONLY_TYPES, true ) ) {
			return false;
		}
		return $button;
	}

	/**
	 * Action handler for `groups_join_group`. BP fires this with ($group_id, $user_id).
	 */
	public static function on_join( $group_id, $user_id ): void {
		if ( self::$propagating ) {
			return;
		}
		$group_id = (int) $group_id;
		$user_id  = (int) $user_id;
		if ( $group_id <= 0 || $user_id <= 0 ) {
			return;
		}
		self::$propagating = true;
		try {
			self::join_ancestors( $group_id, $user_id );
		} finally {
			self::$propagating = false;
		}
	}

	/**
	 * Walk up the parent_id chain from $group_id; for each ancestor that the
	 * user is not yet a member of, fire groups_join_group. Stops on
	 * parent_id=0, missing group, or MAX_DEPTH.
	 *
	 * @return int Number of ancestor joins performed.
	 */
	public static function join_ancestors( int $group_id, int $user_id ): int {
		if ( ! function_exists( 'groups_get_group' )
		  || ! function_exists( 'groups_is_user_member' )
		  || ! function_exists( 'groups_join_group' ) ) {
			return 0;
		}

		$joined = 0;
		$seen   = [ $group_id ];
		$cursor = $group_id;

		for ( $depth = 0; $depth < self::MAX_DEPTH; $depth++ ) {
			$grp = groups_get_group( $cursor );
			if ( ! $grp || empty( $grp->id ) ) {
				break;
			}
			$parent_id = (int) ( $grp->parent_id ?? 0 );
			if ( $parent_id <= 0 ) {
				break;
			}
			if ( in_array( $parent_id, $seen, true ) ) {
				// Cycle in parent_id chain — log and bail.
				error_log( sprintf(
					'[GroupHierarchyMembership] parent_id cycle detected at group=%d for user=%d',
					$parent_id, $user_id
				) );
				break;
			}
			$seen[]  = $parent_id;
			$cursor  = $parent_id;

			if ( groups_is_user_member( $user_id, $parent_id ) ) {
				continue;
			}
			if ( groups_join_group( $parent_id, $user_id ) ) {
				$joined++;
			}
		}

		return $joined;
	}

	/**
	 * Backfill: for every existing groups_membership, ensure the user is also
	 * a member of every ancestor of their groups.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change, don't write.
	 *
	 * [--user_id=<id>]
	 * : Limit to one user.
	 *
	 * ## EXAMPLES
	 *
	 *     wp frs-users backfill-hierarchy
	 *     wp frs-users backfill-hierarchy --dry-run
	 *     wp frs-users backfill-hierarchy --user_id=2
	 *
	 * @when after_wp_load
	 */
	public static function cli_backfill( $args, $assoc_args ): void {
		$dry_run    = isset( $assoc_args['dry-run'] );
		$only_user  = isset( $assoc_args['user_id'] ) ? (int) $assoc_args['user_id'] : 0;

		if ( ! function_exists( 'groups_get_user_groups' ) || ! function_exists( 'groups_get_group' ) ) {
			\WP_CLI::error( 'BuddyPress groups component not loaded.' );
			return;
		}

		global $wpdb;
		$bp = function_exists( 'buddypress' ) ? buddypress() : null;
		$table_members = $bp && isset( $bp->groups->table_name_members )
			? $bp->groups->table_name_members
			: $wpdb->prefix . 'bp_groups_members';

		$where = 'WHERE is_confirmed = 1 AND is_banned = 0';
		if ( $only_user > 0 ) {
			$where .= $wpdb->prepare( ' AND user_id = %d', $only_user );
		}

		$rows = $wpdb->get_results( "SELECT DISTINCT user_id, group_id FROM {$table_members} {$where}" );
		\WP_CLI::log( sprintf( 'Walking %d membership rows...', count( $rows ) ) );

		$users_touched = 0;
		$joins_added   = 0;
		$prev_user     = 0;
		$prev_added    = 0;

		foreach ( $rows as $row ) {
			$uid = (int) $row->user_id;
			$gid = (int) $row->group_id;

			if ( $dry_run ) {
				// Count what would be added without actually joining.
				$depth_added = 0;
				$cursor      = $gid;
				$seen        = [ $gid ];
				for ( $d = 0; $d < self::MAX_DEPTH; $d++ ) {
					$grp = groups_get_group( $cursor );
					if ( ! $grp || empty( $grp->id ) ) break;
					$pid = (int) ( $grp->parent_id ?? 0 );
					if ( $pid <= 0 || in_array( $pid, $seen, true ) ) break;
					$seen[]  = $pid;
					$cursor  = $pid;
					if ( ! groups_is_user_member( $uid, $pid ) ) {
						$depth_added++;
					}
				}
				$joins_added += $depth_added;
				if ( $depth_added > 0 && $uid !== $prev_user ) {
					$users_touched++;
					$prev_user = $uid;
				}
			} else {
				$added = self::join_ancestors( $gid, $uid );
				if ( $added > 0 ) {
					$joins_added += $added;
					if ( $uid !== $prev_user ) {
						$users_touched++;
						$prev_user = $uid;
					}
				}
			}
		}

		\WP_CLI::log( sprintf(
			'%s — would add %d ancestor joins across %d users.',
			$dry_run ? 'DRY RUN' : 'DONE',
			$joins_added,
			$users_touched
		) );
		\WP_CLI::success( 'backfill-hierarchy complete.' );
	}
}

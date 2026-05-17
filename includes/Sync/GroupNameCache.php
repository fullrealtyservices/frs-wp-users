<?php
/**
 * BP group-membership cache hook.
 *
 * Keeps three user_meta keys in sync with the user's BP group memberships:
 *
 *   `office`     ← name of the user's most-recent office-typed group
 *   `region`     ← name of the user's most-recent region-typed group
 *   `department` ← name of the user's most-recent department-typed group
 *
 * Why a cache? The relationship (which groups a user belongs to) IS the
 * truth — it lives in `wp_bp_groups_members`. But every profile render
 * needs the human-readable "Bay Area" / "Walnut" string, so we cache it
 * as user_meta to avoid join queries. This class keeps the cache from
 * drifting by re-computing on every join/leave/promotion event.
 *
 * Listens to:
 *   groups_join_group         — user joined a group
 *   groups_leave_group        — user left a group
 *   groups_remove_member      — admin removed a user
 *   groups_member_after_save  — any membership state change
 *
 * Idempotent. If the user has multiple offices, the most-recently-joined
 * wins (BP returns memberships ordered by date_modified DESC by default).
 *
 * @package FRSUsers\Sync
 * @since 2.2.0
 */

namespace FRSUsers\Sync;

defined( 'ABSPATH' ) || exit;

class GroupNameCache {

	/** Group types we cache. */
	const CACHED_TYPES = [ 'office', 'region', 'department' ];

	public static function init(): void {
		if ( ! function_exists( 'bp_get_user_groups' ) ) {
			return;
		}

		add_action( 'groups_join_group',        [ __CLASS__, 'on_membership_change' ], 10, 2 );
		add_action( 'groups_leave_group',       [ __CLASS__, 'on_membership_change' ], 10, 2 );
		add_action( 'groups_remove_member',     [ __CLASS__, 'on_membership_change_admin' ], 10, 2 );
		add_action( 'groups_member_after_save', [ __CLASS__, 'on_membership_after_save' ], 10, 1 );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'frs-users refresh-group-cache', [ __CLASS__, 'cli_refresh_all' ] );
		}
	}

	/**
	 * Hook: groups_join_group / groups_leave_group → recompute for that user.
	 *
	 * @param int $group_id (unused — we recompute from full membership)
	 * @param int $user_id
	 */
	public static function on_membership_change( $group_id, $user_id ): void {
		self::refresh_for_user( (int) $user_id );
	}

	/** Hook: groups_remove_member fires with ($group_id, $user_id) same shape. */
	public static function on_membership_change_admin( $group_id, $user_id ): void {
		self::refresh_for_user( (int) $user_id );
	}

	/**
	 * Hook: groups_member_after_save fires with the BP_Groups_Member object.
	 */
	public static function on_membership_after_save( $member ): void {
		if ( isset( $member->user_id ) ) {
			self::refresh_for_user( (int) $member->user_id );
		}
	}

	/**
	 * Walk the user's memberships, pick the most-recent group of each cached
	 * type, write its name to user_meta.
	 */
	public static function refresh_for_user( int $user_id ): void {
		if ( ! $user_id ) {
			return;
		}

		$picks = []; // type → group name
		$memberships = bp_get_user_groups( $user_id, [
			'is_confirmed' => true,
			'is_banned'    => false,
		] );
		if ( ! is_array( $memberships ) ) {
			$memberships = [];
		}

		foreach ( $memberships as $m ) {
			$gid = (int) ( $m->group_id ?? 0 );
			if ( ! $gid ) {
				continue;
			}
			$group = groups_get_group( $gid );
			if ( ! $group || empty( $group->id ) ) {
				continue;
			}
			$type = function_exists( 'bp_groups_get_group_type' ) ? (string) bp_groups_get_group_type( $gid ) : '';
			if ( ! in_array( $type, self::CACHED_TYPES, true ) ) {
				continue;
			}
			// Most-recent wins; bp_get_user_groups already returns sorted desc by date_modified.
			if ( ! isset( $picks[ $type ] ) ) {
				$picks[ $type ] = (string) $group->name;
			}
		}

		// Write each cache key; clear if no longer present.
		foreach ( self::CACHED_TYPES as $type ) {
			$val = $picks[ $type ] ?? '';
			if ( '' === $val ) {
				delete_user_meta( $user_id, $type );
			} else {
				update_user_meta( $user_id, $type, $val );
			}
		}
	}

	/**
	 * WP-CLI: refresh the cache for every user. Useful one-time after the
	 * initial sync, or after a bulk group restructure.
	 */
	public static function cli_refresh_all( $args, $assoc_args ): void {
		global $wpdb;
		$user_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->users}" );
		\WP_CLI::log( sprintf( 'Refreshing group-name cache for %d users...', count( $user_ids ) ) );
		foreach ( $user_ids as $i => $uid ) {
			self::refresh_for_user( (int) $uid );
			if ( ( $i + 1 ) % 50 === 0 ) {
				\WP_CLI::log( sprintf( '  %d/%d', $i + 1, count( $user_ids ) ) );
			}
		}
		\WP_CLI::success( 'Group-name cache refreshed.' );
	}
}

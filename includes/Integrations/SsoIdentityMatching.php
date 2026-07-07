<?php
/**
 * SSO Identity Matching
 *
 * Prevents duplicate WordPress accounts when the same person signs in via
 * SSO (WPO365) with a different email/UPN than the one already on file.
 *
 * WPO365 matches an incoming login to an existing WP user in this order:
 * Azure AD object ID -> UPN -> preferred_username -> email. Profiles that
 * were bulk-imported or created manually never had an oid/UPN recorded, so
 * if the same person later signs in with a second email, none of those
 * match and WPO365 silently creates a brand new WP user — the same real
 * person now has two profiles (and, on the marketing site, two public
 * directory cards).
 *
 * Fix: when no existing user matches the incoming email, but exactly one
 * existing FRS-role profile matches on first+last name, record the new
 * email as that profile's secondary email and rewrite the WPO365 user
 * object so it resolves to the existing account instead of creating a new
 * one. Never merges more than one profile per new secondary email, and
 * never touches an existing user's login-relevant fields.
 *
 * @package FRSUsers\Integrations
 * @since   2.4.0
 */

namespace FRSUsers\Integrations;

use FRSUsers\Core\Roles;

defined( 'ABSPATH' ) || exit;

class SsoIdentityMatching {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'wpo365/user', array( __CLASS__, 'match_existing_profile' ), 20, 2 );
	}

	/**
	 * Redirect a new-looking SSO login to an existing profile when it's
	 * confidently the same person under a different email.
	 *
	 * @param object $wpo_usr WPO365's internal user representation.
	 * @param string $flow    Auth flow ('oidc', 'graph', 'saml', 'scim').
	 * @return object
	 */
	public static function match_existing_profile( $wpo_usr, $flow ) {
		if ( empty( $wpo_usr->email ) || empty( $wpo_usr->first_name ) || empty( $wpo_usr->last_name ) ) {
			return $wpo_usr;
		}

		// A WP user already owns this exact email — normal login, nothing to do.
		if ( get_user_by( 'email', $wpo_usr->email ) ) {
			return $wpo_usr;
		}

		$candidates = self::find_profiles_by_name( $wpo_usr->first_name, $wpo_usr->last_name );

		if ( count( $candidates ) !== 1 ) {
			if ( count( $candidates ) > 1 ) {
				error_log( sprintf(
					'FRS SSO: Ambiguous name match for "%s %s" (%d existing profiles) while signing in as %s — creating a new account rather than guessing. Please review and merge manually if this is a duplicate.',
					$wpo_usr->first_name,
					$wpo_usr->last_name,
					count( $candidates ),
					$wpo_usr->email
				) );
			}
			return $wpo_usr;
		}

		$existing = $candidates[0];

		$current_secondary = get_user_meta( $existing->ID, 'frs_secondary_email', true );
		if ( empty( $current_secondary ) || 0 !== strcasecmp( $current_secondary, $wpo_usr->email ) ) {
			update_user_meta( $existing->ID, 'frs_secondary_email', $wpo_usr->email );
		}

		error_log( sprintf(
			'FRS SSO: Matched incoming login %s to existing profile #%d (%s) by name "%s %s" — recorded as secondary email instead of creating a duplicate account.',
			$wpo_usr->email,
			$existing->ID,
			$existing->user_email,
			$wpo_usr->first_name,
			$wpo_usr->last_name
		) );

		// Redirect WPO365's own matching (which reads these properties after
		// this filter runs) to the existing account.
		$wpo_usr->email = $existing->user_email;
		if ( ! empty( $wpo_usr->upn ) ) {
			$wpo_usr->upn = $existing->user_email;
		}

		return $wpo_usr;
	}

	/**
	 * Find existing FRS-role users matching a first+last name (case-insensitive).
	 *
	 * @param string $first_name First name.
	 * @param string $last_name  Last name.
	 * @return \WP_User[]
	 */
	private static function find_profiles_by_name( string $first_name, string $last_name ): array {
		return get_users( array(
			'role__in'   => Roles::get_wp_role_slugs(),
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'AND',
				array(
					'key'     => 'first_name',
					'value'   => $first_name,
					'compare' => '=',
				),
				array(
					'key'     => 'last_name',
					'value'   => $last_name,
					'compare' => '=',
				),
			),
		) );
	}
}

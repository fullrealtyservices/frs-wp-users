<?php
/**
 * BuddyPress Label Overrides
 *
 * Relabels BuddyPress UI strings (Members → People, Friends → Connections, etc.)
 * across the admin and frontend by filtering WordPress's gettext pipeline.
 *
 * Behavior:
 *  - Acts ONLY when the translation domain is `buddypress`; all other domains
 *    pass through untouched.
 *  - Idempotent — exact-match lookups against a static label map; missing keys
 *    return the original translation.
 *  - Runs at priority 20 so BuddyPress's own translations have already loaded.
 *  - Extension point: `frs_bp_label_map` filter lets other code add or override
 *    entries in the swap table without modifying this class.
 *
 * @package FRSUsers\Integrations
 */

namespace FRSUsers\Integrations;

defined( 'ABSPATH' ) || exit;

class BuddyPressLabels {

	/**
	 * Default label swap table — original BP string => replacement.
	 *
	 * Singular + plural pairs are listed together so the ngettext filter
	 * picks up the correct form based on the count BP passed.
	 */
	const LABEL_MAP = array(
		// Members → People
		'Members'                      => 'People',
		'Member'                       => 'Person',
		'Members Directory'            => 'People Directory',
		'All Members'                  => 'All People',
		'My Members'                   => 'My People',
		'%d Member'                    => '%d Person',
		'%d Members'                   => '%d People',
		'%s Member'                    => '%s Person',
		'%s Members'                   => '%s People',
		'Member Type'                  => 'Person Type',
		'Member Types'                 => 'Person Types',
		'View Member'                  => 'View Person',
		'Edit Member'                  => 'Edit Person',
		'Recently Active Members'      => 'Recently Active People',
		'Newest Members'               => 'Newest People',
		'Popular Members'              => 'Popular People',
		'Online Members'               => 'Online People',
		'No Members Found'             => 'No People Found',
		'Search Members...'            => 'Search People...',

		// Friends → Connections
		'Friends'                      => 'Connections',
		'Friend'                       => 'Connection',
		'%d Friend'                    => '%d Connection',
		'%d Friends'                   => '%d Connections',
		'%s Friend'                    => '%s Connection',
		'%s Friends'                   => '%s Connections',
		'Add Friend'                   => 'Connect',
		'Add friend'                   => 'Connect',
		'Friendship Request'           => 'Connection Request',
		'Friendship Requests'          => 'Connection Requests',
		'My Friends'                   => 'My Connections',
		'New Friends'                  => 'New Connections',
		'Cancel Friendship Request'    => 'Cancel Connection Request',
		'Accept Friendship'            => 'Accept Connection',
		'Reject Friendship'            => 'Reject Connection',
		'Pending Friendship Requests'  => 'Pending Connection Requests',
		'Active Friendships'           => 'Active Connections',
		'Friendships'                  => 'Connections',
	);

	/**
	 * Register every translation filter at priority 20 — gettext (singular),
	 * ngettext (plural — "1 Member / 5 Members"), and the _with_context
	 * variants so labels firing through _x() / _nx() are also caught.
	 */
	public static function init(): void {
		add_filter( 'gettext',                array( __CLASS__, 'translate' ),                20, 3 );
		add_filter( 'gettext_with_context',   array( __CLASS__, 'translate_with_context' ),   20, 4 );
		add_filter( 'ngettext',               array( __CLASS__, 'translate_plural' ),         20, 5 );
		add_filter( 'ngettext_with_context',  array( __CLASS__, 'translate_plural_context' ), 20, 6 );
	}

	/**
	 * Look up `$text` in the map; return mapped value or fall back to BP's translation.
	 */
	protected static function lookup( string $text, string $translation ): string {
		$map = apply_filters( 'frs_bp_label_map', self::LABEL_MAP );
		return isset( $map[ $text ] ) ? $map[ $text ] : $translation;
	}

	/**
	 * Singular gettext — `__()` and `_e()` calls.
	 */
	public static function translate( $translation, $text, $domain ) {
		if ( 'buddypress' !== $domain ) {
			return $translation;
		}
		return self::lookup( $text, $translation );
	}

	/**
	 * Singular gettext with context — `_x()` calls.
	 */
	public static function translate_with_context( $translation, $text, $context, $domain ) {
		if ( 'buddypress' !== $domain ) {
			return $translation;
		}
		return self::lookup( $text, $translation );
	}

	/**
	 * Plural gettext — `_n()` calls. BP returns either $single or $plural based
	 * on $number; whichever it picked is what we look up in the map.
	 */
	public static function translate_plural( $translation, $single, $plural, $number, $domain ) {
		if ( 'buddypress' !== $domain ) {
			return $translation;
		}
		// Try the form BP actually selected (the $translation BP gave us);
		// fall back to whichever raw key is in the map.
		$candidate = ( 1 === (int) $number ) ? $single : $plural;
		return self::lookup( $candidate, $translation );
	}

	/**
	 * Plural gettext with context — `_nx()` calls.
	 */
	public static function translate_plural_context( $translation, $single, $plural, $number, $context, $domain ) {
		if ( 'buddypress' !== $domain ) {
			return $translation;
		}
		$candidate = ( 1 === (int) $number ) ? $single : $plural;
		return self::lookup( $candidate, $translation );
	}
}

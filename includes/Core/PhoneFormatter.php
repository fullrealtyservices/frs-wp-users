<?php
/**
 * Phone Formatter
 *
 * Display-only US phone number formatting. Storage stays raw digits
 * (frs_phone_number etc.) — this is applied only where a number is
 * rendered to a human.
 *
 * @package FRSUsers
 * @subpackage Core
 * @since 2.3.0
 */

namespace FRSUsers\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class PhoneFormatter
 */
class PhoneFormatter {

	/**
	 * Format a phone number as US-style "(XXX) XXX-XXXX".
	 *
	 * Numbers that aren't a standard 10-digit US number (or 11 digits with a
	 * leading country code of 1) are returned unchanged rather than mangled.
	 *
	 * @param string $raw Raw phone number, any format.
	 * @return string Formatted number, or the original string if unformattable.
	 */
	public static function us( $raw ) {
		$digits = preg_replace( '/\D+/', '', (string) $raw );

		if ( 11 === strlen( $digits ) && '1' === $digits[0] ) {
			$digits = substr( $digits, 1 );
		}

		if ( 10 !== strlen( $digits ) ) {
			return $raw;
		}

		return sprintf(
			'(%s) %s-%s',
			substr( $digits, 0, 3 ),
			substr( $digits, 3, 3 ),
			substr( $digits, 6 )
		);
	}
}

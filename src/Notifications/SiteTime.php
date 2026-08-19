<?php
declare( strict_types=1 );

namespace Reservant\Notifications;

/**
 * A UTC datetime column rendered for a human, in the SITE's timezone - the database is UTC
 * (AGENTS.md section 1) and a guest reading "14:00 UTC" would have to do arithmetic to find out
 * when to turn up.
 *
 * Extracted from `BookingEmails` the moment a second Notifications class (`ApprovalEmails`, for the
 * payment-link deadline) needed the same rule: the money formatter came to undercharge zero-decimal
 * currencies by 100x precisely because a formatting rule lived in two places, and this one was
 * about to. `Admin\ApprovalActionEndpoint::summary()` still builds the same timestamp inline - a
 * home BOTH an Admin and a Notifications class could depend on does not exist today, and inventing
 * a namespace for one function is a worse trade than one remaining copy.
 */
final class SiteTime {

	/** @param string $sqlUtc a `Y-m-d H:i:s` UTC value as the DB stores it, or '' for nothing. */
	public static function local( string $sqlUtc ): string {
		if ( '' === $sqlUtc ) {
			return '';
		}
		$utc    = new \DateTimeImmutable( $sqlUtc, new \DateTimeZone( 'UTC' ) );
		$format = trim( (string) get_option( 'date_format', 'F j, Y' ) . ' ' . (string) get_option( 'time_format', 'g:i a' ) );
		return (string) wp_date( $format, $utc->getTimestamp(), wp_timezone() );
	}
}

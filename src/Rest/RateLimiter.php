<?php
declare( strict_types=1 );

namespace Reservant\Rest;

/**
 * A transient-backed request counter, used to keep `POST /holds` from being a free slot-shredder
 * (AGENTS.md section 5). Best-effort by design: transients may be object-cache-backed and are not atomic,
 * so this thins abuse rather than proving a bound. Capacity itself is protected by the locked write
 * protocol (section 2.2), never by this.
 *
 * The window is 60 seconds anchored to the last *allowed* request: rejected calls do not touch the
 * counter, so a blocked client is let back in 60 s after its last success, not 60 s after its last
 * attempt.
 */
final class RateLimiter {

	/** Transient keys are capped at 172 chars; the digest keeps any bucket name legal. */
	public static function key( string $bucket ): string {
		return 'reservant_rl_' . md5( $bucket );
	}

	/** @param int $maxPerMinute 0 or less blocks everything - an explicit "closed" setting. */
	public static function allow( string $bucket, int $maxPerMinute ): bool {
		if ( $maxPerMinute < 1 ) {
			return false;
		}
		$key    = self::key( $bucket );
		$stored = get_transient( $key );
		$count  = false === $stored ? 0 : (int) $stored;
		if ( $count >= $maxPerMinute ) {
			return false;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}
}

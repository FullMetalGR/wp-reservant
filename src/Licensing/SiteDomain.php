<?php
declare( strict_types=1 );

namespace Reservant\Licensing;

/**
 * The one place that decides what "this site's domain" means for licensing.
 *
 * A license is bound to a domain, so the comparison "is the stored domain this site?" runs on every
 * status read - and it must give the same answer for `https://WWW.Example.com:8443/shop/` as for
 * `http://example.com`. Scheme, port, userinfo, path and a leading `www.` are all things that change
 * without the site changing, and a site that appeared to move because someone turned on HTTPS would
 * lock its own owner out.
 *
 * `normalize()` takes the URL as an ARGUMENT and `current()` is the only method that calls
 * `home_url()`. That split is not decoration: the normaliser is the part with edge cases worth
 * pinning, and `tests/Unit` bootstraps `vendor/autoload.php` and nothing else, so a normaliser that
 * reached for `home_url()` itself could only ever be tested with WordPress booted. The same split is
 * what lets P8.2's guard ask for this site's domain without importing a second copy of the rules.
 */
final class SiteDomain {

	/** This site's bound-domain identity, derived from `home_url()`. */
	public static function current(): string {
		return self::normalize( home_url() );
	}

	/**
	 * The comparable host inside a URL: lowercased, without scheme, userinfo, port, path or a
	 * leading `www.`.
	 *
	 * Hand-rolled rather than `parse_url()`/`wp_parse_url()` on purpose. `wp_parse_url()` is a
	 * WordPress function and would put this back out of reach of the unit suite; and a bare
	 * `example.com:8443` - no scheme - is a shape a stored or hand-typed value really takes, which
	 * the step-by-step trim below handles identically to a full URL.
	 *
	 * A leading `www.` is dropped because `www.example.com` and `example.com` are one site to every
	 * owner who ever bought a license. Only the LEADING one: `www.www.example.com` keeps its second.
	 */
	public static function normalize( string $url ): string {
		$host = trim( $url );

		// Scheme, if any. Anything matching RFC 3986's scheme grammar, not just http(s).
		$host = (string) preg_replace( '#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', '', $host );

		// Everything from the first path, query or fragment character onward is not the authority.
		$host = substr( $host, 0, strcspn( $host, '/?#' ) );

		// `user:pass@` - the LAST `@`, since a password may legitimately contain one.
		$at = strrpos( $host, '@' );
		if ( false !== $at ) {
			$host = substr( $host, $at + 1 );
		}

		// The port. An IPv6 literal is bracketed and full of colons, so only a colon AFTER the
		// closing bracket is a port separator; without that check `[2001:db8::1]` would be shredded
		// down to `[2001` and never match anything again.
		$bracket = strrpos( $host, ']' );
		$colon   = strrpos( $host, ':' );
		if ( false !== $colon && ( false === $bracket || $colon > $bracket ) ) {
			$host = substr( $host, 0, $colon );
		}

		$host = strtolower( $host );

		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}
}

<?php
declare( strict_types=1 );

namespace Reservant\Tests\Unit\Licensing;

use PHPUnit\Framework\TestCase;
use Reservant\Licensing\SiteDomain;

/**
 * The comparison a domain-bound license lives or dies by.
 *
 * `SiteDomain::normalize()` takes the URL as an argument precisely so this suite can exist: the unit
 * bootstrap is `vendor/autoload.php` and nothing else, so a normaliser that called `home_url()`
 * itself could only ever be exercised with WordPress booted - and this is the function whose edge
 * cases decide whether turning on HTTPS locks an owner out of the plugin they paid for.
 */
final class SiteDomainTest extends TestCase {

	public function test_a_plain_host_survives_unchanged(): void {
		self::assertSame( 'example.com', SiteDomain::normalize( 'example.com' ) );
	}

	public function test_the_scheme_never_changes_which_site_this_is(): void {
		self::assertSame(
			SiteDomain::normalize( 'http://example.com' ),
			SiteDomain::normalize( 'https://example.com' ),
			'switching a site to HTTPS must not read as moving it to another domain'
		);
	}

	public function test_case_and_a_leading_www_and_a_port_all_normalise_away(): void {
		self::assertSame( 'example.com', SiteDomain::normalize( 'WWW.Example.COM:8443' ) );
	}

	public function test_a_full_url_reduces_to_its_host(): void {
		self::assertSame( 'example.com', SiteDomain::normalize( 'https://WWW.Example.com:8443/shop/book?a=1#top' ) );
	}

	public function test_credentials_in_the_url_are_not_mistaken_for_the_host(): void {
		self::assertSame( 'example.com', SiteDomain::normalize( 'https://user:p@ss@www.example.com:8443/x' ) );
	}

	/** Only the LEADING `www.` goes: `www.example.com` and `example.com` are one site, deeper ones are not. */
	public function test_only_the_leading_www_is_dropped(): void {
		self::assertSame( 'www.example.com', SiteDomain::normalize( 'https://www.www.example.com/' ) );
		self::assertSame( 'wwwexample.com', SiteDomain::normalize( 'wwwexample.com' ), 'the dot is part of the prefix' );
	}

	/** A subdomain IS a different site - staging.example.com must not pass as example.com. */
	public function test_a_subdomain_is_a_different_domain(): void {
		self::assertSame( 'staging.example.com', SiteDomain::normalize( 'https://staging.example.com' ) );
		self::assertNotSame( SiteDomain::normalize( 'https://example.com' ), SiteDomain::normalize( 'https://staging.example.com' ) );
	}

	/**
	 * An IPv6 literal is bracketed and full of colons. Stripping "the port" naively would leave
	 * `[2001` behind, and a site on a v6 address would never match its own stored domain again.
	 */
	public function test_an_ipv6_literal_keeps_its_colons_but_loses_its_port(): void {
		self::assertSame( '[2001:db8::1]', SiteDomain::normalize( 'http://[2001:DB8::1]/blog' ) );
		self::assertSame( '[2001:db8::1]', SiteDomain::normalize( 'http://[2001:db8::1]:8443/blog' ) );
	}

	public function test_a_development_host_with_a_port_is_still_one_host(): void {
		self::assertSame( 'localhost', SiteDomain::normalize( 'http://localhost:8888/wp' ) );
	}

	public function test_surrounding_whitespace_and_a_trailing_slash_are_not_part_of_the_domain(): void {
		self::assertSame( 'example.com', SiteDomain::normalize( "  https://example.com/  \n" ) );
	}

	/** Nothing in, nothing out - the caller compares, and '' never equals a real stored domain. */
	public function test_an_empty_url_yields_an_empty_domain(): void {
		self::assertSame( '', SiteDomain::normalize( '' ) );
	}
}

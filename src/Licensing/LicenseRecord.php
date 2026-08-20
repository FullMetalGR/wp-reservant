<?php
declare( strict_types=1 );

namespace Reservant\Licensing;

/**
 * The stored license row, and the derivation of a `LicenseStatus` from it.
 *
 * **Its own option, `reservant_license`, deliberately not a field inside `reservant_settings`.**
 * `Settings` is handed wholesale to the admin SPA and round-tripped by `Settings::update()`; a
 * license key living in there would ride out to the browser with the currency and the TTLs, and any
 * settings write would rewrite it. Different data, different lifecycle, different audience, so a
 * different row. Non-autoloaded (`update_option( ..., false )`) like `reservant_settings`, because
 * nothing on an ordinary front-end request reads it.
 *
 * **Reads never throw**, for the reason `Settings::make()` gives at length: the screen that could
 * repair a corrupt row is a screen that has to load on top of it. Here the stakes are sharper still
 * - a row this class refused to read would be an owner who has PAID and cannot get back to `Active`,
 * because activating is itself a read-modify-write of this row. So every field degrades to its empty
 * value independently, and the emptiest possible reading of a corrupt row is `Inactive`: enter your
 * key again. Nothing in this plugin can write a malformed row (only this class writes it), so this
 * is recovery from outside interference, not a path the code takes.
 *
 * **The state is DERIVED, never stored.** There is no `state` column, and that is what makes grace
 * expiry work without a job: `statusAt()` compares the stored grace START to the instant it is asked
 * about, so a license whose window ran out is `Invalid` the moment anyone looks, even on a site
 * whose Action Scheduler queue has not run in a month. Storing a deadline instead would also have to
 * be migrated the day `GRACE_DAYS` changes; storing the start never does.
 */
final class LicenseRecord {

	private const OPTION = 'reservant_license';

	/**
	 * How long a previously-good license keeps working while re-checks are failing.
	 *
	 * Deliberately NOT filterable, unlike almost every other number in this plugin: a site that
	 * could set its own grace window could set it to a century, and "how long may an unverified
	 * license run" is the one question the licensed site does not get a vote on.
	 */
	public const GRACE_DAYS = 14;

	private const STORED_FORMAT = 'Y-m-d H:i:s';

	/** @var array{key:string,domain:string,last_check:string,grace_started:string,rejected:bool} */
	private const EMPTY = array(
		'key'           => '',
		'domain'        => '',
		'last_check'    => '',
		'grace_started' => '',
		'rejected'      => false,
	);

	/**
	 * @param array{key:string,domain:string,last_check:string,grace_started:string,rejected:bool} $values
	 */
	private function __construct( private readonly array $values ) {}

	/** The row as stored, or the empty record when there is nothing (or nothing readable) there. */
	public static function load(): self {
		return self::fromStored( get_option( self::OPTION ) );
	}

	/**
	 * Coercion, split out from `load()` so the leniency itself is unit-testable with no WordPress
	 * bootstrap - the same reason `Plugin::devToolsAllowed()` is a pure function of its inputs.
	 *
	 * Per field, exactly like `Settings::coerce()`: one unreadable value must not discard the good
	 * ones beside it. A malformed value is REPLACED, never repaired - a timestamp that does not
	 * match the stored format becomes "no timestamp" rather than something `strtotime()` guessed at.
	 */
	public static function fromStored( mixed $stored ): self {
		if ( ! is_array( $stored ) ) {
			return self::none();
		}

		$key      = $stored['key'] ?? null;
		$domain   = $stored['domain'] ?? null;
		$rejected = $stored['rejected'] ?? null;

		return new self(
			array(
				'key'           => is_string( $key ) ? $key : '',
				'domain'        => is_string( $domain ) ? $domain : '',
				'last_check'    => self::timestampOrEmpty( $stored['last_check'] ?? null ),
				'grace_started' => self::timestampOrEmpty( $stored['grace_started'] ?? null ),
				'rejected'      => is_bool( $rejected ) ? $rejected : false,
			)
		);
	}

	/** No license: a fresh install, or one the owner has deactivated. */
	public static function none(): self {
		return new self( self::EMPTY );
	}

	/**
	 * A key the validator has just accepted, bound to the domain it was accepted at.
	 *
	 * Built from nothing rather than from the previous record on purpose: activation REPLACES, and
	 * carrying a stale grace clock or an old domain across a fresh, successful activation would let
	 * a site inherit a failure that no longer describes it.
	 */
	public static function activated( string $key, string $domain, \DateTimeImmutable $nowUtc ): self {
		return new self(
			array(
				'key'           => $key,
				'domain'        => $domain,
				'last_check'    => self::format( $nowUtc ),
				'grace_started' => '',
				'rejected'      => false,
			)
		);
	}

	/**
	 * A key the validator refused at activation.
	 *
	 * The key is kept, not discarded, so the settings screen can show its last 4 characters back to
	 * the owner - "that is not the key I meant to paste" is the commonest cause and the cheapest to
	 * spot. `rejected` is the ONE flag that means Invalid outright, and only this path sets it:
	 * a refusal at activation is a human act with an immediate answer, where a failing periodic
	 * re-check is indistinguishable from an outage and gets the grace window instead.
	 */
	public static function rejected( string $key, string $domain ): self {
		return new self(
			array(
				'key'           => $key,
				'domain'        => $domain,
				'last_check'    => '',
				'grace_started' => '',
				'rejected'      => true,
			)
		);
	}

	/**
	 * The validator said yes again. Clears the grace clock AND the rejection: a key that has become
	 * good (the invoice was paid, the seat was freed) should recover on its own rather than needing
	 * the owner to re-paste the same characters.
	 */
	public function withSuccessfulCheck( \DateTimeImmutable $nowUtc ): self {
		return new self(
			array(
				'key'           => $this->values['key'],
				'domain'        => $this->values['domain'],
				'last_check'    => self::format( $nowUtc ),
				'grace_started' => '',
				'rejected'      => false,
			)
		);
	}

	/**
	 * The validator did not say yes. Starts the grace clock if it is not already running, and
	 * otherwise changes NOTHING - the deadline is measured from the first failure, so a job that
	 * fires daily must not push it forward every day.
	 *
	 * A license already `rejected` is left alone: it never earned a grace window, and starting one
	 * here would silently promote an Invalid license to `isActive()` for a fortnight.
	 */
	public function withFailedCheck( \DateTimeImmutable $nowUtc ): self {
		if ( $this->values['rejected'] || '' !== $this->values['grace_started'] ) {
			return $this;
		}

		return new self(
			array(
				'key'           => $this->values['key'],
				'domain'        => $this->values['domain'],
				'last_check'    => $this->values['last_check'],
				'grace_started' => self::format( $nowUtc ),
				'rejected'      => false,
			)
		);
	}

	public function persist(): void {
		update_option( self::OPTION, $this->values, false );
	}

	/**
	 * Removes the row entirely - what deactivation does.
	 *
	 * A delete rather than a write of `EMPTY`, because `fromStored()` reads the two identically and
	 * of the two only the delete is honest about there being nothing left to remember.
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}

	/** The stored plaintext key, or '' when there is none. Only a validator should ever want this. */
	public function key(): string {
		return $this->values['key'];
	}

	/** The domain this key is bound to, which is not necessarily this site's. */
	public function domain(): string {
		return $this->values['domain'];
	}

	/**
	 * The state this row implies at a given instant, on a given site.
	 *
	 * Pure: both the clock and the site are arguments, so every branch below is reachable from a
	 * unit test and nothing here reads an option or a global.
	 *
	 * The order of the tests is the point:
	 *
	 * 1. No key at all is `Inactive` - the fresh install, and the reading a corrupt row degrades to.
	 * 2. The DOMAIN is checked before anything the validator ever said, because a mismatch means the
	 *    check history in this row was written about a DIFFERENT installation. A staging clone
	 *    carrying a copy of production's option row must be told "this license belongs to another
	 *    site", not handed production's verdict about itself. Nothing rebinds: auto-rebinding would
	 *    let one production license silently cover every clone, which is the entire point of binding
	 *    to a domain in the first place.
	 * 3. A rejection at activation is `Invalid` outright - see `rejected()`.
	 * 4. A running grace clock is `Grace` until its deadline and `Invalid` from the deadline on. The
	 *    comparison is strict, so the deadline instant itself has already run out.
	 */
	public function statusAt( \DateTimeImmutable $nowUtc, string $currentDomain ): LicenseStatus {
		if ( '' === $this->values['key'] ) {
			return new LicenseStatus( LicenseState::Inactive, '', '', null, null );
		}

		$masked    = self::mask( $this->values['key'] );
		$domain    = $this->values['domain'];
		$lastCheck = self::parse( $this->values['last_check'] );

		if ( $domain !== $currentDomain ) {
			return new LicenseStatus( LicenseState::DomainMismatch, $masked, $domain, $lastCheck, null );
		}

		if ( $this->values['rejected'] ) {
			return new LicenseStatus( LicenseState::Invalid, $masked, $domain, $lastCheck, null );
		}

		$graceStarted = self::parse( $this->values['grace_started'] );
		if ( null !== $graceStarted ) {
			$deadline = $graceStarted->add( new \DateInterval( 'P' . self::GRACE_DAYS . 'D' ) );
			return $nowUtc < $deadline
				? new LicenseStatus( LicenseState::Grace, $masked, $domain, $lastCheck, $deadline )
				: new LicenseStatus( LicenseState::Invalid, $masked, $domain, $lastCheck, null );
		}

		return new LicenseStatus( LicenseState::Active, $masked, $domain, $lastCheck, null );
	}

	/**
	 * Last 4 characters behind a fixed run of asterisks.
	 *
	 * The run is fixed rather than proportional so the masked form does not leak the key's length,
	 * and a key of 4 characters or fewer is masked ENTIRELY - "the last four" of a four-character
	 * key is the whole key, and this method's promise is that its output can never license anything.
	 */
	private static function mask( string $key ): string {
		return str_repeat( '*', 8 ) . ( strlen( $key ) > 4 ? substr( $key, -4 ) : '' );
	}

	private static function format( \DateTimeImmutable $instant ): string {
		return $instant->setTimezone( new \DateTimeZone( 'UTC' ) )->format( self::STORED_FORMAT );
	}

	/**
	 * The leading `!` resets every field the format does not name, so a stored instant carries no
	 * microseconds off the wall clock - two timestamps a fortnight apart must compare on the
	 * calendar, not on whatever fraction of a second the parse happened at.
	 */
	private static function parse( string $stored ): ?\DateTimeImmutable {
		if ( '' === $stored ) {
			return null;
		}
		$parsed = \DateTimeImmutable::createFromFormat( '!' . self::STORED_FORMAT, $stored, new \DateTimeZone( 'UTC' ) );
		return false === $parsed ? null : $parsed;
	}

	/**
	 * A stored timestamp, or '' for anything that is not exactly one.
	 *
	 * Both halves are doing real work. The regex rejects anything of the wrong SHAPE, and the
	 * round-trip rejects anything of the right shape that is not a real instant: `createFromFormat()`
	 * does not fail on `2026-13-45 99:99:99`, it rolls the overflow forward into a real (and wrong)
	 * date, and a grace deadline invented out of a corrupt row is worse than no deadline at all.
	 */
	private static function timestampOrEmpty( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
			return '';
		}
		$parsed = \DateTimeImmutable::createFromFormat( '!' . self::STORED_FORMAT, $value, new \DateTimeZone( 'UTC' ) );
		return false !== $parsed && $parsed->format( self::STORED_FORMAT ) === $value ? $value : '';
	}
}

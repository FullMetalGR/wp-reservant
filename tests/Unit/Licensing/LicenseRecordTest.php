<?php
declare( strict_types=1 );

namespace Reservant\Tests\Unit\Licensing;

use PHPUnit\Framework\TestCase;
use Reservant\Licensing\LicenseRecord;
use Reservant\Licensing\LicenseState;

/**
 * The state machine's arithmetic, with neither WordPress nor a database anywhere near it.
 *
 * `LicenseRecord::statusAt()` takes the clock AND the site as arguments and stores no state of its
 * own, which is what makes a fourteen-day window testable in microseconds rather than by waiting
 * a fortnight - and what makes grace expire on a site whose scheduler has not run in a month.
 * `fromStored()` is the same idea applied to the corrupt-row leniency: the coercion is pure, so the
 * cases below need no option table to prove that a junk row degrades instead of throwing.
 */
final class LicenseRecordTest extends TestCase {

	private const HOME = 'example.com';

	private function utc( string $iso ): \DateTimeImmutable {
		return new \DateTimeImmutable( $iso, new \DateTimeZone( 'UTC' ) );
	}

	private function activeRecord(): LicenseRecord {
		return LicenseRecord::activated( 'RSVT-AAAA-BBBB-WXYZ', self::HOME, $this->utc( '2026-01-01 09:00:00' ) );
	}

	public function test_no_key_at_all_is_inactive_not_invalid(): void {
		$status = LicenseRecord::none()->statusAt( $this->utc( '2026-01-01 00:00:00' ), self::HOME );

		self::assertSame( LicenseState::Inactive, $status->state );
		self::assertFalse( $status->isActive() );
		self::assertSame( '', $status->maskedKey );
		self::assertSame( '', $status->domain );
		self::assertNull( $status->lastCheckedAt );
		self::assertNull( $status->graceEndsAt );
	}

	public function test_a_freshly_activated_key_is_active_and_remembers_when_it_was_checked(): void {
		$status = $this->activeRecord()->statusAt( $this->utc( '2026-01-01 09:00:01' ), self::HOME );

		self::assertSame( LicenseState::Active, $status->state );
		self::assertTrue( $status->isActive() );
		self::assertSame( self::HOME, $status->domain );
		self::assertSame( '2026-01-01 09:00:00', $status->lastCheckedAt?->format( 'Y-m-d H:i:s' ) );
		self::assertNull( $status->graceEndsAt, 'nothing is failing, so there is no deadline to show' );
	}

	public function test_the_key_never_leaves_this_object_whole(): void {
		$status = $this->activeRecord()->statusAt( $this->utc( '2026-01-01 09:00:01' ), self::HOME );

		self::assertSame( '********WXYZ', $status->maskedKey );
		self::assertStringNotContainsString( 'RSVT', $status->maskedKey );
	}

	/** "The last four" of a four-character key is the whole key, so a short key is masked entirely. */
	public function test_a_key_too_short_to_mask_partially_is_masked_completely(): void {
		$status = LicenseRecord::activated( 'ABCD', self::HOME, $this->utc( '2026-01-01 09:00:00' ) )
			->statusAt( $this->utc( '2026-01-01 09:00:00' ), self::HOME );

		self::assertSame( '********', $status->maskedKey );
	}

	public function test_a_key_refused_at_activation_is_invalid_immediately(): void {
		$status = LicenseRecord::rejected( 'NOPE-NOPE-NOPE-NOPE', self::HOME )
			->statusAt( $this->utc( '2026-01-01 09:00:00' ), self::HOME );

		self::assertSame( LicenseState::Invalid, $status->state );
		self::assertFalse( $status->isActive() );
		self::assertNull( $status->lastCheckedAt, 'it was never once good' );
	}

	public function test_a_failing_recheck_does_not_unlicense_a_site_that_was_paying(): void {
		$failedAt = $this->utc( '2026-02-01 03:00:00' );
		$status   = $this->activeRecord()->withFailedCheck( $failedAt )->statusAt( $failedAt, self::HOME );

		self::assertSame( LicenseState::Grace, $status->state );
		self::assertTrue( $status->isActive(), 'a DNS blip at the validator is not a licensing decision' );
		self::assertSame( '2026-02-15 03:00:00', $status->graceEndsAt?->format( 'Y-m-d H:i:s' ) );
		self::assertSame( '2026-01-01 09:00:00', $status->lastCheckedAt?->format( 'Y-m-d H:i:s' ), 'a failure is not a check' );
	}

	public function test_grace_holds_to_the_last_moment_of_the_window_and_not_past_it(): void {
		$record = $this->activeRecord()->withFailedCheck( $this->utc( '2026-02-01 03:00:00' ) );

		self::assertSame( LicenseState::Grace, $record->statusAt( $this->utc( '2026-02-15 02:59:59' ), self::HOME )->state );
		self::assertSame(
			LicenseState::Invalid,
			$record->statusAt( $this->utc( '2026-02-15 03:00:00' ), self::HOME )->state,
			'the deadline instant has already run out'
		);
	}

	public function test_an_expired_grace_window_stops_counting_as_active(): void {
		$status = $this->activeRecord()
			->withFailedCheck( $this->utc( '2026-02-01 03:00:00' ) )
			->statusAt( $this->utc( '2026-03-20 00:00:00' ), self::HOME );

		self::assertSame( LicenseState::Invalid, $status->state );
		self::assertFalse( $status->isActive() );
		self::assertNull( $status->graceEndsAt, 'a deadline that has passed is not a deadline to show' );
	}

	/**
	 * The deadline is measured from the FIRST failure. A daily job that pushed it forward on every
	 * run would grant an unreachable validator an unlimited license extension.
	 */
	public function test_repeated_failures_do_not_push_the_deadline_forward(): void {
		$record = $this->activeRecord()
			->withFailedCheck( $this->utc( '2026-02-01 03:00:00' ) )
			->withFailedCheck( $this->utc( '2026-02-02 03:00:00' ) )
			->withFailedCheck( $this->utc( '2026-02-10 03:00:00' ) );

		self::assertSame(
			'2026-02-15 03:00:00',
			$record->statusAt( $this->utc( '2026-02-10 03:00:01' ), self::HOME )->graceEndsAt?->format( 'Y-m-d H:i:s' )
		);
	}

	public function test_a_later_success_clears_the_grace_clock_entirely(): void {
		$record = $this->activeRecord()
			->withFailedCheck( $this->utc( '2026-02-01 03:00:00' ) )
			->withSuccessfulCheck( $this->utc( '2026-02-03 03:00:00' ) );

		$status = $record->statusAt( $this->utc( '2026-02-03 03:00:00' ), self::HOME );
		self::assertSame( LicenseState::Active, $status->state );
		self::assertNull( $status->graceEndsAt );
		self::assertSame( '2026-02-03 03:00:00', $status->lastCheckedAt?->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * A rejected key never earned a grace window. Starting one here would silently promote an
	 * Invalid license to `isActive()` for a fortnight.
	 */
	public function test_a_rejected_key_is_not_handed_a_grace_window_by_a_failing_recheck(): void {
		$record = LicenseRecord::rejected( 'NOPE-NOPE-NOPE-NOPE', self::HOME )
			->withFailedCheck( $this->utc( '2026-02-01 03:00:00' ) );

		$status = $record->statusAt( $this->utc( '2026-02-02 03:00:00' ), self::HOME );
		self::assertSame( LicenseState::Invalid, $status->state );
		self::assertFalse( $status->isActive() );
	}

	/** A key that has become good again recovers on its own, without the owner re-pasting it. */
	public function test_a_rejection_is_cleared_by_a_later_success(): void {
		$record = LicenseRecord::rejected( 'LATE-PAID-KEY0-9999', self::HOME )
			->withSuccessfulCheck( $this->utc( '2026-02-03 03:00:00' ) );

		self::assertSame( LicenseState::Active, $record->statusAt( $this->utc( '2026-02-03 03:00:00' ), self::HOME )->state );
	}

	/**
	 * The whole point of binding to a domain: one production license must not silently cover every
	 * staging clone that copied the option row along with the database.
	 */
	public function test_a_clone_of_the_row_does_not_license_the_clone(): void {
		$status = $this->activeRecord()->statusAt( $this->utc( '2026-01-02 00:00:00' ), 'staging.example.com' );

		self::assertSame( LicenseState::DomainMismatch, $status->state );
		self::assertFalse( $status->isActive() );
		self::assertSame( self::HOME, $status->domain, 'the status names the domain it is bound to, not this one' );
	}

	/**
	 * The domain is checked before any verdict the validator ever gave, because on a different site
	 * that verdict was about a different installation. A clone is told "this belongs elsewhere",
	 * which is both truer and more actionable than repeating production's own grace state at it.
	 */
	public function test_the_domain_is_answered_before_the_check_history(): void {
		$record = $this->activeRecord()->withFailedCheck( $this->utc( '2026-02-01 03:00:00' ) );

		self::assertSame( LicenseState::DomainMismatch, $record->statusAt( $this->utc( '2026-02-02 00:00:00' ), 'clone.example.com' )->state );
		self::assertSame( LicenseState::DomainMismatch, LicenseRecord::rejected( 'X-KEY-0000-1111', self::HOME )->statusAt( $this->utc( '2026-02-02 00:00:00' ), 'clone.example.com' )->state );
	}

	public function test_a_scalar_where_the_row_should_be_reads_as_no_license_at_all(): void {
		self::assertSame(
			LicenseState::Inactive,
			LicenseRecord::fromStored( 'not-an-array' )->statusAt( $this->utc( '2026-01-01 00:00:00' ), self::HOME )->state
		);
		self::assertSame(
			LicenseState::Inactive,
			LicenseRecord::fromStored( false )->statusAt( $this->utc( '2026-01-01 00:00:00' ), self::HOME )->state
		);
	}

	/**
	 * A corrupt field must degrade on its own, exactly as `Settings::coerce()` does - one bad value
	 * must not discard the good ones beside it, and the key surviving is what lets the owner see
	 * which key is installed rather than a blank screen.
	 */
	public function test_a_junk_timestamp_degrades_without_taking_its_neighbours_with_it(): void {
		$status = LicenseRecord::fromStored(
			array(
				'key'           => 'RSVT-AAAA-BBBB-WXYZ',
				'domain'        => self::HOME,
				'last_check'    => 'yesterday',
				'grace_started' => '',
				'rejected'      => false,
			)
		)->statusAt( $this->utc( '2026-01-01 00:00:00' ), self::HOME );

		self::assertSame( LicenseState::Active, $status->state );
		self::assertSame( '********WXYZ', $status->maskedKey );
		self::assertNull( $status->lastCheckedAt, 'unparseable becomes "never checked", never a guess' );
	}

	/**
	 * `createFromFormat()` does not fail on an impossible date, it rolls the overflow forward into a
	 * real one - and a grace deadline invented out of a corrupt row is worse than no deadline.
	 */
	public function test_an_impossible_date_is_rejected_rather_than_rolled_forward(): void {
		$status = LicenseRecord::fromStored(
			array(
				'key'           => 'RSVT-AAAA-BBBB-WXYZ',
				'domain'        => self::HOME,
				'last_check'    => '2026-01-01 09:00:00',
				'grace_started' => '2026-13-45 99:99:99',
				'rejected'      => false,
			)
		)->statusAt( $this->utc( '2026-06-01 00:00:00' ), self::HOME );

		self::assertSame( LicenseState::Active, $status->state, 'a grace clock nobody can read is no grace clock' );
		self::assertNull( $status->graceEndsAt );
	}

	/** A truthy junk value must not read as a rejection any more than it reads as a consent to purge. */
	public function test_a_non_boolean_rejection_flag_is_not_a_rejection(): void {
		$status = LicenseRecord::fromStored(
			array(
				'key'      => 'RSVT-AAAA-BBBB-WXYZ',
				'domain'   => self::HOME,
				'rejected' => 'yes',
			)
		)->statusAt( $this->utc( '2026-01-01 00:00:00' ), self::HOME );

		self::assertSame( LicenseState::Active, $status->state );
	}

	/**
	 * The one degradation that matters most: whatever else is wrong with the row, an owner who has
	 * paid must be able to get back to Active, and re-activating is itself a read of this row.
	 */
	public function test_a_row_with_no_readable_key_reads_as_inactive_so_the_owner_can_start_over(): void {
		$status = LicenseRecord::fromStored(
			array(
				'key'      => array( 'not', 'a', 'key' ),
				'domain'   => 42,
				'rejected' => true,
			)
		)->statusAt( $this->utc( '2026-01-01 00:00:00' ), self::HOME );

		self::assertSame( LicenseState::Inactive, $status->state );
	}

	public function test_the_grace_window_is_the_documented_fourteen_days(): void {
		self::assertSame( 14, LicenseRecord::GRACE_DAYS );
	}
}

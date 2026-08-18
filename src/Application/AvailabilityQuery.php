<?php
declare( strict_types=1 );

namespace Reservant\Application;

use Reservant\Application\Dto\SegmentChoice;
use Reservant\Domain\Availability\MaskBuilder;
use Reservant\Domain\Availability\RuleExpander;
use Reservant\Domain\Availability\SlotGenerator;
use Reservant\Domain\Chain\ChainResolver;
use Reservant\Domain\Chain\ChainSegment;
use Reservant\Domain\Enum\ServiceType;
use Reservant\Infrastructure\Db\AvailabilityRepository;
use Reservant\Infrastructure\Db\BookingRepository;
use Reservant\Infrastructure\Db\ResourceRepository;
use Reservant\Infrastructure\Db\ServiceRepository;

/**
 * Advisory availability (AGENTS.md section 2.4): translate stored rows into the pure domain engine and
 * hand back the chain starts it finds. A returned slot may be gone 200ms later - HoldBooking is
 * the only authority.
 */
final class AvailabilityQuery {

	private const DEFAULT_GRANULARITY = 5;

	public function __construct(
		private readonly ServiceRepository $services,
		private readonly SegmentEligibility $eligibility,
		private readonly AvailabilityRepository $availability,
		private readonly BookingRepository $bookings,
	) {}

	public static function make( \wpdb $db ): self {
		return new self(
			new ServiceRepository( $db ),
			new SegmentEligibility( new ResourceRepository( $db ) ),
			new AvailabilityRepository( $db ),
			new BookingRepository( $db )
		);
	}

	/**
	 * @param list<SegmentChoice> $segmentChoices ordered chain
	 * @param bool $ignoreWindow Admin relaxation (AGENTS.md Task 10): skips the lead-time/horizon
	 *                           clamps ONLY - `$fromUtc`/`$toUtc` still bound the result. Mirrors
	 *                           `HoldBooking`'s `admin` flag (including allowing already-past starts,
	 *                           the backdating ruling), so the admin availability endpoint offers
	 *                           exactly what an admin hold accepts.
	 * @return list<\DateTimeImmutable> feasible chain start times, UTC
	 */
	public function appointmentStarts(
		array $segmentChoices,
		\DateTimeImmutable $fromUtc,
		\DateTimeImmutable $toUtc,
		\DateTimeImmutable $nowUtc,
		bool $sameStaff = false,
		bool $ignoreWindow = false
	): array {
		if ( array() === $segmentChoices ) {
			throw new \InvalidArgumentException( 'A chain needs at least one segment.' );
		}
		$granularity = max( 1, (int) apply_filters( 'reservant/granularity_min', self::DEFAULT_GRANULARITY ) );

		$segments    = array();
		$resourceIds = array();
		$leadTimeMin = 0;
		$horizonDays = null;

		foreach ( $segmentChoices as $index => $choice ) {
			$service = $this->services->find( $choice->serviceId );
			// Status is checked here for the same reason `HoldBooking::planAppointment()` checks it:
			// an archived service is not on sale, and offering starts for one advertises a chain
			// every hold on it would refuse as `not_found`.
			if ( null === $service || ServiceType::Appointment->value !== $service['type'] || 'active' !== $service['status'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new SlotConflict( 'not_found', $index );
			}
			$eligible = $this->eligibility->forSegment( $choice->serviceId, $choice->resourceId, $index );

			$segments[]  = new ChainSegment(
				(int) $service['id'],
				(int) $service['duration_min'],
				(int) $service['processing_time_min'],
				(int) $service['buffer_before_min'],
				(int) $service['buffer_after_min'],
				$eligible,
				$choice->resourceId
			);
			$resourceIds = array_merge( $resourceIds, null === $choice->resourceId ? $eligible : array( $choice->resourceId ) );

			// The whole chain must satisfy every segment's booking window.
			$leadTimeMin = max( $leadTimeMin, (int) $service['lead_time_min'] );
			$horizonDays = null === $horizonDays ? (int) $service['horizon_days'] : min( $horizonDays, (int) $service['horizon_days'] );
		}
		$resourceIds = array_values( array_unique( $resourceIds ) );

		$generator = new SlotGenerator(
			new RuleExpander( wp_timezone() ),
			new MaskBuilder( $granularity ),
			new ChainResolver( $granularity )
		);

		$starts = $generator->starts(
			$segments,
			$fromUtc,
			$toUtc,
			$nowUtc,
			$leadTimeMin,
			(int) $horizonDays,
			$this->availability->rulesForResources( $resourceIds ),
			$this->availability->exceptionsForResources( $resourceIds ),
			$this->busyFor( $resourceIds, $fromUtc, $toUtc ),
			$sameStaff,
			$ignoreWindow
		);

		/**
		 * Last word on what the customer is offered (AGENTS.md section 7). Applied on the ONE path
		 * both the public and admin endpoints reach, so `$ignoreWindow` cannot fork the two apart.
		 *
		 * @param list<\DateTimeImmutable> $starts
		 */
		return array_values(
			array_filter(
				(array) apply_filters( 'reservant/availability/slots', $starts, $segmentChoices, $fromUtc, $toUtc ),
				static fn ( mixed $s ): bool => $s instanceof \DateTimeImmutable
			)
		);
	}

	/**
	 * Widened by a day either side so a block range that straddles UTC midnight is still seen.
	 *
	 * @param list<int> $resourceIds
	 * @return array<int, list<array{\DateTimeImmutable,\DateTimeImmutable}>>
	 */
	private function busyFor( array $resourceIds, \DateTimeImmutable $fromUtc, \DateTimeImmutable $toUtc ): array {
		$utc  = new \DateTimeZone( 'UTC' );
		$rows = $this->bookings->blockingIntervalsForResources(
			$resourceIds,
			$fromUtc->modify( '-1 day' )->format( 'Y-m-d H:i:s' ),
			$toUtc->modify( '+1 day' )->format( 'Y-m-d H:i:s' )
		);

		$busy = array();
		foreach ( $rows as $resourceId => $intervals ) {
			$busy[ $resourceId ] = array_map(
				static fn ( array $interval ): array => array(
					new \DateTimeImmutable( $interval[0], $utc ),
					new \DateTimeImmutable( $interval[1], $utc ),
				),
				$intervals
			);
		}
		return $busy;
	}
}

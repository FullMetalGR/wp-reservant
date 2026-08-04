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
		private readonly ResourceRepository $resources,
		private readonly AvailabilityRepository $availability,
		private readonly BookingRepository $bookings,
	) {}

	public static function make( \wpdb $db ): self {
		return new self(
			new ServiceRepository( $db ),
			new ResourceRepository( $db ),
			new AvailabilityRepository( $db ),
			new BookingRepository( $db )
		);
	}

	/**
	 * @param list<SegmentChoice> $segmentChoices ordered chain
	 * @return list<\DateTimeImmutable> feasible chain start times, UTC
	 */
	public function appointmentStarts(
		array $segmentChoices,
		\DateTimeImmutable $fromUtc,
		\DateTimeImmutable $toUtc,
		\DateTimeImmutable $nowUtc,
		bool $sameStaff = false
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
			if ( null === $service || ServiceType::Appointment->value !== $service['type'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new SlotConflict( 'not_found', $index );
			}
			$eligible = $this->resources->idsForService( $choice->serviceId );
			if ( array() === $eligible || ( null !== $choice->resourceId && ! in_array( $choice->resourceId, $eligible, true ) ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new SlotConflict( 'no_staff', $index );
			}

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

		return $generator->starts(
			$segments,
			$fromUtc,
			$toUtc,
			$nowUtc,
			$leadTimeMin,
			(int) $horizonDays,
			$this->availability->rulesForResources( $resourceIds ),
			$this->availability->exceptionsForResources( $resourceIds ),
			$this->busyFor( $resourceIds, $fromUtc, $toUtc ),
			$sameStaff
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

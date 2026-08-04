<?php
declare( strict_types=1 );

namespace Reservant\Domain\Availability;

use Reservant\Domain\Chain\ChainResolver;
use Reservant\Domain\Chain\ChainSegment;

/**
 * Walks UTC days across a window, builds a 2-day mask pair per resource (hours and bookings kept
 * apart - see ResourceMasks), and asks ChainResolver for feasible chain starts. Pure: "now" is
 * always injected.
 */
final class SlotGenerator {

	public function __construct(
		private readonly RuleExpander $ruleExpander,
		private readonly MaskBuilder $maskBuilder,
		private readonly ChainResolver $chainResolver,
	) {}

	/**
	 * @param list<ChainSegment>                                                     $segments
	 * @param array<int, list<AvailabilityRule>>                                     $rulesByResource
	 * @param array<int, list<AvailabilityException>>                                $exceptionsByResource
	 * @param array<int, list<array{\DateTimeImmutable,\DateTimeImmutable}>>         $busyByResource
	 * @return list<\DateTimeImmutable>
	 */
	public function starts(
		array $segments,
		\DateTimeImmutable $fromUtc,
		\DateTimeImmutable $toUtc,
		\DateTimeImmutable $nowUtc,
		int $leadTimeMin,
		int $horizonDays,
		array $rulesByResource,
		array $exceptionsByResource,
		array $busyByResource,
		bool $sameStaff = false,
	): array {
		$granularity = $this->maskBuilder->granularityMin;
		$segments    = array_map( static fn ( ChainSegment $s ): ChainSegment => self::aligned( $s, $granularity ), $segments );

		$from = max( $fromUtc, $nowUtc->add( new \DateInterval( 'PT' . max( 0, $leadTimeMin ) . 'M' ) ) );
		$to   = min( $toUtc, $nowUtc->add( new \DateInterval( 'P' . max( 0, $horizonDays ) . 'D' ) ) );
		if ( $from >= $to ) {
			return array();
		}

		$slotsPerDay = intdiv( 1440, $granularity );
		$openCache   = array(); // "resourceId|Y-m-d" => open intervals.
		$results     = array();

		for ( $day = $from->setTime( 0, 0 ); $day < $to; $day = $day->modify( '+1 day' ) ) {
			$masksBySegment = array();
			foreach ( $segments as $i => $segment ) {
				foreach ( $segment->candidateResourceIds() as $resourceId ) {
					// Two days of open hours so a chain (or a segment) may run past midnight, and
					// the resource's WHOLE busy list - the caller fetches it widened by a day
					// either side, so a block range that started yesterday still darkens this
					// window. MaskBuilder clips both to the window.
					$masksBySegment[ $i ][ $resourceId ] = $this->maskBuilder->masks(
						$day,
						2,
						array_merge(
							$this->openFor( $openCache, $resourceId, $day, $rulesByResource, $exceptionsByResource ),
							$this->openFor( $openCache, $resourceId, $day->modify( '+1 day' ), $rulesByResource, $exceptionsByResource )
						),
						$busyByResource[ $resourceId ] ?? array()
					);
				}
			}

			$feasible = $this->chainResolver->feasibleStarts( $segments, $masksBySegment, $sameStaff );
			foreach ( $feasible->setSlots() as $slot ) {
				if ( $slot >= $slotsPerDay ) {
					break; // Next-day starts belong to the next iteration.
				}
				$start = $day->add( new \DateInterval( 'PT' . ( $slot * $granularity ) . 'M' ) );
				if ( $start >= $from && $start < $to ) {
					$results[] = $start;
				}
			}
		}
		return $results;
	}

	/**
	 * @param array<string, list<array{\DateTimeImmutable,\DateTimeImmutable}>> $cache by-ref memo
	 * @param array<int, list<AvailabilityRule>> $rulesByResource
	 * @param array<int, list<AvailabilityException>> $exceptionsByResource
	 * @return list<array{\DateTimeImmutable,\DateTimeImmutable}>
	 */
	private function openFor( array &$cache, int $resourceId, \DateTimeImmutable $day, array $rulesByResource, array $exceptionsByResource ): array {
		$key = $resourceId . '|' . $day->format( 'Y-m-d' );
		if ( ! isset( $cache[ $key ] ) ) {
			$cache[ $key ] = $this->ruleExpander->openIntervalsForUtcDay(
				$day,
				$rulesByResource[ $resourceId ] ?? array(),
				$exceptionsByResource[ $resourceId ] ?? array()
			);
		}
		return $cache[ $key ];
	}

	/** Round duration/processing/buffers UP to the granularity (AGENTS.md section 10). */
	private static function aligned( ChainSegment $s, int $granularity ): ChainSegment {
		$up = static fn ( int $m ): int => (int) ( ceil( $m / $granularity ) * $granularity );
		return new ChainSegment(
			$s->serviceId,
			$up( $s->durationMin ),
			$up( $s->processingMin ),
			$up( $s->bufferBeforeMin ),
			$up( $s->bufferAfterMin ),
			$s->eligibleResourceIds,
			$s->requestedResourceId
		);
	}
}

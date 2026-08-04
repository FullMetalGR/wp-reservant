<?php
declare( strict_types=1 );

namespace Reservant\Domain\Availability;

/**
 * Expands weekly rules + date exceptions (business timezone) into UTC intervals
 * clipped to one UTC day. Handles DST and local days spilling across UTC midnight.
 */
final class RuleExpander {

	public function __construct( private readonly \DateTimeZone $businessTz ) {}

	/**
	 * @param list<AvailabilityRule>      $rules
	 * @param list<AvailabilityException> $exceptions
	 * @return list<array{\DateTimeImmutable,\DateTimeImmutable}>
	 */
	public function openIntervalsForUtcDay( \DateTimeImmutable $dayStartUtc, array $rules, array $exceptions ): array {
		$utc       = new \DateTimeZone( 'UTC' );
		$dayEndUtc = $dayStartUtc->modify( '+1 day' );

		// The local dates that can overlap this UTC day.
		$localDates = array_values(
			array_unique(
				array(
					$dayStartUtc->setTimezone( $this->businessTz )->format( 'Y-m-d' ),
					$dayEndUtc->modify( '-1 second' )->setTimezone( $this->businessTz )->format( 'Y-m-d' ),
				)
			)
		);

		$intervals = array();
		foreach ( $localDates as $localDate ) {
			foreach ( $this->windowsForLocalDate( $localDate, $rules, $exceptions ) as $window ) {
				$start = $window[0]->setTimezone( $utc );
				$end   = $window[1]->setTimezone( $utc );
				$s     = max( $start, $dayStartUtc );
				$e     = min( $end, $dayEndUtc );
				if ( $s < $e ) {
					$intervals[] = array( $s, $e );
				}
			}
		}
		usort( $intervals, static fn ( array $a, array $b ): int => $a[0] <=> $b[0] );
		return $intervals;
	}

	/**
	 * @param list<AvailabilityRule>      $rules
	 * @param list<AvailabilityException> $exceptions
	 * @return list<array{\DateTimeImmutable,\DateTimeImmutable}> local-tz windows
	 */
	private function windowsForLocalDate( string $localDate, array $rules, array $exceptions ): array {
		foreach ( $exceptions as $exception ) {
			if ( $exception->localDate !== $localDate ) {
				continue;
			}
			if ( $exception->closed ) {
				return array();
			}
			return array(
				array(
					$this->localAt( $localDate, (string) $exception->startTime ),
					$this->localAt( $localDate, (string) $exception->endTime ),
				),
			);
		}

		$weekday = (int) ( new \DateTimeImmutable( $localDate, $this->businessTz ) )->format( 'N' );
		$windows = array();
		foreach ( $rules as $rule ) {
			if ( $rule->weekday !== $weekday ) {
				continue;
			}
			if ( null !== $rule->validFrom && $localDate < $rule->validFrom ) {
				continue;
			}
			if ( null !== $rule->validTo && $localDate > $rule->validTo ) {
				continue;
			}
			$windows[] = array(
				$this->localAt( $localDate, $rule->startTime ),
				$this->localAt( $localDate, $rule->endTime ),
			);
		}
		return $windows;
	}

	private function localAt( string $localDate, string $time ): \DateTimeImmutable {
		return new \DateTimeImmutable( $localDate . ' ' . $time, $this->businessTz );
	}
}

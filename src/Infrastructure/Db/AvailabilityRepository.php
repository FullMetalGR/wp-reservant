<?php
declare( strict_types=1 );

namespace Reservant\Infrastructure\Db;

use Reservant\Domain\Availability\AvailabilityException;
use Reservant\Domain\Availability\AvailabilityRule;

/** Thin \wpdb wrapper over reservant_availability_rules and reservant_availability_exceptions. */
final class AvailabilityRepository {

	public function __construct( private readonly \wpdb $db ) {}

	public function insertRule( int $resourceId, AvailabilityRule $rule ): int {
		$this->db->insert(
			"{$this->db->prefix}reservant_availability_rules",
			array(
				'resource_id' => $resourceId,
				'weekday'     => $rule->weekday,
				'start_time'  => $rule->startTime . ':00',
				'end_time'    => $rule->endTime . ':00',
				'valid_from'  => $rule->validFrom,
				'valid_to'    => $rule->validTo,
			)
		);
		return (int) $this->db->insert_id;
	}

	/** @param ?int $resourceId NULL = business-wide */
	public function insertException( ?int $resourceId, AvailabilityException $exception ): int {
		$this->db->insert(
			"{$this->db->prefix}reservant_availability_exceptions",
			array(
				'resource_id' => $resourceId,
				'date_local'  => $exception->localDate,
				'closed'      => $exception->closed ? 1 : 0,
				'start_time'  => null === $exception->startTime ? null : $exception->startTime . ':00',
				'end_time'    => null === $exception->endTime ? null : $exception->endTime . ':00',
			)
		);
		return (int) $this->db->insert_id;
	}

	/**
	 * @param list<int> $resourceIds
	 * @return array<int, list<AvailabilityRule>>
	 */
	public function rulesForResources( array $resourceIds ): array {
		$resourceIds = array_values( array_unique( $resourceIds ) );
		$result      = array_fill_keys( $resourceIds, array() );
		if ( array() === $resourceIds ) {
			return $result;
		}
		$p    = $this->db->prefix;
		$ids  = implode( ',', array_map( 'intval', $resourceIds ) );
		$rows = $this->db->get_results(
			"SELECT resource_id, weekday, start_time, end_time, valid_from, valid_to FROM {$p}reservant_availability_rules WHERE resource_id IN ({$ids})", // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			$resourceId              = (int) $row['resource_id'];
			$result[ $resourceId ][] = new AvailabilityRule(
				(int) $row['weekday'],
				substr( $row['start_time'], 0, 5 ),
				substr( $row['end_time'], 0, 5 ),
				$row['valid_from'],
				$row['valid_to']
			);
		}
		return $result;
	}

	/**
	 * Business-wide (NULL-resource) exceptions appear under every requested id.
	 *
	 * @param list<int> $resourceIds
	 * @return array<int, list<AvailabilityException>>
	 */
	public function exceptionsForResources( array $resourceIds ): array {
		$resourceIds = array_values( array_unique( $resourceIds ) );
		$result      = array_fill_keys( $resourceIds, array() );
		if ( array() === $resourceIds ) {
			return $result;
		}
		$p    = $this->db->prefix;
		$ids  = implode( ',', array_map( 'intval', $resourceIds ) );
		$rows = $this->db->get_results(
			"SELECT resource_id, date_local, closed, start_time, end_time FROM {$p}reservant_availability_exceptions WHERE resource_id IN ({$ids}) OR resource_id IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			$exception = new AvailabilityException(
				$row['date_local'],
				'1' === (string) $row['closed'],
				null === $row['start_time'] ? null : substr( $row['start_time'], 0, 5 ),
				null === $row['end_time'] ? null : substr( $row['end_time'], 0, 5 )
			);
			if ( null === $row['resource_id'] ) {
				foreach ( $resourceIds as $resourceId ) {
					$result[ $resourceId ][] = $exception;
				}
			} else {
				$result[ (int) $row['resource_id'] ][] = $exception;
			}
		}
		return $result;
	}
}

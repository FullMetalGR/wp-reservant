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
	 * Raw rows, ids included - the shape the admin catalog needs to replace-all-on-save (AGENTS.md
	 * Task 11: old row ids must not survive a resource save, so the caller deletes each of these by
	 * id before inserting the replacement set).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function rulesForResource( int $resourceId ): array {
		$p    = $this->db->prefix;
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT id, resource_id, weekday, start_time, end_time, valid_from, valid_to
				 FROM {$p}reservant_availability_rules
				 WHERE resource_id = %d
				 ORDER BY weekday ASC, start_time ASC", // phpcs:ignore WordPress.DB.PreparedSQL
				$resourceId
			),
			ARRAY_A
		);
		return array_map( array( self::class, 'castRuleRow' ), $rows );
	}

	public function deleteRule( int $id ): void {
		$p = $this->db->prefix;
		$this->db->query( $this->db->prepare( "DELETE FROM {$p}reservant_availability_rules WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Raw rows, ids included, for exactly one scope - unlike `exceptionsForResources()`, which merges
	 * business-wide rows into every requested resource for availability math, this is the management
	 * view: a resource's own exceptions (`$resourceId` given), or the business-wide list on its own
	 * (`null`) - AGENTS.md Task 11.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function exceptionsForResource( ?int $resourceId ): array {
		$p = $this->db->prefix;
		if ( null === $resourceId ) {
			$rows = $this->db->get_results(
				"SELECT id, resource_id, date_local, closed, start_time, end_time FROM {$p}reservant_availability_exceptions WHERE resource_id IS NULL ORDER BY date_local ASC", // phpcs:ignore WordPress.DB.PreparedSQL
				ARRAY_A
			);
		} else {
			$rows = $this->db->get_results(
				$this->db->prepare(
					"SELECT id, resource_id, date_local, closed, start_time, end_time FROM {$p}reservant_availability_exceptions WHERE resource_id = %d ORDER BY date_local ASC", // phpcs:ignore WordPress.DB.PreparedSQL
					$resourceId
				),
				ARRAY_A
			);
		}
		return array_map( array( self::class, 'castExceptionRow' ), $rows );
	}

	public function deleteException( int $id ): void {
		$p = $this->db->prefix;
		$this->db->query( $this->db->prepare( "DELETE FROM {$p}reservant_availability_exceptions WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function castRuleRow( array $row ): array {
		$row['id']          = (int) $row['id'];
		$row['resource_id'] = (int) $row['resource_id'];
		$row['weekday']     = (int) $row['weekday'];
		$row['start_time']  = substr( (string) $row['start_time'], 0, 5 );
		$row['end_time']    = substr( (string) $row['end_time'], 0, 5 );
		return $row;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function castExceptionRow( array $row ): array {
		$row['id']          = (int) $row['id'];
		$row['resource_id'] = null === $row['resource_id'] ? null : (int) $row['resource_id'];
		$row['closed']      = '1' === (string) $row['closed'];
		$row['start_time']  = null === $row['start_time'] ? null : substr( (string) $row['start_time'], 0, 5 );
		$row['end_time']    = null === $row['end_time'] ? null : substr( (string) $row['end_time'], 0, 5 );
		return $row;
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

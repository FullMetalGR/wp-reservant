<?php
declare( strict_types=1 );

namespace Reservant\Infrastructure\Db;

/**
 * Wraps a callable in START TRANSACTION / COMMIT with ROLLBACK on throw.
 * NOTE (tests): starting a transaction implicitly commits any transaction WP's
 * test framework already opened - ReservantTestCase truncates tables to compensate.
 */
final class TransactionRunner {

	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * @template T
	 * @param callable(): T $callback
	 * @return T
	 */
	public function run( callable $callback ): mixed {
		$this->db->query( 'START TRANSACTION' );
		try {
			$result = $callback();
			$this->db->query( 'COMMIT' );
			return $result;
		} catch ( \Throwable $e ) {
			$this->db->query( 'ROLLBACK' );
			throw $e;
		}
	}
}

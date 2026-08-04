<?php
declare( strict_types=1 );

namespace Reservant\Application\Dto;

/** Immutable customer identity carried on a booking container. */
final class Customer {

	public function __construct(
		public readonly string $name,
		public readonly string $email,
		public readonly string $phone = '',
	) {}
}

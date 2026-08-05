<?php
declare( strict_types=1 );

namespace Reservant;

/**
 * Plugin-wide settings, backed by a single option row (`reservant_settings`).
 *
 * A small value object: `make()` reads and validates the stored option (defaults fill in any
 * missing key), `update()` validates a partial change, persists it, and hands back the new state.
 * `checkout_ttl_min` is the live default consumed by HoldBooking's checkout hold; `approval_ttl_hours`
 * and `payment_ttl_hours` are stored defaults for later plans (per-service approval holds already
 * carry their own `approval_hold_hours` column and are not rewired here - see AGENTS.md section 2.3).
 */
final class Settings {

	private const OPTION = 'reservant_settings';

	/** @var array{currency:string,checkout_ttl_min:int,approval_ttl_hours:int,payment_ttl_hours:int,purge_on_uninstall:bool} */
	private const DEFAULTS = array(
		'currency'           => 'EUR',
		'checkout_ttl_min'   => 15,
		'approval_ttl_hours' => 48,
		'payment_ttl_hours'  => 24,
		'purge_on_uninstall' => false,
	);

	/**
	 * @param array{currency:string,checkout_ttl_min:int,approval_ttl_hours:int,payment_ttl_hours:int,purge_on_uninstall:bool} $values
	 */
	private function __construct( private readonly array $values ) {}

	public static function make(): self {
		$stored = get_option( self::OPTION );
		$values = is_array( $stored ) ? array_merge( self::DEFAULTS, $stored ) : self::DEFAULTS;
		return new self( self::validate( $values ) );
	}

	public function currency(): string {
		return $this->values['currency'];
	}

	public function checkoutTtlMin(): int {
		return $this->values['checkout_ttl_min'];
	}

	public function approvalTtlHours(): int {
		return $this->values['approval_ttl_hours'];
	}

	public function paymentTtlHours(): int {
		return $this->values['payment_ttl_hours'];
	}

	public function purgeOnUninstall(): bool {
		return $this->values['purge_on_uninstall'];
	}

	/**
	 * Validates a partial change against the current values, persists the merged result, and
	 * returns the settings reflecting it. Unknown keys in `$partial` are ignored.
	 *
	 * @param array<string, mixed> $partial
	 * @throws \InvalidArgumentException When a value fails validation.
	 */
	public function update( array $partial ): self {
		$merged = self::validate(
			array(
				'currency'           => $partial['currency'] ?? $this->values['currency'],
				'checkout_ttl_min'   => $partial['checkout_ttl_min'] ?? $this->values['checkout_ttl_min'],
				'approval_ttl_hours' => $partial['approval_ttl_hours'] ?? $this->values['approval_ttl_hours'],
				'payment_ttl_hours'  => $partial['payment_ttl_hours'] ?? $this->values['payment_ttl_hours'],
				'purge_on_uninstall' => $partial['purge_on_uninstall'] ?? $this->values['purge_on_uninstall'],
			)
		);
		update_option( self::OPTION, $merged, false );
		return new self( $merged );
	}

	/**
	 * @return array{currency:string,checkout_ttl_min:int,approval_ttl_hours:int,payment_ttl_hours:int,purge_on_uninstall:bool}
	 */
	public function toArray(): array {
		return $this->values;
	}

	/**
	 * @param array<string, mixed> $values
	 * @return array{currency:string,checkout_ttl_min:int,approval_ttl_hours:int,payment_ttl_hours:int,purge_on_uninstall:bool}
	 * @throws \InvalidArgumentException When a value fails validation.
	 */
	private static function validate( array $values ): array {
		$currency = (string) $values['currency'];
		if ( 1 !== preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			throw new \InvalidArgumentException( 'currency must be three uppercase letters' );
		}

		foreach ( array( 'checkout_ttl_min', 'approval_ttl_hours', 'payment_ttl_hours' ) as $key ) {
			if ( ! is_int( $values[ $key ] ) || $values[ $key ] < 1 ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new \InvalidArgumentException( $key . ' must be a positive integer' );
			}
		}

		return array(
			'currency'           => $currency,
			'checkout_ttl_min'   => $values['checkout_ttl_min'],
			'approval_ttl_hours' => $values['approval_ttl_hours'],
			'payment_ttl_hours'  => $values['payment_ttl_hours'],
			'purge_on_uninstall' => (bool) $values['purge_on_uninstall'],
		);
	}
}

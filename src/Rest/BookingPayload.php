<?php
declare( strict_types=1 );

namespace Reservant\Rest;

/**
 * What a caller may see of a booking - the one place that decides it.
 *
 * AGENTS.md states one rule ("contact details require `reservant_manage_bookings`"), and it was
 * implemented twice with opposite defaults. `PresentsBookings` forwarded the whole row and then
 * `unset()` two columns; `CalendarAdminController::group()` copied permitted columns and forwarded
 * nothing else. A blacklist and a whitelist do not disagree about the columns they name - they
 * disagree about every column nobody has thought of yet. Add `customer_mobile` to the schema and it
 * ships to a staff-only caller on one surface and is invisible on the other, and neither the route
 * table nor a test would say so.
 *
 * So this module is a whitelist by construction. Every field that reaches the wire is named in one
 * of the four lists below; a column added to `reservant_bookings` is invisible everywhere until
 * someone adds it here, which is the safe direction to fail. The capability question has one
 * answer here too, so the two surfaces cannot drift on who counts as authorised either.
 *
 * The stripped columns are not merely PII: `id` is the surrogate key and `manage_token_hash` is the
 * stored half of the guest credential (AGENTS.md section 5). Neither has ever reached the wire and
 * neither is named below, which is now the whole mechanism rather than an `unset()` that has to be
 * remembered.
 */
final class BookingPayload {

	/**
	 * Container columns every caller who may see the booking at all receives.
	 *
	 * `requires_approval` is absent deliberately - it is cast to a real boolean rather than
	 * forwarded as the DB's `"0"`/`"1"`, so it is written separately below.
	 */
	private const CONTAINER = array(
		'uuid',
		'status',
		'hold_class',
		'hold_expires_at',
		'customer_name',
		'total_minor',
		'currency',
		'payment_mode',
		'created_at',
		'updated_at',
	);

	/**
	 * The customer's contact details.
	 *
	 * A guest reading their own booking through a signed token always receives these - it is their
	 * own data. An admin caller receives them only with `reservant_manage_bookings`; approving a
	 * booking or viewing a calendar does not qualify.
	 */
	private const CONTACT = array( 'customer_email', 'customer_phone' );

	/** Nullable admin columns: omitted entirely when null, never sent as a null the client must ignore. */
	private const OPTIONAL = array( 'approved_at', 'approved_by', 'rejection_reason', 'wc_order_id' );

	/**
	 * Item columns.
	 *
	 * `booking_id` is absent for the reason the old presenter gave: it is the container's id under
	 * another name, and stripping one while keeping the other is theatre. `service_name` and
	 * `resource_name` are present only on the joined list/detail queries
	 * (`BookingRepository::itemsWithNames()`), so they are emitted when the row carries them and
	 * skipped when it does not - which is exactly what the two client types already declare.
	 */
	private const ITEM = array(
		'id',
		'sort',
		'service_id',
		'service_name',
		'resource_id',
		'resource_name',
		'occurrence_id',
		'start_utc',
		'end_utc',
		'block_start_utc',
		'block_end_utc',
		'processing_ends_utc',
		'seats',
		'seat_claim',
		'price_minor',
	);

	/**
	 * Whether the CURRENT caller may see customer contact details.
	 *
	 * The one capability decision, so the admin booking routes and the calendar cannot answer it
	 * differently. Not consulted on the guest surface: a token holder is reading their own booking,
	 * and `Routes::guard()` has already established that.
	 */
	public static function callerMaySeeContact(): bool {
		return current_user_can( Routes::CAP_MANAGE );
	}

	/**
	 * The full booking payload.
	 *
	 * @param array<string, mixed> $booking        `BookingRepository::findByUuid()` shape.
	 * @param bool                 $includeContact Whether the customer's email and phone belong in it.
	 * @return array<string, mixed>
	 */
	public static function present( array $booking, bool $includeContact ): array {
		$payload = array();
		foreach ( self::CONTAINER as $column ) {
			$payload[ $column ] = $booking[ $column ] ?? null;
		}
		$payload['requires_approval'] = (bool) ( $booking['requires_approval'] ?? false );
		foreach ( self::OPTIONAL as $column ) {
			if ( null !== ( $booking[ $column ] ?? null ) ) {
				$payload[ $column ] = $booking[ $column ];
			}
		}
		$payload += self::contact( $booking, $includeContact );

		/** @var list<array<string, mixed>> $rows */
		$rows  = is_array( $booking['items'] ?? null ) ? $booking['items'] : array();
		$items = array();
		foreach ( $rows as $row ) {
			$item = array();
			foreach ( self::ITEM as $column ) {
				if ( array_key_exists( $column, $row ) ) {
					$item[ $column ] = $row[ $column ];
				}
			}
			$items[] = $item;
		}
		$payload['items'] = $items;

		return $payload;
	}

	/**
	 * Just the contact fields, for a payload with a shape of its own.
	 *
	 * The calendar emits a grouped, much narrower event rather than a booking, so it builds its own
	 * container - but the set of columns that COUNT as contact details, and therefore the set a new
	 * one must be added to, lives here with the rule that gates them.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed> empty when the caller may not see them
	 */
	public static function contact( array $row, bool $includeContact ): array {
		if ( ! $includeContact ) {
			return array();
		}
		$fields = array();
		foreach ( self::CONTACT as $column ) {
			$fields[ $column ] = $row[ $column ] ?? '';
		}
		return $fields;
	}

	/**
	 * Every field name this module can emit, for the test that walks the schema.
	 *
	 * A whitelist is only as good as the proof that it is one, and the proof has to be able to name
	 * what is on the list without restating it.
	 *
	 * @return array{container: list<string>, contact: list<string>, optional: list<string>, item: list<string>}
	 */
	public static function fields(): array {
		return array(
			'container' => self::CONTAINER,
			'contact'   => self::CONTACT,
			'optional'  => self::OPTIONAL,
			'item'      => self::ITEM,
		);
	}
}

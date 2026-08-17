/**
 * Every wire shape the public widget exchanges with `reservant/v1`, typed straight off the
 * controllers that produce them (`src/Rest/`) - field names, nullability and casts mirror the PHP
 * side exactly rather than being guessed from a sample payload.
 *
 * Interfaces referenced only through another type here are deliberately not exported (the
 * `index.tsx` `WidgetConfig` precedent): an export nothing imports is invisible dead code, and the
 * first task that needs one from outside adds the `export` keyword alongside its import.
 */

/**
 * The boot object `Assets::config()` prints as `window.reservantWidget` - exactly these six keys.
 * NOT named `WidgetConfig`: that name belongs to the `data-` attribute shape in
 * `assets/src/public/index.tsx`, a different object read from a different place.
 *
 * `nonce` is ALWAYS the empty string (see the `config()` docblock in `src/Frontend/Assets.php`),
 * and the client must then not send an `X-WP-Nonce` header at all. `checkoutTtlMin` is the site
 * default for the CHECKOUT hold class only - the authoritative TTL for a hold actually created is
 * `hold_expires_at` in the `POST /holds` response, which an approval-requiring service sets from
 * a ~48h class instead.
 */
export interface WidgetBootstrap {
	restRoot: string;
	nonce: string;
	currency: string;
	timezone: string;
	granularityMin: number;
	checkoutTtlMin: number;
}

/** `ResourceRepository::publicSummaryForService()` - id and name, NOTHING else, by SQL projection. */
interface PublicResource {
	id: number;
	name: string;
}

/** One row of the public catalog, `ServicesController::index()`. */
export interface PublicService {
	id: number;
	name: string;
	description: string;
	type: 'appointment' | 'event';
	duration_min: number;
	price_minor: number;
	currency: string;
	requires_approval: boolean;
	resources: PublicResource[];
}

/**
 * One chain segment as the wire wants it - `GET /availability`'s `items` JSON and `POST /holds`'
 * `appointment.segments` decode the identical shape (`AvailabilityController::decodeItems()`,
 * `HoldsController::appointment()`). `resource_id` null or absent means "any staff"; a present but
 * unusable id is a 400, never a silent fallback. Task 12's UI-side `Segment` is camelCase - map
 * it to this at the call boundary.
 */
export interface ChainItem {
	service_id: number;
	resource_id?: number | null;
}

/**
 * `utc` is exactly the string `POST /holds` expects back, so a chosen slot round-trips without
 * reformatting; `local` is ISO 8601 in the requested display timezone (the business's by default).
 */
interface SlotStart {
	utc: string;
	local: string;
}

/** `AvailabilityController::occurrences()` - `remaining` is advisory, like every availability read. */
interface OccurrenceOption {
	id: number;
	start_utc: string;
	end_utc: string;
	remaining: number;
}

interface AppointmentAvailability {
	granularity_min: number;
	starts: SlotStart[];
}

interface EventAvailability {
	occurrences: OccurrenceOption[];
}

/**
 * `GET /availability` branches on the first item's service type: an appointment chain answers
 * feasible starts, an event service answers its occurrences. Discriminate with `'starts' in data`.
 */
export type AvailabilityResponse = AppointmentAvailability | EventAvailability;

interface CustomerInput {
	name: string;
	email: string;
	phone?: string;
}

interface AppointmentHoldInput {
	/** `Y-m-d H:i:s`, UTC - the `utc` half of a chosen slot, unchanged. */
	start_utc: string;
	segments: ChainItem[];
	same_staff?: boolean;
}

interface EventHoldInput {
	occurrence_id: number;
	/** Open seating only - a grid pick states its own count through `seat_ids`. */
	seats?: number;
	seat_ids?: number[];
}

/** `POST /holds` body - exactly one of `appointment` or `event` (`HoldsController::parse()`). */
export interface HoldInput {
	customer: CustomerInput;
	appointment?: AppointmentHoldInput;
	event?: EventHoldInput;
}

/**
 * Exactly one of the two, enforced server-side with a 400 (`BookingsController::reschedule()`):
 * an appointment chain moves to a new `start_utc` (`Y-m-d H:i:s`, UTC), an open-seating event
 * booking to another `occurrence_id`.
 */
export interface RescheduleTarget {
	start_utc?: string;
	occurrence_id?: number;
}

type BookingStatus =
	| 'pending'
	| 'awaiting_approval'
	| 'awaiting_payment'
	| 'confirmed'
	| 'completed'
	| 'no_show'
	| 'cancelled'
	| 'rejected'
	| 'expired';

/**
 * One booking item off `BookingRepository::findByUuid()` with `booking_id` stripped
 * (`PresentsBookings`). No `service_name`/`resource_name` here - unlike the admin's joined list
 * queries, the public read is a bare `SELECT *`, so names come from the `useServices()` catalog.
 */
interface BookingItem {
	id: number;
	sort: number;
	service_id: number;
	resource_id: number | null;
	occurrence_id: number | null;
	start_utc: string;
	end_utc: string;
	block_start_utc: string;
	block_end_utc: string;
	processing_ends_utc: string | null;
	seats: number;
	seat_claim: number | null;
	price_minor: number;
}

/**
 * The presented booking container (`PresentsBookings::presentBooking()`): the row's surrogate id
 * and both token columns never reach the wire, and the four nullable admin columns
 * (`approved_at`/`approved_by`/`rejection_reason`/`wc_order_id`) are omitted entirely rather than
 * sent as nulls. All datetimes are `Y-m-d H:i:s`, UTC.
 */
export interface Booking {
	uuid: string;
	status: BookingStatus;
	hold_class: 'checkout' | 'approval' | 'payment' | null;
	hold_expires_at: string | null;
	customer_name: string;
	customer_email: string;
	customer_phone: string;
	total_minor: number;
	currency: string;
	payment_mode: 'free' | 'online' | 'onsite';
	requires_approval: boolean;
	approved_at?: string;
	approved_by?: number;
	rejection_reason?: string;
	wc_order_id?: number;
	created_at: string;
	updated_at: string;
	items: BookingItem[];
}

/**
 * The `POST /holds` 201 body: the booking plus `manage_token`, the guest's only credential,
 * shown exactly once (`HoldsController::create()` sends it with `Cache-Control: no-store`).
 * Hold it in memory for the rest of the journey - it cannot be fetched again.
 */
export interface HeldBooking extends Booking {
	manage_token: string;
}

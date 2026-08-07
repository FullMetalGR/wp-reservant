/**
 * Every REST DTO the admin SPA exchanges with `reservant/v1/admin/*`, typed straight off the
 * controllers/repositories that produce them (Tasks 10-12) - field names, nullability and casts
 * mirror the PHP side exactly rather than being guessed from a sample payload.
 */

export type BookingStatus =
	| 'pending'
	| 'awaiting_approval'
	| 'awaiting_payment'
	| 'confirmed'
	| 'completed'
	| 'no_show'
	| 'cancelled'
	| 'rejected'
	| 'expired';

export type HoldClass = 'checkout' | 'approval' | 'payment';

export type PaymentMode = 'free' | 'online' | 'onsite';

export type ActiveStatus = 'active' | 'inactive';

/**
 * `PresentsBookings::presentBooking()` (`src/Rest/PresentsBookings.php`) - one booking item, with
 * `booking_id` stripped (the container's own id under another name) and `service_name`/
 * `resource_name` present only when the row came from a joined query
 * (`BookingRepository::search()`/`findDetailByUuid()`); a mutation response's items come from
 * `findByUuid()` alone and carry neither.
 */
export interface BookingItem {
	id: number;
	sort: number;
	service_id: number;
	service_name?: string | null;
	resource_id: number | null;
	resource_name?: string | null;
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
 * The admin bookings list/detail/mutation-response shape. `customer_email`/`customer_phone` are
 * optional because `BookingsAdminController::presentForCaller()` strips them for a caller who
 * only holds `reservant_approve_bookings`/`reservant_view_own_calendar` (never
 * `reservant_manage_bookings`); `approved_at`/`approved_by`/`rejection_reason`/`wc_order_id` are
 * optional because `presentBooking()` omits a null one entirely rather than sending `null`.
 */
export interface BookingSummary {
	uuid: string;
	status: BookingStatus;
	hold_class: HoldClass | null;
	hold_expires_at: string | null;
	customer_name: string;
	customer_email?: string;
	customer_phone?: string;
	total_minor: number;
	currency: string;
	payment_mode: PaymentMode;
	requires_approval: boolean;
	approved_at?: string;
	approved_by?: number;
	rejection_reason?: string;
	wc_order_id?: number;
	created_at: string;
	updated_at: string;
	items: BookingItem[];
}

/** `AuditLog::forBooking()` (`src/Infrastructure/Db/AuditLog.php`), oldest first. */
export interface AuditEntry {
	id: number;
	actor: string;
	action: string;
	payload: Record< string, unknown >;
	created_at: string;
}

/** `GET /admin/bookings/{uuid}` - the summary shape plus the full audit trail. */
export interface BookingDetail extends BookingSummary {
	audit: AuditEntry[];
}

/** `GET /admin/bookings` response envelope. */
export interface BookingListResponse {
	total: number;
	bookings: BookingSummary[];
}

/** `BookingsAdminController::index()` filter/search parameters (`AdminRoutes::searchArgs()`). */
export interface BookingFilters {
	from?: string;
	to?: string;
	status?: string;
	resource_id?: number;
	service_id?: number;
	search?: string;
	page?: number;
	per_page?: number;
}

/** `CalendarAdminController::group()` - one item per booking, `resource_id` null for an event item. */
export interface CalendarBookingItem {
	service_id: number;
	service_name: string | null;
	resource_id: number | null;
	resource_name: string | null;
	start_utc: string;
	end_utc: string;
	block_start_utc: string;
	block_end_utc: string;
	processing_ends_utc: string | null;
}

/**
 * `CalendarAdminController::group()`. `customer_email`/`customer_phone` are present only for a
 * caller who manages bookings outright - a staff-only viewer sees a bare name (Task 10).
 */
export interface CalendarBooking {
	uuid: string;
	status: BookingStatus;
	customer_name: string;
	customer_email?: string;
	customer_phone?: string;
	items: CalendarBookingItem[];
}

/** `CalendarAdminController::occurrences()`. */
export interface CalendarOccurrence {
	id: number;
	service_id: number;
	service_name: string | null;
	start_utc: string;
	end_utc: string;
	capacity: number;
	remaining: number;
}

/** `GET /admin/calendar` response envelope. */
export interface CalendarResponse {
	bookings: CalendarBooking[];
	occurrences: CalendarOccurrence[];
}

export type ServiceType = 'appointment' | 'event';

export type ApprovalTimeout = 'expire' | 'auto_approve';

/** `ServicesAdminController::present()` / `ServiceRepository` - the full service row. */
export interface Service {
	id: number;
	name: string;
	type: ServiceType;
	duration_min: number;
	processing_time_min: number;
	buffer_before_min: number;
	buffer_after_min: number;
	capacity: number;
	seat_map_id: number | null;
	price_minor: number;
	currency: string;
	payment_mode: PaymentMode;
	requires_approval: boolean;
	approval_hold_hours: number;
	on_approval_timeout: ApprovalTimeout;
	cancel_window_hours: number;
	reschedule_window_hours: number;
	lead_time_min: number;
	horizon_days: number;
	wc_product_id: number | null;
	status: ActiveStatus;
	created_at: string;
	updated_at: string;
}

/** `GET /admin/services` response envelope. */
export interface ServicesResponse {
	services: Service[];
}

/** `AvailabilityRepository::rulesForResource()` cast shape. */
export interface AvailabilityRule {
	id: number;
	resource_id: number;
	weekday: number;
	start_time: string;
	end_time: string;
	valid_from: string | null;
	valid_to: string | null;
}

/** `AvailabilityRepository::exceptionsForResource()` cast shape; `resource_id` null = business-wide. */
export interface AvailabilityException {
	id: number;
	resource_id: number | null;
	date_local: string;
	closed: boolean;
	start_time: string | null;
	end_time: string | null;
}

/**
 * `ResourcesAdminController::present()`/`attachAssociations()` - the resource row plus its links
 * and rules. The wire response also carries `exceptions` (the same association, still attached
 * server-side to every `GET /admin/resources`/`{id}` row) - omitted here because no frontend code
 * reads it off a `Resource` any more: `StaffScreen`'s own exceptions panels load through
 * `useExceptions()` (`GET /admin/exceptions`, Task 16b), a request scoped to exactly the resource
 * (or business-wide) list being shown, kept fresh by its own cache invalidation rather than by
 * re-reading whatever a resource happened to carry at fetch time.
 */
export interface Resource {
	id: number;
	wp_user_id: number | null;
	name: string;
	email: string | null;
	status: ActiveStatus;
	created_at: string;
	service_ids: number[];
	rules: AvailabilityRule[];
}

/** `GET /admin/resources` response envelope. */
export interface ResourcesResponse {
	resources: Resource[];
}

/**
 * `ResourcesAdminController::presentExceptionRow()` - `GET /admin/exceptions`'s listing shape
 * (Task 16b gap-filler). Distinct from `AvailabilityException` (the `POST`/`DELETE` and the wire's
 * `Resource.exceptions` shape: `date_local`/`closed`) - this is the read-side presentation: an
 * all-day closure is `start_time`/`end_time` both null rather than a separate `closed` flag, and
 * `reason` always echoes `''` since the schema carries no such column.
 */
export interface AvailabilityExceptionListItem {
	id: number;
	resource_id: number | null;
	date: string;
	start_time: string | null;
	end_time: string | null;
	reason: string;
}

/** `GET /admin/exceptions` response envelope. */
export interface AvailabilityExceptionsResponse {
	exceptions: AvailabilityExceptionListItem[];
}

export type OccurrenceStatus = 'active' | 'cancelled';

/** `OccurrencesAdminController::present()`. */
export interface Occurrence {
	id: number;
	service_id: number;
	start_utc: string;
	end_utc: string;
	capacity: number;
	booked: number;
	status: OccurrenceStatus;
}

/** `GET /admin/occurrences` response envelope. */
export interface OccurrencesResponse {
	occurrences: Occurrence[];
}

/** A grid cell parsed from a seat map spec (`Domain\Seating\SeatMapSpec`). */
export type SeatKind = 'seat' | 'aisle' | 'blocked';

/** `SeatMapRepository::seatsForMap()`. */
export interface Seat {
	id: number;
	seat_map_id: number;
	row_label: string;
	seat_label: string;
	sort_row: number;
	sort_col: number;
	kind: SeatKind;
}

/** `SeatMapsAdminController::present()`. */
export interface SeatMap {
	id: number;
	name: string;
	spec: string;
	seats: Seat[];
}

/** `GET /admin/seat-maps` response envelope. */
export interface SeatMapsResponse {
	seat_maps: SeatMap[];
}

/** `Settings::toArray()` (`src/Settings.php`) - the single `reservant_settings` option row. */
export interface SettingsPayload {
	currency: string;
	checkout_ttl_min: number;
	approval_ttl_hours: number;
	payment_ttl_hours: number;
	purge_on_uninstall: boolean;
}

/** `BookingsAdminController::customer()` - the manual booking body's customer block. */
export interface ManualBookingCustomer {
	name: string;
	email: string;
	phone?: string;
}

/** `BookingsAdminController::appointment()` - one chain segment. */
export interface ManualBookingSegment {
	service_id: number;
	resource_id?: number;
}

/** `BookingsAdminController::appointment()` - the appointment half of a manual booking body. */
export interface ManualBookingAppointment {
	start_utc: string;
	segments: ManualBookingSegment[];
	same_staff?: boolean;
}

/** `BookingsAdminController::event()` - the event half of a manual booking body. */
export interface ManualBookingEvent {
	occurrence_id: number;
	seats?: number;
	seat_ids?: number[];
}

/**
 * `POST /admin/bookings` body (`BookingsAdminController::parse()`) - exactly one of `appointment`
 * or `event`.
 */
export interface ManualBookingRequest {
	customer: ManualBookingCustomer;
	appointment?: ManualBookingAppointment;
	event?: ManualBookingEvent;
}

/**
 * A WP core user row, `view` context (`GET /wp/v2/users?search=`, WP-core namespace, not
 * `reservant/v1` - `api/client.ts`'s `apiFetch` namespace override) - only the two fields
 * `StaffScreen`'s (Task 16) user-search link needs; `view` context never carries `email` (that
 * needs `edit` context and `list_users`), which this SPA does not need either.
 */
export interface WpUser {
	id: number;
	name: string;
}

/** `AvailabilityAdminController::index()` - one feasible chain start time, in both zones. */
export interface AvailabilityStart {
	utc: string;
	local: string;
}

/**
 * `GET /admin/availability` response envelope for the appointment branch (`AvailabilityAdminController`'s
 * `occurrences()` branch, used for event services, has no client yet - the manual booking drawer only
 * ever chains appointment services).
 */
export interface AvailabilityResponse {
	granularity_min: number;
	starts: AvailabilityStart[];
}

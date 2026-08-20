import {
	useMutation,
	useQuery,
	useQueryClient,
	type UseMutationResult,
	type UseQueryResult,
} from '@tanstack/react-query';
import { addDays, format, parseISO } from 'date-fns';
import { bootConfig } from '../boot';
import { apiFetch } from './client';
import type {
	AvailabilityException,
	AvailabilityExceptionListItem,
	AvailabilityExceptionsResponse,
	AvailabilityResponse,
	BookingDetail,
	BookingFilters,
	BookingListResponse,
	BookingSummary,
	CalendarResponse,
	LicenseStatus,
	ManualBookingRequest,
	ManualBookingSegment,
	Occurrence,
	OccurrencesResponse,
	Resource,
	ResourcesResponse,
	SeatMap,
	SeatMapsResponse,
	Service,
	ServicesResponse,
	SettingsPayload,
	WpUser,
} from './types';

/** A date-only range, `to` exclusive - matches `CalendarAdminController::window()`. */
export interface CalendarRange {
	from: string;
	to: string;
}

/** Builds a `?a=b&c=d` query string, dropping keys that are absent, empty, or zero. */
function toQueryString( params: Record< string, string | number | boolean | undefined > ): string {
	const search = new URLSearchParams();
	for ( const [ key, value ] of Object.entries( params ) ) {
		if ( undefined === value || '' === value || 0 === value ) {
			continue;
		}
		search.set( key, String( value ) );
	}
	const query = search.toString();
	return '' === query ? '' : `?${ query }`;
}

/**
 * `BookingRepository::search()` compares `to` exclusively (`i.start_utc < %s`, midnight of that
 * date) - a filter bar's "to" date picker is naturally inclusive ("show me through this day"), so
 * the query layer advances it one day before it ever reaches the wire. Doing it once here, rather
 * than at every call site, is what keeps that exclusive-boundary quirk from needing to be
 * remembered by whichever screen builds the filters.
 */
function normalizeBookingFilters( filters: BookingFilters ): BookingFilters {
	if ( undefined === filters.to || '' === filters.to ) {
		return filters;
	}
	return { ...filters, to: format( addDays( parseISO( filters.to ), 1 ), 'yyyy-MM-dd' ) };
}

export function useBookings( filters: BookingFilters ): UseQueryResult< BookingListResponse, Error > {
	return useQuery( {
		queryKey: [ 'bookings', filters ],
		queryFn: () =>
			apiFetch< BookingListResponse >( `/admin/bookings${ toQueryString( { ...normalizeBookingFilters( filters ) } ) }` ),
	} );
}

/** `GET /admin/bookings/{uuid}` - the summary shape plus the full audit trail, for `BookingDrawer`. */
export function useBooking( uuid: string ): UseQueryResult< BookingDetail, Error > {
	return useQuery( {
		queryKey: [ 'booking', uuid ],
		queryFn: () => apiFetch< BookingDetail >( `/admin/bookings/${ uuid }` ),
		enabled: '' !== uuid,
	} );
}

export function useCalendar( range: CalendarRange, resourceId?: number ): UseQueryResult< CalendarResponse, Error > {
	return useQuery( {
		queryKey: [ 'calendar', range, resourceId ?? null ],
		queryFn: () =>
			apiFetch< CalendarResponse >(
				`/admin/calendar${ toQueryString( { from: range.from, to: range.to, resource_id: resourceId } ) }`
			),
	} );
}

/**
 * `GET /admin/services` and `GET /admin/resources` BOTH ask for the full catalog, inactive rows
 * included (`include_inactive=1`).
 *
 * They used to send nothing, and `AdminRoutes`' own `include_inactive` arg defaults to `false`, so
 * `ServiceRepository`/`ResourceRepository` appended `WHERE status <> 'inactive'` and the SPA could
 * never see an inactive row at all. That made deactivation a one-way door: both catalog screens
 * offer an "Inactive" status option (and `ServicesScreen` actively steers the admin into it - a 409
 * `referenced` delete answers with "Deactivate it instead"), but the row it produced then vanished
 * from the only table it could ever have been selected and reactivated from. It also silently broke
 * `BookingsScreen`'s staff/service filters, which are built off these same lists: a booking taken by
 * a since-departed staff member could not be filtered for.
 *
 * One query key, one request, complete data - rather than a boolean-parameterised key per screen:
 * these catalogs are small (tens of rows), every consumer already renders a filtered VIEW of them,
 * and a single cached copy means a status change made on one screen is reflected everywhere on the
 * next invalidation instead of only in whichever variant happened to be cached. Consumers that must
 * not offer an inactive row - `CalendarScreen`'s staff picker and `ManualBookingDrawer`'s
 * service/staff selects, which feed NEW bookings - filter on `status` themselves, and those filters
 * are load-bearing precisely because of this.
 */
const FULL_CATALOG = { include_inactive: 1 };

export function useServices(): UseQueryResult< Service[], Error > {
	return useQuery( {
		queryKey: [ 'services' ],
		queryFn: async () => ( await apiFetch< ServicesResponse >( `/admin/services${ toQueryString( FULL_CATALOG ) }` ) ).services,
	} );
}

export function useResources(): UseQueryResult< Resource[], Error > {
	return useQuery( {
		queryKey: [ 'resources' ],
		queryFn: async () => ( await apiFetch< ResourcesResponse >( `/admin/resources${ toQueryString( FULL_CATALOG ) }` ) ).resources,
	} );
}

export function useOccurrences( serviceId: number ): UseQueryResult< Occurrence[], Error > {
	return useQuery( {
		queryKey: [ 'occurrences', serviceId ],
		queryFn: async () =>
			( await apiFetch< OccurrencesResponse >( `/admin/occurrences${ toQueryString( { service_id: serviceId } ) }` ) )
				.occurrences,
		enabled: serviceId > 0,
	} );
}

export function useSeatMaps(): UseQueryResult< SeatMap[], Error > {
	return useQuery( {
		queryKey: [ 'seat-maps' ],
		queryFn: async () => ( await apiFetch< SeatMapsResponse >( '/admin/seat-maps' ) ).seat_maps,
	} );
}

export function useSettings(): UseQueryResult< SettingsPayload, Error > {
	return useQuery( {
		queryKey: [ 'settings' ],
		queryFn: () => apiFetch< SettingsPayload >( '/admin/settings' ),
	} );
}

/**
 * WP-core user search (Task 16, `StaffScreen`'s wp_user link) - `GET /wp/v2/users?search=`, the
 * core namespace rather than `reservant/v1` (`apiFetch`'s namespace override, `api/client.ts`).
 * Disabled for a blank search: an admin site can carry thousands of users, and the endpoint's own
 * default page size (10) would otherwise show an arbitrary, unsearched slice of them.
 */
export function useWpUsers( search: string ): UseQueryResult< WpUser[], Error > {
	const trimmed = search.trim();
	return useQuery( {
		queryKey: [ 'wp-users', trimmed ],
		queryFn: () => apiFetch< WpUser[] >( `/users${ toQueryString( { search: trimmed } ) }`, {}, 'wp/v2' ),
		enabled: '' !== trimmed,
	} );
}

/**
 * A single WP-core user by id (`GET /wp/v2/users/{id}`) - `StaffScreen`'s own display name for a
 * resource's already-linked `wp_user_id`, which `Resource` itself never carries (only the bare id):
 * without this, editing a resource that already names a user would show the search combobox blank
 * rather than who is actually linked. Disabled for `null` (no link to look up).
 */
export function useWpUser( id: number | null ): UseQueryResult< WpUser, Error > {
	return useQuery( {
		queryKey: [ 'wp-user', id ],
		queryFn: () => apiFetch< WpUser >( `/users/${ id ?? 0 }`, {}, 'wp/v2' ),
		enabled: null !== id,
	} );
}

// ---------------------------------------------------------------------------------------------
// Catalog mutations (Task 16) - services, staff (resources + availability rules/exceptions),
// occurrences, seat maps and settings. Each save takes an optional `id`: present -> PUT (a partial
// patch, mirroring every *AdminController::update()`'s own semantics), absent -> POST (create).
// Every one invalidates its own list key; `resources`/`occurrences` also invalidate `calendar`
// (Task 14's grid reads both), since only those two feed it - `services`/`seat-maps`/`settings` do
// not (the brief's own invalidation table).
// ---------------------------------------------------------------------------------------------

export interface ServiceSaveInput extends Partial< Omit< Service, 'id' | 'wc_product_id' | 'created_at' | 'updated_at' > > {
	id?: number;
}

export function useSaveService(): UseMutationResult< Service, Error, ServiceSaveInput > {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn: ( { id, ...patch }: ServiceSaveInput ) =>
			undefined === id
				? apiFetch< Service >( '/admin/services', { method: 'POST', body: JSON.stringify( patch ) } )
				: apiFetch< Service >( `/admin/services/${ id }`, { method: 'PUT', body: JSON.stringify( patch ) } ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'services' ] } );
		},
	} );
}

export function useDeleteService(): UseMutationResult< void, Error, number > {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn: ( id: number ) => apiFetch< void >( `/admin/services/${ id }`, { method: 'DELETE' } ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'services' ] } );
		},
	} );
}

/** A weekly rule as `ResourcesAdminController::sanitizeRules()` wants it - no id/valid_from/valid_to; the admin route never accepts or returns those on a save. */
export interface RuleInput {
	weekday: number;
	start_time: string;
	end_time: string;
}

export interface ResourceSaveInput {
	id?: number;
	name?: string;
	email?: string | null;
	wp_user_id?: number | null;
	status?: Resource[ 'status' ];
	/** Replace-all-per-save, only when present (`ResourcesAdminController` class docblock). */
	service_ids?: number[];
	/** Replace-all-per-save, only when present - same rule as `service_ids`. */
	rules?: RuleInput[];
}

export function useSaveResource(): UseMutationResult< Resource, Error, ResourceSaveInput > {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn: ( { id, ...patch }: ResourceSaveInput ) =>
			undefined === id
				? apiFetch< Resource >( '/admin/resources', { method: 'POST', body: JSON.stringify( patch ) } )
				: apiFetch< Resource >( `/admin/resources/${ id }`, { method: 'PUT', body: JSON.stringify( patch ) } ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'resources' ] } );
			void queryClient.invalidateQueries( { queryKey: [ 'calendar' ] } );
		},
	} );
}

/**
 * `GET /admin/exceptions` (Task 16b gap-filler): `resourceId` omitted lists business-wide rows
 * only, a real id lists that resource's own rows only - never a merge of the two. Query key mirrors
 * `useCalendar`'s own `resourceId ?? sentinel` shape so a business-wide list and a per-resource list
 * cache and invalidate independently.
 */
export function useExceptions( resourceId?: number ): UseQueryResult< AvailabilityExceptionListItem[], Error > {
	return useQuery( {
		queryKey: [ 'exceptions', resourceId ?? 'business' ],
		queryFn: async () =>
			( await apiFetch< AvailabilityExceptionsResponse >( `/admin/exceptions${ toQueryString( { resource_id: resourceId } ) }` ) )
				.exceptions,
	} );
}

/**
 * One availability exception, resource-scoped or business-wide - `resourceId: null` routes to
 * `/admin/exceptions` (`ResourcesAdminController::addBusinessException()`/`removeBusinessException()`),
 * a real id to `/admin/resources/{id}/exceptions`. The same shape serves both add (POST) and remove
 * (DELETE, which matches by shape - date plus whether a window was given - not by row id; see the
 * controller's own class docblock).
 */
export interface ExceptionInput {
	resourceId: number | null;
	date: string;
	start_time?: string;
	end_time?: string;
	/** Accepted for forward compatibility only - the schema carries no such column, so it never round-trips back. */
	reason?: string;
}

function exceptionPath( resourceId: number | null ): string {
	return null === resourceId ? '/admin/exceptions' : `/admin/resources/${ resourceId }/exceptions`;
}

/**
 * Both mutations below invalidate the `useExceptions()` cache entry the change actually affects.
 * `['resources']` is NOT invalidated: a resource row no longer carries an `exceptions` association
 * at all (`ResourcesAdminController::attachAssociations()` attaches only `service_ids`/`rules`, and
 * the `Resource` TS type declares only those), so adding or removing a blackout date cannot make a
 * cached resource stale - refetching the whole catalog on every blackout edit would be pure waste.
 */
function invalidateExceptionCaches( queryClient: ReturnType< typeof useQueryClient >, resourceId: number | null ): void {
	void queryClient.invalidateQueries( { queryKey: [ 'exceptions', resourceId ?? 'business' ] } );
}

export function useAddException(): UseMutationResult< AvailabilityException, Error, ExceptionInput > {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn: ( { resourceId, ...body }: ExceptionInput ) =>
			apiFetch< AvailabilityException >( exceptionPath( resourceId ), { method: 'POST', body: JSON.stringify( body ) } ),
		onSuccess: ( _data, variables ) => invalidateExceptionCaches( queryClient, variables.resourceId ),
	} );
}

export function useRemoveException(): UseMutationResult< { deleted: number }, Error, ExceptionInput > {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn: ( { resourceId, ...body }: ExceptionInput ) =>
			apiFetch< { deleted: number } >( exceptionPath( resourceId ), { method: 'DELETE', body: JSON.stringify( body ) } ),
		onSuccess: ( _data, variables ) => invalidateExceptionCaches( queryClient, variables.resourceId ),
	} );
}

/** `OccurrencesAdminController` - `service_id` only matters for a create (POST); a PUT patch ignores it if sent. */
export interface OccurrenceSaveInput {
	id?: number;
	service_id?: number;
	start_utc?: string;
	end_utc?: string;
	capacity?: number;
}

export function useSaveOccurrence(): UseMutationResult< Occurrence, Error, OccurrenceSaveInput > {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn: ( { id, ...patch }: OccurrenceSaveInput ) =>
			undefined === id
				? apiFetch< Occurrence >( '/admin/occurrences', { method: 'POST', body: JSON.stringify( patch ) } )
				: apiFetch< Occurrence >( `/admin/occurrences/${ id }`, { method: 'PUT', body: JSON.stringify( patch ) } ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'occurrences' ] } );
			void queryClient.invalidateQueries( { queryKey: [ 'calendar' ] } );
		},
	} );
}

/** DELETE /admin/occurrences/{id} - a soft cancel (`OccurrenceRepository::cancel()`), refused with 409 `referenced` while any booking actively holds it. */
export function useCancelOccurrence(): UseMutationResult< void, Error, number > {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn: ( id: number ) => apiFetch< void >( `/admin/occurrences/${ id }`, { method: 'DELETE' } ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'occurrences' ] } );
			void queryClient.invalidateQueries( { queryKey: [ 'calendar' ] } );
		},
	} );
}

export interface SeatMapSaveInput {
	id?: number;
	name: string;
	spec: string;
}

/** POST/PUT /admin/seat-maps - a 400 carries the parser's own message verbatim in `detail`; a PUT may 409 `referenced` once any seat is claimed. */
export function useSaveSeatMap(): UseMutationResult< SeatMap, Error, SeatMapSaveInput > {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn: ( { id, ...patch }: SeatMapSaveInput ) =>
			undefined === id
				? apiFetch< SeatMap >( '/admin/seat-maps', { method: 'POST', body: JSON.stringify( patch ) } )
				: apiFetch< SeatMap >( `/admin/seat-maps/${ id }`, { method: 'PUT', body: JSON.stringify( patch ) } ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'seat-maps' ] } );
		},
	} );
}

/**
 * PUT /admin/settings - a partial patch; the caller must never forward an explicit `null` (T3
 * ledger note - `SettingsAdminController::sanitizeFields()` 400s any key present with a `null`
 * value rather than silently keeping the old one), so `SettingsScreen` only ever includes keys it
 * holds a real value for.
 */
export function useSaveSettings(): UseMutationResult< SettingsPayload, Error, Partial< SettingsPayload > > {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn: ( patch: Partial< SettingsPayload > ) =>
			apiFetch< SettingsPayload >( '/admin/settings', { method: 'PUT', body: JSON.stringify( patch ) } ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'settings' ] } );
		},
	} );
}

/**
 * `GET /admin/license` - the FALLBACK read, not the primary one.
 *
 * The admin bootstrap already carries the license (`boot.ts`'s `license`), so the Settings screen
 * draws itself with no round trip at all; this exists for the case that bootstrap value is `null`,
 * which means "not known right now" rather than "unlicensed" (a `reservant/license_manager` that
 * threw while the page rendered). Hence `enabled` rather than an unconditional fetch: an owner
 * whose bootstrap answered must not pay for a request whose answer they already have.
 *
 * There is no license-gate on this route by design - it is the way back for a lapsed site
 * (`Rest\Admin\LicenseAdminController`) - so it answers whatever the site's state.
 */
export function useLicense( enabled: boolean ): UseQueryResult< LicenseStatus, Error > {
	return useQuery( {
		queryKey: [ 'license' ],
		queryFn: () => apiFetch< LicenseStatus >( '/admin/license' ),
		enabled,
	} );
}

/**
 * `POST /admin/license` - bind a key to this site.
 *
 * **A 200 here is not an activation.** A key the validator refuses comes back 200 with
 * `state: 'invalid'`, and an EMPTY key comes back 200 with whatever was already stored (a
 * documented no-op, so a blank field posted by accident cannot cost a site the license it paid
 * for - `Licensing\LicenseManager::activate()`). The caller must read `active`/`state` off the
 * answer to know what happened; `onSuccess` here means only that the request completed.
 *
 * The answer is written straight into the cache rather than invalidated, which is the one place
 * this diverges from every other mutation on this file. Every `LicenseManager` method returns the
 * resulting status for the express purpose that no caller writes and then reads back - the two
 * halves of that pair are exactly where an implementation gets to disagree with itself - so
 * refetching what we were just told would reintroduce the round trip the contract exists to remove.
 */
export function useActivateLicense(): UseMutationResult< LicenseStatus, Error, string > {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn: ( key: string ) =>
			apiFetch< LicenseStatus >( '/admin/license', { method: 'POST', body: JSON.stringify( { key } ) } ),
		onSuccess: ( status ) => {
			queryClient.setQueryData( [ 'license' ], status );
		},
	} );
}

/**
 * `DELETE /admin/license` - unbind this site so the seat can be used somewhere else.
 *
 * Always 200 and always `inactive` (`LicenseManager::deactivate()`): "stop claiming to be licensed"
 * has no failure mode worth reporting. Same cache write as the activation, for the same reason.
 */
export function useDeactivateLicense(): UseMutationResult< LicenseStatus, Error, void > {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn: () => apiFetch< LicenseStatus >( '/admin/license', { method: 'DELETE' } ),
		onSuccess: ( status ) => {
			queryClient.setQueryData( [ 'license' ], status );
		},
	} );
}

/**
 * Every booking lifecycle mutation (approve/reject/cancel/outcome/manual create) shares the same
 * aftermath: the list, the calendar and (if `BookingDrawer` is open on this same booking) the
 * detail view may now all be showing a stale row, so every cache key is invalidated together
 * rather than each call site repeating the trio.
 */
function useBookingMutation< TVariables >(
	mutationFn: ( variables: TVariables ) => Promise< BookingSummary >
): UseMutationResult< BookingSummary, Error, TVariables > {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn,
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'bookings' ] } );
			void queryClient.invalidateQueries( { queryKey: [ 'booking' ] } );
			void queryClient.invalidateQueries( { queryKey: [ 'calendar' ] } );
		},
	} );
}

export function useApprove(): UseMutationResult< BookingSummary, Error, string > {
	return useBookingMutation( ( uuid: string ) =>
		apiFetch< BookingSummary >( `/admin/bookings/${ uuid }/approve`, { method: 'POST' } )
	);
}

export interface RejectVariables {
	uuid: string;
	reason?: string;
}

export function useReject(): UseMutationResult< BookingSummary, Error, RejectVariables > {
	return useBookingMutation( ( { uuid, reason }: RejectVariables ) =>
		apiFetch< BookingSummary >( `/admin/bookings/${ uuid }/reject`, {
			method: 'POST',
			body: JSON.stringify( { reason: reason ?? '' } ),
		} )
	);
}

export function useCancelBooking(): UseMutationResult< BookingSummary, Error, string > {
	return useBookingMutation( ( uuid: string ) =>
		apiFetch< BookingSummary >( `/admin/bookings/${ uuid }/cancel`, { method: 'POST' } )
	);
}

export interface OutcomeVariables {
	uuid: string;
	outcome: 'completed' | 'no_show';
}

export function useOutcome(): UseMutationResult< BookingSummary, Error, OutcomeVariables > {
	return useBookingMutation( ( { uuid, outcome }: OutcomeVariables ) =>
		apiFetch< BookingSummary >( `/admin/bookings/${ uuid }/${ 'completed' === outcome ? 'complete' : 'no_show' }`, {
			method: 'POST',
		} )
	);
}

export function useManualBooking(): UseMutationResult< BookingSummary, Error, ManualBookingRequest > {
	return useBookingMutation( ( request: ManualBookingRequest ) =>
		apiFetch< BookingSummary >( '/admin/bookings', { method: 'POST', body: JSON.stringify( request ) } )
	);
}

export interface AvailabilityOptions {
	/** The chain-wide "prefer one staff member throughout" preference; defaults to `false`. */
	sameStaff?: boolean;
	/** Set `false` to suspend the query even when `items`/`range` would otherwise be valid. */
	enabled?: boolean;
}

/**
 * `GET /admin/availability` (`AvailabilityAdminController`, appointment branch): the manual
 * booking drawer's slot list. `items` is the ordered chain (`ManualBookingSegment` - the same
 * `{service_id, resource_id?}` shape `POST /admin/bookings`'s own `appointment.segments` takes, so
 * a chosen start/segment combination here is guaranteed accepted there too - AGENTS.md Task 10's
 * "every start this endpoint offers is a start `POST /admin/bookings` accepts"), JSON-encoded
 * exactly as the endpoint's `items` query param expects. Disabled until every segment names a real
 * service - an empty or half-built chain would 400.
 */
export function useAdminAvailability(
	items: ManualBookingSegment[],
	range: CalendarRange,
	options: AvailabilityOptions = {}
): UseQueryResult< AvailabilityResponse, Error > {
	const { timezone } = bootConfig();
	const itemsJson = JSON.stringify( items );
	const sameStaff = options.sameStaff ?? false;
	const enabled =
		( options.enabled ?? true ) && items.length > 0 && items.every( ( item ) => item.service_id > 0 );

	return useQuery( {
		queryKey: [ 'availability', itemsJson, range, sameStaff, timezone ],
		queryFn: () =>
			apiFetch< AvailabilityResponse >(
				`/admin/availability${ toQueryString( {
					items: itemsJson,
					from: range.from,
					to: range.to,
					same_staff: sameStaff,
					tz: timezone,
				} ) }`
			),
		enabled,
	} );
}

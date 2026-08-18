/**
 * Parses a `Y-m-d H:i:s` (MySQL) or `Y-m-dTH:i:s` (ISO) UTC datetime string - exactly the shape
 * every `*_utc` field on the wire carries (`CalendarAdminController`) - into the real UTC instant
 * it names. A hand-rolled regex rather than the bare `Date` constructor: the space-separated MySQL
 * form is not part of the ECMAScript-guaranteed grammar, so parsing it via `new Date(str)` is
 * implementation-defined and unsafe to rely on across engines.
 */
export function parseUtc( dateStr: string ): Date {
	const match = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/.exec( dateStr );
	if ( null === match ) {
		throw new Error( `utcToSite: unparseable UTC datetime "${ dateStr }"` );
	}
	const [ , year, month, day, hour, minute, second ] = match;
	return new Date(
		Date.UTC( Number( year ), Number( month ) - 1, Number( day ), Number( hour ), Number( minute ), Number( second ) )
	);
}

function requirePart( parts: Partial< Record< Intl.DateTimeFormatPartTypes, string > >, type: Intl.DateTimeFormatPartTypes ): number {
	const value = parts[ type ];
	if ( undefined === value ) {
		throw new Error( `utcToSite: Intl.DateTimeFormat did not produce a "${ type }" part` );
	}
	return Number( value );
}

/**
 * Converts a UTC datetime string to the `Date` that react-big-calendar (and every date-fns call it
 * makes internally) reads back as `tz`'s own wall-clock time - regardless of the browser's or the
 * CI runner's own timezone. `date-fns`/react-big-calendar only ever read a `Date` through its LOCAL
 * getters (`getHours`, `getDay`, ...), which reflect the *host machine's* timezone; a plain
 * `date-fns` localizer has no "pass a timezone in" option. So rather than building the real instant
 * in `tz` (which those local getters would then read back wrong on any host not already in `tz`),
 * this packs `tz`'s wall-clock numbers - computed by `Intl`, which does know the IANA rules
 * including DST - into a `Date` via the LOCAL constructor: the exact inverse of the local getters.
 * `new Date(y, m, d, h, mi, s).getHours()` always returns `h` back, on any machine, in any
 * timezone, which is what makes the result portable between a dev box and a CI runner in a
 * different zone without either of them needing to *be* `tz`.
 */
export function utcToSite( dateStr: string, tz: string ): Date {
	const instant = parseUtc( dateStr );
	const formatter = new Intl.DateTimeFormat( 'en-US', {
		timeZone: tz,
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
		hour: '2-digit',
		minute: '2-digit',
		second: '2-digit',
		hourCycle: 'h23',
	} );

	const parts: Partial< Record< Intl.DateTimeFormatPartTypes, string > > = {};
	for ( const part of formatter.formatToParts( instant ) ) {
		parts[ part.type ] = part.value;
	}

	const year = requirePart( parts, 'year' );
	const month = requirePart( parts, 'month' );
	const day = requirePart( parts, 'day' );
	// hourCycle 'h23' still prints "24" for local midnight on some ICU builds - fold it back to 0.
	const hour = requirePart( parts, 'hour' ) % 24;
	const minute = requirePart( parts, 'minute' );
	const second = requirePart( parts, 'second' );

	return new Date( year, month - 1, day, hour, minute, second );
}

/**
 * Reads `instantMs`'s wall-clock digits in `tz` (via `Intl`, which knows the IANA rules including
 * DST) and repacks them with the UTC constructor - a plain numeric marker usable in arithmetic,
 * never itself a real instant unless `tz` is UTC. Used only to measure `tz`'s own UTC offset at
 * `instantMs` by subtracting the two (`siteToUtc`'s core primitive) - the exact opposite packing
 * choice from `utcToSite`'s own local-constructor packing, and deliberately so: that one exists to
 * be read back by a HOST-local getter, this one exists to be compared by subtraction, which only
 * `Date.UTC` (host-independent) makes safe.
 */
function tzWallClockAsUtcMs( instantMs: number, tz: string ): number {
	const formatter = new Intl.DateTimeFormat( 'en-US', {
		timeZone: tz,
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
		hour: '2-digit',
		minute: '2-digit',
		second: '2-digit',
		hourCycle: 'h23',
	} );

	const parts: Partial< Record< Intl.DateTimeFormatPartTypes, string > > = {};
	for ( const part of formatter.formatToParts( new Date( instantMs ) ) ) {
		parts[ part.type ] = part.value;
	}

	const year = requirePart( parts, 'year' );
	const month = requirePart( parts, 'month' );
	const day = requirePart( parts, 'day' );
	// hourCycle 'h23' still prints "24" for local midnight on some ICU builds - fold it back to 0.
	const hour = requirePart( parts, 'hour' ) % 24;
	const minute = requirePart( parts, 'minute' );
	const second = requirePart( parts, 'second' );

	return Date.UTC( year, month - 1, day, hour, minute, second );
}

function pad( n: number ): string {
	return String( n ).padStart( 2, '0' );
}

/** Formats a real UTC instant (ms since epoch) as the `Y-m-d H:i:s` string every `*_utc` field wants. */
function formatUtc( instantMs: number ): string {
	const d = new Date( instantMs );
	return `${ d.getUTCFullYear() }-${ pad( d.getUTCMonth() + 1 ) }-${ pad( d.getUTCDate() ) } ${ pad( d.getUTCHours() ) }:${ pad(
		d.getUTCMinutes()
	) }:${ pad( d.getUTCSeconds() ) }`;
}

/**
 * Converts a site-local `yyyy-MM-dd` date plus an `HH:MM`(`:SS`) time - exactly what a native
 * `<input type="date">`/`<input type="time">` pair hands back - to the `Y-m-d H:i:s` UTC string
 * every `*_utc` field on the wire expects: the exact inverse of `utcToSite`, for `EventsScreen`'s
 * (Task 16) add-occurrence form.
 *
 * A two-pass fixed-point estimate, the standard technique for this conversion (the one every
 * `Intl`-only timezone library uses in the absence of a platform "local time in named zone -> UTC"
 * primitive): the target wall-clock digits are first read AS IF they were themselves a UTC instant,
 * purely to sample `tz`'s offset near that date; subtracting the offset gives a first UTC estimate;
 * the offset is re-sampled AT that estimate (this only ever differs from the first sample within a
 * few hours of a DST transition) and, if it moved, the estimate is redone once more. Two passes
 * always converge for any real IANA rule - a DST transition changes the offset only once around a
 * given calendar date.
 *
 * **The one input this is not well-defined for is an AMBIGUOUS local time** - a fall-back hour that
 * occurs twice (see the adapter test suite's own worked example for Europe/Athens 2026-10-25 03:30).
 * This function resolves that case deterministically to the LATER of the two real UTC instants (the
 * post-transition, standard-time reading): sampling the offset off the wall-clock digits themselves
 * (the first pass) always lands past the transition point in real UTC time for any transition that,
 * like every real-world rule, happens in the small hours local time - so the first sample is always
 * the post-transition offset, and the second pass then confirms rather than revises it. This is a
 * one-line consequence of the algorithm above, not a special case coded for it, but it is pinned
 * exactly, deterministically, by a test rather than left to be discovered by accident.
 */
export function siteToUtc( dateStr: string, timeStr: string, tz: string ): string {
	const dateMatch = /^(\d{4})-(\d{2})-(\d{2})$/.exec( dateStr );
	if ( null === dateMatch ) {
		throw new Error( `siteToUtc: unparseable date "${ dateStr }"` );
	}
	const timeMatch = /^(\d{2}):(\d{2})(?::(\d{2}))?$/.exec( timeStr );
	if ( null === timeMatch ) {
		throw new Error( `siteToUtc: unparseable time "${ timeStr }"` );
	}

	const [ , yearStr, monthStr, dayStr ] = dateMatch;
	const [ , hourStr, minuteStr, secondStr ] = timeMatch;
	const targetMs = Date.UTC(
		Number( yearStr ),
		Number( monthStr ) - 1,
		Number( dayStr ),
		Number( hourStr ),
		Number( minuteStr ),
		Number( secondStr ?? '0' )
	);

	const firstOffset = tzWallClockAsUtcMs( targetMs, tz ) - targetMs;
	let utcMs = targetMs - firstOffset;

	const secondOffset = tzWallClockAsUtcMs( utcMs, tz ) - utcMs;
	if ( secondOffset !== firstOffset ) {
		utcMs = targetMs - secondOffset;
	}

	return formatUtc( utcMs );
}

/**
 * "Now", expressed as the site-local `Date` the calendar/adapter work in (see `utcToSite`).
 *
 * This, and never `new Date()`, is what any screen must start from when it needs "today" in
 * BUSINESS terms. `date-fns`'s `format()` reads a `Date` through the HOST machine's local getters,
 * so `format(new Date(), 'yyyy-MM-dd')` answers the day it is on the admin's own laptop - which is
 * not the day it is at the business whenever the two are in different zones. An owner in US/Pacific
 * at 16:00 local is already on the NEXT day in a Europe/Athens business; a screen defaulting to the
 * host's day would silently show, and book against, yesterday.
 *
 * `siteNow()` packs the site zone's wall-clock numbers into a `Date` (that is what `utcToSite`
 * does), so those same host-local getters - and therefore `format()`, `startOfWeek()`, `addDays()` -
 * read the SITE's day back on any machine.
 */
export function siteNow( tz: string ): Date {
	return utcToSite( new Date().toISOString().slice( 0, 19 ).replace( 'T', ' ' ), tz );
}

/** The `Y-m-d` business date of a site-packed Date, read through the local getters that unpack it. */
export function ymd( day: Date ): string {
	return `${ day.getFullYear() }-${ pad( day.getMonth() + 1 ) }-${ pad( day.getDate() ) }`;
}

/**
 * How many days one widget availability request covers - and the DateStrip's default strip
 * length, the same number ON PURPOSE: every day the strip renders must sit inside the window the
 * parent fetched, or its slots would silently never load. Two weeks is enough for "next free
 * morning" planning while staying far inside `AvailabilityController::MAX_WINDOW_DAYS` (62).
 */
export const WINDOW_DAYS = 14;

/**
 * The widget's availability request window off a site-packed `today` (`siteNow`): `from` is
 * today's own business date, `to` the day `WINDOW_DAYS` later, EXCLUSIVE - the `Y-m-d` pair
 * `GET /availability` speaks. Day arithmetic uses local-constructor overflow
 * (`new Date( y, m, d + n )`), which normalizes month and year rollover by the calendar itself -
 * packed dates carry no instant, so there is no DST to be off by an hour over.
 */
export function availabilityWindow( today: Date ): { from: string; to: string } {
	return {
		from: ymd( today ),
		to: ymd( new Date( today.getFullYear(), today.getMonth(), today.getDate() + WINDOW_DAYS ) ),
	};
}

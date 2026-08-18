/**
 * The date step (P5 plan, Task 13): a strip of consecutive days from `from`, each a real
 * `<button>`, reporting a pick as the `Y-m-d` BUSINESS date `useAvailability()`'s `from`/`to`
 * parameters speak (`fetchAvailability`'s docblock owns the wire contract).
 *
 * `from` is a SITE-packed `Date` - the `siteNow`/`utcToSite` contract from `assets/src/shared`,
 * where the site timezone's wall-clock digits are packed via the LOCAL constructor precisely so
 * local getters read them back unchanged on any host. This component therefore does no timezone
 * work of its own: it reads the packed digits through local getters and never calls `new Date()`
 * for "today" - the parent passes `siteNow( timezone )`, which is what keeps a visitor in another
 * timezone on the SALON's calendar day, not their own (an evening visitor west of the salon is
 * often a day behind it).
 *
 * Day arithmetic uses local-constructor overflow (`new Date( y, m, d + i )`), which normalizes
 * month and year rollover by the calendar itself - packed dates carry no instant, so there is no
 * DST to be off by an hour over.
 *
 * Labels are formatted by `Intl` in the machine's own locale (the `undefined` locale is
 * deliberate, the ServicePicker precedent pinned by review).
 */
import { WINDOW_DAYS, ymd } from '../../shared';

interface DateStripProps {
	/** The first day shown - site-packed, typically `siteNow( widgetBootstrap().timezone )`. */
	from: Date;
	/** Receives the picked day as `Y-m-d` - the exact string the availability window wants. */
	onSelect: ( day: string ) => void;
	/** The selected day as `Y-m-d`, or null while none is chosen yet. */
	value?: string | null;
	/**
	 * How many days the strip offers - `WINDOW_DAYS` by default, and that coupling is the point:
	 * the strip and the availability request it fronts must agree, or a rendered day's slots
	 * would silently never load (`WINDOW_DAYS`'s docblock owns the sizing).
	 */
	days?: number;
}

export function DateStrip( { from, onSelect, value = null, days = WINDOW_DAYS }: DateStripProps ): JSX.Element {
	const formatter = new Intl.DateTimeFormat( undefined, {
		weekday: 'short',
		month: 'short',
		day: 'numeric',
	} );
	const strip = Array.from(
		{ length: days },
		( _unused, i ) => new Date( from.getFullYear(), from.getMonth(), from.getDate() + i )
	);

	return (
		<ul className="reservant-date-strip">
			{ strip.map( ( day ) => {
				const key = ymd( day );
				return (
					<li key={ key } className="reservant-date-strip__item">
						<button
							type="button"
							className={
								value === key
									? 'reservant-date-strip__day reservant-date-strip__day--selected'
									: 'reservant-date-strip__day'
							}
							aria-pressed={ value === key }
							onClick={ () => onSelect( key ) }
						>
							{ formatter.format( day ) }
						</button>
					</li>
				);
			} ) }
		</ul>
	);
}

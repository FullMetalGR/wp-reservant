import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, CheckboxControl, Modal, Notice, Spinner, TextControl } from '@wordpress/components';
import {
	useActivateLicense,
	useDeactivateLicense,
	useLicense,
	useSaveSettings,
	useSettings,
} from '../api/queries';
import { bootConfig } from '../boot';
import type { LicenseState, LicenseStatus, SettingsPayload } from '../api/types';
import { useToasts } from '../components/Toasts';
import { errorMessage, utcToSite } from '../../shared';

/**
 * A TTL field is only ever meaningful as a positive whole number - `SettingsAdminController`'s own
 * `posIntOrThrow()` 400s anything else. Checked client-side too (review round 1): `toPatch()` below
 * used to fold a blank/garbage value through `parseInt(...) || 0`, which let a blanked-out field
 * reach `Save` as a literal `0` - a 0-minute checkout hold, submitted with nothing but a generic
 * error toast (or worse, silently accepted) rather than being caught before it was ever sent.
 */
function isPositiveIntString( value: string ): boolean {
	return /^[0-9]+$/.test( value.trim() ) && parseInt( value, 10 ) > 0;
}

const TTL_ERROR = __( 'Must be a positive whole number.', 'reservant' );

/**
 * `approval_ttl_hours` is live: `HoldBooking::holdExpiresAt()` falls back to it for a service that
 * stores no `approval_hold_hours` of its own. `payment_ttl_hours` is live too: `ApproveBooking`
 * writes it into the payment hold when approving an online booking, and the sweeper reclaims the
 * slot when the window lapses unpaid (AGENTS.md section 6).
 */
const APPROVAL_TTL_HELP = __(
	'Default window for approval holds. A service with its own approval window overrides this.',
	'reservant'
);
const PAYMENT_TTL_HELP = __(
	'How long an approved online booking holds its slot while the payment link is unpaid.',
	'reservant'
);

/**
 * Zero is a real answer for the reminder lead time and a malformed one for every TTL above it, so
 * this field cannot share `isPositiveIntString`. `SettingsAdminController::nonNegIntOrThrow()` is
 * the server side of the same rule.
 */
function isNonNegativeIntString( value: string ): boolean {
	return /^[0-9]+$/.test( value.trim() );
}

const LEAD_ERROR = __( 'Must be zero or a positive whole number.', 'reservant' );

const REMINDER_HELP = __(
	'How long before the appointment a reminder is sent. Zero switches reminders off.',
	'reservant'
);

/**
 * Stored as an OFF list, and the checkbox reads the other way round - "send this" - because that is
 * how an owner thinks about it. The inversion lives here and nowhere else: `Settings::emailsOff()`
 * keeps the negative form so that an email added by a later release is on by default, which a
 * stored ON list could not manage.
 */
const EMAILS_HELP = __( 'Uncheck a message to stop sending it.', 'reservant' );

interface SettingsFormState {
	currency: string;
	checkoutTtlMin: string;
	approvalTtlHours: string;
	paymentTtlHours: string;
	purgeOnUninstall: boolean;
	reminderLeadHours: string;
	emailsOff: string[];
}

function formFromSettings( settings: SettingsPayload ): SettingsFormState {
	return {
		currency: settings.currency,
		checkoutTtlMin: String( settings.checkout_ttl_min ),
		approvalTtlHours: String( settings.approval_ttl_hours ),
		paymentTtlHours: String( settings.payment_ttl_hours ),
		purgeOnUninstall: settings.purge_on_uninstall,
		reminderLeadHours: String( settings.reminder_lead_hours ),
		emailsOff: settings.emails_off,
	};
}

/**
 * Every field here always carries a real, concrete value once `useSettings()` has loaded (GET
 * never omits a key - `Settings::toArray()`'s shape is fixed), so the patch built from this form
 * can never contain an explicit `null`: `SettingsAdminController::sanitizeFields()` 400s any key
 * present with one rather than silently keeping the old value (T3 ledger obligation), so a screen
 * that DID send one would 400 on every save. Nothing here constructs a `null` in the first place.
 */
function toPatch( form: SettingsFormState ): Partial< SettingsPayload > {
	return {
		currency: form.currency.toUpperCase(),
		checkout_ttl_min: parseInt( form.checkoutTtlMin, 10 ) || 0,
		approval_ttl_hours: parseInt( form.approvalTtlHours, 10 ) || 0,
		payment_ttl_hours: parseInt( form.paymentTtlHours, 10 ) || 0,
		purge_on_uninstall: form.purgeOnUninstall,
		// Not `|| 0`: zero is this field's own meaningful value, so the guard is `canSave` above
		// refusing to submit anything that is not a whole number in the first place.
		reminder_lead_hours: parseInt( form.reminderLeadHours, 10 ),
		emails_off: form.emailsOff,
	};
}

/**
 * One whole translated sentence per license state, never a concatenation of fragments (AGENTS.md
 * section 7, i18n) - and deliberately five sentences rather than one "unlicensed" and a boolean,
 * because the FIX is different in every case (`Licensing\LicenseState`'s own docblock): `invalid`
 * means get a good key, `domain_mismatch` means activate on THIS site, `inactive` means enter one,
 * and `grace` means nothing at all needs doing yet.
 *
 * These are the screen's twin of `AdminGuard::licenseRequired()`'s per-state 403 sentences, minus
 * the "under Reservant -> Settings" pointer those carry: the owner reading this IS on that screen,
 * and the key field is directly below.
 */
function stateMessage( state: LicenseState ): string {
	switch ( state ) {
		case 'active':
			return __( 'Your license is active on this site.', 'reservant' );
		case 'grace':
			return __(
				'Your license could not be re-checked recently, so Reservant is running on a grace period. Nothing is paused, and the next successful check clears this by itself.',
				'reservant'
			);
		case 'invalid':
			return __( 'Your license key is no longer valid, so changes to your setup are paused. Enter a valid key below.', 'reservant' );
		case 'domain_mismatch':
			return __(
				'Your license is registered to a different domain, so changes to your setup are paused. Activate it for this site below.',
				'reservant'
			);
		default:
			return __( 'Reservant is not licensed on this site, so changes to your setup are paused. Enter your license key below.', 'reservant' );
	}
}

/** The same five states as a short label, for the status row above the sentence. */
function stateLabel( state: LicenseState ): string {
	switch ( state ) {
		case 'active':
			return __( 'Active', 'reservant' );
		case 'grace':
			return __( 'Grace period', 'reservant' );
		case 'invalid':
			return __( 'Invalid', 'reservant' );
		case 'domain_mismatch':
			return __( 'Registered to another domain', 'reservant' );
		default:
			return __( 'Not licensed', 'reservant' );
	}
}

/**
 * What a lapsed license actually costs, said plainly, because the alternative is an owner who
 * believes their site is down.
 *
 * This is the exact list `AdminGuard::configureSite()` gates and nothing else: every public and
 * guest route, every read, and the WHOLE admin booking lifecycle stay open on an unlicensed site,
 * on purpose (AGENTS.md section 5) - `awaiting_approval` bookings sit on a TTL, so a frozen
 * approval queue would quietly turn away paying customers over an unpaid invoice. Overstating the
 * freeze here would be a lie that costs the owner a panic; understating it would leave them
 * wondering why Save does nothing.
 */
const FROZEN_HELP = __(
	'Your bookings keep running and your customers are unaffected: they can still book, pay, cancel and reschedule, and you can still approve, reject, cancel and complete bookings. Only changes to your setup - services, staff, availability, events, seat maps and these settings - are paused until a license is active.',
	'reservant'
);

/**
 * Activation REPLACES whatever is stored, a working key included (`LicenseManager::activate()`), so
 * the field says so rather than letting an owner discover it by pasting the wrong one.
 */
const KEY_HELP = __( 'Activating replaces whatever key is stored on this site, including a working one.', 'reservant' );

/**
 * The toast for a key the validator refused, deliberately NOT `stateMessage( 'invalid' )`.
 *
 * Two different things need saying and only one of them is the state: the toast reports what the
 * click just DID, the notice below reports where the site now stands. And what the click did
 * includes the part an owner will not guess - `LicenseManager::activate()` is a REPLACEMENT, so a
 * bad key pasted over a working one loses the working one, and finding that out from a support
 * ticket is the expensive way.
 */
const REFUSED_KEY = __(
	'That license key was refused. It has replaced whatever key was stored on this site.',
	'reservant'
);

const DEACTIVATE_WARNING = __(
	'This unbinds the site from your license so the seat can be used somewhere else. Changes to your setup are paused until a key is activated here again, and your bookings keep running either way.',
	'reservant'
);

/**
 * The facts under the sentence, each row present only when there is something to say.
 *
 * A row per absent value ("Last checked: never", "Domain: -") is noise on a fresh install, where
 * ALL of them are absent, and every one of these can be legitimately empty: a never-activated site
 * carries no key and no domain (`LicenseRecord::statusAt()`), a key refused at activation has never
 * been validated, and `grace_ends_at` is non-null only inside the grace window - a deadline shown
 * outside it reads as a threat that is not real.
 *
 * Timestamps arrive as `Y-m-d H:i:s` UTC (`LicensePayload::instant()`) and are shown in the SITE's
 * timezone, the same conversion at the same edge every other date on this SPA gets.
 */
function LicenseFacts( { license, timezone }: { license: LicenseStatus; timezone: string } ) {
	// Annotated on EVERY timestamp rather than only the deadline: an owner reading "your grace
	// period ends at 09:30" in a zone that is not theirs can be a day out, and a rule applied to one
	// row and not the next reads as though the two are in different zones.
	function moment( utc: string ): string {
		return sprintf(
			/* translators: %s: a date and time, already converted to the site's own timezone. */
			__( '%s (site time)', 'reservant' ),
			utcToSite( utc, timezone ).toLocaleString()
		);
	}

	return (
		<dl className="reservant-license__facts">
			<dt>{ __( 'Status', 'reservant' ) }</dt>
			<dd>{ stateLabel( license.state ) }</dd>
			{ '' !== license.masked_key && (
				<>
					{ /* Deliberately not "License key" - that is the INPUT's label in the section below, and
					     two identical labels on one screen is how an owner comes to believe the field there is
					     showing them what is stored. */ }
					<dt>{ __( 'Key on this site', 'reservant' ) }</dt>
					<dd>{ license.masked_key }</dd>
				</>
			) }
			{ '' !== license.domain && (
				<>
					<dt>{ __( 'Registered domain', 'reservant' ) }</dt>
					<dd>{ license.domain }</dd>
				</>
			) }
			{ null !== license.last_checked_at && (
				<>
					<dt>{ __( 'Last checked', 'reservant' ) }</dt>
					<dd>{ moment( license.last_checked_at ) }</dd>
				</>
			) }
			{ null !== license.grace_ends_at && (
				<>
					<dt>{ __( 'Grace period ends', 'reservant' ) }</dt>
					<dd>{ moment( license.grace_ends_at ) }</dd>
				</>
			) }
		</dl>
	);
}

/**
 * The license section of the Settings screen (AGENTS.md section 5, "License enforcement").
 *
 * **It renders from the bootstrap, not from a fetch.** `window.reservantAdmin.license`
 * (`Admin\AdminPage::license()`) already carries the status in the same shape `GET /admin/license`
 * answers with, so an owner whose configuration is frozen sees why on the first paint instead of
 * watching the screen render once wrongly and then correct itself. The fetch is the FALLBACK, taken
 * only when the bootstrap value is `null` - which means "not known right now" (a
 * `reservant/license_manager` that threw while the page rendered), never "unlicensed".
 *
 * Three sources answer the same question and the freshest wins, in this order: the status a
 * mutation just returned, then the fallback fetch, then the bootstrap. Every `LicenseManager`
 * method returns the resulting status precisely so that no caller has to write and read back, so
 * an activation's own answer is authoritative the moment it lands.
 *
 * `active` is read off the payload and never recomputed: `grace` counts as licensed
 * (`LicenseState::isActive()`), and a screen testing `'active' === state` would put a warning in
 * front of an owner whose only problem is somebody else's DNS.
 */
function LicenseSection() {
	const { timezone, license: booted } = bootConfig();
	const { addToast } = useToasts();

	// `?? null` rather than a bare read: a current build always emits the key (null included), but
	// absence and null mean exactly the same thing here - "not known" - and treating an absent key
	// as "no fallback needed" would leave the section permanently blank.
	const bootstrapped = booted ?? null;
	const licenseQuery = useLicense( null === bootstrapped );
	const activateLicense = useActivateLicense();
	const deactivateLicense = useDeactivateLicense();

	const [ answered, setAnswered ] = useState< LicenseStatus | null >( null );
	const [ key, setKey ] = useState( '' );
	const [ confirmDeactivateOpen, setConfirmDeactivateOpen ] = useState( false );

	const license = answered ?? licenseQuery.data ?? bootstrapped;

	// An empty key is a documented server-side NO-OP (`LicenseManager::activate()`: a blank field
	// posted by accident must not cost a site the license it paid for) - which means it answers 200
	// with whatever was already stored, and would read on screen as a successful activation. So it
	// never leaves here: the button is disabled and the handler refuses it a second time.
	const canActivate = '' !== key.trim();

	function handleActivate(): void {
		const trimmed = key.trim();
		if ( '' === trimmed ) {
			return;
		}
		activateLicense.mutate( trimmed, {
			onSuccess: ( status ) => {
				setAnswered( status );
				if ( status.active ) {
					// The plaintext leaves the screen the moment it is no longer needed; what stays
					// on show is the masked form the payload came back with.
					setKey( '' );
					addToast( __( 'License activated.', 'reservant' ) );
					return;
				}
				// A REFUSED key is a 200 with `state: 'invalid'`, not an HTTP error - so the toast
				// has to come from reading the answer, and the field keeps the key so a typo can be
				// fixed rather than retyped.
				addToast( REFUSED_KEY, 'error' );
			},
			onError: ( error ) => addToast( errorMessage( error ), 'error' ),
		} );
	}

	function handleDeactivate(): void {
		setConfirmDeactivateOpen( false );
		deactivateLicense.mutate( undefined, {
			onSuccess: ( status ) => {
				setAnswered( status );
				addToast( __( 'License deactivated. This site is no longer bound to it.', 'reservant' ) );
			},
			onError: ( error ) => addToast( errorMessage( error ), 'error' ),
		} );
	}

	return (
		<section className="reservant-license">
			<h2>{ __( 'License', 'reservant' ) }</h2>

			{ licenseQuery.isError && (
				<Notice status="error" isDismissible={ false }>
					{ errorMessage( licenseQuery.error ) }
				</Notice>
			) }
			{ null === license && licenseQuery.isLoading && <Spinner /> }

			{ null !== license && (
				<>
					<Notice status={ 'active' === license.state ? 'success' : 'warning' } isDismissible={ false }>
						{ stateMessage( license.state ) }
					</Notice>
					{ ! license.active && <p className="reservant-license__frozen">{ FROZEN_HELP }</p> }

					<LicenseFacts license={ license } timezone={ timezone } />

					{ /* `autoComplete`/`spellCheck` off because this is a credential, not prose: an opaque
					     vendor key is neither a word to check nor a login for a browser to remember. */ }
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						autoComplete="off"
						spellCheck={ false }
						label={ __( 'License key', 'reservant' ) }
						help={ KEY_HELP }
						value={ key }
						onChange={ ( value ) => setKey( value ) }
					/>
					<Button
						variant="primary"
						disabled={ ! canActivate }
						isBusy={ activateLicense.isPending }
						onClick={ handleActivate }
					>
						{ __( 'Activate license', 'reservant' ) }
					</Button>
					{ '' !== license.masked_key && (
						<Button
							variant="secondary"
							isDestructive
							isBusy={ deactivateLicense.isPending }
							onClick={ () => setConfirmDeactivateOpen( true ) }
						>
							{ __( 'Deactivate license', 'reservant' ) }
						</Button>
					) }
				</>
			) }

			{ confirmDeactivateOpen && (
				<Modal title={ __( 'Deactivate this license?', 'reservant' ) } onRequestClose={ () => setConfirmDeactivateOpen( false ) }>
					<p>{ DEACTIVATE_WARNING }</p>
					<Button variant="primary" isDestructive onClick={ handleDeactivate }>
						{ __( 'Deactivate', 'reservant' ) }
					</Button>
					<Button variant="tertiary" onClick={ () => setConfirmDeactivateOpen( false ) }>
						{ __( 'Cancel', 'reservant' ) }
					</Button>
				</Modal>
			) }
		</section>
	);
}

/**
 * The business settings screen (Task 16 brief): currency (a 3-letter uppercase input), the three
 * TTL fields, the reminder lead time, one checkbox per message the plugin can send, the
 * uninstall-purge checkbox, and a save that reports through the shared toast queue rather than an
 * inline banner.
 *
 * `LicenseSection` sits ABOVE all of it and outside the settings query entirely, on purpose: it is
 * what an owner comes to this screen for when their configuration is frozen, it renders from the
 * bootstrap with no round trip, and it has to be reachable even on the load where
 * `GET /admin/settings` itself failed. Gating it behind the settings form would put the way back
 * behind the thing that may be broken.
 */
export function SettingsScreen() {
	const { emailChoices } = bootConfig();
	const { addToast } = useToasts();
	const settingsQuery = useSettings();
	const saveSettings = useSaveSettings();

	const [ form, setForm ] = useState< SettingsFormState | null >( null );

	// Seeded once the GET resolves; subsequent background refetches (react-query's
	// `refetchOnWindowFocus`) must not clobber a save in flight or unsaved edits - same reasoning as
	// `StaffScreen`'s own working-copy note.
	useEffect( () => {
		if ( undefined !== settingsQuery.data && null === form ) {
			setForm( formFromSettings( settingsQuery.data ) );
		}
	}, [ settingsQuery.data, form ] );

	function patchForm( patch: Partial< SettingsFormState > ): void {
		setForm( ( current ) => ( null === current ? current : { ...current, ...patch } ) );
	}

	function handleSave(): void {
		if ( null === form ) {
			return;
		}
		saveSettings.mutate( toPatch( form ), {
			onSuccess: ( saved ) => {
				addToast( __( 'Settings saved.', 'reservant' ) );
				setForm( formFromSettings( saved ) );
			},
			onError: ( error ) => addToast( errorMessage( error ), 'error' ),
		} );
	}

	const checkoutValid = null !== form && isPositiveIntString( form.checkoutTtlMin );
	const approvalValid = null !== form && isPositiveIntString( form.approvalTtlHours );
	const paymentValid = null !== form && isPositiveIntString( form.paymentTtlHours );
	const currencyValid = null !== form && /^[A-Za-z]{3}$/.test( form.currency.trim() );
	const reminderValid = null !== form && isNonNegativeIntString( form.reminderLeadHours );
	const canSave = currencyValid && checkoutValid && approvalValid && paymentValid && reminderValid;

	return (
		<div className="reservant-settings-screen">
			<LicenseSection />

			{ settingsQuery.isError && (
				<Notice status="error" isDismissible={ false }>
					{ __( 'Could not load settings.', 'reservant' ) }
				</Notice>
			) }
			{ ( settingsQuery.isLoading || null === form ) && <Spinner /> }

			{ null !== form && (
				<>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Currency', 'reservant' ) }
						help={ __( 'Three-letter ISO code, e.g. EUR.', 'reservant' ) }
						maxLength={ 3 }
						value={ form.currency }
						onChange={ ( value ) => patchForm( { currency: value.toUpperCase() } ) }
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="number"
						min={ 1 }
						label={ __( 'Checkout hold (minutes)', 'reservant' ) }
						help={ checkoutValid ? undefined : TTL_ERROR }
						value={ form.checkoutTtlMin }
						onChange={ ( value ) => patchForm( { checkoutTtlMin: value } ) }
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="number"
						min={ 1 }
						label={ __( 'Approval hold (hours)', 'reservant' ) }
						help={ approvalValid ? APPROVAL_TTL_HELP : TTL_ERROR }
						value={ form.approvalTtlHours }
						onChange={ ( value ) => patchForm( { approvalTtlHours: value } ) }
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="number"
						min={ 1 }
						label={ __( 'Payment hold (hours)', 'reservant' ) }
						help={ paymentValid ? PAYMENT_TTL_HELP : TTL_ERROR }
						value={ form.paymentTtlHours }
						onChange={ ( value ) => patchForm( { paymentTtlHours: value } ) }
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="number"
						min={ 0 }
						label={ __( 'Reminder lead time (hours)', 'reservant' ) }
						help={ reminderValid ? REMINDER_HELP : LEAD_ERROR }
						value={ form.reminderLeadHours }
						onChange={ ( value ) => patchForm( { reminderLeadHours: value } ) }
					/>

					<fieldset className="reservant-settings-emails">
						<legend>{ __( 'Emails', 'reservant' ) }</legend>
						<p>{ EMAILS_HELP }</p>
						{ emailChoices.map( ( choice ) => (
							<CheckboxControl
								__nextHasNoMarginBottom
								key={ choice.key }
								label={ choice.label }
								checked={ ! form.emailsOff.includes( choice.key ) }
								onChange={ ( on ) =>
									patchForm( {
										emailsOff: on
											? form.emailsOff.filter( ( key ) => key !== choice.key )
											: [ ...form.emailsOff, choice.key ],
									} )
								}
							/>
						) ) }
					</fieldset>

					<CheckboxControl
						__nextHasNoMarginBottom
						label={ __( 'Purge all data on uninstall', 'reservant' ) }
						checked={ form.purgeOnUninstall }
						onChange={ ( value ) => patchForm( { purgeOnUninstall: value } ) }
					/>

					<Button variant="primary" disabled={ ! canSave } isBusy={ saveSettings.isPending } onClick={ handleSave }>
						{ __( 'Save settings', 'reservant' ) }
					</Button>
				</>
			) }
		</div>
	);
}

import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, CheckboxControl, Notice, Spinner, TextControl } from '@wordpress/components';
import { errorMessage } from '../api/client';
import { useSaveSettings, useSettings } from '../api/queries';
import type { SettingsPayload } from '../api/types';
import { useToasts } from '../components/Toasts';

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

interface SettingsFormState {
	currency: string;
	checkoutTtlMin: string;
	approvalTtlHours: string;
	paymentTtlHours: string;
	purgeOnUninstall: boolean;
}

function formFromSettings( settings: SettingsPayload ): SettingsFormState {
	return {
		currency: settings.currency,
		checkoutTtlMin: String( settings.checkout_ttl_min ),
		approvalTtlHours: String( settings.approval_ttl_hours ),
		paymentTtlHours: String( settings.payment_ttl_hours ),
		purgeOnUninstall: settings.purge_on_uninstall,
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
	};
}

/**
 * The business settings screen (Task 16 brief): currency (a 3-letter uppercase input), the three
 * TTL fields, the uninstall-purge checkbox, and a save that reports through the shared toast queue
 * rather than an inline banner.
 */
export function SettingsScreen() {
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
	const canSave = currencyValid && checkoutValid && approvalValid && paymentValid;

	return (
		<div className="reservant-settings-screen">
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
						help={ approvalValid ? undefined : TTL_ERROR }
						value={ form.approvalTtlHours }
						onChange={ ( value ) => patchForm( { approvalTtlHours: value } ) }
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="number"
						min={ 1 }
						label={ __( 'Payment hold (hours)', 'reservant' ) }
						help={ paymentValid ? undefined : TTL_ERROR }
						value={ form.paymentTtlHours }
						onChange={ ( value ) => patchForm( { paymentTtlHours: value } ) }
					/>
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

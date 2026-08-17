/**
 * The details step (P5 plan, Task 14): name, email and an optional phone as real labelled inputs
 * inside a real `<form>` - native submit semantics (Enter works, `required` and `type="email"`
 * give constraint validation for free), never a keyboard-simulating div (the P4 lesson). No
 * `@wordpress/components`: it is a webpack external whose import would hang the ~800 KB
 * `wp-components` core handle on every visitor (`bin/widget-contract.mjs` owns the enforcement).
 *
 * Client-side checking deliberately stops at the native attributes: the SERVER is the authority
 * on what a valid customer is (`HoldsController::customer()` 400s anything without a name and a
 * valid email), and its refusal sentence is rendered verbatim by the flow next to this form
 * rather than being second-guessed with a home-grown validator that could drift from it.
 *
 * The submitted shape is exactly `POST /holds`' `customer`: trimmed, and `phone` OMITTED (not
 * sent empty) when the visitor left it blank - `JSON.stringify` drops the absent key, keeping the
 * wire identical to what the endpoint documents.
 */
import { useId, useState } from 'react';
import type { FormEvent } from 'react';
import { __ } from '@wordpress/i18n';
import type { HoldInput } from '../api/types';

interface CustomerFormProps {
	/** Receives the trimmed customer exactly as `POST /holds`' `customer` field wants it. */
	onSubmit: ( customer: HoldInput[ 'customer' ] ) => void;
	/** True while the hold request is in flight: the submit control disables, the fields stay. */
	busy: boolean;
}

export function CustomerForm( { onSubmit, busy }: CustomerFormProps ): JSX.Element {
	const [ name, setName ] = useState( '' );
	const [ email, setEmail ] = useState( '' );
	const [ phone, setPhone ] = useState( '' );
	const baseId = useId();
	const nameId = `${ baseId }-name`;
	const emailId = `${ baseId }-email`;
	const phoneId = `${ baseId }-phone`;

	const handleSubmit = ( event: FormEvent< HTMLFormElement > ): void => {
		event.preventDefault();
		const trimmedPhone = phone.trim();
		onSubmit( {
			name: name.trim(),
			email: email.trim(),
			...( '' === trimmedPhone ? {} : { phone: trimmedPhone } ),
		} );
	};

	return (
		<form className="reservant-customer-form" onSubmit={ handleSubmit }>
			<div className="reservant-customer-form__field">
				<label htmlFor={ nameId }>{ __( 'Name', 'reservant' ) }</label>
				<input
					id={ nameId }
					type="text"
					required
					autoComplete="name"
					value={ name }
					onChange={ ( event ) => setName( event.target.value ) }
				/>
			</div>
			<div className="reservant-customer-form__field">
				<label htmlFor={ emailId }>{ __( 'Email', 'reservant' ) }</label>
				<input
					id={ emailId }
					type="email"
					required
					autoComplete="email"
					value={ email }
					onChange={ ( event ) => setEmail( event.target.value ) }
				/>
			</div>
			<div className="reservant-customer-form__field">
				<label htmlFor={ phoneId }>{ __( 'Phone (optional)', 'reservant' ) }</label>
				<input
					id={ phoneId }
					type="tel"
					autoComplete="tel"
					value={ phone }
					onChange={ ( event ) => setPhone( event.target.value ) }
				/>
			</div>
			<button type="submit" className="reservant-customer-form__submit" disabled={ busy }>
				{ busy ? __( 'Reserving...', 'reservant' ) : __( 'Continue', 'reservant' ) }
			</button>
		</form>
	);
}

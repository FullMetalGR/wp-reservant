import { createContext, useCallback, useContext, useMemo, useState } from '@wordpress/element';
import type { ReactNode } from 'react';
import { SnackbarList } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export type ToastStatus = 'success' | 'error' | 'info';

interface Toast {
	id: string;
	content: string;
}

export interface ToastContextValue {
	/** Queues a toast; an `error` status prefixes the message so it reads distinctly in the list. */
	addToast: ( message: string, status?: ToastStatus ) => void;
}

const ToastContext = createContext< ToastContextValue | null >( null );

let nextToastId = 0;

/**
 * Wraps the SPA in a toast queue backed by `@wordpress/components`' own `SnackbarList` - the
 * native wp-admin notice look, dismissed automatically or by the user. Every booking mutation
 * (Task 15) reports its result through `useToasts()` rather than each screen rolling its own.
 */
export function ToastProvider( { children }: { children: ReactNode } ) {
	const [ toasts, setToasts ] = useState< Toast[] >( [] );

	const removeToast = useCallback( ( id: string ): void => {
		setToasts( ( current ) => current.filter( ( toast ) => toast.id !== id ) );
	}, [] );

	const addToast = useCallback( ( message: string, status: ToastStatus = 'success' ): void => {
		nextToastId += 1;
		const id = String( nextToastId );
		const content = 'error' === status ? `${ __( 'Error', 'reservant' ) }: ${ message }` : message;
		setToasts( ( current ) => [ ...current, { id, content } ] );
	}, [] );

	const value = useMemo< ToastContextValue >( () => ( { addToast } ), [ addToast ] );

	return (
		<ToastContext.Provider value={ value }>
			{ children }
			<SnackbarList notices={ toasts } onRemove={ removeToast } />
		</ToastContext.Provider>
	);
}

export function useToasts(): ToastContextValue {
	const context = useContext( ToastContext );
	if ( null === context ) {
		throw new Error( 'useToasts must be used within a ToastProvider' );
	}
	return context;
}

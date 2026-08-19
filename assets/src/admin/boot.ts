/**
 * One switchable message: `Notifications\EmailCatalog::choices()`.
 *
 * The list is bootstrapped from the server rather than written here, so a message added in a later
 * phase gets its checkbox with no client-side change - and so the labels stay next to `KEYS`, where
 * a test can hold the two together.
 */
interface EmailChoice {
  key: string;
  label: string;
}

export interface BootConfig {
  restRoot: string;
  nonce: string;
  caps: string[];
  currency: string;
  timezone: string;
  granularityMin: number;
  emailChoices: EmailChoice[];
}

declare global {
  interface Window {
    reservantAdmin?: BootConfig;
  }
}

export function bootConfig(): BootConfig {
  if (!window.reservantAdmin) {
    throw new Error('reservantAdmin missing');
  }
  return window.reservantAdmin;
}

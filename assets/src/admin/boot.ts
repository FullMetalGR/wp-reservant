import type { LicenseStatus } from './api/types';

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
  /**
   * The site's license as `Rest\Admin\LicensePayload` renders it - the same shape
   * `GET /admin/license` answers with, so the SPA parses one shape whether the status arrived with
   * the page or came back from an activation.
   *
   * It rides in the bootstrap rather than being fetched because an unlicensed owner should not
   * watch the Settings screen render once wrongly and then correct itself.
   *
   * `null` means "not known right now", NOT "unlicensed": `Admin\AdminPage::license()` answers null
   * both for a caller without `reservant_manage_settings` (a staff-only viewer, who has nothing to
   * act on) and for a `reservant/license_manager` implementation that threw. A screen that needs the
   * answer falls back to `GET /admin/license`; a screen that does not, ignores it.
   */
  license: LicenseStatus | null;
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

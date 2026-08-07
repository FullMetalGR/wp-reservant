export interface BootConfig {
  restRoot: string;
  nonce: string;
  caps: string[];
  currency: string;
  timezone: string;
  granularityMin: number;
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

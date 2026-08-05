import { bootConfig } from '../boot';

describe('bootConfig', () => {
  it('throws without injected config', () => {
    delete (window as { reservantAdmin?: unknown }).reservantAdmin;
    expect(() => bootConfig()).toThrow('reservantAdmin missing');
  });
  it('returns the injected config', () => {
    (window as { reservantAdmin?: unknown }).reservantAdmin = {
      restRoot: '/wp-json/', nonce: 'n', caps: ['reservant_manage_bookings'],
      currency: 'EUR', timezone: 'Europe/Athens', granularityMin: 5,
    };
    expect(bootConfig().currency).toBe('EUR');
  });
});

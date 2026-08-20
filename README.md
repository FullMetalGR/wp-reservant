# Reservant

A premium WordPress plugin for booking time slots: appointments (haircuts, nails, beauty,
consultations) and events or seminars (fixed occurrences, with or without assigned seats).
Works with or without payments. WooCommerce is an optional payment bridge, never a hard
dependency.

## Status

The v1.0 engine core is complete: pure availability domain (bitmask slot math, service
chains with processing gaps), the locked hold protocol, custom InnoDB schema, and the
public REST API (`reservant/v1`), proven under concurrent load. The admin UI (services,
staff, availability, calendar, manual booking) and the public booking widget (shortcode
and block, chain builder, guest flow, magic-link manage page) are complete as well, as are
notifications: the customer and approver email sets, `.ics` invitations that move a booking
in the guest's calendar rather than duplicating it, reminders on Action Scheduler, and the
hold-expiry sweeper.

The optional WooCommerce bridge is complete too. A service set to "pay online" mirrors to a
virtual, hidden WooCommerce product; a held booking becomes a cart, and a paid order confirms
the booking, while a cancelled, failed or refunded one releases the slot. An approved booking
for a paid service moves to `awaiting_payment` and the customer is emailed a payment link that
lives as long as the hold does. The hold is the authority throughout: a cart or a payment link
that outlives it is refused under lock, before any money moves.

WooCommerce remains optional. With no payment plugin active the whole thing degrades on
purpose - "pay online" behaves as pay-on-arrival, bookings still complete, and wp-admin says
plainly that nothing is currently taking money.

Licensing is in too: a key activates against the site's domain and is re-checked daily, and a
site whose license has lapsed loses configuration writes and nothing else - bookings, payments
and the whole approval queue keep running. `composer package` builds the distributable zip, and
`readme.txt` plus `CHANGELOG.md` ship inside it. That was the last milestone before v1.0; what
remains is QA.

See `AGENTS.md` for the full product spec, invariants, schema, and conventions.

## Requirements

- PHP 8.1+
- WordPress 6.6+
- MySQL 5.7+ / MariaDB 10.3+ (InnoDB)

## Development

```bash
composer install && npm install
npx wp-env start              # local WordPress + DB (Docker)

composer test:unit            # domain tests, no WordPress bootstrap
composer test:integration     # repositories, locking, REST (needs wp-env)
./bin/run-concurrency.sh      # parallel holds, opposing chains, contested seats
composer lint                 # PHPCS (WordPress Coding Standards)
composer stan                 # PHPStan level 6
```

CI runs all of the above on every push. The concurrency gate is mandatory and may not be
skipped: capacity must never be exceeded, and that property is proven, not assumed.

## License

Proprietary. All rights reserved.

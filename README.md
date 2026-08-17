# Reservant

A premium WordPress plugin for booking time slots: appointments (haircuts, nails, beauty,
consultations) and events or seminars (fixed occurrences, with or without assigned seats).
Works with or without payments. WooCommerce is an optional payment bridge, never a hard
dependency.

## Status

The v1.0 engine core is complete: pure availability domain (bitmask slot math, service
chains with processing gaps), the locked hold protocol, custom InnoDB schema, and the
public REST API (`reservant/v1`), proven under concurrent load. Admin UI, booking widget,
notifications, and the WooCommerce bridge are the next milestones.

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

# Reservant

A premium WordPress plugin for booking time slots - both **appointments** (haircuts, nails, beauty,
consultations) and **events/seminars** (fixed occurrences, with or without assigned seats).
Works with or without money. WooCommerce is an *optional* payment bridge, never a hard dependency.

- Slug / text domain: `reservant`
- PHP namespace: `Reservant\`
- DB tables: `{$wpdb->prefix}reservant_*`
- Options: `reservant_*` , Hooks: `reservant/*` , REST: `reservant/v1`
- Constants: `RESERVANT_VERSION`, `RESERVANT_FILE`, `RESERVANT_PATH`, `RESERVANT_URL`

---

## 1. Product decisions (settled - do not relitigate)

| Decision | Choice |
|---|---|
| Booking model | **One unified engine.** Appointment = generated slots, capacity 1. Event = fixed occurrence, capacity N or a seat grid. |
| Booking shape | **A booking is a container of one or more items.** A single haircut is a one-item booking; "cut + colour" is a multi-item chain. Capacity is consumed per *item*. |
| Chains | Segments may use **different staff** and may declare **processing gaps**. Booked atomically - all segments or none. |
| Processing gaps | **The staff member is free during the gap.** The gap blocks nothing; the next segment holds its own range. |
| Cancellation granularity | **Whole booking only.** Status lives on the container. Items have no independent lifecycle. |
| Payments | **Optional WooCommerce bridge.** Plugin activates and fully works with WC absent. |
| Approval queue | Per service: booking may require owner approval. **Approve -> emailed payment link -> paid -> confirmed.** No money moves before a human says yes. |
| Approval holds | A pending request **blocks the slot**. On timeout (default 48h) the service's `on_approval_timeout` applies: `expire` or `auto_approve`. Owner emails carry signed approve/reject links; each opens a confirm page requiring one human click before the decision executes (scanner-proof). |
| Seats | Optional per service: capacity-only, or a **row x seat grid** the customer picks from. No visual canvas editor. |
| Resources | **Multiple staff, single location.** Rooms/equipment and multi-location are v2. |
| Distribution | **Premium only.** Licensing abstracted behind an interface, vendor undecided. |
| Concurrency | **Held rows + TTL**, written under deterministically-ordered DB row locks. |
| Availability | **Quantized free/busy bitmasks** per resource-day (default 5-min grid), cached and revision-invalidated. Chain feasibility is mask algebra, not search. |
| Storage | **Custom InnoDB tables.** No CPT/postmeta for bookings or availability. |
| Time | **UTC in the DB.** Site-timezone display, optional "show in my timezone". |
| UI | **React + TypeScript + REST.** `@wordpress/scripts` build; widget via shortcode *and* block; admin SPA. All asset sources are TypeScript (strict); fallow gates JS/TS health in CI. |
| Identity | **Guest booking + signed magic link** for self-service manage/cancel/reschedule. |
| Cancellation policy | Policy engine per service; slot auto-releases. **Refunds are flagged, never automatic.** |
| Notifications | **Email + `.ics` + reminders** via Action Scheduler. |
| Payment modes | Per service: **free / pay online in full / pay on site**. |
| PHP | **8.1+**, typed, namespaced, Composer PSR-4. WP 6.5+. |
| Testing | **Unit + integration + Playwright E2E.** PHPCS (WPCS) + PHPStan >=6. |

**Non-goals for v1:** Google/Outlook calendar sync, deposits/partial payments, prepaid packages
& credits, memberships/subscriptions, SMS, recurring bookings, waitlists, multi-location,
rooms/equipment, automatic refunds, per-seat price tiers, visual seat-map editor, competing
(non-blocking) approval requests, per-segment cancellation, multisite network activation.

---

## 2. The one invariant that matters

**Capacity is never exceeded, a booking is atomic across all its items, and availability shown
in the UI is never trusted.**

### 2.1 What "blocking" means

One predicate, used by *every* availability read and every re-validation:

```
is_blocking(item) :=
     booking.status = 'confirmed'
  OR (booking.status IN ('pending','awaiting_approval','awaiting_payment')
      AND booking.hold_expires_at > UTC_NOW())
```

Expired holds are free **by time comparison, in the query**. Correctness must never depend on the
sweeper job having run - the sweeper only tidies rows and fires notifications.

### 2.2 Write protocol

```
-- before the transaction: ensure every mutex row exists
INSERT IGNORE INTO reservant_resource_days (resource_id, day_utc) VALUES ...   -- per appointment item
-- (event items use the occurrence row itself as the mutex)

BEGIN
  -- 1. LOCK, IN A DETERMINISTIC GLOBAL ORDER  <- deadlock protection, not optional
  --    sort every key as ('resource_day', resource_id, day_utc) / ('occurrence', occurrence_id)
  --    ascending, then SELECT ... FOR UPDATE each in turn.

  -- 2. reap expired holds touching these keys (keeps DB constraints honest)
  UPDATE bookings SET status='expired' ... WHERE status IN (held) AND hold_expires_at <= UTC_NOW()
  UPDATE booking_items SET seat_claim = NULL WHERE booking_id IN (...)

  -- 3. RE-VALIDATE every item under the lock, ignoring what the client believed
  --    appointment : range-overlap query against blocking items on that resource/day
  --    event (open): booked_seats + requested <= capacity
  --    event (grid): each requested seat_id has no blocking claim on that occurrence

  -- 4. INSERT booking + all items, or none
  --    status   = 'awaiting_approval' if the service requires approval, else 'pending'
  --    hold_ttl = approval_hold_hours (default 48) or checkout_hold_minutes (default 15)
COMMIT
```

Rules that are not optional:

- **Lock ordering.** A chain locks several resource-days; two chains booking the same pair in
  opposite order will deadlock. Always sort keys before locking. Test this with parallel chains.
- **Appointments contend on overlapping ranges**, not equal start times - a 10:00-10:45 booking
  blocks a 10:30 start. A unique index on `start_utc` is *wrong* here.
- **Buffers are part of the range.** Overlap is checked against
  `start_utc - buffer_before` ... `end_utc + buffer_after`.
- **Processing gaps are not part of the range.** A segment with processing time occupies
  `start_utc ... start_utc + service_time`; the gap that follows blocks nothing. The next segment
  begins at `start_utc + service_time + processing_time` and holds its own range.
- **Seats:** the occurrence row lock is the primary mechanism. As a hard backstop, a unique index
  on `(occurrence_id, seat_claim)` with `seat_claim` NULLable - set to the seat id while blocking,
  NULLed on release/expiry/cancellation (MySQL permits many NULLs in a unique index).
- **Atomicity.** If any item fails re-validation, the whole transaction rolls back and the API
  returns `409` naming the failing segment. Never partially book a chain.
- The availability endpoint is advisory. It may return a slot that is gone 200ms later. The hold
  endpoint is the only authority, and `409 Conflict` is a normal, expected response.

This protocol gets tests before it gets a UI. Concurrency tests - parallel holds on one slot,
parallel chains in opposing lock order, parallel claims on one seat - are mandatory and may not
be skipped in CI.

### 2.3 Status machine (on the booking container)

```
                      +- free / pay-on-site --------------> confirmed
request -+- no approval -> pending -+
         |                         +- pay online -> (WC order paid) -> confirmed
         |
         +- approval -> awaiting_approval -+- approve, free/onsite ---> confirmed
                                          +- approve, paid -> awaiting_payment
                                          |                    +- paid -> confirmed
                                          +- reject ---------------> rejected

confirmed -> completed | no_show | cancelled
any held state, TTL elapsed -> expired
```

Three hold classes, each with its own TTL, all writing `hold_expires_at`:
`pending` = checkout (default 15 min) , `awaiting_approval` = owner decision (default 48 h) ,
`awaiting_payment` = payment link validity (default 24 h).

Transitions go through `Application/`, never a raw repository update, and every one writes an
audit row. A chain requiring approval on *any* segment requires approval as a whole.

On approval timeout the service's `on_approval_timeout` decides: `expire` (release the slot) or
`auto_approve` (proceed as if the owner said yes). Owners get a nag at 25/50/75% of the window,
and every approval email carries signed approve/reject URLs; each opens a confirm page requiring
one human click before the decision executes (scanner-proof - the link itself is inert, so an
email security scanner that GETs every link in the message never trips it), so the decision never
requires a wp-admin login.

### 2.4 How availability is computed

**Never query per candidate start time.** For a window `[from, to]`:

1. Quantize to `granularity_min` (default 5; a day is 288 bits).
2. Build the bitmasks per `(resource, day)`: an **open** mask (availability rules minus exceptions)
   and a **not-booked** mask (everything minus blocking items, section 2.1). They stay separate because
   they constrain different spans - see the buffer note in step 4. **One** range query for the
   whole window across all resources - not one per slot, not one per resource-day.
3. "Resource *r* can start a *d*-minute segment at slot *s*" = `runs(mask, n)` with
   `n = ceil(d / granularity)`, computed by shift-and (`f &= f >> 1`, log2n times), never by
   looping over slots - applied to both masks per step 2: the segment start is valid iff
   `runs(busyfree_r, bb+d+ba)` holds at `s - bb` AND `runs(open_r, d)` holds at `s`.
4. **Chain segments never overlap in time, so the staff choice for one segment does not constrain
   another.** Feasibility therefore decomposes - no backtracking, no search:

   ```
   feasible(t) = INTERSECT over segments i of  shift( UNION over eligible staff r of startmask(r, i), -offset_i )
   offset_i = sum (duration + processing) of all preceding segments
   (buffers shape each segment's staff occupancy - [start - buf_before, start + duration + buf_after) - not the customer timeline;
    buffers contend with other bookings only - they may extend outside opening hours, so the
    open mask constrains [start, start + duration) while the not-booked mask constrains the wider
    block range, which is exactly what section 2.2 re-validates under lock)
   ```

   The optional "same staff for the whole chain" preference is the same expression with the union
   moved outside the intersection. Both are linear.
5. **Availability math and staff assignment are separate steps.** Concrete staff are chosen only
   at hold time (customer pick, else least-loaded), then re-validated under lock per section 2.2.
6. Masks cache per `(resource, day)`, keyed on `reservant_resource_days.rev`, which is bumped
   inside the write transaction. The cache is an optimisation only: a cold cache must produce
   byte-identical results, and tests run both ways.

---

## 3. Layout

```
reservant/
+-- reservant.php              # header, guards (PHP/WP version), bootstrap only
+-- uninstall.php              # honors reservant_delete_data_on_uninstall (default: keep)
+-- composer.json  package.json
+-- src/
|   +-- Plugin.php             # container + hook registration, nothing else
|   +-- Domain/                # PURE PHP. No WP functions, no $wpdb, no globals.
|   |   +-- Availability/      # SlotGenerator, AvailabilityRule, Exceptions, Buffers
|   |   +-- Chain/             # ChainResolver - segment->staff assignment search
|   |   +-- Booking/           # Booking, BookingItem, BookingRequest, CancellationPolicy
|   |   +-- Seating/           # SeatMap, SeatGrid, SeatSelection
|   |   +-- Money/             # Money (integer minor units), Currency
|   |   +-- Enum/              # BookingStatus, ServiceType, PaymentMode, HoldClass
|   +-- Application/           # use cases: HoldBooking, ConfirmBooking, ApproveBooking,
|   |                          #   RejectBooking, CancelBooking, RescheduleBooking, ExpireHolds
|   +-- Infrastructure/
|   |   +-- Db/                # Repositories, Schema, Migrations, LockManager, TransactionRunner
|   |   +-- Scheduler/         # Action Scheduler wrapper
|   +-- Rest/                  # controllers: sanitize in, permission checks, DTO out
|   +-- Admin/                 # menus, settings, SPA mount, approval inbox
|   +-- Frontend/              # shortcode, block registration, template loader
|   +-- Notifications/         # mailers, templates, IcsBuilder
|   +-- Integrations/
|   |   +-- WooCommerce/       # the ONLY place WC symbols may appear
|   +-- Licensing/             # LicenseManager interface + AlwaysValidLicense stub
+-- assets/src/{booking,admin}/  # React
+-- templates/                 # overridable via theme: yourtheme/reservant/*.php
+-- languages/  tests/{Unit,Integration,e2e}  bin/
```

`Domain/` is pure so the availability, chain and policy engines run in millisecond unit tests with
no WordPress bootstrap. Keep it that way - a single `get_option()` in there and it's over.

---

## 4. Schema

All datetimes are `DATETIME` in **UTC**. All money is **integer minor units** (`price_minor`) plus
a 3-letter currency. No floats, ever.

| Table | Purpose / key columns |
|---|---|
| `reservant_services` | `type` (appointment\|event), `duration_min`, `processing_time_min`, `buffer_before/after`, `capacity`, `seat_map_id` (NULL = capacity-only), `price_minor`, `currency`, `payment_mode` (free\|online\|onsite), `requires_approval`, `approval_hold_hours`, `on_approval_timeout` (expire\|auto_approve), `cancel_window_hours`, `reschedule_window_hours`, `lead_time_min`, `horizon_days`, `wc_product_id` |
| `reservant_resources` | staff. `wp_user_id` (nullable), `name`, `email`, `status` |
| `reservant_service_resource` | which staff perform which service |
| `reservant_availability_rules` | recurring weekly windows per resource: `weekday`, `start_time`, `end_time`, `valid_from/to` |
| `reservant_availability_exceptions` | date overrides / blackouts; `resource_id` NULL = business-wide |
| `reservant_occurrences` | events only: `service_id`, `start_utc`, `end_utc`, `capacity`, `booked_seats`, `status` |
| `reservant_seat_maps` | `name`, `spec` (source text) |
| `reservant_seats` | `seat_map_id`, `row_label`, `seat_label`, `sort_row`, `sort_col`, `kind` (seat\|aisle\|blocked) |
| `reservant_resource_days` | mutex rows: PK `(resource_id, day_utc)`, plus `rev` (bumped in-transaction on any write touching that resource-day - the free/busy mask cache key). |
| `reservant_bookings` | **container.** `uuid`, `status`, `hold_expires_at`, `hold_class`, customer fields, `total_minor`, `currency`, `payment_mode`, `requires_approval`, `approved_at`, `approved_by`, `rejection_reason`, `wc_order_id`, `manage_token_hash`, timestamps |
| `reservant_booking_items` | **the unit that consumes capacity.** `booking_id`, `sort`, `service_id`, `resource_id`, `occurrence_id`, `start_utc`, `end_utc`, `block_start_utc`, `block_end_utc` (denormalized staff-occupied range incl. buffers - immune to later service edits), `processing_ends_utc`, `seats`, `seat_claim` (NULLable), `price_minor` |
| `reservant_booking_meta` | custom intake fields |
| `reservant_audit_log` | every status transition: `booking_id`, `actor`, `action`, `payload_json` |

Required indexes:
`booking_items(resource_id, block_start_utc, block_end_utc)` , `booking_items(booking_id)` ,
`booking_items(occurrence_id)` , **unique** `booking_items(occurrence_id, seat_claim)` ,
`bookings(status, hold_expires_at)` , **unique** `bookings(uuid)` ,
`occurrences(service_id, start_utc)`.

Items carry no status - they inherit the container's. Availability joins items to their booking
and applies `is_blocking()` (section 2.1). That join is on the hot path: index it and check the query
plan, don't assume. Grid-seat bookings create ONE item per claimed seat (seats=1, seat_claim=seat
id); open-capacity event bookings are a single item with seats=N.

Migrations are versioned and run through `Infrastructure/Db/Migrations` on activation and on
`RESERVANT_VERSION` change. `dbDelta` for creates; explicit SQL for data migrations.

---

## 5. REST API (`/wp-json/reservant/v1`)

| Route | Notes |
|---|---|
| `GET /services`, `GET /services/{id}` | public |
| `GET /availability` | body describes the **whole chain**: ordered `items[]` of `{service_id, resource_id\|any}`, plus `from`, `to`, `tz`. Returns start times for which a complete valid chain exists. Advisory. |
| `GET /occurrences/{id}/seats` | seat grid + which seats are currently blocking |
| `POST /holds` | takes `items[]` (with optional `seat_ids`), creates the container + items under lock. Returns `uuid`, `status`, `hold_expires_at`. `409` names the failing segment. **Rate-limited.** |
| `DELETE /holds/{uuid}` | releases immediately |
| `POST /bookings/{uuid}/confirm` | free / pay-on-site path |
| `GET\|POST /bookings/{uuid}` | guest self-service, requires `token` |
| `POST /bookings/{uuid}/cancel` , `/reschedule` | policy-checked. Reschedule moves **all** segments as one atomic release + re-hold; partial success is impossible. |
| `GET /admin/bookings` | search/list; `reservant_manage_bookings` |
| `POST /admin/bookings` | the owner's manual booking - lands on `confirmed` directly, never a hold; `reservant_manage_bookings` |
| `GET /admin/bookings/{uuid}` | detail plus the audit trail; `reservant_manage_bookings` |
| `POST /admin/bookings/{uuid}/approve` , `/reject` | `reservant_approve_bookings` alone is enough - a staff member without `reservant_manage_bookings` is scoped to bookings with an item on their own resource; approve issues the payment link when the booking is paid |
| `POST /admin/bookings/{uuid}/cancel` , `/no_show` , `/complete` | manager overrides; `reservant_manage_bookings` |
| `GET /admin/calendar` | the week/day grid; `reservant_manage_bookings` or `reservant_view_own_calendar` |
| `GET /admin/availability` | chain feasibility for the manual-booking drawer, same request shape as the public `GET /availability`; `reservant_manage_bookings` or `reservant_view_own_calendar` |
| `GET\|POST /admin/services`, `GET\|PUT\|DELETE /admin/services/{id}` | service catalog CRUD; `reservant_manage_settings` |
| `GET\|POST /admin/resources`, `GET\|PUT\|DELETE /admin/resources/{id}` | staff CRUD - identity, linked WP user, services performed, weekly rules; `reservant_manage_settings` |
| `POST\|DELETE /admin/resources/{id}/exceptions` | one resource's own blackout dates; `reservant_manage_settings` |
| `GET\|POST\|DELETE /admin/exceptions` | business-wide blackout dates (`GET` also lists a single resource's own, via `resource_id`); `reservant_manage_settings` |
| `GET\|POST /admin/occurrences`, `PUT\|DELETE /admin/occurrences/{id}` | event occurrences; `reservant_manage_settings` |
| `GET\|POST /admin/seat-maps`, `GET\|PUT\|DELETE /admin/seat-maps/{id}` | seat grid specs; `reservant_manage_settings` |
| `GET\|PUT /admin/settings` | plugin settings; `reservant_manage_settings` |

Auth: `X-WP-Nonce` for logged-in/admin; for guests a **signed manage token** - random secret in the
email link, only its hash stored (`manage_token_hash`), compared with `hash_equals()`, expiring
a fixed period after the booking ends.

Sanitize at the REST boundary, escape at output, `$wpdb->prepare()` always. Domain objects never
see `$_POST`. Every route declares a real `permission_callback` - `__return_true` is only
acceptable on genuinely public reads.

---

## 6. WooCommerce bridge

**No WC class, function, or constant may be referenced outside `src/Integrations/WooCommerce/`.**
That namespace only loads when `class_exists( 'WooCommerce' )`. Everything else talks to
`PaymentProvider`; with WC absent the null provider is used and `online` services degrade to
`onsite` with an admin notice.

- Each service with `payment_mode=online` mirrors to a **virtual WC product** (`wc_product_id`).
  Reservant's service is the price source of truth; the WC product is a mirror, resynced on save.
- **One WC order per booking, one line item per booking item.** The order carries `booking_uuid`;
  cart item meta carries it too.
- Non-approval flow: hold -> cart -> order paid -> `ConfirmBooking`.
- Approval flow: **no order exists until approval.** On approve, create the order and email
  `$order->get_checkout_payment_url()`. The booking moves to `awaiting_payment` with its own TTL.
- Order cancelled/failed/refunded, cart item removed, or any hold TTL elapsed -> release.
- **The hold TTL is the authority, not the cart and not the payment link.** A cart or link that
  outlives its hold loses the slot; checkout must re-validate under lock and fail loudly rather
  than silently overbook.
- Taxes, invoicing and refunds are WooCommerce's job. Cancellation flags the order for the owner;
  the plugin never issues a refund by itself in v1.

---

## 7. Conventions

- **Time:** `DateTimeImmutable` with an explicit `DateTimeZone`, always. Never `date()`,
  `strtotime()`, or a naive string. Convert at the edges: UTC in the DB, site/user tz in the UI.
  Test across a DST boundary - that's where slot generation breaks.
- **Money:** integers. Chain total = sum of item prices. Format only at the presentation layer.
- **Chain resolution:** `ChainResolver` takes free/busy masks in and returns feasible start times
  by mask algebra (section 2.4) - it does not search, and it does not touch the DB. Assignment of
  concrete staff is a separate, deterministic step at hold time, so failures reproduce in tests.
- **Hooks:** actions `reservant/booking/held`, `/approved`, `/rejected`, `/confirmed`,
  `/cancelled`, `reservant/hold/expired`; filters `reservant/availability/slots`,
  `reservant/chain/candidates`, `reservant/booking/can_cancel`, `reservant/email/{key}/args`.
  Pass DTOs, not arrays.
- **Capabilities:** custom caps (`reservant_manage_bookings`, `reservant_approve_bookings`,
  `reservant_manage_settings`, `reservant_view_own_calendar`) mapped on activation, plus a
  `reservant_staff` role that can approve bookings assigned to them. Never check `manage_options`.
- **Type safety:** PHPStan level 6+ on `src/` and TypeScript `strict` on `assets/src/` are CI
  gates, alongside fallow static analysis for the JS/TS surface. New code may not lower either bar.
- **i18n:** every user-facing string translatable against `reservant`; JS via `wp.i18n` +
  `wp_set_script_translations()`. No concatenation of translated fragments.
- **Scheduling:** Action Scheduler only (bundled via Composer, works without WC). Never bare
  `wp_cron` for reminders, hold expiry, or approval-nag emails - it doesn't fire on low-traffic sites.
- **Privacy:** register GDPR exporter and eraser for customer bookings.
- **Uninstall:** keep data by default; purge only if the user opted in.

---

## 8. Commands

```bash
composer install && npm install
npm run start                 # watch build
npm run build                 # production build
npx wp-env start              # local WP + DB

composer test:unit            # Domain/ - no WP bootstrap, fast
composer test:integration     # repositories, REST, locking (needs wp-env)
npm run test:e2e              # Playwright booking flow
composer lint                 # PHPCS / WPCS
composer stan                 # PHPStan level 6+
```

CI runs all of the above. Concurrency tests are not allowed to be marked skipped.

---

## 9. Build order and release lines

**The schema ships whole in v1.0.** `booking_items`, `requires_approval`, `seat_claim`, the seat
tables and `resource_days.rev` all land immediately, even where no UI uses them yet - later
releases are then purely additive and need no migration. The expensive part of these features is
UI, not data.

**v1.0 - the salon core.** Appointments + chains + payments + notifications.

1. **P0** Scaffolding: plugin header, autoloader, container, CI, lint/stan gates.
2. **P1** `Domain/` - slot generation (rules, exceptions, buffers, processing gaps, lead time,
   horizon), free/busy masks, `ChainResolver`, cancellation policy, money, seat grids.
   Unit-tested to death, including DST. No DB yet.
3. **P2** Schema (complete), migrations, repositories, `LockManager`, `TransactionRunner`.
4. **P3** The hold protocol + REST holds/confirm/cancel, with concurrency tests (single slot,
   opposing-order chains, contested seats). **The riskiest work - done before any UI exists.**
5. **P4** Admin: services, staff, availability, calendar, manual booking.
6. **P5** Booking widget (shortcode + block): chain builder, staff picker, guest flow,
   magic-link manage page.
7. **P6** Notifications: emails, `.ics`, reminders, hold-expiry sweeper.
8. **P7** WooCommerce bridge.
9. **P8** Licensing stub, packaging, docs.

**v1.1 - approval queue.** Statuses and columns already exist. Adds the admin inbox, signed
approve/reject links with a one-click confirm page, nag + timeout jobs, and the approval ->
payment-link path in the bridge.

**v1.2 - assigned seats.** Seat picker in the widget. The admin builder is a **text spec**
("rows A-J, 12 per row, aisle after 6"), not a drag-and-drop canvas - identical data model, a
fraction of the work. A canvas editor happens only if customers actually ask for it.

Ship v1.0 to one real salon and watch it run before starting v1.2.

---

## 10. Assumptions made without asking - correct if wrong

- Single business location; site timezone is the business timezone.
- Default TTLs: checkout 15 min, approval 48 h, payment link 24 h - all filterable per service.
- Slot granularity defaults to 5 minutes; durations, buffers and processing times are rounded up
  to a multiple of it. Changing granularity after bookings exist is not supported.
- `on_approval_timeout` defaults to `expire`; `auto_approve` is opt-in per service.
- Chain segments are ordered and run forward in time; no user-reorderable or parallel segments.
- Processing gaps always release the staff member (no per-service opt-out in v1).
- A chain is priced as the sum of its segments; no bundle discounts.
- Seats are undifferentiated in price; no adjacency guarantee when booking several at once
  (the picker suggests adjacent seats, but nothing enforces it).
- An event either uses a seat map or plain capacity - never both, and it can't switch once booked.
- Approval decisions are made by admins or by the staff member assigned to the booking.
- Currency comes from WooCommerce when active, otherwise a plugin setting.
- Multisite is untested and unsupported; it must not fatal, but nothing more is promised.

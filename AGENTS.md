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
| PHP | **8.1+**, typed, namespaced, Composer PSR-4. WP 6.6+ (the built bundles depend on the `react-jsx-runtime` core script, registered in 6.6; on older WP the dependency is silently dropped and the widget renders nothing). |
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

The block above is the HOLD (insert) shape. The four guarded **state transitions** - cancel,
approve, reject, expire - run the same protocol with a compare-and-set in place of the insert, and
share one implementation in `Application\GuardedWrite`: mutex rows outside the transaction, lock in
sorted order, re-read under the mutex, guard, compare-and-set on the status read there, release seat
claims where the target status frees them, bump `rev`, audit, commit, and only then fire the hook.
A new transition of that shape goes through it rather than transcribing the sequence again;
`HoldBooking` (insert plus nested reap) and `RescheduleBooking` (delete-then-insert behind a key-set
guard) are different acts and stay outside it.

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
+-- uninstall.php              # honors reservant_settings['purge_on_uninstall'] (default: keep)
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
|   |                          #   GuardedWrite: the shared section-2.2 state transition
|   |   +-- Payment/           # PaymentProvider seam, NullPaymentProvider, Providers (resolution)
|   +-- Infrastructure/
|   |   +-- Db/                # Repositories, Schema, Migrations, LockManager, TransactionRunner
|   |   +-- Scheduler/         # Action Scheduler wrapper
|   +-- Rest/                  # controllers: sanitize in, permission checks, DTO out
|   +-- Admin/                 # menus, settings, SPA mount, approval inbox
|   +-- Frontend/              # shortcode, block registration, template loader
|   +-- Notifications/         # mailers, templates, IcsBuilder
|   +-- Integrations/
|   |   +-- WooCommerce/       # the ONLY place WC symbols may appear
|   +-- Licensing/             # LicenseManager seam + Providers (resolution, filterable),
|                              #   LicenseState/LicenseStatus, LicenseRecord (the
|                              #   reservant_license row + grace arithmetic), SiteDomain
|                              #   (the binding), LocalKeyLicense (stub validator)
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
| `GET /availability` | the query string describes the **whole chain**: `items` (a JSON list of `{service_id, resource_id\|null}`), `from`, `to`, optional `same_staff` and `tz`. Appointments answer the start times for which a complete valid chain exists; an EVENT service answers its occurrences with remaining places instead. Advisory either way. |
| `GET /occurrences/{id}/seats` | seat grid + which seats are currently blocking |
| `POST /holds` | takes `items[]` (with optional `seat_ids`), creates the container + items under lock. Returns the booking (`uuid`, `status`, `hold_expires_at`, items) plus `manage_token` - the guest's only credential, shown exactly once, in a `no-store` response - and, for a hold that cannot be confirmed until it is paid, `checkout_url`: the section 6 entry link, built from that same once-shown token because this is the only moment it exists. `409` names the failing segment. **Rate-limited.** |
| `DELETE /holds/{uuid}` | releases immediately; requires `token` |
| `POST /bookings/{uuid}/confirm` | free / pay-on-site path; `token` or `reservant_manage_bookings` |
| `GET /bookings/{uuid}` | guest self-service read, requires `token` (or `reservant_manage_bookings`) |
| `POST /bookings/{uuid}/cancel` , `/reschedule` | policy-checked. Reschedule moves **all** segments as one atomic release + re-hold; partial success is impossible. |
| `GET /admin/bookings` | search/list; `reservant_manage_bookings` |
| `POST /admin/bookings` | the owner's manual booking - lands on `confirmed` directly, never a hold; `reservant_manage_bookings` |
| `GET /admin/bookings/{uuid}` | detail plus the audit trail; `reservant_manage_bookings` |
| `POST /admin/bookings/{uuid}/approve` , `/reject` | `reservant_approve_bookings` alone is enough - a staff member without `reservant_manage_bookings` is scoped to bookings with an item on their own resource. Approve lands the booking on `confirmed` for a free/onsite service - and for an `online` one with no provider able to take money (the section 6 degrade). An `online` service with a live provider lands on `awaiting_payment` instead: the WC order is created after the transition commits, the customer is emailed the payment link, and the slot stays held for `payment_ttl_hours`, after which the ordinary hold sweeper reclaims it |
| `POST /admin/bookings/{uuid}/cancel` , `/no_show` , `/complete` | manager overrides; `reservant_manage_bookings` |
| `GET /admin/calendar` | the week/day grid; `reservant_manage_bookings` or `reservant_view_own_calendar` |

**What a caller may see of a booking is `Rest\BookingPayload`'s to decide, on every surface.** It is
a whitelist: each field that reaches the wire is named there, so a column added to
`reservant_bookings` is invisible until someone classifies it, and `BookingPayloadTest` reads the
live schema and fails on an unclassified one rather than waiting for it to leak. Customer contact
details (`customer_email`, `customer_phone`) require `reservant_manage_bookings` - approving a
booking or viewing a calendar does not qualify - and a guest holding a signed manage token receives
them because the booking is their own. `id` and `manage_token_hash` reach nobody.
| `GET /admin/availability` | chain feasibility for the manual-booking drawer, same request shape as the public `GET /availability`; `reservant_manage_bookings` or `reservant_view_own_calendar` |
| `GET\|POST /admin/services`, `GET\|PUT\|DELETE /admin/services/{id}` | service catalog CRUD; `reservant_manage_settings` throughout, except the collection `GET`, which also answers `reservant_manage_bookings` (the manual-booking drawer reads the catalog) |
| `GET\|POST /admin/resources`, `GET\|PUT\|DELETE /admin/resources/{id}` | staff CRUD - identity, linked WP user, services performed, weekly rules; `reservant_manage_settings` throughout, except the collection `GET`, which also answers `reservant_manage_bookings` |
| `POST\|DELETE /admin/resources/{id}/exceptions` | one resource's own blackout dates; `reservant_manage_settings` |
| `GET\|POST\|DELETE /admin/exceptions` | business-wide blackout dates (`GET` also lists a single resource's own, via `resource_id`); `reservant_manage_settings` |
| `GET\|POST /admin/occurrences`, `PUT\|DELETE /admin/occurrences/{id}` | event occurrences; `reservant_manage_settings` |
| `GET\|POST /admin/seat-maps`, `GET\|PUT\|DELETE /admin/seat-maps/{id}` | seat grid specs; `reservant_manage_settings` |
| `GET\|PUT /admin/settings` | plugin settings; `reservant_manage_settings` |
| `GET\|POST\|DELETE /admin/license` | the site's license: read the status, activate a key, deactivate. `reservant_manage_settings`, and deliberately NOT the license gate below - this is the way back. `POST` takes `key`; all three answer the same payload (`Rest\Admin\LicensePayload`): `state`, `active`, `masked_key`, `domain`, `last_checked_at`, `grace_ends_at`. The plaintext key never crosses the wire in either direction of a response |

**License enforcement.** An unlicensed site (`Licensing\LicenseStatus::isActive()` false - so
`inactive`, `invalid` or `domain_mismatch`; `grace` counts as licensed) loses **configuration
writes and nothing else**.

FROZEN: creating, editing and deleting services, staff/resources, availability rules and blackout
exceptions, occurrences, seat maps, and settings. Every one of those verbs is on
`Rest\Admin\AdminGuard::configureSite()` - the `reservant_manage_settings` capability plus an
active license - and the refusal is a `403` whose code is `reservant_license_required`, whose
message is `license_required`, and whose `data.state` and `data.detail` name which of the three
situations it is and where to fix it.

NEVER FROZEN, under any circumstance:

- **Every public and guest route.** Search availability, hold, confirm, pay, cancel, reschedule. A
  billing lapse at the salon must never turn away the salon's customers.
- **The entire admin booking lifecycle** - approve, reject, cancel, reschedule, manual booking,
  no-show, complete. This one is not a convenience: `awaiting_approval` bookings sit on a TTL and
  `ExpireHolds` reclaims them, so a frozen approval queue would let held bookings expire on their
  own and turn away paying customers because of an unpaid invoice, silently, while the owner
  watched. That is strictly worse than an unlicensed site being unable to edit its service list.
- **Every READ.** The owner still sees their calendar, their bookings, their catalog and their
  settings. `GET /admin/settings` in particular shares its permission callback with the settings
  WRITE, and gating it would lock a lapsed owner out of the very screen where they enter their key.
- **The license routes themselves.**

The admin SPA gets the current status in its bootstrap (`window.reservantAdmin.license`) in the
same shape `GET /admin/license` answers with, so a configuration screen can render itself read-only
without a round trip first. It is `null` for a caller without `reservant_manage_settings`.

Auth: `X-WP-Nonce` for logged-in/admin; for guests a **signed manage token** - random secret in the
email link, only its hash stored (`manage_token_hash`), compared with `hash_equals()`. The token
expires 30 days after the LAST segment ends, filterable via `reservant/manage_token_days_after`
(zero switches expiry off). It has no stored expiry of its own, so the lifetime is derived from the
booking in `Routes::guard()` - the single verifier - and an expired link is refused in the same
shape as a wrong one, adding no way to tell the two apart. What the expiry protects is the contact
details on `GET /bookings/{uuid}`: the lifecycle routes are already self-limiting, since a finished
booking cannot be cancelled or rescheduled, but a link that lives in a mailbox forever would
otherwise disclose the customer's email and phone to whoever holds that mailbox years later.

The plaintext secret exists only inside `HoldBooking::execute()`, so exactly two moments can put it
in an email and both do: the `held` hook (grafted onto the snapshot BEFORE the action fires - it
used to be grafted after, so no listener could ever see it) and `ConfirmBooking`, which echoes back
the token the guest just presented after re-verifying it against that booking's own hash.
`BookingSnapshot::toArray()` refuses to emit it in either case.

Sanitize at the REST boundary, escape at output, `$wpdb->prepare()` always. Domain objects never
see `$_POST`. Every route declares a real `permission_callback` - `__return_true` is only
acceptable on genuinely public reads.

---

## 6. WooCommerce bridge

**No WC class, function, or constant may be referenced outside `src/Integrations/WooCommerce/`.**
That namespace only loads when `class_exists( 'WooCommerce' )`, and that call itself appears in
exactly one place: `Application\Payment\Providers`, which decides who takes the money. Everything
else - use cases, REST controllers, the admin - talks to the six methods of
`Application\Payment\PaymentProvider` (`isAvailable`, `syncService`, `createOrder`, `paymentUrl`,
`checkoutUrl`, `flagOrder`) and never learns whose order it holds. The resolved provider is filterable at
`reservant/payment_provider`, which is how a site supplies its own gateway - and how the test suite
reaches the WC-absent path on a machine where WooCommerce is installed.

**Absence is a supported configuration, not an error.** `NullPaymentProvider` answers every method
instead of throwing: `ConfirmBooking` stops refusing `online_payment_required`, `ApproveBooking`
keeps landing approvals on `confirmed` rather than stranding them in a state nothing could ever
pay for, and an `online` service behaves as `onsite` - the booking completes and the owner takes
the money in person. Silent to the guest, loud to the owner: `Admin\PaymentNotice` warns in
wp-admin whenever active `online` services exist and no provider does, because from the outside the
degrade is indistinguishable from bookings that simply stopped being paid for.

- **The service mirror.** Each service with `payment_mode=online` mirrors to a **virtual,
  catalog-hidden WC product** (`services.wc_product_id`), rewritten by
  `WooPaymentProvider::syncService()` on every save through `ServicesAdminController` - which owns
  that column; no request may set it. Reservant's price is the source of truth and nothing is ever
  read back out of the mirror. Virtual, so WooCommerce skips shipping and stock; hidden, because a
  product page "Add to cart" would sell time nobody reserved. Leaving `online` mode **trashes** the
  mirror rather than purging it - past orders still render their line items from the product - and
  a product without `_reservant_service_id` is never touched, so a shop that reused the id keeps
  it. A mirror failure never fails the save: it is reported on `reservant/error` and repaired by
  the next save, or on demand when a cart line needs a product to hang on.
- **One WC order per booking, one line item per booking item.** The order carries the booking uuid
  (`_reservant_booking_uuid`), cart lines carry it too, and each order line names the
  `booking_items` row it settles. Every line is priced from the BOOKING, never from the mirrored
  product: the price was fixed when the hold was taken, and a service repriced since must not
  silently reprice a total the customer already saw.
- **Non-approval flow: hold -> cart -> order paid -> `ConfirmBooking`.** `CartBridge` boards a live
  `pending` hold from the front-channel link `?reservant_checkout={uuid}&token={manage_token}` -
  the guest's manage token is the credential here as everywhere else (section 5), and a missing
  booking and a wrong token give the same answer, so the entry is no existence oracle. **The guest
  gets that link from the `POST /holds` 201, as `checkout_url` beside the manage token**: the
  plaintext credential exists only while `HoldBooking::execute()` runs, so no later read could
  build it and no email covers the gap (`BookingEmails` deliberately says nothing about a
  `checkout` hold). It appears for exactly the holds whose confirm would answer 402 -
  `ConfirmBooking::requiresOnlinePayment()` is the one implementation of that question - and only
  where the provider has somewhere to send them, which is `CartBridge::boardable()`'s ruling and
  the same one the door itself enforces. The widget's review step then reads "Continue to payment"
  and goes there rather than pressing a confirm it knows is refused. Boarding
  empties the cart first (an order that also sold a shampoo would tie that shampoo's fate to the
  booking's), re-asserts the booking's prices on every totals run, and refuses quantity edits.
  Removing a booking line releases the whole booking and sweeps its siblings out of the cart -
  cancellation granularity is the container (section 1), and half a chain must never reach
  checkout.
- **Approval flow: no order exists until approval.** On approve, `ApproveBooking` lands an `online`
  booking on `awaiting_payment` with `hold_expires_at = now + payment_ttl_hours` in the same
  compare-and-set, creates the order AFTER that commits, and fires `reservant/booking/payment_due`
  with the pay-for-order URL, which `Notifications\ApprovalEmails` mails as `booking_payment_due`.
  All of it post-commit and unfailable: an order that cannot be created is reported on
  `reservant/error` and the payment TTL reclaims the seat - a committed approval never reports
  failure. `awaiting_payment` is a held status (section 2.1) and is already selected by the hold
  sweeper, so payment expiry needs no machinery of its own.
- **Order status is the ear.** `OrderObserver` listens on `woocommerce_order_status_changed` and
  nowhere else - every path money takes ends in a status change. Arrival at a paid status
  (`wc_get_is_paid_statuses()`, so a shop that widened the definition is honoured) confirms the
  booking; `cancelled`, `failed` or `refunded` releases it. A PARTIAL refund changes no status and
  deliberately releases nothing: that is the owner compensating a customer whose booking still
  stands. An order with no booking uuid on it is not ours and is ignored completely. Repeat
  deliveries are normal and are told apart from real failures by re-reading the booking.
- **The hold TTL is the authority, not the cart and not the payment link.** `CheckoutGuard`
  re-validates under the section-2.2 locks at every door into payment: classic checkout
  (`woocommerce_after_checkout_validation`), the Store API / block checkout
  (`woocommerce_store_api_cart_errors`), and the emailed pay-for-order link
  (`woocommerce_checkout_validate_order_before_payment`, `woocommerce_before_pay_action`, plus
  `before_woocommerce_pay_form` for the courtesy display). A cart or link that outlived its hold is
  refused before the gateway is reached. On the cart doors the guard also checks that the cart
  still says what the booking says - every item present, at the quantity it boarded with - so a
  tampered cart cannot pay for one seat and confirm three. It fails CLOSED: a check that cannot run
  refuses the payment, while carts and orders carrying no booking of ours are never touched, so a
  Reservant fault can never block a shop's ordinary sales.
- **Nothing in this namespace may throw at WooCommerce.** Every handler fires mid-checkout, mid
  webhook or mid order screen, outside any Reservant transaction; failures are caught, reported on
  `reservant/error` with the booking uuid as context, and swallowed.
- Taxes, invoicing and refunds are WooCommerce's job, and the plugin never issues a refund by
  itself in v1. `PaymentProvider::flagOrder()` is the seam for leaving the owner a note on the
  order (a note, deliberately not a refund), and `CancelBooking` calls it - post-commit, for every
  cancelled booking that carries a `wc_order_id`, with a note naming the booking and saying that the
  slot is released and nothing has been refunded. Unfailable like the approval's order write: a
  provider that throws is reported on `reservant/error` and never turns a released seat back into a
  failure. A booking with no order (free, onsite, never checked out) flags nothing, and provider
  availability is deliberately not consulted - `NullPaymentProvider::flagOrder()` is the right
  no-op for a site that deactivated WooCommerce with the stale id still on the row.

---

## 7. Conventions

- **Time:** `DateTimeImmutable` with an explicit `DateTimeZone`, always. Never `date()`,
  `strtotime()`, or a naive string. Convert at the edges: UTC in the DB, site/user tz in the UI.
  Test across a DST boundary - that's where slot generation breaks.
- **Money:** integers. Chain total = sum of item prices. Format only at the presentation layer.
- **Chain resolution:** `ChainResolver` takes free/busy masks in and returns feasible start times
  by mask algebra (section 2.4) - it does not search, and it does not touch the DB. Assignment of
  concrete staff is a separate, deterministic step at hold time, so failures reproduce in tests.
- **Hooks** (signatures read off the call sites in `src/`; the DTO rule is a split, not one
  sentence - ACTIONS pass a `BookingSnapshot` DTO, the POLICY FILTERS pass the booking row as
  an array, and both halves are deliberate):
  - Booking actions, every one `( BookingSnapshot $booking )`: `reservant/booking/held`,
    `/approved`, `/rejected`, `/confirmed`, `/cancelled`, `/rescheduled`, and the dynamic
    `reservant/booking/{outcome}` (`completed` | `no_show`, from `MarkBookingOutcome`).
  - `reservant/hold/expired` `( BookingSnapshot $booking )` - the reaped hold.
  - `reservant/approval/nag` `( BookingSnapshot $booking, int $percent )` - fired at 25/50/75%
    of the approval window (an ACTION; the P6 mailer listens here).
  - `reservant/booking/payment_due` `( BookingSnapshot $booking, string $url )` - fired by
    `ApproveBooking` after an `online` booking lands on `awaiting_payment` and its order exists;
    `$url` is the provider's pay-for-order link (the `approval/nag` two-argument shape - the URL
    is the provider's answer, not a column, so it cannot ride on the snapshot).
  - `reservant/error` `( \Throwable $e )`, at some sites plus a string context (booking uuid or
    mail key) - the diagnostics channel for swallowed failures.
  - Policy filters, both `( bool $allowed, array $booking, \DateTimeImmutable $nowUtc )` - the
    booking ROW, not a DTO: `reservant/booking/can_cancel`, `reservant/booking/can_reschedule`.
  - `reservant/availability/slots` `( list<\DateTimeImmutable> $starts,
    list<SegmentChoice> $segments, \DateTimeImmutable $fromUtc, \DateTimeImmutable $toUtc )` -
    last word on what the customer is offered.
  - `reservant/chain/candidates` `( list<int> $eligible, int $serviceId, int $segmentIndex )` -
    narrows which staff may serve a segment. Applied in `Application\SegmentEligibility`, the one
    module both the advisory read and the authoritative write draw their pool from, so a narrowing
    binds the hold and not only the offer. `$eligible` is active staff only.
  - `reservant/manage_token_days_after` `( int $days )`, default 30 - how long a guest's magic
    link outlives the last segment. Zero switches expiry off.
  - `reservant/holds/rate_limit` `( int $maxPerMinute )`, default 10.
  - `reservant/hold_ttl_minutes` `( int $minutes )` - the checkout hold TTL.
  - `reservant/reminder_lead_hours` `( int $hours )` - how long before the appointment the
    reminder goes out. The `reminder_lead_hours` setting is the default and this is the last word,
    the same shape as `hold_ttl_minutes` over `checkout_ttl_min`. Zero means no reminder.
  - `reservant/granularity_min` `( int $minutes )`, default 5.
  - `reservant/allow_direct_confirm` `( bool $allowed, array $booking )` - lets an `online`
    booking confirm without payment (the bridge's escape hatch).
  - `reservant/license_manager` `( Licensing\LicenseManager $manager )` - the one seam a real
    remote validator is dropped in through, so that no caller changes when the vendor is chosen.
    Resolved and memoized by `Licensing\Providers`, the exact shape `reservant/payment_provider`
    has in `Application\Payment\Providers`; a return value that is not a `LicenseManager` is
    ignored rather than fatal in both.
  - `reservant/booking/reminder` `( BookingSnapshot $booking )` - fired by
    `Infrastructure\Scheduler\Jobs::reminder()` once it has re-read the booking and confirmed it
    still stands. The timer is scheduled optimistically and cancelled best-effort; THIS re-read is
    what keeps a reminder off an appointment that is not happening.
  - `reservant/email/{key}/args` `( array{to,subject,body,attachments} $args, array $context )` -
    the last word on every message, per key. `attachments` is `filename => file CONTENTS`;
    `Notifications\Mailer` materializes and unlinks the temp files itself. A filter that returns
    only the original three keys leaves attachments UNCHANGED - absence means "unchanged", not
    "none" - so a site rewriting a subject cannot silently strip a guest's `.ics`. The keys are
    listed in `Notifications\EmailCatalog::KEYS`, and the owner's per-key off switch
    (`emails_off`) is honoured in `Mailer::send()` before this filter runs, because there is
    nothing to filter about a message that is not being sent.
- **Notifications:** `Notifications\Mailer` is the one seam every message passes through - it
  applies the per-key filter, honours the owner's `emails_off` switch, materializes and unlinks
  attachment temp files, and never throws (a broken transport degrades to a logged
  `reservant/error`). `Notifications\EmailCatalog` is the list of messages and their owner-facing
  labels; the admin Settings screen renders one checkbox per entry from the bootstrap rather than
  from a hard-coded list of its own. **Which holds get an acknowledgement is a rule, not an
  oversight:** a `checkout` hold is a guest still inside the widget, so mailing there would mail
  every abandoned checkout; an `approval` hold is a guest waiting on a human, and
  `approval_request` goes to the APPROVER, so without `booking_received` they hear nothing until
  someone decides; an admin-created booking has no hold at all and gets the confirmation instead.
  The same rule runs on expiry - an abandoned checkout lapsing is not news, a timed-out approval
  request is the guest's answer. Every listener runs AFTER its transition committed, so none of
  them may throw, and the post-commit re-reads they need are guarded and degrade to "skip the
  email" (see `ApprovalEmails::sendApproverEmail()` for the full pre-decision / post-commit split).
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
# One-time / after a pull
composer install && npm install
npm run build                 # production build - REQUIRED before any suite that renders wp-admin
npx wp-env start              # local WP + DB - REQUIRED before the integration, concurrency and e2e suites
                              #   (it also installs WooCommerce, which the bridge suite exercises for real)
                              #   `.wp-env.json`'s afterStart also LICENSES the dev site: configuration
                              #   writes are license-gated (section 5), so without it the admin SPA - and
                              #   the e2e smoke test that drives it - cannot create a service. The tests
                              #   environment is untouched; the integration suite says so per class
                              #   (`ReservantTestCase::licenseThisSite()`).
npx playwright install chromium   # once, before the first e2e run

npm run start                 # watch build (development)

# Gates, in the order CI runs them
composer lint                 # PHPCS / WPCS over src/ and bin/
composer stan                 # PHPStan level 6+ over src/
composer test:unit            # Domain/ + pure Application/ - no WP bootstrap, fast
composer test:integration     # repositories, REST, migrations, locking (needs wp-env)
npm run build                 # the bundles every later gate measures or serves
npm run size                  # byte budgets (widget.js, style-widget.css) + the externals contract
npm run tsc                   # TypeScript strict, no emit
npm run test:js               # Jest + Testing Library over assets/src
npm run fallow                # fallow static analysis, failing on its error-level findings
./bin/run-concurrency.sh      # parallel holds, opposing-order chains, contested seats (needs wp-env)
npm run test:e2e              # Playwright: admin smoke + the public booking flow (needs wp-env + a built bundle)

# The release artifact
composer package              # reservant-<version>.zip at the repository root, ready for wp-admin
                              #   "Add Plugin -> Upload": one top-level reservant/ holding
                              #   reservant.php, uninstall.php, README.md, readme.txt,
                              #   CHANGELOG.md, composer.json/lock, src/, a freshly built build/
                              #   and a --no-dev vendor/. Nothing else - the script copies an
                              #   explicit manifest rather than filtering the tree, so AGENTS.md,
                              #   tests/, assets/, bin/, docs/, node_modules/ and every tool
                              #   config are out by construction.
```

CI runs all of the above. Concurrency tests are not allowed to be marked skipped: `./bin/run-concurrency.sh`
is the command, and it must pass, not be commented out or `|| true`-d.

`composer package` (`bin/package-plugin.php`) is not a gate and CI does not run it; it is how the
zip a customer installs gets made. It runs `npm run build` itself rather than documenting it as a
prerequisite - a zip carrying last week's bundle installs cleanly and misbehaves with nothing to
point at - and it installs the production dependencies into a STAGING copy with an explicit
`--working-dir`, because a `composer install --no-dev` in this directory would delete the phpunit,
phpstan, phpcs and wpcs that four of the gates above run out of. The version is read out of
`reservant.php`, never passed in, and the plugin header disagreeing with `RESERVANT_VERSION` is a
refusal rather than a coin toss. Nothing is taken on trust: both `require` targets in
`reservant.php`, every asset the three enqueuers name, the staged autoloader resolving a real
class, and the finished archive re-opened and walked - any one of them failing means no zip is
written at all, and the previous one stays where it was.

**The three documents, and which one owns what.** `README.md` is developer-facing and stays that
way. `readme.txt` is the CUSTOMER's, in the WordPress readme format, and it ships - it is where a
shop owner reads what the plugin does and, in particular, what a lapsed license actually costs
(section 5's freeze list, in their language and not the spec's). `CHANGELOG.md` is the canonical
version history and ships beside it, because `readme.txt`'s `== Changelog ==` deliberately carries
only the CURRENT release and points there for everything earlier: the same history maintained in
two files drifts apart inside one release, so one of them is the record and the other is a pointer.
Neither claims a license the project has not chosen - `composer.json` says `proprietary`,
`reservant.php` carries no `License:` header, and there is no LICENSE file to point one at.

**The version lives in exactly two places, both in `reservant.php`:** the `Version:` plugin header
and `RESERVANT_VERSION`. `composer package` refuses to run when they disagree, and nothing else in
the repository - no test, no readme header, no package.json - restates the number, so that refusal
is the whole gate. Adding a third copy means extending `read_agreed_version()` to cover it.

`npm run fallow` is the enforcing form of `npx fallow --ci`: fallow writes SARIF but exits 0 even when
it has reported `level: "error"` findings, so the wrapper (`bin/fallow-gate.mjs`) reads the report and
fails the build on any of them. Running `npx fallow --ci` alone gates nothing.

---

## 9. Build order and release lines

**The schema ships whole in v1.0.** `booking_items`, `requires_approval`, `seat_claim`, the seat
tables and `resource_days.rev` all land immediately, even where no UI uses them yet - later
releases are then purely additive and need no migration. The expensive part of these features is
UI, not data.

**v1.0 - the salon core.** Appointments + chains + payments + notifications.

`[DONE]` means merged on the development branch and passing every gate in section 8.

1. **P0** `[DONE]` Scaffolding: plugin header, autoloader, container, CI, lint/stan gates.
2. **P1** `[DONE]` `Domain/` - slot generation (rules, exceptions, buffers, processing gaps,
   lead time, horizon), free/busy masks, `ChainResolver`, cancellation policy, money, seat
   grids. Unit-tested to death, including DST. No DB yet.
3. **P2** `[DONE]` Schema (complete), migrations, repositories, `LockManager`,
   `TransactionRunner`.
4. **P3** `[DONE]` The hold protocol + REST holds/confirm/cancel, with concurrency tests
   (single slot, opposing-order chains, contested seats). **The riskiest work - done before any
   UI exists.**
5. **P4** `[DONE]` Admin: services, staff, availability, calendar, manual booking.
6. **P5** `[DONE]` Booking widget (shortcode + block): chain builder, staff picker, guest flow,
   magic-link manage page.
7. **P6** `[DONE]` Notifications: `Notifications\BookingEmails` (the customer set, on
   `held`/`confirmed`/`rescheduled`/`cancelled`/`hold expired`/`reminder`),
   `Notifications\Calendar` (the `.ics`), `Notifications\Reminders` (the timer),
   `Notifications\EmailCatalog` (the switchable list), and the hold-expiry sweeper, which was
   already built and is verified rather than rebuilt - see below.
8. **P7** `[DONE]` WooCommerce bridge: the `Application\Payment\PaymentProvider` seam and the
   null provider behind it (with WooCommerce absent an `online` service degrades to `onsite` and
   `Admin\PaymentNotice` says so out loud), `Integrations\WooCommerce\WooPaymentProvider` (the
   mirrored virtual product, the one order per booking), `CartBridge` (hold -> cart -> order),
   `OrderObserver` (a paid order confirms, a dead one releases), `CheckoutGuard` (the hold
   re-validated under lock at every door into payment), and the approval -> `awaiting_payment` ->
   emailed payment link, whose window is `payment_ttl_hours` and whose expiry the existing hold
   sweeper already reclaims.
9. **P8** `[DONE]` Licensing, packaging, docs: `Licensing\LicenseManager` and the five-state
   `LicenseStatus` behind it (key activation bound to the site domain, a daily re-check, a
   14-day grace window because a validator that cannot answer means "unknown" and not
   "unlicensed"), `AdminGuard::configureSite()` freezing configuration WRITES and nothing else
   (see section 5), the Settings screen's License section, `composer package` and the shipped
   `readme.txt`/`CHANGELOG.md`.

**v1.1 - approval queue.** Statuses and columns already exist. Adds the admin inbox, signed
approve/reject links with a one-click confirm page, and nag + timeout jobs. The approval ->
`awaiting_payment` -> payment-link path is NOT among them: P7 built it, because an approval-gated
`online` service that can never take money is not a shippable half.

**v1.2 - assigned seats.** Seat picker in the widget. The admin builder is a **text spec**
("rows A-J, 12 per row, aisle after 6"), not a drag-and-drop canvas - identical data model, a
fraction of the work. A canvas editor happens only if customers actually ask for it.

Ship v1.0 to one real salon and watch it run before starting v1.2.

---

## 10. Assumptions made without asking - correct if wrong

- Single business location; site timezone is the business timezone.
- Default TTLs: checkout 15 min, approval 48 h, payment link 24 h. Only the approval window is
  per service: `services.approval_hold_hours`, with the site-wide `approval_ttl_hours` behind it.
  Checkout is the site-wide `checkout_ttl_min` with a site-wide filter
  (`reservant/hold_ttl_minutes`); the payment link is the site-wide `payment_ttl_hours` with
  neither a filter nor a per-service column. Making either of those two per service is a product
  decision nobody has taken.
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

# Changelog

Every released version of Reservant, newest first.

A version here is the value of the `Version:` header in `reservant.php`, which is also the number
WordPress shows on the Plugins screen. Dates are the day the version was cut.

There is no 0.5.1 and no 0.5.2. Neither was ever released; the project went from 0.5.0 to 0.5.3.

## 0.5.3 - 2026-08-20

Licensing, and the zip that carries it.

- **License activation.** A key is activated on the Settings screen and bound to this site's
  domain. The site re-checks it once a day in the background.
- **Five states, five different answers.** Active; grace period; not licensed; invalid; registered
  to another domain. The states are told apart because the fix is different in each one, and the
  Settings screen says which one you are in and what to do about it.
- **A failed re-check opens a 14-day grace period and pauses nothing.** A validator that cannot be
  reached means "unknown", not "unlicensed", and a site is not turned off over somebody else's
  outage. The next successful check clears the grace period by itself.
- **What an unlicensed site loses: changes to its setup, and nothing else.** Services, staff,
  availability, events, seat maps and settings stop accepting changes. Everything else keeps
  running - customers can still search, book, pay, cancel and reschedule, and the owner can still
  approve, reject, cancel, reschedule, complete and manually book. Reads are never blocked, so the
  Settings screen where the key is entered stays reachable.
- **Deactivation** unbinds this site from the license so the seat can be used somewhere else. It
  asks for confirmation first, and bookings are unaffected either way.
- **A cancelled booking that was paid for now leaves a note on its WooCommerce order** saying the
  slot is released and that nothing has been refunded. Reservant still never issues a refund by
  itself; the note is there so the money is a decision somebody makes rather than one nobody sees.
- **`composer package` builds the distributable zip.** One command produces
  `reservant-<version>.zip`, laid out the way the "Add Plugin -> Upload Plugin" screen expects, with
  the asset bundles rebuilt and the production dependency tree installed fresh. The archive is
  re-opened and inspected before it is written; if anything is missing, no zip is produced at all.

## 0.5.0 - 2026-08-19

Payments, through WooCommerce, and only if you want them.

- **A payment seam with WooCommerce behind it.** Services can be free, pay on arrival, or pay
  online. Pay online needs a payment plugin; WooCommerce is the one that ships supported.
- **A service set to pay online mirrors to a hidden, virtual WooCommerce product.** Reservant's
  price is the source of truth, and every order line is priced from the booking, so a service
  repriced later never silently reprices a total the customer already saw.
- **One order per booking.** A held booking becomes a cart, a paid order confirms the booking, and
  a cancelled, failed or refunded order releases the slot. A partial refund deliberately releases
  nothing: that is compensation for a booking that still stands.
- **Approve first, pay after.** For a service that needs approval, no order exists until you
  approve. The booking then moves to awaiting payment, the customer is emailed a payment link, and
  the slot stays held for as long as that link is good.
- **The hold is the authority, not the cart and not the payment link.** Every door into payment
  re-checks the booking under the same locks that took it, so a cart or a link that outlived its
  hold is refused before the gateway is ever reached, and a tampered cart cannot pay for one seat
  and confirm three.
- **With no payment plugin active, pay online behaves as pay on arrival.** Bookings still complete,
  and wp-admin says plainly that nothing is currently taking money - the degrade is deliberate, but
  it is not silent.
- Internally, the four guarded booking transitions - cancel, approve, reject, expire - moved onto
  one shared implementation instead of four transcriptions of the same locking sequence.

## 0.4.0 - 2026-08-19

Everything the plugin sends by email.

- **The customer email set:** request received, confirmed, moved, cancelled, request expired, and a
  reminder before the appointment.
- **The approver email set:** a booking needs your decision, and a reminder that one is still
  waiting.
- **Calendar invitations.** Every confirmation carries an `.ics` file, and a rescheduled booking
  moves the existing entry in the guest's calendar rather than adding a second one.
- **Reminders** run on Action Scheduler, with the lead time set once in Settings. The reminder
  re-reads the booking before it goes out, so a cancelled appointment never gets one.
- **An off switch per message.** Settings lists every message Reservant can send, each with its own
  checkbox.
- **The guest's manage link now expires** 30 days after the last appointment in the booking ends,
  so a link sitting in an old mailbox stops disclosing the customer's contact details.

## 0.3.2 - 2026-08-18

The booking widget itself.

- **The customer-facing flow:** service picker, chain builder, staff picker, day strip and slot
  grid, a live countdown on the hold, and recovery when somebody else takes the slot first.
- **The guest manage view**, reached from the link in the confirmation email: see the booking,
  cancel it, or reschedule it, with no account and no login.
- **Appearance controls** on the block - accent colour, corner radius, density - plus an error
  boundary and a message for visitors with JavaScript off.
- **Fixed: zero-decimal currencies were undercharged.** Prices in currencies with no minor unit
  (JPY and similar) were formatted as though they had one.
- Internally, one module each now decides what a caller may see of a booking, who may serve a
  chain segment, how money is formatted, and how lock keys are ordered.

## 0.3.1 - 2026-08-17

The widget's plumbing, and a batch of concurrency fixes.

- **The booking widget is placeable** as the "Booking Widget" block or the `[reservant_booking]`
  shortcode, with its scripts loaded only on pages that actually use it.
- **The magic-link manage page** works under both pretty and plain permalinks.
- **Reschedule moves the whole booking or none of it.** All segments are released and re-held as
  one atomic act under the union of the old and new locks; partial success is impossible.
- **Fixed:** several writes that a held lock was supposed to protect were not actually guarded by
  it, a lock that could not be taken was treated as though it had been, and a busy lock could not
  be told apart from a booking somebody else had already moved.
- **WordPress 6.6 is now the minimum.** The bundles depend on a core script registered in 6.6; on
  older WordPress the dependency was dropped silently and the widget rendered nothing.

## 0.3.0 - 2026-08-07

The whole admin.

- **Screens:** Calendar (week and day), Bookings with a detail drawer, Services, Staff,
  Events, Seat Maps and Settings.
- **Manual booking** from the calendar, through the same hold protocol customers go through, so an
  owner cannot double-book by hand.
- **The approval queue.** A service can require approval; the request holds the slot while the
  owner decides. Approve or reject arrives by email as a signed link that opens a confirmation page
  and needs one human click - the link itself does nothing, so an email security scanner cannot
  trip it, and no wp-admin login is needed. Reminders go out at 25%, 50% and 75% of the decision
  window, and when it closes the service decides whether the slot is released or approved
  automatically.
- **Service descriptions**, editable in admin and shown to customers.
- **Uninstall honours the "purge all data on uninstall" setting**, which is off by default.
- **The demo data seeder page was removed.** Seeding is a command-line tool now, and it refuses to
  run on a production site.

## 0.2.0 - 2026-08-05

- **Custom capabilities and a `reservant_staff` role**, mapped on activation, so booking work can
  be delegated without handing out administrator access. Reservant never checks `manage_options`.
- Schema upgrades now run automatically when the plugin version changes, not only on activation.

## 0.1.1 - 2026-08-05

- A Tools page for seeding demo data without shell access. (Removed again in 0.3.0.)

## 0.1.0 - 2026-08-04

First build: the booking engine.

- **The availability engine.** Weekly rules per staff member, date overrides and blackouts, buffers
  before and after, processing gaps, lead time and booking horizon, all computed as bitmask algebra
  rather than one query per candidate start time - and correct across daylight-saving boundaries.
- **Service chains.** "Cut, then colour" is one booking made of several segments, which may use
  different staff and may declare a gap between them during which the staff member is free.
  Feasibility is solved without searching, and the whole chain is booked atomically or not at all.
- **The hold protocol.** Every write takes the slot's row locks in a fixed global order, re-checks
  availability under those locks, and rolls the whole booking back if any segment fails. Expired
  holds are free by time comparison in the query itself, so correctness never depends on a
  background job having run.
- **The schema**, complete: bookings as containers of items, occurrences, seat maps and seats,
  resource-day mutex rows, and an audit row for every status transition.
- **The public REST API** (`reservant/v1`): service lookup, availability, seat grids, holds,
  confirm, cancel, and the guest's signed manage token.

=== Reservant ===
Tags: booking, appointments, scheduling, events, woocommerce
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1

Bookings for appointments, chains, and events - with or without WooCommerce.

== Description ==

Reservant sells time. It covers the two shapes a booking actually takes, and it does not oversell
either of them.

**Appointments** - a haircut, a set of nails, a consultation. You describe the service once, say who
performs it and when they work, and Reservant works out the bookable times from there.

**Events and seminars** - fixed occurrences on your calendar with a number of places. The customer
picks an occurrence and says how many places they want.

= What customers get =

* **No account, ever.** A customer picks a service, picks a time, fills in their details and they
  are booked. The slot is held while they finish - 15 minutes by default - so nobody loses a
  half-finished booking to somebody who clicked faster.
* **A confirmation email with a calendar invitation.** If the booking later moves, the invitation
  moves the existing calendar entry rather than adding a second one.
* **A private link in that email** to see the booking, cancel it, or move it - no login. The link
  stops working 30 days after the last appointment in the booking ends.
* **A reminder** before the appointment, at a lead time you choose.

= Chains, for when one appointment is several services =

"Cut, then colour" is one booking made of several segments. The segments can be performed by
different people, and a segment can declare a gap - colour developing, a treatment resting - during
which the staff member is free to serve somebody else. The whole chain is booked as one thing: all
segments or none, never half.

= What you get =

* **A calendar** in week and day form, and a bookings list with the full history of every booking.
* **Manual booking** straight from the calendar, through the same protocol customers go through, so
  a booking taken over the phone cannot double-book one taken on the web.
* **An approval queue, optional per service.** The request holds the slot while you decide - 48
  hours by default. You approve or reject from a link in the email, with one click and no wp-admin
  login. You are reminded at a quarter, a half and three quarters of the window, and if you never
  answer, the service decides for you: release the slot, or treat it as approved.
* **Staff who are not administrators.** Installing adds a **Reservant Staff** role that sees only
  its own calendar and can approve only the bookings assigned to it. Reservant's four permissions
  are its own rather than WordPress's administrator switch, so a role editor can also assemble a
  front-desk account that runs the bookings without being able to change your setup.
* **An off switch for every email** Reservant sends, in Settings.
* **Buffers, processing gaps, lead time and a booking horizon**, set per service.

= Money =

Every service is free, pay on arrival, or pay online.

Pay online needs a payment plugin. **WooCommerce is optional and it is the one that ships
supported.** With it active, a service set to pay online mirrors to a hidden WooCommerce product,
the held booking becomes a cart, a paid order confirms the booking, and a cancelled, failed or
refunded order releases the slot. If the service also needs your approval, no order exists until
you approve it; the customer is then emailed a payment link that lives exactly as long as the hold
does.

**Without a payment plugin, "pay online" behaves as pay on arrival.** Bookings still complete and
customers see nothing unusual - but wp-admin tells you plainly that nothing is currently taking
money, because from the outside that is indistinguishable from bookings that stopped being paid
for.

Reservant never issues a refund by itself. Cancelling a paid booking releases the slot and leaves a
note on the order saying so; what happens to the money is your decision.

= Time =

Times are stored in UTC and shown on your business's clock, so a customer in another timezone sees
your 09:00 rather than theirs. Daylight-saving changes are handled in the slot maths, not papered
over.

== Requirements ==

* WordPress 6.6 or newer
* PHP 8.1 or newer
* MySQL 5.7 or newer, or MariaDB 10.3 or newer, with InnoDB
* WooCommerce, only if you want to take payment online

Reservant is single-site. It does not fatal on a multisite network, but nothing beyond that is
promised.

== Installation ==

1. In wp-admin, go to **Plugins -> Add Plugin -> Upload Plugin**, choose the `reservant-x.y.z.zip`
   file you were sent, install it and activate it.
2. Go to **Reservant -> Settings** and enter your license key. Until you do, you can read
   everything but you cannot change your setup - see "Your license" below.
3. Go to **Reservant -> Staff** and add the people who perform the work, with their weekly hours
   and any days off.
4. Go to **Reservant -> Services** and add what you sell: how long it takes, what it costs, who can
   perform it, and whether it needs your approval before it is confirmed.
5. Put the widget on a page. Either add the **Booking Widget** block, or paste the shortcode
   `[reservant_booking]`.

The shortcode takes optional attributes to start the customer further along:
`[reservant_booking service="12" staff="3"]`.

You do not need a second page for customers to manage their bookings. The link in their email opens
one by itself, at `/booking/<booking id>?token=...`. (A `[reservant_manage]` shortcode exists too,
but it has to be given one particular booking's id and token as attributes, so it is for a bespoke
page rather than a general one.)

== Your license ==

Reservant is a premium plugin. Your key activates on one site and binds to that site's domain, and
the site re-checks it once a day in the background.

The Settings screen tells you which of five things is true, and what to do about each:

* **Active** - nothing to do.
* **Grace period** - the key could not be re-checked recently. Nothing is paused, and the next
  successful check clears it by itself. A check that cannot complete means "unknown", not
  "unlicensed", so an outage at either end does not turn your plugin off. The grace period lasts 14
  days.
* **Not licensed** - no key has been entered on this site, or one was removed. Enter yours.
* **Invalid** - the key was refused, or a grace period ran out. You need a good key.
* **Registered to another domain** - the key is active on a different site. Activate it here, or
  free it there first.

= What a lapsed license actually costs =

**Your bookings keep running and your customers are unaffected.** They can still search, book, pay,
cancel and reschedule. You can still approve, reject, cancel, reschedule, complete and manually
book. You can still read everything - your calendar, your bookings, your catalogue and your
settings.

**Only changes to your setup are paused**: services, staff, availability, events, seat maps and the
settings themselves. That is the whole list.

This is deliberate. Approval requests sit on a timer, so freezing the approval queue over a billing
question would let held bookings quietly expire and turn away paying customers - a far worse
outcome than not being able to edit a service list for a few days.

= Moving to another site =

Deactivate the license on the old site first, from **Reservant -> Settings**. That unbinds the site
and frees the seat. Note that activating a key **replaces** whatever key is stored on the site,
including a working one, so paste carefully.

== Frequently Asked Questions ==

= Do I need WooCommerce? =

Only if you want to take payment online. Free services and pay-on-arrival services need nothing at
all. If you set a service to pay online and there is no payment plugin active, that service behaves
as pay on arrival - bookings still complete, and wp-admin tells you so.

= Do my customers need to create an account? =

No. Booking is a guest flow from start to finish. The link in the confirmation email is how a
customer returns to cancel or move a booking, and it needs no login.

= Can customers choose their own seat? =

Not in this release. Events sell a number of places and the customer says how many they want. You
can build seat grids under **Reservant -> Seat Maps** and Reservant stores bookings against
individual seats, but the seat picker in the customer's widget is a later release.

= Does uninstalling delete my bookings? =

Not unless you ask it to. Settings has a **Purge all data on uninstall** switch which is off by
default, so removing the plugin leaves your data in place. Deactivating never removes anything.

= Can two customers book the same slot at the same moment? =

No. Every booking is written under a database lock on the slots it occupies, and availability is
re-checked under that lock rather than trusted from what the browser was showing. The customer who
is a moment too late is told the slot has gone, and which part of their booking caused it.

== Changelog ==

= 0.5.3 - 2026-08-20 =

* **License activation**, bound to your site's domain and re-checked daily, with a Settings section
  that names which of five states you are in and what to do about it.
* **A failing re-check opens a 14-day grace period and pauses nothing.** An unreachable validator
  means "unknown", not "unlicensed".
* **An unlicensed site loses changes to its setup and nothing else.** Bookings, payments,
  cancellations, reschedules and the whole approval queue keep running.
* **A cancelled booking that was paid for now leaves a note on its WooCommerce order**, saying the
  slot is released and that nothing has been refunded.
* One command now builds the distributable zip, rebuilding the asset bundles and verifying the
  archive before writing it.

Earlier releases are listed in `CHANGELOG.md`, in the plugin folder.

== Distribution ==

Reservant is proprietary software, supplied to licensed customers. It is not published in the
WordPress.org plugin directory, and no open-source license is granted with it. All rights reserved.

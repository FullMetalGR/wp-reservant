#!/usr/bin/env bash
# Task 16 -- "the tests AGENTS.md forbids skipping": parallel holds, opposing-order chains, and
# contested seats, run against the live REST API on wp-env's tests site. Never marked skipped,
# locally or in CI (AGENTS.md section 8).
set -euo pipefail

BASE="${BASE:-http://localhost:8889}"
CLI="${CLI:-tests-cli}"

json_field() {
	# $1 = JSON on stdin, $2 = top-level string/int key. jq if present (CI always has it); a
	# dependency-free PHP fallback otherwise, so this also runs on a bare dev box.
	local key="$2"
	if command -v jq >/dev/null 2>&1; then
		jq -r --arg k "$key" '.[$k]' <<<"$1"
	else
		php -r '
			$data = json_decode($argv[1], true);
			$v = $data[$argv[2]] ?? null;
			echo is_array($v) ? json_encode($v) : (string) $v;
		' "$1" "$key"
	fi
}

json_array_field() {
	# Same as json_field but for a list value, printing one element per line.
	local key="$2"
	if command -v jq >/dev/null 2>&1; then
		jq -r --arg k "$key" '.[$k][]' <<<"$1"
	else
		php -r '
			$data = json_decode($argv[1], true);
			foreach ( (array) ( $data[$argv[2]] ?? array() ) as $v ) { echo $v . "\n"; }
		' "$1" "$key"
	fi
}

echo "== Reservant concurrency proof ==" >&2

# The plugin ships inactive on a fresh wp-env volume; the fixture command and every REST route
# this script depends on need it active on the tests site specifically.
npx wp-env run "$CLI" wp plugin is-active reservant >/dev/null 2>&1 \
	|| npx wp-env run "$CLI" wp plugin activate reservant >&2

# POST /holds is rate-limited to 10/min per IP (AGENTS.md section 5) -- a sane default for a real site,
# but this driver alone fires ~35 requests from one address across three scripts. Lift the cap for
# the duration of this run via a throwaway mu-plugin (container-local; never touches the repo or
# the plugin itself) rather than weakening the real limiter, and clear any counters already
# ticking from a previous run. Removed on exit (trap below): left in place it only keeps working
# against composer test:integration's own rate-limit assertions by filter-registration-order
# coincidence, not by design.
MU_PLUGIN_PATH=/var/www/html/wp-content/mu-plugins/reservant-test-ratelimit.php
cleanup() {
	npx wp-env run "$CLI" bash -c "rm -f '$MU_PLUGIN_PATH'" >/dev/null 2>&1 || true
}
trap cleanup EXIT

printf '%s' '<?php
/* Task 16 test harness only -- see bin/run-concurrency.sh. Not part of the plugin. */
add_filter( "reservant/holds/rate_limit", static fn () => 100000 );
' | npx wp-env run "$CLI" bash -c "mkdir -p /var/www/html/wp-content/mu-plugins && cat > $MU_PLUGIN_PATH" >/dev/null 2>&1
npx wp-env run "$CLI" wp transient delete --all >/dev/null 2>&1 || true

# Re-runnability: every scenario below books deterministic dates relative to "now", so a second
# run inside the same 15-minute hold TTL would otherwise contest its own previous winners --
# zero winners on the holds/seats scripts, wrong-reason losses on the chains script -- and look
# exactly like a broken engine. This is a disposable, wp-env-only test site (fixture data is
# already reseeded from an option cache, never hand-authored), so the simplest correct fix is the
# one the fixture command itself relies on: start every run from an empty table, not a "still
# valid" one.
npx wp-env run "$CLI" wp eval 'global $wpdb; $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}reservant_booking_items" ); $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}reservant_bookings" );' >/dev/null 2>&1

FIXTURE=$(npx wp-env run "$CLI" wp reservant fixture 2>/dev/null)
CUT=$(json_field "$FIXTURE" cut)
COLOUR=$(json_field "$FIXTURE" colour)
STAFF_A=$(json_field "$FIXTURE" staff_a)
STAFF_B=$(json_field "$FIXTURE" staff_b)
GRID_OCC=$(json_field "$FIXTURE" grid_occ)
SEAT=$(json_array_field "$FIXTURE" grid_seats | head -n1)

# ~21 days ahead, inside the fixture's 09:00-17:00 working hours, comfortably past any lead time
# and comfortably inside the 60-day horizon, and on the engine's 5-minute booking grid (an
# off-grid start is `bad_time` for every request regardless of contention). Fixed rather than
# clock-derived: the truncation above is what makes re-runs clean, not a shifting start time.
START=$(date -u -d '+21 days' +%Y-%m-%d)' 10:00:00'

echo "fixture: cut=$CUT colour=$COLOUR staff_a=$STAFF_A staff_b=$STAFF_B grid_occ=$GRID_OCC seat=$SEAT start=$START" >&2

php bin/concurrency-holds.php  "$BASE" "$CUT" "$STAFF_A" "$START"
php bin/concurrency-chains.php "$BASE" "$CUT" "$COLOUR" "$STAFF_A" "$STAFF_B" "$START"
php bin/concurrency-seats.php  "$BASE" "$GRID_OCC" "$SEAT"

echo "ALL CONCURRENCY TESTS PASSED"

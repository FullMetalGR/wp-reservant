<?php
declare( strict_types=1 );

namespace Reservant\Application;

/**
 * A guarded state transition declined to happen (`Application\GuardedWrite`).
 *
 * Extends `\RuntimeException` and carries the SAME reason strings the four use cases threw by hand
 * before the protocol was stated once - `stale_state`, `not_held`, `not_cancellable`,
 * `not_approvable`. That inheritance is load-bearing rather than tidy: `Rest\Errors` maps refusals
 * by `getMessage()`, every controller catches `\RuntimeException`, and `Rest\ErrorsExhaustivenessTest`
 * pins one customer-facing sentence per reason. A refusal that arrived as a new unrelated type, or
 * under a new reason string, would reach the wire as an opaque 500 with the right thing having
 * happened underneath.
 *
 * **Why a distinct type at all, when the string is what the wire reads.** `ExpireHolds` answers all
 * four of its refusals with `null` rather than an exception - a sweeper skipping a row somebody else
 * already decided is its ordinary path, not an error - while the other three use cases answer with a
 * throw. One shared executor cannot do both, so it throws, and the one caller that wants `null`
 * converts at its own boundary. Catching this type is how it converts WITHOUT also swallowing
 * `lock_unavailable` (a genuine DB fault, which `ExpireHolds::run()` handles under a separate and
 * deliberately narrower rule) or a bug in a listener. Catching `\RuntimeException` there would
 * silently eat both.
 */
final class TransitionRefused extends \RuntimeException {
	// No body on purpose. The reason travels as the message, which is the only thing
	// `Rest\Errors::failure()` reads; a second copy on a promoted property would be one more place
	// for the two to disagree. Unlike `SlotConflict`, which carries a segment index the message
	// cannot express, this type adds nothing but its own name - and its name is the whole point.
}

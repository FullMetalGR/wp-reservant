<?php
declare( strict_types=1 );

namespace Reservant\Tests\Integration\Notifications;

use Reservant\Notifications\EmailCatalog;
use Reservant\Settings;
use Reservant\Tests\Integration\ReservantTestCase;

/**
 * The catalog is the one list four unrelated things read: `Mailer::send()` (which refuses a
 * switched-off key), `Settings::validate()` (which refuses a switch for a message that does not
 * exist), the admin bootstrap that hands the Settings screen one checkbox per entry, and this.
 *
 * The KEYS-to-labels pairing needs no test - `EmailCatalog::choices()` indexes the label map with a
 * key that PHPStan has proved comes from `KEYS`, so a message added without a label fails
 * `composer stan` and names it. What DOES need a test is that the other three readers accept
 * everything the catalog offers, since each of them restates the relationship in its own terms.
 */
final class EmailCatalogTest extends ReservantTestCase {

	public function test_every_message_the_catalog_offers_can_actually_be_switched_off(): void {
		// The settings validator keeps its own copy of the rule ("names an email this plugin
		// sends"), so a key added to KEYS and rejected there would render a checkbox that 400s on
		// save - visible only to whoever tried it.
		self::assertSame(
			EmailCatalog::KEYS,
			Settings::make()->update( array( 'emails_off' => EmailCatalog::KEYS ) )->emailsOff()
		);
	}

	public function test_the_choices_are_the_keys_in_order_and_every_one_is_named(): void {
		$choices = EmailCatalog::choices();

		self::assertSame( EmailCatalog::KEYS, array_column( $choices, 'key' ) );
		foreach ( $choices as $choice ) {
			self::assertNotSame( '', trim( $choice['label'] ), 'an unnamed checkbox is not usable' );
			self::assertNotSame( $choice['key'], $choice['label'], 'the label must be prose, not the machine key' );
		}
	}

	/**
	 * Four of the ten go to the approver rather than the guest, and "stop emailing my customers"
	 * and "stop emailing me" are different intentions - so each label has to say which it is before
	 * an owner can answer the question the checkbox asks.
	 */
	public function test_every_label_says_who_receives_the_message(): void {
		foreach ( EmailCatalog::choices() as $choice ) {
			self::assertMatchesRegularExpression(
				'/^(Customer|Approver):/',
				$choice['label'],
				$choice['key'] . ' must name its recipient'
			);
		}
	}

	/** A message with no switch is a message an owner cannot stop. */
	public function test_the_catalog_names_every_key_the_plugin_actually_sends(): void {
		$sent = array();
		foreach ( glob( dirname( __DIR__, 3 ) . '/src/Notifications/*.php' ) as $file ) {
			$source = (string) file_get_contents( $file );
			if ( 1 === preg_match_all( "/Mailer::send\(\s*\n?\s*'([a-z_]+)'/", $source, $matches ) || array() !== ( $matches[1] ?? array() ) ) {
				$sent = array_merge( $sent, $matches[1] );
			}
			// The two classes that pass the key as a variable name it in their own switch instead.
			if ( 1 === preg_match_all( "/case '([a-z_]+)':/", $source, $cases ) || array() !== ( $cases[1] ?? array() ) ) {
				$sent = array_merge( $sent, $cases[1] );
			}
		}

		$unswitchable = array_values( array_diff( array_unique( $sent ), EmailCatalog::KEYS ) );
		self::assertSame( array(), $unswitchable, 'these keys are sent but carry no switch' );
	}
}

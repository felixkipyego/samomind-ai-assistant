<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SAMOMIND_Orders_Mail
 *
 * Sends the verification code by the shop's own wp_mail(), from the sender
 * WooCommerce uses. The mail goes out on the shutdown hook after the response
 * has been flushed, so the time it takes cannot tell a caller whether an order
 * matched. WP-Cron is only the fallback where the host has no way to flush
 * (design section 4). The email is in English.
 *
 * @package     SAMOMIND
 * @subpackage  Classes/SAMOMIND_Orders_Mail
 * @since       0.4.0
 */
class SAMOMIND_Orders_Mail {

	/**
	 * Cron hook of the fallback.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'samomind_orders_send_code';

	/**
	 * Orders store.
	 *
	 * @var SAMOMIND_Orders_Store
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @since   0.4.0
	 * @param   SAMOMIND_Orders_Store $store  Orders store.
	 */
	public function __construct( SAMOMIND_Orders_Store $store ) {
		$this->store = $store;
	}

	/**
	 * The function that flushes the response and lets the script go on, or
	 * null where the server has none.
	 *
	 * @since   0.4.0
	 * @return  callable|null
	 */
	public static function finish_function() {
		$function = null;
		foreach ( array( 'fastcgi_finish_request', 'litespeed_finish_request' ) as $name ) {
			if ( function_exists( $name ) ) {
				$function = $name;
				break;
			}
		}

		/**
		 * The function that ends the response early. Hosts with none get the
		 * WP-Cron fallback.
		 *
		 * @since 0.4.0
		 * @param callable|null $function Function, or null.
		 */
		$function = apply_filters( 'SAMOMIND/orders/finish_function', $function );

		return is_callable( $function ) ? $function : null;
	}

	/**
	 * "shutdown" or "cron_fallback", as reported in the capabilities.
	 *
	 * @since   0.4.0
	 * @return  string
	 */
	public static function dispatch_mode() {
		return null !== self::finish_function() ? 'shutdown' : 'cron_fallback';
	}

	/**
	 * Arrange for a code to be emailed after the response has left.
	 *
	 * @since   0.4.0
	 * @param   string $to    The order's billing address.
	 * @param   string $code  Six digits.
	 * @return  void
	 */
	public function dispatch( $to, $code ) {
		$id = bin2hex( random_bytes( 12 ) );
		$this->store->park_mail(
			$id,
			array(
				'to'   => $to,
				'code' => $code,
			)
		);

		if ( null !== self::finish_function() ) {
			// Late, so the output buffers WordPress flushes at priority 1 are already out.
			add_action(
				'shutdown',
				function () use ( $id ) {
					$finish = self::finish_function();
					if ( null !== $finish ) {
						call_user_func( $finish );
					}
					$this->send_parked( $id );
				},
				50
			);
			return;
		}

		wp_schedule_single_event( time(), self::CRON_HOOK, array( $id ) );
		spawn_cron();
	}

	/**
	 * Send a parked code. Also the WP-Cron callback.
	 *
	 * @since   0.4.0
	 * @param   string $id  Id given by dispatch().
	 * @return  bool  Whether wp_mail accepted it.
	 */
	public function send_parked( $id ) {
		$message = $this->store->take_mail( (string) $id );
		if ( null === $message || empty( $message['to'] ) || empty( $message['code'] ) ) {
			return false;
		}

		$sent = wp_mail(
			$message['to'],
			$this->subject(),
			$this->body( $message['code'] ),
			$this->headers()
		);
		if ( ! $sent ) {
			SAMOMIND()->log->add( 'orders/start', 202, 'mail_failed' );
		}
		return (bool) $sent;
	}

	/**
	 * Subject line, in English.
	 *
	 * @since   0.4.0
	 * @return  string
	 */
	public function subject() {
		return sprintf( 'Your order check code for %s', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
	}

	/**
	 * Message text, in English. It holds the code and nothing about the order.
	 *
	 * @since   0.4.0
	 * @param   string $code  Six digits.
	 * @return  string
	 */
	public function body( $code ) {
		return "Your code is {$code}\n\n"
			. "Enter it in the chat to check your order. It works for a few minutes and only once.\n\n"
			. "If you did not ask for this, you can ignore this email. Nobody can see your order without the code.\n";
	}

	/**
	 * Headers: plain text, and the sender WooCommerce uses for its own mail.
	 *
	 * @since   0.4.0
	 * @return  string[]
	 */
	public function headers() {
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		$name    = trim( (string) preg_replace( '/[\x00-\x1f\x7f<>"]+/', '', wp_specialchars_decode( (string) get_option( 'woocommerce_email_from_name', '' ), ENT_QUOTES ) ) );
		$address = sanitize_email( (string) get_option( 'woocommerce_email_from_address', '' ) );
		if ( '' !== $address && is_email( $address ) ) {
			$headers[] = '' !== $name ? 'From: "' . $name . '" <' . $address . '>' : 'From: ' . $address;
		}

		return $headers;
	}
}

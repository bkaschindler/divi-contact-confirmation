<?php
/**
 * Intercepts Divi contact form submissions and triggers the confirmation email.
 *
 * Detection strategy (four layers — first successful match wins, static flag
 * prevents sending more than one confirmation per request):
 *
 *  1. AJAX output-buffer intercept (primary, most reliable)
 *     Hooks into Divi's own AJAX action at priority 1, starts an output buffer,
 *     then reads Divi's JSON response in a shutdown function.  Works even when
 *     Divi is not configured to send an admin notification email and regardless
 *     of which Divi version is installed.
 *
 *  2. wp_mail filter
 *     If Divi IS configured to send an admin notification, the filter reads the
 *     Reply-To header (Divi sets this to the submitter's address).
 *
 *  3. et_pb_contact_form_submit — Divi 4 named action hook.
 *
 *  4. divi_contact_form_submitted — Divi 5 named action hook.
 *
 * @author  Mohammad Babaei <https://adschi.com>
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DCC_Hooks {

	/** Prevents sending more than one confirmation per HTTP request. */
	private static $sent = false;

	/** POST fields captured at the moment the AJAX action fires. */
	private static $captured_fields = array();

	public static function init() {
		// Inject reCAPTCHA v3 script + nonce into frontend pages
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend' ) );

		// Layer 1 — AJAX output-buffer intercept (Divi 4 & 5, logged-in or not)
		add_action( 'wp_ajax_nopriv_et_pb_contact_form_submit', array( __CLASS__, 'start_ajax_intercept' ), 1 );
		add_action( 'wp_ajax_et_pb_contact_form_submit',        array( __CLASS__, 'start_ajax_intercept' ), 1 );

		// Layer 2 — wp_mail filter (fires when Divi sends an admin notification)
		add_filter( 'wp_mail', array( __CLASS__, 'intercept_via_wp_mail' ), 5 );

		// Layer 3 — Divi 4 named action
		add_action( 'et_pb_contact_form_submit', array( __CLASS__, 'handle_divi4' ), 10, 3 );

		// Layer 4 — Divi 5 named action
		add_action( 'divi_contact_form_submitted', array( __CLASS__, 'handle_divi5' ), 10, 2 );
	}

	// -------------------------------------------------------------------------
	// Frontend: inject reCAPTCHA script + nonce
	// -------------------------------------------------------------------------

	public static function enqueue_frontend() {
		$site_key = get_option( 'dcc_sec_recaptcha_site_key', '' );
		if ( ! $site_key ) {
			return;
		}

		// Priority 1 in wp_head: patch XHR BEFORE any other script (including Divi)
		// so Divi cannot cache a reference to the original send() method.
		// dccToken lives in global scope so the footer script can write to it.
		add_action( 'wp_head', function() {
			echo '<style>.grecaptcha-badge{visibility:hidden!important}</style>' . "\n";
			echo '<script>window.dccToken="";(function(){var _o=XMLHttpRequest.prototype.open,_s=XMLHttpRequest.prototype.send;XMLHttpRequest.prototype.open=function(m,u){this._dccU=typeof u==="string"?u:"";return _o.apply(this,arguments);};XMLHttpRequest.prototype.send=function(b){if(this._dccU.indexOf("admin-ajax.php")!==-1){if(typeof b==="string"&&b.indexOf("action=et_pb_contact_form_submit")!==-1){b+="&dcc_recaptcha_token="+encodeURIComponent(window.dccToken);}else if(b instanceof FormData&&b.get&&b.get("action")==="et_pb_contact_form_submit"){b.append("dcc_recaptcha_token",window.dccToken);}}return _s.call(this,b);};}());</script>' . "\n";
		}, 1 );

		// Load reCAPTCHA v3 in footer — sets window.dccToken once library is ready
		wp_enqueue_script(
			'google-recaptcha-v3',
			'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( $site_key ),
			array(),
			null,
			true
		);

		// Token is fetched immediately when this script runs (footer, right after reCAPTCHA loads).
		// A real user takes several seconds to fill the form, so the token is always ready.
		// Bots that POST directly without loading the page have no token and are blocked server-side.
		$script = sprintf(
			'(function(){
				var k = %s;
				var _f = window.fetch;

				function refreshToken() {
					if (typeof grecaptcha === "undefined") { return; }
					grecaptcha.ready(function() {
						grecaptcha.execute(k, {action:"divi_contact_form"})
							.then(function(t){ window.dccToken = t; })
							.catch(function(){});
					});
				}

				/* Fetch token immediately — no waiting for DOM ready */
				refreshToken();
				setInterval(refreshToken, 90000);

				/* Fetch API intercept for Divi 5 */
				window.fetch = function(url, opts) {
					if (opts && opts.body) {
						var b = opts.body;
						if (b instanceof URLSearchParams && b.get("action") === "et_pb_contact_form_submit") {
							b.set("dcc_recaptcha_token", window.dccToken); opts.body = b;
						} else if (typeof b === "string" && b.indexOf("action=et_pb_contact_form_submit") !== -1) {
							opts.body = b + "&dcc_recaptcha_token=" + encodeURIComponent(window.dccToken);
						}
					}
					return _f.apply(this, arguments);
				};
			}());',
			wp_json_encode( $site_key )
		);

		wp_add_inline_script( 'google-recaptcha-v3', $script );
	}

	// -------------------------------------------------------------------------
	// Layer 1: AJAX output-buffer intercept
	// -------------------------------------------------------------------------

	/**
	 * Runs at priority 1 — before Divi processes the form.
	 * Captures POST fields and starts an output buffer so we can read Divi's
	 * JSON response inside a shutdown function.
	 */
	public static function start_ajax_intercept() {
		if ( self::$sent ) {
			return;
		}

		// Snapshot POST data now, before Divi might alter superglobals
		self::$captured_fields = self::fields_from_post();

		// Wrap everything Divi outputs in a buffer
		ob_start();

		// PHP shutdown functions run after die() / wp_die()
		register_shutdown_function( array( __CLASS__, 'finish_ajax_intercept' ) );
	}

	/**
	 * Called from PHP shutdown — reads the buffered response.
	 * Divi returns {"error":"no",...} on a successful submission.
	 */
	public static function finish_ajax_intercept() {
		if ( self::$sent ) {
			// Another layer already sent — just flush the buffer unchanged
			if ( ob_get_level() > 0 ) {
				ob_end_flush();
			}
			return;
		}

		$output = '';
		if ( ob_get_level() > 0 ) {
			$output = ob_get_clean();
		}

		// Divi success response contains "error":"no"
		$data = json_decode( $output, true );
		if ( isset( $data['error'] ) && 'no' === $data['error'] ) {
			$fields = self::$captured_fields;
			$email  = self::extract_email_from_fields( $fields );
			$name   = self::extract_name_from_fields( $fields );

			if ( $email ) {
				self::$sent = true;
				DCC_Mailer::send( $email, $name, $fields );
			}
		}

		// Always echo the original output so Divi's response reaches the browser
		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	// -------------------------------------------------------------------------
	// Layer 2: wp_mail filter
	// -------------------------------------------------------------------------

	/**
	 * Fires on every wp_mail() call.  Only acts when we detect a Divi AJAX
	 * request and can find a Reply-To header with the submitter's address.
	 */
	public static function intercept_via_wp_mail( $args ) {
		if ( self::$sent ) {
			return $args;
		}

		if ( ! self::is_divi_ajax_request() ) {
			return $args;
		}

		$headers = is_array( $args['headers'] )
			? $args['headers']
			: array_filter( array_map( 'trim', explode( "\n", $args['headers'] ) ) );

		$submitter_email = '';
		$submitter_name  = '';

		foreach ( $headers as $header ) {
			if ( stripos( trim( $header ), 'Reply-To:' ) !== 0 ) {
				continue;
			}
			if ( preg_match( '/<([^>]+)>/', $header, $m ) && is_email( $m[1] ) ) {
				$submitter_email = sanitize_email( $m[1] );
				if ( preg_match( '/Reply-To:\s*([^<]+)\s*</i', $header, $nm ) ) {
					$submitter_name = sanitize_text_field( trim( $nm[1], " \t\"'" ) );
				}
			} elseif ( preg_match( '/Reply-To:\s*(\S+@\S+\.\S+)/i', $header, $m ) && is_email( trim( $m[1] ) ) ) {
				$submitter_email = sanitize_email( trim( $m[1] ) );
			}
			if ( $submitter_email ) {
				break;
			}
		}

		// Fallback: scan $_POST
		if ( ! $submitter_email ) {
			$fields          = empty( self::$captured_fields ) ? self::fields_from_post() : self::$captured_fields;
			$submitter_email = self::extract_email_from_fields( $fields );
			$submitter_name  = self::extract_name_from_fields( $fields );
		}

		if ( $submitter_email ) {
			self::$sent = true;
			$fields     = empty( self::$captured_fields ) ? self::fields_from_post() : self::$captured_fields;
			DCC_Mailer::send( $submitter_email, $submitter_name, $fields );
		}

		return $args;
	}

	// -------------------------------------------------------------------------
	// Layer 3: Divi 4 named action
	// -------------------------------------------------------------------------

	public static function handle_divi4( $et_contact_error, $fields, $module_settings ) {
		if ( self::$sent || 'yes' === $et_contact_error ) {
			return;
		}

		// $fields here may be only email-type fields; supplement from POST
		$all_fields = array_merge( self::fields_from_post(), is_array( $fields ) ? $fields : array() );
		$email      = self::extract_email_from_fields( $all_fields );
		$name       = self::extract_name_from_fields( $all_fields );

		if ( $email ) {
			self::$sent = true;
			DCC_Mailer::send( $email, $name, $all_fields );
		}
	}

	// -------------------------------------------------------------------------
	// Layer 4: Divi 5 named action
	// -------------------------------------------------------------------------

	public static function handle_divi5( $form_data, $module ) {
		if ( self::$sent ) {
			return;
		}

		$fields = isset( $form_data['fields'] ) ? $form_data['fields'] : $form_data;
		$email  = self::extract_email_from_fields( $fields );
		$name   = self::extract_name_from_fields( $fields );

		if ( $email ) {
			self::$sent = true;
			DCC_Mailer::send( $email, $name, $fields );
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private static function is_divi_ajax_request() {
		if ( ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification
		$action = isset( $_POST['action'] ) ? sanitize_key( $_POST['action'] ) : '';
		return 'et_pb_contact_form_submit' === $action;
	}

	/**
	 * Sanitise and return submitted POST fields, skipping WordPress/Divi internals.
	 */
	private static function fields_from_post() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}

		$skip = array( '_wpnonce', '_wp_http_referer', 'action', 'et_pb_contactform_submit' );
		$out  = array();

		// phpcs:ignore WordPress.Security.NonceVerification
		foreach ( $_POST as $key => $value ) {
			if ( in_array( $key, $skip, true ) ) {
				continue;
			}
			if ( is_string( $value ) ) {
				$out[ sanitize_key( $key ) ] = sanitize_text_field( wp_unslash( $value ) );
			}
		}

		$cache = $out;
		return $out;
	}

	/** Finds a valid email address in a field array. */
	private static function extract_email_from_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return '';
		}
		// Prefer a key that mentions "email"
		foreach ( $fields as $key => $value ) {
			if ( false !== strpos( strtolower( (string) $key ), 'email' ) && is_email( $value ) ) {
				return sanitize_email( $value );
			}
		}
		// Fallback: scan all values
		foreach ( $fields as $value ) {
			if ( is_string( $value ) && is_email( $value ) ) {
				return sanitize_email( $value );
			}
		}
		return '';
	}

	/** Finds a name in a field array. */
	private static function extract_name_from_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return '';
		}
		$keywords = array( 'name', 'full_name', 'fullname', 'first_name', 'firstname', 'your_name', 'vorname', 'nachname' );
		foreach ( $fields as $key => $value ) {
			$key_lower = strtolower( (string) $key );
			foreach ( $keywords as $kw ) {
				if ( false !== strpos( $key_lower, $kw ) ) {
					return sanitize_text_field( $value );
				}
			}
		}
		return '';
	}
}

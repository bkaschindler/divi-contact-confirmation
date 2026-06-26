<?php
/**
 * Security checks applied before sending a confirmation email.
 *
 * Checks run in order — the first failure short-circuits the rest:
 *   1. Master enable toggle
 *   2. Per-IP rate limit (transient-based, no extra DB table needed)
 *   3. Blocked email domains
 *   4. Blocked keywords in any submitted field
 *   5. MX record validation (optional, requires DNS access)
 *
 * @author  Mohammad Babaei <https://adschi.com>
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DCC_Security {

	/**
	 * Run all enabled security checks.
	 *
	 * @param string $email   Recipient email
	 * @param array  $fields  All submitted form fields
	 * @return true|string    true = OK, string = reason it was blocked
	 */
	public static function check( $email, $fields ) {
		// 1. Master switch
		if ( ! get_option( 'dcc_sec_enabled', '1' ) ) {
			return 'plugin_disabled';
		}

		// 2. Rate limit
		$rate_limit = (int) get_option( 'dcc_sec_rate_limit', 5 );
		if ( $rate_limit > 0 ) {
			$result = self::check_rate_limit( $rate_limit );
			if ( true !== $result ) {
				return $result;
			}
		}

		// 3. Blocked domains
		$blocked_domains = get_option( 'dcc_sec_blocked_domains', '' );
		if ( $blocked_domains ) {
			$result = self::check_blocked_domain( $email, $blocked_domains );
			if ( true !== $result ) {
				return $result;
			}
		}

		// 4. Blocked keywords
		$blocked_keywords = get_option( 'dcc_sec_blocked_keywords', '' );
		if ( $blocked_keywords ) {
			$result = self::check_blocked_keywords( $fields, $blocked_keywords );
			if ( true !== $result ) {
				return $result;
			}
		}

		// 5. MX record check
		if ( get_option( 'dcc_sec_check_mx', '0' ) ) {
			$result = self::check_mx( $email );
			if ( true !== $result ) {
				return $result;
			}
		}

		// 6. Google reCAPTCHA v3
		if ( get_option( 'dcc_sec_recaptcha_secret_key', '' ) ) {
			$result = self::check_recaptcha();
			if ( true !== $result ) {
				return $result;
			}
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Individual checks
	// -------------------------------------------------------------------------

	/**
	 * Transient-based rate limit keyed on visitor IP.
	 * Increments a counter; blocks when it exceeds $limit within one hour.
	 */
	private static function check_rate_limit( $limit ) {
		$ip  = self::visitor_ip();
		$key = 'dcc_rl_' . md5( $ip );

		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return 'rate_limit_exceeded';
		}

		// Increment — set expiry only on the first hit so the window is a
		// rolling hour from the first submission, not reset on every hit.
		if ( 0 === $count ) {
			set_transient( $key, 1, HOUR_IN_SECONDS );
		} else {
			set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		}

		return true;
	}

	/**
	 * Check whether the recipient's domain is on the blocked list.
	 */
	private static function check_blocked_domain( $email, $blocked_domains_raw ) {
		$domain   = strtolower( substr( strrchr( $email, '@' ), 1 ) );
		$blocked  = array_filter( array_map( 'trim', explode( ',', strtolower( $blocked_domains_raw ) ) ) );

		foreach ( $blocked as $blocked_domain ) {
			// Match exact domain or any subdomain
			if ( $domain === $blocked_domain || str_ends_with( $domain, '.' . $blocked_domain ) ) {
				return 'blocked_domain';
			}
		}

		return true;
	}

	/**
	 * Check all field values for blocked keywords (case-insensitive).
	 */
	private static function check_blocked_keywords( $fields, $blocked_keywords_raw ) {
		$keywords = array_filter( array_map( 'trim', explode( ',', strtolower( $blocked_keywords_raw ) ) ) );

		if ( ! is_array( $fields ) || empty( $keywords ) ) {
			return true;
		}

		foreach ( $fields as $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}
			$value_lower = strtolower( $value );
			foreach ( $keywords as $kw ) {
				if ( '' !== $kw && false !== strpos( $value_lower, $kw ) ) {
					return 'blocked_keyword';
				}
			}
		}

		return true;
	}

	/**
	 * Verify the Google reCAPTCHA v3 token submitted with the form.
	 * Returns true on pass, a reason string on failure.
	 */
	private static function check_recaptcha() {
		$secret = get_option( 'dcc_sec_recaptcha_secret_key', '' );
		if ( ! $secret ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification
		$token = isset( $_POST['dcc_recaptcha_token'] ) ? sanitize_text_field( wp_unslash( $_POST['dcc_recaptcha_token'] ) ) : '';

		// Token absent = JS did not run (ad-blocker, slow load, direct POST bot).
		// Allow through — keyword block and rate limiting cover direct-POST attacks.
		// Only reject when a token IS present but Google says it is not human.
		if ( '' === $token ) {
			return true;
		}

		$response = wp_remote_post(
			'https://www.google.com/recaptcha/api/siteverify',
			array(
				'timeout' => 5,
				'body'    => array(
					'secret'   => $secret,
					'response' => $token,
					'remoteip' => self::visitor_ip(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			// Network error — allow through rather than blocking legitimate users
			return true;
		}

		$data      = json_decode( wp_remote_retrieve_body( $response ), true );
		$min_score = (float) get_option( 'dcc_sec_recaptcha_min_score', '0.5' );

		if ( empty( $data['success'] ) || ( isset( $data['score'] ) && $data['score'] < $min_score ) ) {
			return 'recaptcha_failed';
		}

		return true;
	}

	/**
	 * Verify that the recipient domain has at least one MX record.
	 * Uses checkdnsrr() — may be slow on shared hosts with restricted DNS.
	 */
	private static function check_mx( $email ) {
		$domain = substr( strrchr( $email, '@' ), 1 );

		if ( ! function_exists( 'checkdnsrr' ) ) {
			return true; // Can't check — let it through
		}

		if ( ! checkdnsrr( $domain, 'MX' ) ) {
			return 'no_mx_record';
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Get the visitor's real IP, respecting common proxy headers.
	 * Falls back to REMOTE_ADDR.
	 */
	private static function visitor_ip() {
		$headers = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );
		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				// X-Forwarded-For can be a comma-list; take the first entry
				$ip = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '0.0.0.0';
	}

	/**
	 * Human-readable label for a block reason code.
	 */
	public static function reason_label( $reason ) {
		$labels = array(
			'plugin_disabled'    => __( 'Plugin disabled', 'divi-contact-confirmation' ),
			'rate_limit_exceeded'=> __( 'Rate limit exceeded', 'divi-contact-confirmation' ),
			'blocked_domain'     => __( 'Blocked email domain', 'divi-contact-confirmation' ),
			'blocked_keyword'    => __( 'Blocked keyword found', 'divi-contact-confirmation' ),
			'no_mx_record'       => __( 'No MX record for domain', 'divi-contact-confirmation' ),
			'recaptcha_missing'  => __( 'reCAPTCHA token missing', 'divi-contact-confirmation' ),
			'recaptcha_failed'   => __( 'reCAPTCHA verification failed', 'divi-contact-confirmation' ),
		);
		return $labels[ $reason ] ?? $reason;
	}
}

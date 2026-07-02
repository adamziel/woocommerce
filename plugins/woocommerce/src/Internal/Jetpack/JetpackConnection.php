<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Jetpack;

use Automattic\Jetpack\Connection\Manager;
use WP_Error;

/**
 * Jetpack Connection wrapper class.
 *
 * @since 8.3.0
 */
class JetpackConnection {
	/**
	 * Transient key used to throttle repeated Jetpack registration failures.
	 *
	 * @var string
	 */
	private const REGISTRATION_FAILURE_TRANSIENT = 'woocommerce_jetpack_registration_failure';

	/**
	 * How long to wait before retrying a failed registration attempt.
	 *
	 * @var int
	 */
	private const REGISTRATION_FAILURE_RETRY_TIMEOUT = MINUTE_IN_SECONDS;

	/**
	 * Jetpack connection manager.
	 *
	 * @var Manager
	 */
	private static $manager;

	/**
	 * Get the Jetpack connection manager.
	 *
	 * @return Manager
	 */
	public static function get_manager() {
		if ( ! self::$manager instanceof Manager ) {
			self::$manager = new Manager( 'woocommerce' );
		}

		return self::$manager;
	}

	/**
	 * Get the authorization URL for the Jetpack connection.
	 *
	 * @param mixed  $redirect_url Redirect URL.
	 * @param string $from         From parameter.
	 *
	 * @return array {
	 *     Authorization data.
	 *
	 *     @type bool   $success      Whether authorization URL generation succeeded.
	 *     @type array  $errors       Array of error messages if any.
	 *     @type string $color_scheme User's admin color scheme.
	 *     @type string $url          The authorization URL.
	 * }
	 */
	public static function get_authorization_url( $redirect_url, $from = '' ) {
		$manager = self::get_manager();
		$errors  = self::maybe_register_site( $manager );

		$calypso_env = defined( 'WOOCOMMERCE_CALYPSO_ENVIRONMENT' ) && in_array( WOOCOMMERCE_CALYPSO_ENVIRONMENT, array( 'development', 'wpcalypso', 'horizon', 'stage' ), true ) ? WOOCOMMERCE_CALYPSO_ENVIRONMENT : 'production';

		$authorization_url = $manager->get_authorization_url( null, $redirect_url );
		$authorization_url = add_query_arg( 'locale', self::get_wpcom_locale(), $authorization_url );

		$color_scheme = get_user_option( 'admin_color', get_current_user_id() );
		if ( ! $color_scheme ) {
			// The default Core color schema is 'fresh'.
			$color_scheme = 'fresh';
		}

		return array(
			'success'      => ! $errors->has_errors(),
			'errors'       => $errors->get_error_messages(),
			'color_scheme' => $color_scheme,
			'url'          => add_query_arg(
				array(
					'from'        => $from,
					'calypso_env' => $calypso_env,
				),
				$authorization_url,
			),
		);
	}

	/**
	 * Register the site to wp.com when needed.
	 *
	 * @param Manager $manager Jetpack connection manager.
	 * @return WP_Error Registration errors.
	 */
	private static function maybe_register_site( Manager $manager ) {
		$errors = new WP_Error();

		if ( $manager->is_connected() ) {
			delete_transient( self::REGISTRATION_FAILURE_TRANSIENT );
			return $errors;
		}

		$recent_failure = self::get_recent_registration_failure();
		if ( $recent_failure instanceof WP_Error ) {
			$errors->add( $recent_failure->get_error_code(), $recent_failure->get_error_message() );
			return $errors;
		}

		$result = $manager->try_registration();
		if ( is_wp_error( $result ) ) {
			self::cache_registration_failure( $result );
			$errors->add( $result->get_error_code(), $result->get_error_message() );
		} else {
			delete_transient( self::REGISTRATION_FAILURE_TRANSIENT );
		}

		return $errors;
	}

	/**
	 * Get the recent Jetpack registration failure, if any.
	 *
	 * @return WP_Error|null
	 */
	private static function get_recent_registration_failure() {
		$failure = get_transient( self::REGISTRATION_FAILURE_TRANSIENT );

		if ( ! is_array( $failure ) ) {
			return null;
		}

		$code    = $failure['code'] ?? '';
		$message = $failure['message'] ?? '';

		if ( ! is_string( $code ) || '' === $code || ! is_string( $message ) || '' === $message ) {
			return null;
		}

		return new WP_Error( $code, $message );
	}

	/**
	 * Cache a Jetpack registration failure for a short retry window.
	 *
	 * @param WP_Error $error Registration error.
	 * @return void
	 */
	private static function cache_registration_failure( WP_Error $error ) {
		set_transient(
			self::REGISTRATION_FAILURE_TRANSIENT,
			array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
			),
			self::REGISTRATION_FAILURE_RETRY_TIMEOUT
		);
	}

	/**
	 * Return a locale string for wpcom.
	 *
	 * @return string
	 */
	private static function get_wpcom_locale() {
		// List of locales that should be used with region code.
		$locale_to_lang = array(
			'bre'   => 'br',
			'de_AT' => 'de-at',
			'de_CH' => 'de-ch',
			'de'    => 'de_formal',
			'el'    => 'el-po',
			'en_GB' => 'en-gb',
			'es_CL' => 'es-cl',
			'es_MX' => 'es-mx',
			'fr_BE' => 'fr-be',
			'fr_CA' => 'fr-ca',
			'nl_BE' => 'nl-be',
			'nl'    => 'nl_formal',
			'pt_BR' => 'pt-br',
			'sr'    => 'sr_latin',
			'zh_CN' => 'zh-cn',
			'zh_HK' => 'zh-hk',
			'zh_SG' => 'zh-sg',
			'zh_TW' => 'zh-tw',
		);

		$system_locale = get_locale();
		if ( isset( $locale_to_lang[ $system_locale ] ) ) {
			// Return the locale with region code if it's in the list.
			return $locale_to_lang[ $system_locale ];
		}

		// If the locale is not in the list, return the language code only.
		return explode( '_', $system_locale )[0];
	}
}

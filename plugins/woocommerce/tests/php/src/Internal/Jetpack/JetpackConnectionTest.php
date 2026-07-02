<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Jetpack;

use Automattic\Jetpack\Connection\Manager;
use Automattic\WooCommerce\Internal\Jetpack\JetpackConnection;
use WC_Unit_Test_Case;
use WP_Error;

/**
 * Tests for the Jetpack connection wrapper.
 */
class JetpackConnectionTest extends WC_Unit_Test_Case {
	/**
	 * Transient key used by JetpackConnection to throttle registration failures.
	 *
	 * @var string
	 */
	private const REGISTRATION_FAILURE_TRANSIENT = 'woocommerce_jetpack_registration_failure';

	/**
	 * Clean up static and transient state after each test.
	 */
	public function tearDown(): void {
		$this->set_jetpack_manager( null );
		delete_transient( self::REGISTRATION_FAILURE_TRANSIENT );

		parent::tearDown();
	}

	/**
	 * Test that recent registration failures are not retried immediately.
	 */
	public function test_get_authorization_url_throttles_recent_registration_failures() {
		$manager = $this->getMockBuilder( Manager::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_connected', 'try_registration', 'get_authorization_url' ) )
			->getMock();

		$manager
			->expects( $this->exactly( 2 ) )
			->method( 'is_connected' )
			->willReturn( false );
		$manager
			->expects( $this->once() )
			->method( 'try_registration' )
			->willReturn( new WP_Error( 'registration_failed', 'Registration failed.' ) );
		$manager
			->expects( $this->exactly( 2 ) )
			->method( 'get_authorization_url' )
			->with( null, 'https://example.com/return' )
			->willReturn( 'https://example.com/authorize' );

		$this->set_jetpack_manager( $manager );

		$first_result  = JetpackConnection::get_authorization_url( 'https://example.com/return' );
		$second_result = JetpackConnection::get_authorization_url( 'https://example.com/return' );

		$this->assertFalse( $first_result['success'] );
		$this->assertSame( array( 'Registration failed.' ), $first_result['errors'] );
		$this->assertFalse( $second_result['success'] );
		$this->assertSame( array( 'Registration failed.' ), $second_result['errors'] );
	}

	/**
	 * Test that connected stores are not blocked by stale registration failures.
	 */
	public function test_get_authorization_url_clears_recent_failure_when_connected() {
		set_transient(
			self::REGISTRATION_FAILURE_TRANSIENT,
			array(
				'code'    => 'registration_failed',
				'message' => 'Registration failed.',
			),
			MINUTE_IN_SECONDS
		);

		$manager = $this->getMockBuilder( Manager::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_connected', 'try_registration', 'get_authorization_url' ) )
			->getMock();

		$manager
			->expects( $this->once() )
			->method( 'is_connected' )
			->willReturn( true );
		$manager
			->expects( $this->never() )
			->method( 'try_registration' );
		$manager
			->expects( $this->once() )
			->method( 'get_authorization_url' )
			->with( null, 'https://example.com/return' )
			->willReturn( 'https://example.com/authorize' );

		$this->set_jetpack_manager( $manager );

		$result = JetpackConnection::get_authorization_url( 'https://example.com/return' );

		$this->assertTrue( $result['success'] );
		$this->assertSame( false, get_transient( self::REGISTRATION_FAILURE_TRANSIENT ) );
	}

	/**
	 * Set the Jetpack connection manager used by the wrapper.
	 *
	 * @param Manager|null $manager Jetpack connection manager.
	 */
	private function set_jetpack_manager( ?Manager $manager ): void {
		$manager_property = new \ReflectionProperty( JetpackConnection::class, 'manager' );
		$manager_property->setAccessible( true );
		$manager_property->setValue( null, $manager );
	}
}

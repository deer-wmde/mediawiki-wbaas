<?php
/**
 * @author Antoine Musso
 * @copyright Copyright © 2013, Antoine Musso
 * @copyright Copyright © 2013, Wikimedia Foundation Inc.
 * @file
 */

class MWExceptionHandlerTest extends \MediaWikiUnitTestCase {

	private $oldSettingValue;

	protected function setUp() : void {
		parent::setUp();
		// We need to make sure the traces have function arguments as we're testing
		// their handling.
		$this->oldSettingValue = ini_set( 'zend.exception_ignore_args', 0 );
	}

	protected function tearDown() : void {
		ini_set( 'zend.exception_ignore_args', $this->oldSettingValue );
		parent::tearDown();
	}

	/**
	 * @covers MWExceptionHandler::getRedactedTrace
	 */
	public function testGetRedactedTrace() {
		$refvar = 'value';
		try {
			$array = [ 'a', 'b' ];
			$object = (object)[];
			self::helperThrowForArgs( $array, $object, $refvar );
		} catch ( Exception $e ) {
		}

		// Make sure our stack trace contains an array and an object passed to
		// some function in the stacktrace. Else, we can not assert the trace
		// redaction achieved its job.
		$trace = $e->getTrace();
		$hasObject = false;
		$hasArray = false;
		foreach ( $trace as $frame ) {
			if ( !isset( $frame['args'] ) ) {
				continue;
			}
			foreach ( $frame['args'] as $arg ) {
				$hasObject = $hasObject || is_object( $arg );
				$hasArray = $hasArray || is_array( $arg );
			}

			if ( $hasObject && $hasArray ) {
				break;
			}
		}
		$this->assertTrue( $hasObject, "The stacktrace has a frame with an object parameter" );
		$this->assertTrue( $hasArray, "The stacktrace has a frame with an array parameter" );

		// Now we redact the trace.. and verify there are no longer any arrays or objects
		$redacted = MWExceptionHandler::getRedactedTrace( $e );

		foreach ( $redacted as $frame ) {
			if ( !isset( $frame['args'] ) ) {
				continue;
			}
			foreach ( $frame['args'] as $arg ) {
				$this->assertIsNotArray( $arg );
				$this->assertIsNotObject( $arg );
			}
		}

		$this->assertEquals( 'value', $refvar, 'Reference variable' );
	}

	/**
	 * @dataProvider provideJsonSerializedKeys
	 * @covers MWExceptionHandler::jsonSerializeException
	 *
	 * @param string $expectedKeyType Type expected as returned by gettype()
	 * @param string $exClass An exception class (ie: Exception, MWException)
	 * @param string $key Name of the key to validate in the serialized JSON
	 */
	public function testJsonserializeexceptionKeys( $expectedKeyType, $exClass, $key ) {
		// Make sure we log a backtrace:
		$GLOBALS['wgLogExceptionBacktrace'] = true;

		$json = json_decode(
			MWExceptionHandler::jsonSerializeException( new $exClass() )
		);
		$this->assertObjectHasAttribute( $key, $json );
		$this->assertSame( $expectedKeyType, gettype( $json->$key ), "Type of the '$key' key" );
	}

	/**
	 * Each case provides: [ type, exception class, key name ]
	 */
	public static function provideJsonSerializedKeys() {
		foreach ( [ Exception::class, MWException::class ] as $exClass ) {
			yield [ 'string', $exClass, 'id' ];
			yield [ 'string', $exClass, 'file' ];
			yield [ 'integer', $exClass, 'line' ];
			yield [ 'string', $exClass, 'message' ];
			yield [ 'NULL', $exClass, 'url' ];
			// Backtrace only enabled with wgLogExceptionBacktrace = true
			yield [ 'array', $exClass, 'backtrace' ];
		}
	}

	/**
	 * Given wgLogExceptionBacktrace is true
	 * then serialized exception must have a backtrace
	 *
	 * @covers MWExceptionHandler::jsonSerializeException
	 */
	public function testJsonserializeexceptionBacktracingEnabled() {
		$GLOBALS['wgLogExceptionBacktrace'] = true;
		$json = json_decode(
			MWExceptionHandler::jsonSerializeException( new Exception() )
		);
		$this->assertObjectHasAttribute( 'backtrace', $json );
	}

	/**
	 * Given wgLogExceptionBacktrace is false
	 * then serialized exception must not have a backtrace
	 *
	 * @covers MWExceptionHandler::jsonSerializeException
	 */
	public function testJsonserializeexceptionBacktracingDisabled() {
		$GLOBALS['wgLogExceptionBacktrace'] = false;
		$json = json_decode(
			MWExceptionHandler::jsonSerializeException( new Exception() )
		);
		$this->assertObjectNotHasAttribute( 'backtrace', $json );
	}

	/**
	 * Helper function for testGetRedactedTrace
	 *
	 * @throws Exception
	 */
	protected static function helperThrowForArgs( array $a, object $b, &$c ) {
		throw new Exception();
	}
}

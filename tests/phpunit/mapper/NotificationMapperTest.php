<?php

use Wikimedia\Rdbms\IDatabase;

/**
 * @covers \EchoNotificationMapper
 */
class EchoNotificationMapperTest extends MediaWikiIntegrationTestCase {

	/**
	 * @todo write this test
	 */
	public function testInsert() {
		$this->assertTrue( true );
	}

	public function fetchUnreadByUser( User $user, $limit, array $eventTypes = [] ) {
		// Unsuccessful select
		$notifMapper = new EchoNotificationMapper( $this->mockMWEchoDbFactory( [ 'select' => false ] ) );
		$res = $notifMapper->fetchUnreadByUser( $this->mockUser(), 10, null, '' );
		$this->assertEmpty( $res );

		// Successful select
		$dbResult = [
			(object)[
				'event_id' => 1,
				'event_type' => 'test_event',
				'event_variant' => '',
				'event_extra' => '',
				'event_page_id' => '',
				'event_agent_id' => '',
				'event_agent_ip' => '',
				'notification_user' => 1,
				'notification_timestamp' => '20140615101010',
				'notification_read_timestamp' => null,
				'notification_bundle_hash' => 'testhash',
			]
		];
		$notifMapper = new EchoNotificationMapper( $this->mockMWEchoDbFactory( [ 'select' => $dbResult ] ) );
		$res = $notifMapper->fetchUnreadByUser( $this->mockUser(), 10, null, '', [] );
		$this->assertEmpty( $res );

		$notifMapper = new EchoNotificationMapper( $this->mockMWEchoDbFactory( [ 'select' => $dbResult ] ) );
		$res = $notifMapper->fetchUnreadByUser( $this->mockUser(), 10, null, '', [ 'test_event' ] );
		$this->assertIsArray( $res );
		$this->assertNotEmpty( $res );
		foreach ( $res as $row ) {
			$this->assertInstanceOf( EchoNotification::class, $row );
		}
	}

	public function testFetchByUser() {
		// Unsuccessful select
		$notifMapper = new EchoNotificationMapper( $this->mockMWEchoDbFactory( [ 'select' => false ] ) );
		$res = $notifMapper->fetchByUser( $this->mockUser(), 10, '' );
		$this->assertEmpty( $res );

		// Successful select
		$notifDbResult = [
			(object)[
				'event_id' => 1,
				'event_type' => 'test_event',
				'event_variant' => '',
				'event_extra' => '',
				'event_page_id' => '',
				'event_agent_id' => '',
				'event_agent_ip' => '',
				'event_deleted' => 0,
				'notification_user' => 1,
				'notification_timestamp' => '20140615101010',
				'notification_read_timestamp' => '20140616101010',
				'notification_bundle_hash' => 'testhash',
			]
		];

		$tpDbResult = [
			(object)[
				'etp_page' => 7, // pageid
				'etp_event' => 1, // eventid
			],
		];

		$notifMapper = new EchoNotificationMapper( $this->mockMWEchoDbFactory( [ 'select' => $notifDbResult ] ) );
		$res = $notifMapper->fetchByUser( $this->mockUser(), 10, '', [] );
		$this->assertEmpty( $res );

		$notifMapper = new EchoNotificationMapper(
			$this->mockMWEchoDbFactory( [ 'select' => $notifDbResult ] ),
			new EchoTargetPageMapper(
				$this->mockMWEchoDbFactory( [ 'select' => $tpDbResult ] )
			)
		);
		$res = $notifMapper->fetchByUser( $this->mockUser(), 10, '', [ 'test_event' ] );
		$this->assertIsArray( $res );
		$this->assertNotEmpty( $res );
		foreach ( $res as $row ) {
			$this->assertInstanceOf( EchoNotification::class, $row );
		}

		$notifMapper = new EchoNotificationMapper( $this->mockMWEchoDbFactory( [] ) );
		$res = $notifMapper->fetchByUser( $this->mockUser(), 10, '' );
		$this->assertEmpty( $res );
	}

	public function testFetchByUserOffset() {
		// Unsuccessful select
		$notifMapper = new EchoNotificationMapper( $this->mockMWEchoDbFactory( [ 'selectRow' => false ] ) );
		$res = $notifMapper->fetchByUserOffset( User::newFromId( 1 ), 500 );
		$this->assertFalse( $res );

		// Successful select
		$dbResult = (object)[
			'event_id' => 1,
			'event_type' => 'test',
			'event_variant' => '',
			'event_extra' => '',
			'event_page_id' => '',
			'event_agent_id' => '',
			'event_agent_ip' => '',
			'event_deleted' => 0,
			'notification_user' => 1,
			'notification_timestamp' => '20140615101010',
			'notification_read_timestamp' => '20140616101010',
			'notification_bundle_hash' => 'testhash',
		];
		$notifMapper = new EchoNotificationMapper( $this->mockMWEchoDbFactory( [ 'selectRow' => $dbResult ] ) );
		$row = $notifMapper->fetchByUserOffset( User::newFromId( 1 ), 500 );
		$this->assertInstanceOf( EchoNotification::class, $row );
	}

	public function testDeleteByUserEventOffset() {
		$this->setMwGlobals( [ 'wgUpdateRowsPerQuery' => 4 ] );
		$mockDb = $this->createMock( IDatabase::class );
		$makeResultRows = static function ( $eventIds ) {
			return new ArrayIterator( array_map( static function ( $eventId ) {
				return (object)[ 'notification_event' => $eventId ];
			}, $eventIds ) );
		};
		$mockDb->expects( $this->exactly( 4 ) )
			->method( 'select' )
			->willReturnOnConsecutiveCalls(
				$this->returnValue( $makeResultRows( [ 1, 2, 3, 5 ] ) ),
				$this->returnValue( $makeResultRows( [ 8, 13, 21, 34 ] ) ),
				$this->returnValue( $makeResultRows( [ 55, 89 ] ) ),
				$this->returnValue( $makeResultRows( [] ) )
			);
		$mockDb->expects( $this->exactly( 3 ) )
			->method( 'selectFieldValues' )
			->willReturnOnConsecutiveCalls(
				$this->returnValue( [] ),
				$this->returnValue( [ 13, 21 ] ),
				$this->returnValue( [ 55 ] )
			);
		$mockDb->expects( $this->exactly( 7 ) )
			->method( 'delete' )
			->withConsecutive(
				[
					'echo_notification',
					[ 'notification_user' => 1, 'notification_event' => [ 1, 2, 3, 5 ] ],
					$this->anything()
				],
				[
					'echo_notification',
					[ 'notification_user' => 1, 'notification_event' => [ 8, 13, 21, 34 ] ],
					$this->anything()
				],
				[
					'echo_event',
					[ 'event_id' => [ 13, 21 ] ],
					$this->anything()
				],
				[
					'echo_target_page',
					[ 'etp_event' => [ 13, 21 ] ],
					$this->anything()
				],
				[
					'echo_notification',
					[ 'notification_user' => 1, 'notification_event' => [ 55, 89 ] ],
					$this->anything()
				],
				[
					'echo_event',
					[ 'event_id' => [ 55 ] ],
					$this->anything()
				],
				[
					'echo_target_page',
					[ 'etp_event' => [ 55 ] ],
					$this->anything()
				]
			)
			->willReturn( true );

		$notifMapper = new EchoNotificationMapper( $this->mockMWEchoDbFactory( $mockDb ) );
		$this->assertTrue( $notifMapper->deleteByUserEventOffset( User::newFromId( 1 ), 500 ) );
	}

	/**
	 * Mock object of User
	 * @return User
	 */
	protected function mockUser() {
		$user = $this->createMock( User::class );
		$user->method( 'getID' )
			->willReturn( 1 );

		return $user;
	}

	/**
	 * Mock object of EchoNotification
	 * @return EchoNotification
	 */
	protected function mockEchoNotification() {
		$event = $this->createMock( EchoNotification::class );
		$event->method( 'toDbArray' )
			->willReturn( [] );

		return $event;
	}

	/**
	 * Mock object of MWEchoDbFactory
	 * @param array|\Wikimedia\Rdbms\IDatabase $dbResultOrMockDb
	 * @return MWEchoDbFactory
	 */
	protected function mockMWEchoDbFactory( $dbResultOrMockDb ) {
		$mockDb = is_array( $dbResultOrMockDb ) ? $this->mockDb( $dbResultOrMockDb ) : $dbResultOrMockDb;
		$dbFactory = $this->createMock( MWEchoDbFactory::class );
		$dbFactory->method( 'getEchoDb' )
			->willReturn( $mockDb );

		return $dbFactory;
	}

	/**
	 * Returns a mock database object
	 * @param array $dbResult
	 * @return \Wikimedia\Rdbms\IDatabase
	 */
	protected function mockDb( array $dbResult ) {
		$dbResult += [
			'insert' => '',
			'select' => '',
			'selectRow' => '',
			'delete' => ''
		];

		$db = $this->createMock( IDatabase::class );
		$db->method( 'insert' )
			->willReturn( $dbResult['insert'] );
		$db->method( 'select' )
			->willReturn( $dbResult['select'] );
		$db->method( 'delete' )
			->willReturn( $dbResult['delete'] );
		$db->method( 'selectRow' )
			->willReturn( $dbResult['selectRow'] );
		$db->method( 'onTransactionCommitOrIdle' )
			->will( new EchoExecuteFirstArgumentStub );

		return $db;
	}

}

<?php

/**
 * Database mapper for EchoNotification model
 */
class EchoNotificationMapper {

	/**
	 * Echo database factory
	 * @param MWEchoDbFactory
	 */
	protected $dbFactory;

	/**
	 * @param MWEchoDbFactory
	 */
	public function __construct( MWEchoDbFactory $dbFactory ) {
		$this->dbFactory = $dbFactory;
	}

	/**
	 * Insert a notification record
	 * @param EchoNotification
	 * @return null
	 */
	public function insert( EchoNotification $notification ) {
		$dbw = $this->dbFactory->getEchoDb( DB_MASTER );

		$fname = __METHOD__;
		$row = $notification->toDbArray();

		$dbw->onTransactionIdle( function() use ( $dbw, $row, $fname ) {
			$dbw->startAtomic( $fname );
			// reset the bundle base if this notification has a display hash
			// the result of this operation is that all previous notifications
			// with the same display hash are set to non-base because new record
			// is becoming the bundle base
			if ( $row['notification_bundle_display_hash'] ) {
				$dbw->update(
					'echo_notification',
					array( 'notification_bundle_base' => 0 ),
					array(
						'notification_user' => $row['notification_user'],
						'notification_bundle_display_hash' => $row['notification_bundle_display_hash'],
						'notification_bundle_base' => 1
					),
					$fname
				);
			}

			$row['notification_timestamp'] = $dbw->timestamp( $row['notification_timestamp'] );
			$res = $dbw->insert( 'echo_notification', $row, $fname );
			$dbw->endAtomic( $fname );

			// @todo - This is simple and easy but the proper way is to build a notification
			// observer to notify all listeners on creating a new notification
			if ( $res ) {
				$user = User::newFromId( $row['notification_user'] );
				MWEchoNotifUser::newFromUser( $user )->resetNotificationCount( DB_MASTER );
			}
		} );
	}

	/**
	 * Extract the offset used for notification list
	 * @param $continue String Used for offset
	 * @throws MWException
	 * @return int[]
	 */
	protected function extractQueryOffset( $continue ) {
		$offset = array (
			'timestamp' => 0,
			'offset' => 0,
		);
		if ( $continue ) {
			$values = explode( '|', $continue, 3 );
			if ( count( $values ) !== 2 ) {
				throw new MWException( 'Invalid continue param: ' . $continue );
			}
			$offset['timestamp'] = (int)$values[0];
			$offset['offset'] = (int)$values[1];
		}

		return $offset;
	}

	/**
	 * Get Notification by user in batch along with limit, offset etc
	 * @param User the user to get notifications for
	 * @param int The maximum number of notifications to return
	 * @param string Used for offset
	 * @param string Notification distribution type ( web, email, etc.)
	 * @return EchoNotification[]
	 */
	public function fetchByUser( User $user, $limit, $continue, $distributionType = 'web' ) {
		$dbr = $this->dbFactory->getEchoDb( DB_SLAVE );

		$eventTypesToLoad = EchoNotificationController::getUserEnabledEvents( $user, $distributionType );
		if ( !$eventTypesToLoad ) {
			return array();
		}

		// Look for notifications with base = 1
		$conds = array(
			'notification_user' => $user->getID(),
			'event_type' => $eventTypesToLoad,
			'notification_bundle_base' => 1
		);

		$offset = $this->extractQueryOffset( $continue );

		// Start points are specified
		if ( $offset['timestamp'] && $offset['offset'] ) {
			$ts = $dbr->addQuotes( $dbr->timestamp( $offset['timestamp'] ) );
			// The offset and timestamp are those of the first notification we want to return
			$conds[] = "notification_timestamp < $ts OR ( notification_timestamp = $ts AND notification_event <= " . $offset['offset'] . " )";
		}

		$res = $dbr->select(
			array( 'echo_notification', 'echo_event' ),
			'*',
			$conds,
			__METHOD__,
			array(
				'ORDER BY' => 'notification_timestamp DESC, notification_event DESC',
				'LIMIT' => $limit,
			),
			array(
				'echo_event' => array( 'LEFT JOIN', 'notification_event=event_id' ),
			)
		);

		$data = array();

		if ( $res ) {
			foreach ( $res as $row ) {
				$data[] = EchoNotification::newFromRow( $row );
			}
		}
		return $data;
	}

	/**
	 * Get the last notification in a set of bundle-able notifications by a bundle hash
	 * @param User
	 * @param string The hash used to identify a set of bundle-able notifications
	 * @return EchoNotification|bool
	 */
	public function fetchNewestByUserBundleHash( User $user, $bundleHash ) {
		$dbr = $this->dbFactory->getEchoDb( DB_SLAVE );

		$row = $dbr->selectRow(
			array( 'echo_notification', 'echo_event' ),
			array( '*' ),
			array(
				'notification_user' => $user->getId(),
				'notification_bundle_hash' => $bundleHash
			),
			__METHOD__,
			array( 'ORDER BY' => 'notification_timestamp DESC', 'LIMIT' => 1 ),
			array(
				'echo_event' => array( 'LEFT JOIN', 'notification_event=event_id' ),
			)
		);
		if ( $row ) {
			return EchoNotification::newFromRow( $row );
		} else {
			return false;
		}
	}

}

<?php

namespace SMW\SQLStore;

use Onoi\Cache\Cache;
use SMW\DIWikiPage;
use SMW\SQLStore\ChangeOp\TableChangeOp;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerAwareInterface;

/**
 * Pending the size of a diff, transferring it with the job parameter maybe too
 * large and can eventually fail during unserialization forcing a job and hereby
 * the update transaction to fail with:
 *
 * "Notice: unserialize(): Error at offset 65504 of 65535 bytes in ...
 * JobQueueDB.php on line 817"
 *
 * This class will store the diff object temporarily in Cache with the possibility
 * to retrieve it at a later point without relying on the JobQueueDB as storage
 * medium.
 *
 * The slot can be removed using a reference and the SequentialCachePurgeJob.
 *
 * @license GNU GPL v2+
 * @since 2.5
 *
 * @author mwjames
 */
class TransitionalDiffStore implements LoggerAwareInterface {

	const CACHE_NAMESPACE = ':smw:diff:';

	/**
	 * @var Cache
	 */
	private $cache;

	/**
	 * @var string
	 */
	private $prefix = '';

	/**
	 * loggerInterface
	 */
	private $logger;

	/**
	 * @since 2.5
	 *
	 * @param Cache $cache
	 * @param string $prefix
	 */
	public function __construct( Cache $cache, $prefix = '' ) {
		$this->cache = $cache;
		$this->prefix = $prefix;
	}

	/**
	 * @see LoggerAwareInterface::setLogger
	 *
	 * @since 2.5
	 *
	 * @param LoggerInterface $logger
	 */
	public function setLogger( LoggerInterface $logger ) {
		$this->logger = $logger;
	}

	/**
	 * @since 2.5
	 *
	 * @param CompositePropertyTableDiffIterator $compositePropertyTableDiffIterator
	 *
	 * @return string
	 */
	public function getSlot( CompositePropertyTableDiffIterator $compositePropertyTableDiffIterator ) {
		return $this->prefix . self::CACHE_NAMESPACE . $compositePropertyTableDiffIterator->getHash();
	}

	/**
	 * @since 2.5
	 *
	 * @param CompositePropertyTableDiffIterator $compositePropertyTableDiffIterator
	 *
	 * @return null|string
	 */
	public function createSlotFrom( CompositePropertyTableDiffIterator $compositePropertyTableDiffIterator ) {

		$orderedDiffByTable = $compositePropertyTableDiffIterator->getOrderedDiffByTable();

		if ( $orderedDiffByTable === array() ) {
			return null;
		}

		$slot = $this->getSlot( $compositePropertyTableDiffIterator );

		$this->cache->save(
			$slot,
			$orderedDiffByTable
		);

		return $slot;
	}

	/**
	 * @since 2.5
	 *
	 * @param string $slot
	 */
	public function delete( $slot ) {
		$this->cache->delete( $slot );
	}

	/**
	 * @since 2.5
	 *
	 * @param string $slot
	 * @param string|null $tableName
	 *
	 * @return TableChange[]
	 */
	public function newTableChangeOpsFrom( $slot, $tableName = null ) {

		$start = microtime( true );

		$diffByTable = $this->cache->fetch( $slot );
		$tableChangeOps = array();

		if ( $diffByTable === false || $diffByTable === null ) {
			$this->log( __METHOD__ . ' unknown slot :: '. $slot );
			return array();
		}

		foreach ( $diffByTable as $tblName => $diff ) {

			if ( $tableName !== null && $tableName !== $tblName ) {
				continue;
			}

			$tableChangeOps[] = new TableChangeOp( $tblName, $diff );
		}

		$this->log( __METHOD__ . ' procTime (sec): '. round( ( microtime( true ) - $start ), 5 ) );

		return $tableChangeOps;
	}

	private function log( $message, $context = array() ) {

		if ( $this->logger === null ) {
			return;
		}

		$this->logger->info( $message, $context );
	}

}

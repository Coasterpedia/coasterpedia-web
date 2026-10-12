<?php
/**
 * Repair Cargo's _pageData in place, page by page, instead of rebuilding it.
 *
 * Why not setCargoPageData.php --replacement:
 *  - On MediaWiki 1.45+ (no categorylinks.cl_to) and with no ParserOutput, Cargo
 *    3.9.4 reads categories from Title::getParentCategories() and implodes the
 *    array's values, which are the page's own title: every category it writes
 *    is the page name ("Red Fire|Red Fire|…").
 *  - While _pageData__NEXT exists, every save and every category change writes
 *    there only, so the live table goes stale for the whole run.
 *  - One deadlock ends the run, and a rerun drops the replacement and starts again.
 *
 * Here each page is checked against the wiki and rewritten only if its row is
 * missing, duplicated, older than the page's latest revision, or holds other
 * categories than categorylinks does. A rewrite is Cargo's own save path
 * (CargoHooks::deletePageFromSpecialTable, then CargoPageData::storeValuesForPage)
 * on the live table, given a ParserOutput that carries only the categories, so
 * nothing is parsed. Category names are stored with spaces, as a save stores them.
 *
 *   --dry-run    count what would change, with examples; write nothing
 *   (default)    repair, resuming after the last page done; rerun to continue
 *   --status     where a run is up to
 *
 * Exit 75: stopped cleanly (--max-seconds), run again to continue.
 * Exit 73: another repair is running.
 *
 * Written against Cargo 3.9.4 and MediaWiki 1.46.
 */

// @codeCoverageIgnoreStart
require_once __DIR__ . '/Maintenance.php';
// @codeCoverageIgnoreEnd

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Title\Title;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\Rdbms\DBError;
use Wikimedia\Rdbms\DBQueryError;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IMaintainableDatabase;
use Wikimedia\Timestamp\TimestampFormat as TS;

class CpPageDataRepair extends Maintenance {

	private const TABLE = '_pageData';
	private const CATEGORIES = '_pageData___categories';
	private const EXIT_RESUME = 75;
	private const EXIT_BUSY = 73;
	private const LEASE_TTL = 120;
	private const BATCH = 200;
	/** As cpCargoRebuild: lock wait, deadlock, snapshot, duplicate _ID race, lost connection. */
	private const TRANSIENT = [ 1205, 1213, 1020, 1062, 2006, 2013 ];
	/** Columns storeValuesForPage fills from a ParserOutput: a categories-only one would blank them. */
	private const PO_COLUMNS = [ 'outgoingLinks', 'displayTitle' ];
	private const EXAMPLES = 10;

	private IMaintainableDatabase $cdb;

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'Cargo' );
		$this->addDescription( 'Repair Cargo _pageData in place: missing, stale or wrong-category rows.' );
		$this->addOption( 'dry-run', 'Count what would change; write nothing' );
		$this->addOption( 'status', 'Show progress' );
		$this->addOption( 'restart', 'Forget saved progress and start from the first page' );
		$this->addOption( 'from', 'Start after this page ID', false, true );
		$this->addOption( 'retries', 'Attempts per page (default 6)', false, true );
		$this->addOption( 'sleep', 'Milliseconds between rewritten pages (default 0)', false, true );
		$this->addOption( 'max-seconds', 'Stop cleanly after this long (exit 75)', false, true );
	}

	public function execute() {
		global $wgCargoPageDataColumns;
		$this->cdb = CargoUtils::getDB();

		if ( $this->hasOption( 'status' ) ) {
			$this->status();
			return;
		}
		if ( !in_array( 'categories', $wgCargoPageDataColumns, true ) ) {
			$this->fatalError( '$wgCargoPageDataColumns has no categories; nothing to repair.' );
		}
		$blanked = array_intersect( self::PO_COLUMNS, $wgCargoPageDataColumns );
		if ( $blanked ) {
			$this->fatalError( 'This would blank ' . implode( ', ', $blanked ) . ', which come from a full parse.' );
		}
		if ( !$this->cdb->tableExists( self::TABLE, __METHOD__ ) ) {
			$this->fatalError( self::TABLE . ' does not exist.' );
		}
		if ( $this->cdb->tableExists( self::TABLE . '__NEXT', __METHOD__ ) ) {
			$this->fatalError( self::TABLE . '__NEXT exists, so saves are going there and a repair would land in it. '
				. 'Delete it first: setCargoPageData.php --replacement --delete' );
		}
		$this->repair( $this->hasOption( 'dry-run' ) );
	}

	// ---- Repair ------------------------------------------------------------

	private function repair( bool $dryRun ): void {
		$state = $this->hasOption( 'restart' ) ? [] : $this->loadState();
		$state += [ 'last' => 0, 'checked' => 0, 'written' => 0, 'failed' => [], 'reasons' => [] ];
		if ( $this->hasOption( 'from' ) ) {
			$state['last'] = (int)$this->getOption( 'from' );
		}
		if ( $dryRun ) {
			// A dry run reports on everything and never moves a real run's progress.
			$state = [ 'last' => (int)$this->getOption( 'from', 0 ), 'checked' => 0, 'written' => 0,
				'failed' => [], 'reasons' => [] ];
		} else {
			$this->claim();
		}
		$examples = [];
		$deadline = $this->hasOption( 'max-seconds' ) ? time() + (int)$this->getOption( 'max-seconds' ) : null;
		$pause = (int)$this->getOption( 'sleep', 0 );

		$this->output( sprintf( "%s %s from page ID %d.\n", wfTimestamp( TS::ISO_8601 ),
			$dryRun ? 'Dry run' : 'Repairing ' . self::TABLE, $state['last'] ) );
		while ( true ) {
			$pages = $this->pageBatch( $state['last'] );
			if ( !$pages ) {
				break;
			}
			$ids = array_keys( $pages );
			$wanted = $this->categoriesFor( $ids );
			$stored = $this->storedRows( $ids );
			foreach ( $pages as $id => $page ) {
				$state['checked']++;
				$state['last'] = $id;
				$reason = $this->reason( $page, $wanted[$id] ?? [], $stored[$id] ?? [] );
				if ( $reason === null ) {
					continue;
				}
				$state['reasons'][$reason] = ( $state['reasons'][$reason] ?? 0 ) + 1;
				if ( count( $examples[$reason] ?? [] ) < self::EXAMPLES ) {
					$examples[$reason][] = $page['name'];
				}
				if ( !$dryRun ) {
					$this->rewrite( $id, $wanted[$id] ?? [], $state );
					if ( $pause ) {
						usleep( $pause * 1000 );
					}
				}
				if ( $this->mustStop( $deadline ) ) {
					$this->saveState( $state );
					$this->release();
					$this->output( "Stopping cleanly at page ID $id; run again to continue.\n" );
					$this->report( $state, $examples );
					exit( self::EXIT_RESUME );
				}
			}
			$this->renew( $dryRun );
			if ( !$dryRun ) {
				$this->saveState( $state );
			}
			$this->output( sprintf( "%s …page ID %d, %d checked, %d %s, %d failed\n",
				wfTimestamp( TS::ISO_8601 ), $state['last'], $state['checked'],
				$dryRun ? array_sum( $state['reasons'] ) : $state['written'],
				$dryRun ? 'to rewrite' : 'rewritten', count( $state['failed'] ) ) );
			$this->getServiceContainer()->getLinkCache()->clear();
		}

		$orphans = $this->orphanPages();
		$this->output( count( $orphans ) . " page ID(s) hold rows but no longer exist.\n" );
		if ( !$dryRun ) {
			foreach ( $orphans as $id ) {
				CargoHooks::deletePageFromSpecialTable( $id, self::TABLE );
			}
			$this->commitTransactionRound( __METHOD__ );
			$state['finished'] = wfTimestampNow();
			$this->saveState( $state );
			$this->release();
		}
		$this->report( $state, $examples );
	}

	/**
	 * Why this page's row needs rewriting, or null if it is right.
	 *
	 * @param array $page [ 'name' => string, 'touched' => TS::DB of the latest revision ]
	 * @param string[] $wanted Category names from categorylinks, spaces, sorted
	 * @param array[] $rows Stored rows: [ 'modified' => ?string, 'categories' => string[] ]
	 */
	private function reason( array $page, array $wanted, array $rows ): ?string {
		if ( !$rows ) {
			return 'missing row';
		}
		if ( count( $rows ) > 1 ) {
			return 'duplicate rows';
		}
		$row = $rows[0];
		if ( $row['modified'] !== $page['touched'] ) {
			return 'older than the latest revision';
		}
		$have = $row['categories'];
		sort( $have, SORT_STRING );
		return $have === $wanted ? null : 'wrong categories';
	}

	/**
	 * Delete the page's row and store it afresh, as a save does. The delete makes
	 * a retry after a half-written attempt safe.
	 *
	 * @param string[] $categories
	 */
	private function rewrite( int $id, array $categories, array &$state ): void {
		$title = Title::newFromID( $id );
		if ( !$title ) {
			return;
		}
		$po = new ParserOutput();
		foreach ( $categories as $name ) {
			$po->addCategory( str_replace( ' ', '_', $name ) );
		}
		$attempts = max( 1, (int)$this->getOption( 'retries', 6 ) );
		for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
			try {
				CargoHooks::deletePageFromSpecialTable( $id, self::TABLE );
				CargoPageData::storeValuesForPage( $title, false, true, false, $po );
				$this->commitTransactionRound( __METHOD__ );
				$state['written']++;
				$state['failed'] = array_values( array_diff( $state['failed'], [ $id ] ) );
				return;
			} catch ( DBError $e ) {
				$this->rollbackAll();
				$errno = $e instanceof DBQueryError ? (int)$e->errno : 0;
				$permanent = $errno !== 0 && !in_array( $errno, self::TRANSIENT, true );
				if ( $permanent || $attempt === $attempts ) {
					$this->fail( $id, $title, $e, $state );
					return;
				}
				$wait = min( 60, 2 ** $attempt ) + mt_rand( 0, 1000 ) / 1000;
				$this->output( sprintf( "  %s: %s; retry %d/%d in %.1fs\n",
					$title->getPrefixedText(), $this->shortError( $e ), $attempt, $attempts - 1, $wait ) );
				usleep( (int)( $wait * 1e6 ) );
			} catch ( Throwable $e ) {
				$this->rollbackAll();
				$this->fail( $id, $title, $e, $state );
				return;
			}
		}
	}

	// ---- Reads -------------------------------------------------------------

	/** @return array<int,array> page ID => [ 'name', 'touched' ], the next batch after $after */
	private function pageBatch( int $after ): array {
		$dbr = $this->getReplicaDB();
		$res = $dbr->newSelectQueryBuilder()
			->select( [ 'page_id', 'page_namespace', 'page_title', 'rev_timestamp' ] )
			->from( 'page' )
			->join( 'revision', null, 'rev_id = page_latest' )
			->where( $dbr->expr( 'page_id', '>', $after ) )
			->orderBy( 'page_id' )->limit( self::BATCH )
			->caller( __METHOD__ )->fetchResultSet();
		$out = [];
		foreach ( $res as $row ) {
			$out[(int)$row->page_id] = [
				'name' => Title::makeTitle( (int)$row->page_namespace, $row->page_title )->getPrefixedText(),
				// The form Cargo stores _modificationDate in.
				'touched' => wfTimestamp( TS::DB, $row->rev_timestamp ),
			];
		}
		return $out;
	}

	/**
	 * Each page's categories as MediaWiki records them, spaced and sorted.
	 *
	 * @param int[] $ids
	 * @return array<int,string[]>
	 */
	private function categoriesFor( array $ids ): array {
		$dbr = $this->getReplicaDB();
		$qb = $dbr->newSelectQueryBuilder()->from( 'categorylinks' )
			->where( [ 'cl_from' => $ids ] )->caller( __METHOD__ );
		if ( $dbr->fieldExists( 'categorylinks', 'cl_target_id', __METHOD__ ) ) {
			$qb->select( [ 'cl_from', 'name' => 'lt_title' ] )
				->join( 'linktarget', null, 'lt_id = cl_target_id' );
		} else {
			$qb->select( [ 'cl_from', 'name' => 'cl_to' ] );
		}
		$out = [];
		foreach ( $qb->fetchResultSet() as $row ) {
			$out[(int)$row->cl_from][] = str_replace( '_', ' ', $row->name );
		}
		foreach ( $out as &$names ) {
			$names = array_values( array_unique( $names ) );
			sort( $names, SORT_STRING );
		}
		return $out;
	}

	/**
	 * The live rows for these pages.
	 *
	 * @param int[] $ids
	 * @return array<int,array[]> page ID => [ [ 'modified' => ?string, 'categories' => string[] ], … ]
	 */
	private function storedRows( array $ids ): array {
		$rows = $this->cdb->newSelectQueryBuilder()
			->select( [ 'id' => $this->q( '_ID' ), 'page' => $this->q( '_pageID' ), 'modified' => $this->q( '_modificationDate' ) ] )
			->from( self::TABLE )
			->where( [ $this->q( '_pageID' ) => $ids ] )
			->caller( __METHOD__ )->fetchResultSet();
		$byRow = [];
		$out = [];
		foreach ( $rows as $row ) {
			$byRow[(int)$row->id] = (int)$row->page;
			$out[(int)$row->page][(int)$row->id] = [ 'modified' => $row->modified, 'categories' => [] ];
		}
		if ( $byRow ) {
			$cats = $this->cdb->newSelectQueryBuilder()
				->select( [ 'row' => $this->q( '_rowID' ), 'value' => $this->q( '_value' ) ] )
				->from( self::CATEGORIES )
				->where( [ $this->q( '_rowID' ) => array_keys( $byRow ) ] )
				->caller( __METHOD__ )->fetchResultSet();
			foreach ( $cats as $cat ) {
				$out[$byRow[(int)$cat->row]][(int)$cat->row]['categories'][] = $cat->value;
			}
		}
		return array_map( 'array_values', $out );
	}

	/** @return int[] Page IDs with rows whose page no longer exists */
	private function orphanPages(): array {
		$held = array_map( 'intval', $this->cdb->newSelectQueryBuilder()
			->select( $this->q( '_pageID' ) )->distinct()->from( self::TABLE )
			->caller( __METHOD__ )->fetchFieldValues() );
		$orphans = [];
		foreach ( array_chunk( $held, 1000 ) as $chunk ) {
			$exist = array_map( 'intval', $this->getReplicaDB()->newSelectQueryBuilder()
				->select( 'page_id' )->from( 'page' )->where( [ 'page_id' => $chunk ] )
				->caller( __METHOD__ )->fetchFieldValues() );
			$orphans = array_merge( $orphans, array_diff( $chunk, $exist ) );
		}
		return array_values( $orphans );
	}

	// ---- Output ------------------------------------------------------------

	private function report( array $state, array $examples ): void {
		$this->output( sprintf( "\n%d checked, %d rewritten, %d failed.\n",
			$state['checked'], $state['written'], count( $state['failed'] ) ) );
		foreach ( $state['reasons'] as $reason => $n ) {
			$this->output( sprintf( "  %-32s %7d", $reason, $n ) );
			$this->output( isset( $examples[$reason] ) ? '  e.g. ' . implode( '; ', $examples[$reason] ) . "\n" : "\n" );
		}
		if ( $state['failed'] ) {
			$this->output( 'Failed page IDs: ' . implode( ', ', $state['failed'] ) . "\n" );
		}
	}

	private function status(): void {
		$state = $this->loadState();
		if ( !$state ) {
			$this->output( "No run recorded.\n" );
			return;
		}
		$this->output( sprintf( "After page ID %d: %d checked, %d rewritten, %d failed%s\n",
			$state['last'] ?? 0, $state['checked'] ?? 0, $state['written'] ?? 0, count( $state['failed'] ?? [] ),
			isset( $state['finished'] ) ? ", finished {$state['finished']}" : '' ) );
		$lease = $this->stash()->get( $this->stateKey( 'lease' ) );
		$this->output( $lease ? "Running: $lease\n" : "Not running.\n" );
	}

	private function fail( int $id, Title $title, Throwable $e, array &$state ): void {
		$state['failed'][] = $id;
		$state['failed'] = array_values( array_unique( $state['failed'] ) );
		$this->output( sprintf( "  FAILED %s (page ID %d): %s\n", $title->getPrefixedText(), $id, $this->shortError( $e ) ) );
	}

	private function shortError( Throwable $e ): string {
		return $e instanceof DBQueryError ? 'error ' . $e->errno . ': ' . $e->error : get_class( $e ) . ': ' . $e->getMessage();
	}

	private function rollbackAll(): void {
		if ( $this->cdb->trxLevel() ) {
			$this->cdb->rollback( __METHOD__, IDatabase::FLUSHING_INTERNAL );
		}
		$this->rollbackTransactionRound( __METHOD__ );
	}

	private function mustStop( ?int $deadline ): bool {
		return $deadline !== null && time() >= $deadline;
	}

	// ---- One run at a time, progress in the main stash ----------------------

	private function claim(): void {
		$holder = gethostname() . ':' . getmypid();
		if ( !$this->stash()->add( $this->stateKey( 'lease' ), $holder, self::LEASE_TTL ) ) {
			$this->output( 'Another repair is running: ' . $this->stash()->get( $this->stateKey( 'lease' ) ) . "\n" );
			exit( self::EXIT_BUSY );
		}
	}

	private function renew( bool $dryRun ): void {
		if ( !$dryRun ) {
			$this->stash()->set( $this->stateKey( 'lease' ), gethostname() . ':' . getmypid(), self::LEASE_TTL );
		}
	}

	private function release(): void {
		$this->stash()->delete( $this->stateKey( 'lease' ) );
	}

	private function stash(): BagOStuff {
		return $this->getServiceContainer()->getMainObjectStash();
	}

	private function stateKey( string $scope ): string {
		return $this->stash()->makeKey( 'cp-pagedata-repair', $scope );
	}

	private function saveState( array $state ): void {
		$this->stash()->set( $this->stateKey( 'progress' ), $state, BagOStuff::TTL_INDEFINITE );
	}

	private function loadState(): array {
		$state = $this->stash()->get( $this->stateKey( 'progress' ) );
		return is_array( $state ) ? $state : [];
	}

	private function q( string $field ): string {
		return $this->cdb->addIdentifierQuotes( $field );
	}
}

// @codeCoverageIgnoreStart
$maintClass = CpPageDataRepair::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd

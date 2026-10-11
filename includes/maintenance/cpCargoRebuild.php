<?php
/**
 * Rebuild one Cargo table into its replacement (`<Table>__NEXT`) without the
 * single-pass fragility of Cargo's cargoRecreateData.php.
 *
 * Cargo's script walks every page once with nothing around each parse, so one
 * lock wait or deadlock ends the run and a rerun starts from page 1. Meanwhile
 * #cargo_store writes every save only to the replacement, so the live table
 * goes stale and a failed run never gives those edits back. This splits the
 * work into steps that each survive failure:
 *
 *   --create     make <Table>__NEXT (the only step that drops anything)
 *   --populate   parse pages in page-ID order, each retried on DB errors,
 *                progress kept in the main stash; rerun to resume
 *   --retry-failed  reparse only the pages that ran out of retries
 *   --compare    row and page counts, live vs replacement, before switching
 *   --resync     after abandoning a run (replacement deleted): re-store every
 *                page changed since the run began into the live table
 *   --audit      pages whose next save would duplicate their rows (read-only)
 *   --status     where a run is up to
 *
 * Switching is still Special:SwitchCargoTable. Storage goes through Cargo's own
 * CargoUtils::parsePageForStorage, so rows are exactly what Cargo writes.
 *
 * Exit code 75 means "stopped cleanly, run again to continue" (memory or time
 * limit); scripts/cargo-rebuild.sh loops on it.
 *
 * Written against Cargo 3.9.4 and MediaWiki 1.46.
 */

// @codeCoverageIgnoreStart
require_once __DIR__ . '/Maintenance.php';
// @codeCoverageIgnoreEnd

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Title\Title;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\Timestamp\TimestampFormat as TS;
use Wikimedia\Rdbms\DBError;
use Wikimedia\Rdbms\DBQueryError;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IMaintainableDatabase;

class CpCargoRebuild extends Maintenance {

	private const EXIT_RESUME = 75;
	/**
	 * MySQL errors that clear by themselves: lock wait timeout, deadlock, a
	 * snapshot conflict, a duplicate _ID from two writers racing for the max,
	 * a dropped connection. Anything else (a value too long, a bad value) fails
	 * the same way every time, so it is not retried.
	 */
	private const TRANSIENT = [ 1205, 1213, 1020, 1062, 2006, 2013 ];
	/** What to do about the permanent ones an article can cause. */
	private const HINTS = [
		1406 => 'a value is longer than its Cargo column holds (300 characters for a String)',
		1366 => 'a value is not valid for its column type',
		1264 => 'a number is out of range for its column',
	];
	/** Another populate holds this table and shard; the wrapper waits and tries again. */
	private const EXIT_BUSY = 73;
	/** Seconds a populate's claim outlives its last page. */
	private const LEASE_TTL = 120;
	private const BATCH = 200;

	private string $table;
	/** @var string[] DB keys of the templates that declare or attach this table */
	private array $templates = [];
	private IMaintainableDatabase $cdb;

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'Cargo' );
		$this->addDescription( 'Resumable rebuild of one Cargo table into its replacement.' );
		$this->addOption( 'table', 'The Cargo table', true, true );
		$this->addOption( 'create', 'Create <table>__NEXT' );
		$this->addOption( 'populate', 'Fill <table>__NEXT, resuming where the last run stopped' );
		$this->addOption( 'retry-failed', 'Reparse only the pages that ran out of retries' );
		$this->addOption( 'compare', 'Compare the live table with its replacement' );
		$this->addOption( 'resync', 'Re-store pages changed since a run began into the live table (no replacement may exist)' );
		$this->addOption( 'status', 'Show progress' );
		$this->addOption( 'audit', 'List pages with rows in the live table but no cargo_pages record (read-only)' );
		$this->addOption( 'since', 'For --resync: YYYYMMDDHHMMSS (default: when --create ran)', false, true );
		$this->addOption( 'restart', 'For --populate: forget saved progress and start from the first page' );
		$this->addOption( 'from', 'For --populate: start after this page ID', false, true );
		$this->addOption( 'shard', 'For --populate: i/n, take the pages whose ID mod n is i', false, true );
		$this->addOption( 'retries', 'Attempts per page (default 6)', false, true );
		$this->addOption( 'sleep', 'Milliseconds between pages, to ease the database (default 0)', false, true );
		$this->addOption( 'max-seconds', 'Stop cleanly after this long (exit 75)', false, true );
		$this->addOption( 'dry-run', 'Say what would happen; write nothing' );
	}

	public function execute() {
		$this->table = $this->getOption( 'table' );
		if ( str_ends_with( $this->table, '__NEXT' ) ) {
			$this->fatalError( 'Name the live table, not its replacement.' );
		}
		$this->cdb = CargoUtils::getDB();
		$this->templates = $this->templatesForTable();
		if ( !$this->templates ) {
			$this->fatalError( "No template declares or attaches \"{$this->table}\"." );
		}

		$modes = array_filter( [ 'create', 'populate', 'retry-failed', 'compare', 'resync', 'status', 'audit' ],
			fn ( $m ) => $this->hasOption( $m ) );
		if ( count( $modes ) !== 1 ) {
			$this->fatalError( 'Choose exactly one of --create, --populate, --retry-failed, --compare, --resync, --status, --audit.' );
		}
		match ( reset( $modes ) ) {
			'create' => $this->create(),
			'populate' => $this->populate( false ),
			'retry-failed' => $this->populate( true ),
			'compare' => $this->compare(),
			'resync' => $this->resync(),
			'status' => $this->status(),
			'audit' => $this->audit(),
		};
	}

	// ---- Modes -------------------------------------------------------------

	private function create(): void {
		$next = $this->next();
		if ( $this->cdb->tableExists( $next, __METHOD__ ) ) {
			$this->fatalError( "$next already exists. Resume it with --populate, or delete it first." );
		}
		$this->output( "Templates: " . implode( ', ', $this->templates ) . "\n" );
		$this->output( "Pages to parse: " . $this->countPages() . "\n" );
		// What the replacement will be built from: the declaring template's
		// declaration as it stands now. After flipping Module:SchemaState, check
		// the new columns are here; if not, purge the template and look again.
		$schemaString = CargoUtils::getPageProp( CargoUtils::getTemplateIDForDBTable( $this->table ), 'CargoFields' );
		if ( $schemaString !== null ) {
			$fields = [];
			foreach ( CargoTableSchema::newFromDBString( $schemaString )->mFieldDescriptions as $name => $fd ) {
				$fields[] = $name . ' (' . ( $fd->mIsList ? 'list of ' : '' ) . $fd->mType . ')';
			}
			$this->output( "Declares: " . implode( ', ', $fields ) . "\n" );
		}
		if ( $this->hasOption( 'dry-run' ) ) {
			$this->output( "Dry run: $next not created.\n" );
			return;
		}
		$templateId = CargoUtils::getTemplateIDForDBTable( $this->table );
		$user = MediaWiki\User\User::newSystemUser( 'Maintenance script', [ 'steal' => true ] );
		CargoUtils::recreateDBTablesForTemplate( $templateId, true, $user, $this->table );
		// From here every save goes to the replacement only; remember when, for --resync.
		$this->saveState( [ 'started' => wfTimestampNow() ], 'run' );
		foreach ( $this->allStateKeys() as $key ) {
			$this->stash()->delete( $key );
		}
		$this->output( "Created $next at " . wfTimestampNow() . ". Saves now go to it only.\n" );
		$this->output( "Next: --populate (scripts/cargo-rebuild.sh {$this->table})\n" );
	}

	/**
	 * Parse every page into the replacement, or with $failedOnly just the pages a
	 * previous run gave up on.
	 */
	private function populate( bool $failedOnly ): void {
		$next = $this->next();
		if ( !$this->cdb->tableExists( $next, __METHOD__ ) ) {
			// Without a replacement, #cargo_store would write to the live table.
			$this->fatalError( "$next does not exist. Run --create first." );
		}
		[ $shard, $shards ] = $this->shard();
		$scope = "shard-$shard-of-$shards";
		$state = $this->loadState( $scope ) + [ 'last' => 0, 'done' => 0, 'failed' => [] ];
		if ( $this->hasOption( 'restart' ) ) {
			$state = [ 'last' => 0, 'done' => 0, 'failed' => [] ];
		}
		if ( $this->hasOption( 'from' ) ) {
			$state['last'] = (int)$this->getOption( 'from' );
		}

		$dryRun = $this->hasOption( 'dry-run' );
		if ( !$dryRun ) {
			$this->claim( $scope );
		}
		$deadline = $this->hasOption( 'max-seconds' ) ? time() + (int)$this->getOption( 'max-seconds' ) : null;
		$pause = (int)$this->getOption( 'sleep', 0 );

		if ( $failedOnly ) {
			$queue = $state['failed'];
			$state['failed'] = [];
			$this->output( count( $queue ) . " failed page(s) to retry.\n" );
			foreach ( $queue as $id ) {
				$this->processPage( (int)$id, $next, $state, $dryRun );
				$this->saveState( $state, $scope );
				$this->renew( $scope, $dryRun );
			}
			$this->release( $scope, $dryRun );
			$this->report( $state );
			return;
		}

		$doneBefore = $state['done'];
		$this->output( sprintf( "%s Populating %s (%s) from page ID %d (%d done so far).\n",
			wfTimestamp( TS::ISO_8601 ), $next, $scope, $state['last'], $state['done'] ) );
		while ( true ) {
			$ids = $this->pageIds( $state['last'], self::BATCH, $shard, $shards );
			if ( !$ids ) {
				break;
			}
			foreach ( $ids as $id ) {
				$this->processPage( $id, $next, $state, $dryRun );
				$state['last'] = $id;
				$this->renew( $scope, $dryRun );
				if ( $pause ) {
					usleep( $pause * 1000 );
				}
				if ( $this->mustStop( $deadline ) ) {
					$this->saveState( $state, $scope );
					$this->release( $scope, $dryRun );
					$this->output( "Stopping cleanly at page ID $id; run again to continue.\n" );
					$this->report( $state );
					exit( self::EXIT_RESUME );
				}
			}
			$this->saveState( $state, $scope );
			$this->output( sprintf( "%s …page ID %d, %d done, %d failed\n",
				wfTimestamp( TS::ISO_8601 ), $state['last'], $state['done'], count( $state['failed'] ) ) );
			$this->getServiceContainer()->getLinkCache()->clear();
		}
		// A rerun that found nothing left keeps the real finish time.
		if ( $state['done'] > $doneBefore || !isset( $state['finished'] ) ) {
			$state['finished'] = wfTimestampNow();
		}
		$this->saveState( $state, $scope );
		$this->release( $scope, $dryRun );
		$this->report( $state );
		$started = $this->loadState( 'run' )['started'] ?? null;
		if ( $started ) {
			$secs = (int)wfTimestamp( TS::UNIX, $state['finished'] ) - (int)wfTimestamp( TS::UNIX, $started );
			$this->output( sprintf( "Since --create: %dh %02dm.\n", intdiv( $secs, 3600 ), intdiv( $secs % 3600, 60 ) ) );
		}
		$this->output( $state['failed']
			? "Then --retry-failed, then --compare.\n"
			: "Next: --compare, then Special:SwitchCargoTable.\n" );
	}

	/** Counts that should agree before switching, and the pages that differ. */
	private function compare(): void {
		$next = $this->next();
		if ( !$this->cdb->tableExists( $next, __METHOD__ ) ) {
			$this->fatalError( "$next does not exist." );
		}
		foreach ( [ $this->table, $next ] as $t ) {
			$rows = $this->cdb->newSelectQueryBuilder()->select( 'COUNT(*)' )->from( $t )
				->caller( __METHOD__ )->fetchField();
			$pages = $this->cdb->newSelectQueryBuilder()->select( 'COUNT(DISTINCT ' . $this->q( '_pageID' ) . ')' )
				->from( $t )->caller( __METHOD__ )->fetchField();
			$this->output( sprintf( "%-40s %8d rows %8d pages\n", $t, $rows, $pages ) );
		}
		$live = $this->distinctPages( $this->table );
		$repl = $this->distinctPages( $next );
		$this->listDiff( 'In the live table, missing from the replacement', array_diff( $live, $repl ) );
		$this->listDiff( 'New in the replacement', array_diff( $repl, $live ) );
		$this->output( "Rows can differ legitimately (pages edited or created since --create).\n" );
	}

	/**
	 * After abandoning a run. While the replacement existed, saves skipped the
	 * live table but still deleted the page's cargo_pages record for it, so a
	 * plain re-save would leave the stale row and add a second. Each page's rows
	 * are deleted explicitly, then re-stored.
	 */
	private function resync(): void {
		if ( $this->cdb->tableExists( $this->next(), __METHOD__ ) ) {
			$this->fatalError( "{$this->next()} still exists. Delete it (Special:DeleteCargoTable) before resyncing, "
				. "or saves would keep going to it." );
		}
		$since = $this->getOption( 'since' ) ?? ( $this->loadState( 'run' )['started'] ?? null );
		if ( !$since || !preg_match( '/^\d{14}$/', $since ) ) {
			$this->fatalError( 'Give --since=YYYYMMDDHHMMSS (no saved start time was found).' );
		}
		$dryRun = $this->hasOption( 'dry-run' );
		$dbr = $this->getReplicaDB();

		$changed = array_map( 'intval', $dbr->newSelectQueryBuilder()
			->select( 'rc_cur_id' )->distinct()
			->from( 'recentchanges' )
			->where( [ $dbr->expr( 'rc_timestamp', '>=', $dbr->timestamp( $since ) ), $dbr->expr( 'rc_cur_id', '>', 0 ) ] )
			->caller( __METHOD__ )->fetchFieldValues() );
		$inScope = array_flip( $this->pagesAmong( $changed ) );
		$live = $this->distinctPages( $this->table );
		// A null edit fires Cargo's save hook but never reaches recentchanges, so
		// also take every page whose live rows lost their cargo_pages record: that
		// is what any save during the run left behind, whatever made it.
		$unregistered = $this->unregisteredPages( $live );
		// Changed pages that still use the table, plus any that held rows here
		// (a page that dropped the template, or was deleted, keeps a stale row).
		$targets = array_values( array_unique( array_merge(
			array_keys( $inScope ),
			array_values( array_intersect( $changed, $live ) ),
			$unregistered
		) ) );
		sort( $targets );
		$this->output( sprintf( "%d page(s) to resync into %s: %d changed since %s, %d with rows but no cargo_pages record.\n",
			count( $targets ), $this->table, count( $inScope ), $since, count( $unregistered ) ) );

		$state = [ 'done' => 0, 'failed' => [] ];
		foreach ( $targets as $id ) {
			$this->processPage( $id, $this->table, $state, $dryRun );
		}

		// Pages deleted outright during the run: their rows outlived them.
		$gone = array_diff( $live, $this->existingPageIds( $live ) );
		$this->output( count( $gone ) . " row set(s) belong to pages that no longer exist.\n" );
		foreach ( $gone as $id ) {
			if ( !$dryRun ) {
				$this->deleteRowsForPage( $this->table, (int)$id );
			}
		}
		$this->report( $state );
	}

	/**
	 * Read-only. Pages with rows in the live table but no cargo_pages record
	 * for it: their next ordinary save will not delete those rows and will add
	 * a second set. A save during any rebuild that was later abandoned leaves
	 * exactly this. Fix with --resync (any --since; the gap is found regardless).
	 */
	private function audit(): void {
		if ( $this->cdb->tableExists( $this->next(), __METHOD__ ) ) {
			$this->output( "{$this->next()} exists, so saves are skipping the live table: a gap now is expected.\n" );
		}
		$live = $this->distinctPages( $this->table );
		$gap = $this->unregisteredPages( $live );
		$this->output( count( $live ) . " page(s) hold rows in {$this->table}.\n" );
		$this->listDiff( 'Rows with no cargo_pages record (next save duplicates them)', $gap );
		// An article using the template with no row: its store failed, every
		// save, silently (a value too long for its column, say). It is missing
		// from every count and map until the article is fixed.
		$this->listDiff( 'Articles using the template with no row (their store fails)',
			array_diff( $this->articleIds(), $live ) );
		$dupes = $this->cdb->newSelectQueryBuilder()
			->select( [ 'page' => $this->q( '_pageID' ), 'n' => 'COUNT(*)' ] )
			->from( $this->table )
			->groupBy( $this->q( '_pageID' ) )
			->having( 'COUNT(*) > 1' )
			->caller( __METHOD__ )->fetchResultSet();
		$this->output( $dupes->numRows() . " page(s) hold more than one row (expected only where one page stores several, as past locations do).\n" );
	}

	/** Main-namespace pages transcluding the table's templates. */
	private function articleIds(): array {
		$dbr = $this->getReplicaDB();
		return array_map( 'intval', $dbr->newSelectQueryBuilder()
			->select( 'tl_from' )->distinct()
			->from( 'templatelinks' )
			->join( 'linktarget', null, 'lt_id = tl_target_id' )
			->join( 'page', null, 'page_id = tl_from' )
			->where( [ 'lt_namespace' => NS_TEMPLATE, 'lt_title' => $this->templates, 'page_namespace' => NS_MAIN ] )
			->caller( __METHOD__ )->fetchFieldValues() );
	}

	/** Of $pageIds, those with no cargo_pages record for the live table. */
	private function unregisteredPages( array $pageIds ): array {
		if ( !$pageIds ) {
			return [];
		}
		$registered = array_map( 'intval', CargoUtils::getMainDBForRead()->newSelectQueryBuilder()
			->select( 'page_id' )->from( 'cargo_pages' )
			->where( [ 'table_name' => $this->table ] )
			->caller( __METHOD__ )->fetchFieldValues() );
		return array_values( array_diff( $pageIds, $registered ) );
	}

	private function status(): void {
		$next = $this->next();
		$this->output( "Templates: " . implode( ', ', $this->templates ) . "\n" );
		$this->output( "$next " . ( $this->cdb->tableExists( $next, __METHOD__ ) ? 'exists' : 'does not exist' ) . ".\n" );
		$run = $this->loadState( 'run' );
		if ( isset( $run['started'] ) ) {
			$this->output( "Created at {$run['started']}.\n" );
		}
		foreach ( $this->allStateKeys() as $key ) {
			$state = $this->stash()->get( $key );
			if ( is_array( $state ) ) {
				$this->output( sprintf( "%s: after page ID %d, %d done, %d failed%s\n",
					$key, $state['last'] ?? 0, $state['done'] ?? 0, count( $state['failed'] ?? [] ),
					isset( $state['finished'] ) ? ", finished {$state['finished']}" : '' ) );
			}
		}
		$this->output( "Pages to parse in total: " . $this->countPages() . "\n" );
	}

	// ---- One page ----------------------------------------------------------

	/**
	 * Delete the page's rows in $target, then parse it so #cargo_store writes
	 * them afresh. The delete makes every attempt idempotent: a retry after a
	 * half-written page cannot leave duplicates.
	 */
	private function processPage( int $id, string $target, array &$state, bool $dryRun ): void {
		$services = $this->getServiceContainer();
		$page = $services->getWikiPageFactory()->newFromID( $id );
		if ( !$page || !$page->exists() ) {
			if ( !$dryRun ) {
				$this->deleteRowsForPage( $target, $id );
			}
			return;
		}
		if ( $dryRun ) {
			$state['done']++;
			return;
		}
		$title = $page->getTitle();
		$text = CargoUtils::getContentText( $page->getContent() );
		$attempts = max( 1, (int)$this->getOption( 'retries', 6 ) );

		for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
			try {
				$this->deleteRowsForPage( $target, $id );
				// As cargoRecreateData.php does: some code still reads the global.
				$GLOBALS['wgTitle'] = $title;
				CargoStore::$settings['origin'] = 'template';
				CargoStore::$settings['dbTableName'] = $this->table;
				try {
					CargoUtils::parsePageForStorage( $title, $text );
				} finally {
					unset( CargoStore::$settings['origin'] );
				}
				$this->commitTransactionRound( __METHOD__ );
				$state['done']++;
				$state['failed'] = array_values( array_diff( $state['failed'], [ $id ] ) );
				return;
			} catch ( DBError $e ) {
				$this->rollbackAll();
				$errno = $e instanceof DBQueryError ? (int)$e->errno : 0;
				// A query error not on the transient list is the page's data:
				// retrying cannot help. Connection errors carry no errno; retry.
				$permanent = $errno !== 0 && !in_array( $errno, self::TRANSIENT, true );
				if ( $permanent || $attempt === $attempts ) {
					$this->fail( $id, $title, $e, $state,
						isset( self::HINTS[$errno] ) ? self::HINTS[$errno] . '; fix the article, then --retry-failed' : null );
					return;
				}
				// Lock waits and deadlocks clear; back off and try again.
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

	/** Every row the page holds in $table: field tables, files table, main table (as Cargo's own delete). */
	private function deleteRowsForPage( string $table, int $id ): void {
		$cdb = $this->cdb;
		$pageCond = [ $cdb->addIdentifierQuotes( '_pageID' ) => $id ];
		$fieldTables = CargoUtils::getMainDBForRead()->newSelectQueryBuilder()
			->select( 'field_tables' )->from( 'cargo_tables' )->where( [ 'main_table' => $table ] )
			->caller( __METHOD__ )->fetchField();
		foreach ( ( $fieldTables ? unserialize( $fieldTables ) : [] ) ?: [] as $fieldTable ) {
			$cdb->deleteJoin( $fieldTable, $table,
				$cdb->addIdentifierQuotes( '_rowID' ), $cdb->addIdentifierQuotes( '_ID' ),
				$pageCond, __METHOD__ );
		}
		if ( $cdb->tableExists( $table . '___files', __METHOD__ ) ) {
			$cdb->delete( $table . '___files', $pageCond, __METHOD__ );
		}
		$cdb->delete( $table, $pageCond, __METHOD__ );
	}

	private function fail( int $id, Title $title, Throwable $e, array &$state, ?string $hint = null ): void {
		$state['failed'][] = $id;
		$state['failed'] = array_values( array_unique( $state['failed'] ) );
		$this->output( sprintf( "  FAILED %s (page ID %d): %s\n",
			$title->getPrefixedText(), $id, $hint ?? $this->shortError( $e ) ) );
	}

	private function rollbackAll(): void {
		if ( $this->cdb->trxLevel() ) {
			$this->cdb->rollback( __METHOD__, IDatabase::FLUSHING_INTERNAL );
		}
		$this->rollbackTransactionRound( __METHOD__ );
	}

	// ---- Pages -------------------------------------------------------------

	/** The template DB keys whose pages feed this table, as Cargo's own script finds them. */
	private function templatesForTable(): array {
		$ids = array_merge(
			CargoUtils::getAllPageProps( 'CargoTableName' )[$this->table] ?? [],
			CargoUtils::getAllPageProps( 'CargoAttachedTable' )[$this->table] ?? []
		);
		$keys = [];
		foreach ( array_unique( $ids ) as $templateId ) {
			$title = Title::newFromID( $templateId );
			if ( $title ) {
				$keys[] = $title->getDBkey();
			}
		}
		return $keys;
	}

	/** Pages transcluding the table's templates, after $after, in page-ID order. */
	private function pageIds( int $after, int $limit, int $shard = 0, int $shards = 1 ): array {
		$dbr = $this->getReplicaDB();
		$qb = $dbr->newSelectQueryBuilder()
			->select( 'tl_from' )->distinct()
			->from( 'templatelinks' )
			->join( 'linktarget', null, 'lt_id = tl_target_id' )
			->where( [ 'lt_namespace' => NS_TEMPLATE, 'lt_title' => $this->templates ] )
			->andWhere( $dbr->expr( 'tl_from', '>', $after ) )
			->orderBy( 'tl_from' )->limit( $limit )->caller( __METHOD__ );
		if ( $shards > 1 ) {
			$qb->andWhere( 'tl_from % ' . (int)$shards . ' = ' . (int)$shard );
		}
		return array_map( 'intval', $qb->fetchFieldValues() );
	}

	/** Those of $ids that transclude the table's templates. */
	private function pagesAmong( array $ids ): array {
		if ( !$ids ) {
			return [];
		}
		$dbr = $this->getReplicaDB();
		return array_map( 'intval', $dbr->newSelectQueryBuilder()
			->select( 'tl_from' )->distinct()
			->from( 'templatelinks' )
			->join( 'linktarget', null, 'lt_id = tl_target_id' )
			->where( [ 'lt_namespace' => NS_TEMPLATE, 'lt_title' => $this->templates, 'tl_from' => $ids ] )
			->caller( __METHOD__ )->fetchFieldValues() );
	}

	private function countPages(): int {
		$dbr = $this->getReplicaDB();
		return (int)$dbr->newSelectQueryBuilder()
			->select( 'COUNT(DISTINCT tl_from)' )
			->from( 'templatelinks' )
			->join( 'linktarget', null, 'lt_id = tl_target_id' )
			->where( [ 'lt_namespace' => NS_TEMPLATE, 'lt_title' => $this->templates ] )
			->caller( __METHOD__ )->fetchField();
	}

	private function existingPageIds( array $ids ): array {
		if ( !$ids ) {
			return [];
		}
		return array_map( 'intval', $this->getReplicaDB()->newSelectQueryBuilder()
			->select( 'page_id' )->from( 'page' )->where( [ 'page_id' => $ids ] )
			->caller( __METHOD__ )->fetchFieldValues() );
	}

	private function distinctPages( string $table ): array {
		return array_map( 'intval', $this->cdb->newSelectQueryBuilder()
			->select( $this->q( '_pageID' ) )->distinct()->from( $table )
			->caller( __METHOD__ )->fetchFieldValues() );
	}

	// ---- One populate per table and shard ----------------------------------

	/**
	 * Refuse to run beside another populate of the same table and shard: two
	 * would do the same pages twice and fight over the same locks. The claim
	 * expires LEASE_TTL seconds after the holder's last page, so a crashed run
	 * frees it on its own.
	 */
	private function claim( string $scope ): void {
		$holder = gethostname() . ':' . getmypid();
		if ( !$this->stash()->add( $this->stateKey( "lease-$scope" ), $holder, self::LEASE_TTL ) ) {
			$this->output( "Another populate of {$this->table} ($scope) is running: "
				. $this->stash()->get( $this->stateKey( "lease-$scope" ) ) . ". Waiting is safe.\n" );
			exit( self::EXIT_BUSY );
		}
	}

	private function renew( string $scope, bool $dryRun ): void {
		if ( !$dryRun ) {
			$this->stash()->set( $this->stateKey( "lease-$scope" ), gethostname() . ':' . getmypid(), self::LEASE_TTL );
		}
	}

	private function release( string $scope, bool $dryRun ): void {
		if ( !$dryRun ) {
			$this->stash()->delete( $this->stateKey( "lease-$scope" ) );
		}
	}

	// ---- State -------------------------------------------------------------

	private function stash(): BagOStuff {
		return $this->getServiceContainer()->getMainObjectStash();
	}

	private function stateKey( string $scope ): string {
		return $this->stash()->makeKey( 'cp-cargo-rebuild', $this->table, $scope );
	}

	/** Kept indefinitely: no TTL, so Redis's volatile-lru never evicts it. */
	private function saveState( array $state, string $scope ): void {
		$this->stash()->set( $this->stateKey( $scope ), $state, BagOStuff::TTL_INDEFINITE );
	}

	private function loadState( string $scope ): array {
		$state = $this->stash()->get( $this->stateKey( $scope ) );
		return is_array( $state ) ? $state : [];
	}

	/** Progress keys of every shard count that has been used, up to 8. */
	private function allStateKeys(): array {
		$keys = [];
		for ( $n = 1; $n <= 8; $n++ ) {
			for ( $i = 0; $i < $n; $i++ ) {
				$keys[] = $this->stateKey( "shard-$i-of-$n" );
			}
		}
		return $keys;
	}

	// ---- Helpers -----------------------------------------------------------

	private function next(): string {
		return $this->table . '__NEXT';
	}

	private function q( string $field ): string {
		return $this->cdb->addIdentifierQuotes( $field );
	}

	/** @return int[] [ i, n ] */
	private function shard(): array {
		$spec = $this->getOption( 'shard', '0/1' );
		if ( !preg_match( '#^(\d+)/(\d+)$#', $spec, $m ) || (int)$m[2] < 1 || (int)$m[2] > 8 || (int)$m[1] >= (int)$m[2] ) {
			$this->fatalError( '--shard is i/n with 0 <= i < n <= 8.' );
		}
		return [ (int)$m[1], (int)$m[2] ];
	}

	/** Out of time, or near the memory limit: parsing thousands of pages in one process grows. */
	private function mustStop( ?int $deadline ): bool {
		if ( $deadline !== null && time() >= $deadline ) {
			return true;
		}
		$limit = wfShorthandToInteger( ini_get( 'memory_limit' ) );
		return $limit > 0 && memory_get_usage( true ) > $limit * 0.8;
	}

	private function shortError( Throwable $e ): string {
		return get_class( $e ) . ': ' . mb_strimwidth( preg_replace( '/\s+/', ' ', $e->getMessage() ), 0, 200, '…' );
	}

	private function listDiff( string $label, array $ids ): void {
		$this->output( "$label: " . count( $ids ) . "\n" );
		foreach ( array_slice( array_values( $ids ), 0, 25 ) as $id ) {
			$title = Title::newFromID( (int)$id );
			$this->output( "  $id " . ( $title ? $title->getPrefixedText() : '(page no longer exists)' ) . "\n" );
		}
	}

	private function report( array $state ): void {
		$this->output( sprintf( "Done: %d page(s) stored, %d failed.\n", $state['done'], count( $state['failed'] ) ) );
		if ( $state['failed'] ) {
			$this->output( "Failed page IDs: " . implode( ', ', $state['failed'] ) . "\n" );
		}
	}
}

// @codeCoverageIgnoreStart
$maintClass = CpCargoRebuild::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd

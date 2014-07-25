<?php
/**
 */

require_once( dirname( __FILE__ ) . '/Maintenance.php' );

class TranslateVariants extends Maintenance {

	public $tablesPrepared = false;

	public function __construct() {
		parent::__construct();
		$this->addOption( 'lang', 'Language to translate, such as zh', true, true );
		$this->addOption( 'ns', 'Namespace ids to translate, all subject namespaces by default', false, true );
		# Determined by ns automatically.
		# $this->addOption( 'msg', 'If a variant code equals to $wgLanguageCode, write it without suffix.', false );
		$this->addOption( 'dry-run', 'Do not really publish translations', false );
		$this->addOption( 'delete', 'Follow delete actions as well', false );
		$this->addOption( 'table', 'Extra conversion table to load (subpage)', false, true );
	}

	private function getTargets( $title, $lang ) {
		global $wgContLang;

		$targets = array();

		# Blacklist CSS/JS pages.
		$parts = explode( '/', $title->getText() );
		unset( $parts[count( $parts ) - 1] );
		$btitle = Title::makeTitleSafe( $title->getNamespace(), implode( '/', $parts ) );
		if ( $btitle->isCssOrJsPage() ) {
			return $targets;
		}

		foreach ( $lang->getVariants() as $variant ) {
			$base = ( $title->getNamespace() === NS_MEDIAWIKI && $variant === $wgContLang->getCode() );
			if ( $variant === $lang->getCode() && !$base ) {
				continue;
			}

			# Find the title & page for this variant
			$parts = explode( '/', $title->getText() );
			if ( $base ) {
				unset( $parts[count( $parts ) - 1] );
			} else {
				$parts[count( $parts ) - 1] = $variant;
			}
			$vtitle = Title::makeTitleSafe( $title->getNamespace(), implode( '/', $parts ) );
			if ( !$vtitle ) {
				continue;
			}
			$targets[$variant] = $vtitle;
		}

		return $targets;
	}

	private function prepareTables( $lang ) {
		if ( $this->tablesPrepared ) {
			return;
		}
		$this->tablesPrepared = true;

		$converter = $lang->getConverter();

		$converter->mTablesLoaded = true;
		$converter->mTables = false;
		$converter->loadDefaultTables();
		foreach ( $converter->mVariants as $var ) {
			$cached = $converter->parseCachedTable( $var );
			$converter->mTables[$var]->mergeArray( $cached );

			if ( !$this->hasOption( 'table' ) ) {
				continue;
			}

			$cached = $converter->parseCachedTable( $var, $this->getOption( 'table' ) );
			$converter->mTables[$var]->mergeArray( $cached );
		}
		$converter->postLoadTables();
	}

	public function execute() {
		global $wgContLang;

		$lang = Language::factory( $this->getOption( 'lang' ) );
		$this->output( "Translating messages in {$lang->getCode()}: " );
		if ( $lang->hasVariants() ) {
			$this->output( implode( ', ', $lang->getVariants() ) . ".\n" );
		} else {
			$this->output( "no variants.\n" );
			return;
		}
		$allns = MWNamespace::getValidNamespaces();
		if ( $this->hasOption( 'ns' ) ) {
			$ns = array_intersect( $allns, array_map( 'intval', preg_split(
				'/,|:/', $this->getOption( 'ns' ), null, PREG_SPLIT_NO_EMPTY
			) ) );
		} else {
			$ns = array_filter( $allns, 'MWNamespace::isSubject' );
		}
		$this->output( 'Namespace(s): ' );
		if ( count( $ns ) ) {
			$this->output( implode( ', ', array_map(
				array( $wgContLang, 'getFormattedNsText' ), $ns
			) ) . ".\n" );
		} else {
			$this->output( "no valid namespaces.\n" );
			return;
		}

		sort( $ns );
		$cachekey = wfMemcKey( 'TranslateVariants', $lang->getCode(), implode( '!', $ns ) );
		$this->output( "Using cache key: $cachekey\n" );
		$cache = ObjectCache::getInstance( CACHE_DB );
		$revid = $cache->get( $cachekey );
		if ( !$revid ) {
			$revid = 0;
		}
		$this->output( "Starting from revision $revid.\n" );

		# Find all /lang pages which have been updated since last run.
		$dbw = wfGetDB( DB_MASTER );
		$res = $dbw->select(
			'page',
			WikiPage::selectFields(),
			array(
				'page_namespace' => $ns,
				'page_title' . $dbw->buildLike(
					$dbw->anyString(), '/', $lang->getCode()
				),
				'page_latest > ' . $revid,
			),
			__METHOD__,
			array(
				'ORDER BY' => 'page_latest',
			)
		);

		while ( $row = $dbw->fetchObject( $res ) ) {
			$title = Title::newFromRow( $row );
			$page = WikiPage::newFromRow( $row );
			$text = $page->getText();
			if ( $text === '' ) { # FIXME: update cache?
				continue;
			}

			$this->output( "Translating [[{$title->getPrefixedText()}]], revision {$page->getLatest()}.\n" );
			$summary = "Automatically converted from [[{$title->getPrefixedText()}]], revision {$page->getLatest()}";
			$userText = $page->getUserText();
			$summary = wfMessage( $userText === '' ? 'ts-variant-translate-from' : 'ts-variant-translate-from-user' )
				->params( $title->getPrefixedText(), $page->getLatest(), $userText )->text();
			foreach ( $this->getTargets( $title, $lang ) as $variant => $vtitle ) {
				$this->prepareTables( $lang );
				$vpage = WikiPage::factory( $vtitle );
				# Translate text to the specified variant
				$vtext = $lang->getConverter()->convertTo( $text, $variant );

				$this->output( "Editing [[{$vtitle->getPrefixedText()}]]..." );
				if ( $this->hasOption( 'dry-run' ) ) {
					$this->output( " with content: $vtext\n" );
				} else {
					$st = $vpage->doEdit( $vtext, $summary, EDIT_SUPPRESS_RC );
					if ( $st->isOK() ) {
						$this->output( " done.\n" );
					} else {
						$this->output( " ERROR.\n");
					}
				}
			}
			if ( !$this->hasOption( 'dry-run' ) ) {
				$this->output( "Updating cache..." );
				if ( $cache->set( $cachekey, $page->getLatest() ) ) {
					$this->output( " done.\n" );
				} else {
					$this->output( " ERROR.\n");
				}
			}
		}

		if ( !$this->hasOption( 'delete' ) ) {
			# We're not expected to follow deletion then.
			return;
		}

		$cachekey = wfMemcKey( 'TranslateVariants', $lang->getCode(), implode( '!', $ns ), 'delete' );
		$this->output( "Using cache key: $cachekey\n" );
		$cache = ObjectCache::getInstance( CACHE_DB );
		$logid = $cache->get( $cachekey );
		if ( !$logid ) {
			$logid = 0;
		}
		$this->output( "Starting from log entry $logid.\n" );

		# Find all log entries of /lang pages since last run.
		$query = DatabaseLogEntry::getSelectQueryData();
		$query['conds'][] = 'log_id > ' . $logid;
		$query['conds']['log_type'] = 'delete';
		$query['conds']['log_action'] = 'delete';
		$query['conds']['log_namespace'] = $ns;
		$query['conds'][] = 'log_title' . $dbw->buildLike( $dbw->anyString(), '/', $lang->getCode() );
		$query['options']['ORDER BY'] = 'log_id';
		$res = $dbw->select( $query['tables'], $query['fields'], $query['conds'],
			__METHOD__, $query['options'], $query['join_conds'] );

		while ( $row = $dbw->fetchObject( $res ) ) {
			$logentry = DatabaseLogEntry::newFromRow( $row );
			$title = $logentry->getTarget();
			$user = $logentry->getPerformer();
			if ( $title->exists() ) {
				continue;
			}

			$this->output( "Processing deletion of [[{$title->getPrefixedText()}]].\n" );
			$comment = $logentry->getComment();
			$reason = wfMessage( $comment === '' ? 'ts-variant-delete' : 'ts-variant-delete-reason' )
				->params( $title->getPrefixedText(), $user->getName(), $logentry->getType(), $comment )->text();
			foreach ( $this->getTargets( $title, $lang ) as $variant => $vtitle ) {
				if ( !$vtitle->exists() ) {
					continue;
				}
				$vpage = WikiPage::factory( $vtitle );

				$this->output( "Deleting [[{$vtitle->getPrefixedText()}]]..." );
				if ( $this->hasOption( 'dry-run' ) ) {
					$this->output( "\n" );
				} else {
					$ok = $vpage->doDeleteArticle( $reason );
					if ( $ok ) {
						$this->output( " done.\n" );
					} else {
						$this->output( " ERROR.\n");
					}
				}
			}
			if ( !$this->hasOption( 'dry-run' ) ) {
				$this->output( "Updating cache..." );
				if ( $cache->set( $cachekey, $logentry->getId() ) ) {
					$this->output( " done.\n" );
				} else {
					$this->output( " ERROR.\n");
				}
			}
		}
	}
}

$maintClass = "TranslateVariants";
require_once( RUN_MAINTENANCE_IF_MAIN );

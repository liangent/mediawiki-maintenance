<?php
/**
 */

require_once( dirname( __FILE__ ) . '/Maintenance.php' );

class InterwikiFromCategorySort_wikibase extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addOption( 'category', 'Category to check', true, true );
		$this->addOption( 'sites', 'Possible foreign sites', true, true );
		$this->addOption( 'dry-run', 'Do not really call the linker', false );
		$this->addOption( 'regex-replace', 'Do replacement before passing sortkey to interwiki.py', false, true );
		$this->addOption( 'regex-replacement', 'Replace with this string, empty by default', false, true );
		$this->addOption( 'wikibase-merge', 'Try to merge items on Wikibase Repo', false );
	}

	public function execute() {
		global $IP, $wgLabs, $wgContLang, $wmgUseWikibaseClient, $wgDBname;

		if ( !$wmgUseWikibaseClient ) {
			$this->output( "Wikibase is not enabled as a client here." );
			return;
		}

		$cattitle = Title::makeTitleSafe( NS_CATEGORY, $this->getOption( 'category' ) );
		if ( !$cattitle ) {
			$this->output( "Invalid category name.\n" );
			return;
		}

		# To make SGE happy, avoid commas.
		$sites = array_map( 'trim', preg_split(
			'/,|:/', $this->getOption( 'sites' ), null, PREG_SPLIT_NO_EMPTY
		) );
		sort( $sites );

		$replace = $this->getOption( 'regex-replace', false );
		$replacement = $this->getOption( 'regex-replacement', '' );

		if ( $replace === false ) {
			$cachekey = wfMemcKey( 'InterwikiFromCategorySort_wikibase',
				$cattitle->getDBkey(), implode( '!', $sites ) );
		} else {
			$cachekey = wfMemcKey( 'InterwikiFromCategorySort_wikibase',
				$cattitle->getDBkey(), implode( '!', $sites ),
				$replace, $replacement );
			$replace = "/$replace/u";
		}
		$cache = ObjectCache::getInstance( CACHE_DB );
		$cltscid = $cache->get( $cachekey );
		if ( $cltscid ) {
			list( $clts, $cid ) = $cltscid;
		}

		$dbw = wfGetDB( DB_MASTER );
		$this->output( "Cache key: $cachekey\n" );
		$this->output( 'Looking for pages to add langlinks...' );
		if ( $cltscid ) {
			$this->output( " (starting from $clts, $cid)" );
		}
		# Due to replag we must fetch all rows first.
		$res = $dbw->select(
			array( 'page', 'categorylinks' ),
			array( 'page_id', 'page_namespace', 'page_title', 'cl_sortkey_prefix', 'cl_timestamp' ),
			array(
				'cl_to' => $cattitle->getDBkey(),
				( $cltscid ? $dbw->makeList( array(
					'cl_timestamp > ' . $dbw->addQuotes( $clts ),
					$dbw->makeList( array(
						'cl_timestamp' => $clts,
						'page_id > ' . $cid,
					), LIST_AND ),
				), LIST_OR ) : true ),
			),
			__METHOD__,
			array(
				'ORDER BY' => array( 'cl_timestamp', 'page_id' ),
			),
			array(
				'categorylinks' => array( 'JOIN', array(
					'cl_from = page_id',
				) ),
			)
		);
		$this->output( " {$dbw->numRows( $res )} rows.\n" );
		$this->output( 'Linking to: ' . implode( ', ', $sites ) . ".\n" );
		while ( $row = $dbw->fetchObject( $res ) ) {
			$title = Title::newFromRow( $row );
			$sortkey = $row->cl_sortkey_prefix;
			if ( $replace !== false ) {
				$sortkey = preg_replace( $replace, $replacement, $sortkey );
			}
			$ft = Title::makeTitleSafe( $title->getNamespace(), $sortkey );
			if ( !$ft ) {
				continue;
			}
			$fptext = $ft->getText();
			if ( $ft->getNamespace() !== 0 ) {
				$fptext = MWNamespace::getCanonicalName( $ft->getNamespace() ) . ':' . $fptext;
			}
			$this->output( "Processing {$title->getPrefixedText()} "
				. "(interwiki = {$fptext}, "
				. "timestamp = {$row->cl_timestamp}, "
				. "curid = {$row->page_id})...\n" );
			# Invoke the linker
			$args = array(
				'--bot', '--wiki', Wikibase\Settings::get( 'repoDatabase' ),
				'--report', wfMessage( 'ts-iwcatsort-wb-report' )->text(),
				'--report-message', wfMessage( 'ts-iwcatsort-wb-report-message' )->params(
					$wgDBname, $title->getFullText(),
					$cattitle->getText(), $sortkey, $row->cl_sortkey_prefix
				)->text(),
				$wgDBname, $title->getFullText(),
			);
			if ( $this->hasOption( 'wikibase-merge' ) ) {
				$args[] = '--merge';
			}
			foreach ( $sites as $site ) {
				$args = array_merge( $args, array( $site, $fptext ) );
			}
			$cmd = wfShellWikiCmd( "$IP/maintenance/wbLinkTitlesLocal.php", $args );
			$this->output( "Invoking $cmd\n" );
			if ( !$this->hasOption( 'dry-run' ) ) {
				$retval = 1;
				while ( $retval != 0 ) {
					$this->output( wfShellExec( $cmd, $retval, array(), array( 'memory' => 0 ) ) );
					$this->output( "Linker exits with return code $retval.\n" );
				}
				if ( $cache->set( $cachekey, array( $row->cl_timestamp, $row->page_id ) ) ) {
					$this->output( "cltscid cache updated.\n" );
				}
			}
		}
	}
}

$maintClass = "InterwikiFromCategorySort_wikibase";
require_once( RUN_MAINTENANCE_IF_MAIN );

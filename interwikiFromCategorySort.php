<?php
/**
 */

require_once( dirname( __FILE__ ) . '/Maintenance.php' );

class InterwikiFromCategorySort extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addOption( 'category', 'Category to check', true, true );
		$this->addOption( 'lang', 'Possible foreign wikis', true, true );
		$this->addOption( 'force', 'Still run on pages with langlinks to given langs', false );
		$this->addOption( 'dry-run', 'Do not really call interwiki.py', false );
		$this->addOption( 'regex-replace', 'Do replacement before passing sortkey to interwiki.py', false, true );
		$this->addOption( 'regex-replacement', 'Replace with this string, empty by default', false, true );
	}

	public function execute() {
		global $wgTSPywikipediaPath, $wgToolserver, $wgContLang;

		$cattitle = Title::makeTitleSafe( NS_CATEGORY, $this->getOption( 'category' ) );
		if ( !$cattitle ) {
			$this->output( "Invalid category name.\n" );
			return;
		}

		# To make SGE happy, avoid commas.
		$langs = array_map( 'trim', preg_split(
			'/,|:/', $this->getOption( 'lang' ), null, PREG_SPLIT_NO_EMPTY
		) );
		sort( $langs );

		$replace = $this->getOption( 'regex-replace', false );
		$replacement = $this->getOption( 'regex-replacement', '' );

		if ( $replace === false ) {
			$cachekey = wfMemcKey( 'InterwikiFromCategorySort',
				$cattitle->getDBkey(), implode( '!', $langs ) );
		} else {
			$cachekey = wfMemcKey( 'InterwikiFromCategorySort',
				$cattitle->getDBkey(), implode( '!', $langs ),
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
			array_merge( array( 'page', 'categorylinks' ),
				$this->hasOption( 'force' ) ? array() : array( 'langlinks' ) ),
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
			) + ( $this->hasOption( 'force' ) ? array() : array( 'll_title' => null ) ),
			__METHOD__,
			array(
				'ORDER BY' => array( 'cl_timestamp', 'page_id' ),
			),
			array(
				'categorylinks' => array( 'JOIN', array(
					'cl_from = page_id',
				) ),
			) + ( $this->hasOption( 'force' ) ? array() : array(
				'langlinks' => array( 'LEFT JOIN', array(
					'll_lang' => $langs,
					'll_from = page_id',
				) ),
			) )
		);
		$this->output( " {$dbw->numRows( $res )} rows.\n" );
		$this->output( 'Linking to: ' . implode( ', ', $langs ) . ".\n" );
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
			# Invoke interwiki.py
			$cmd = 'interwiki.py ';
			$cmd .= wfEscapeShellArg(
				'-family:' . $wgToolserver->wiki->family, '-lang:' . $wgContLang->getCode(),
				'-page:' . wfUrlencode( $title->getPrefixedDBkey() ), '-localonly', '-auto',
				'-hint:' . implode( ',', $langs ) . ':' . $fptext
			);
			$this->output( "Invoking $cmd\n" );
			if ( !$this->hasOption( 'dry-run' ) ) {
				$cmd = "python $wgTSPywikipediaPath" . DIRECTORY_SEPARATOR . $cmd;
				$retval = 1;
				while ( $retval != 0 ) {
					wfShellExec( $cmd, $retval, array(
						'PYTHONPATH' => $wgTSPywikipediaPath,
					) );
					$this->output( "interwiki.py exits with return code $retval.\n" );
				}
				if ( $cache->set( $cachekey, array( $row->cl_timestamp, $row->page_id ) ) ) {
					$this->output( "cltscid cache updated.\n" );
				}
			}
		}
	}
}

$maintClass = "InterwikiFromCategorySort";
require_once( RUN_MAINTENANCE_IF_MAIN );

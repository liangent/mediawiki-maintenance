<?php
/**
 */

require_once( dirname( __FILE__ ) . '/PageMaintenance.php' );

class CleanupILH extends PageMaintenance {

	static $templates = null;
	static $suffix = null;

	public function __construct() {
		parent::__construct();
		$this->addOption( 'maxlag', 'Do not run if DB lags more than this time.' );
		$this->titleKnown = array();
	}

	static function fallbackArray( &$a, $b ) {
		for ( $i = 0; $i < count( $a ); $i++ ) {
			if ( !is_string( $a[$i] ) || trim( $a[$i] ) === '' ) {
				$a[$i] = $b[$i];
			}
		}
	}

	static function getOptionalColonForWikiLink( $title, $onTitle ) {
		if ( in_array( $title->getNamespace(), array( NS_CATEGORY, NS_FILE ) ) ) {
			return ':';
		}
		$iw = $title->getInterwiki();
		if ( $iw && !$onTitle->isTalkPage() && Language::fetchLanguageName( $iw, null, 'mw' ) ) {
			return ':';
		}
		return '';
	}

	private function findAlias( $pageTitle, &$title, $lang, $interwiki, $local ) {
		global $wgContLang, $wgConf, $wgDBname, $wgLocalInterwiki;

		if ( is_null( self::$suffix ) ) {
			list( $site, $_ ) = $wgConf->siteFromDB( $wgDBname );
			self::$suffix = $site == 'wikipedia' ? 'wiki' : $site;
		}
		$fdbn = str_replace( '-', '_', $lang ) . self::$suffix;
		try {
			$fdbr = wfGetDB( DB_SLAVE, array(), $fdbn );
		} catch ( DBError $e ) {
			$fdbr = false;
			$this->output( " (dbcerror $fdbn)" );
		}
		if ( !$fdbr ) {
			return false;
		}

		# Ideally we may want to parse $interwiki on the specified site, for
		# correct namespaces and $wgCapitalLinks, but it's a little too expensive.
		$ftitle = Title::newFromText( $interwiki );
		if ( !$ftitle ) {
			return false;
		}

		try {
			$ll_title = $fdbr->selectField(
				array( 'page', 'langlinks' ),
				'll_title',
				array(
					'page_namespace' => $ftitle->getNamespace(),
					'page_title' => $ftitle->getDBKey(),
					'page_id = ll_from',
					'll_lang' => $wgLocalInterwiki,
				),
				__METHOD__
			);
			if ( $ll_title === false ) {
				$ll_title = $fdbr->selectField(
					array(
						'redirect', 'langlinks',
						'rdpage' => 'page', 'dstpage' => 'page',
					),
					'll_title',
					array(
						'rdpage.page_namespace' => $ftitle->getNamespace(),
						'rdpage.page_title' => $ftitle->getDBKey(),
						'rdpage.page_is_redirect' => true,
						'rdpage.page_id = rd_from',
						'dstpage.page_namespace = rd_namespace',
						'dstpage.page_title = rd_title',
						$fdbr->makeList( array(
							# https://bugzilla.wikimedia.org/48853
							$fdbr->makeList( array( 'rd_interwiki' => null ), LIST_OR ),
							'rd_interwiki' => '',
						), LIST_OR ),
						'dstpage.page_id = ll_from',
						'll_lang' => $wgLocalInterwiki,
					),
					__METHOD__
				);
			}
		} catch ( DBError $e ) {
			$this->output( " (dbqerror $fdbn)" );
			return false;
		}
		if ( $ll_title === false ) {
			return false;
		}

		$newTitle = Title::newFromText( $ll_title );
		$wgContLang->findVariantLink( $ll_title, $newTitle, true );
		if ( $newTitle && ( $newTitle->isKnown()
			|| isset( $this->titleKnown[$newTitle->getPrefixedDBKey()] ) )
		) {
			# Hooray we managed to find an alias!
			$redirected = false;
			if ( $title ) {
				$this->output( " (rd [[{$title->getPrefixedText()}]] "
					. "=> [[{$newTitle->getFullText()}]]" );
				# Create redirect
				$contentHandler = ContentHandler::getForTitle( $title );
				$redirectContent = $contentHandler->makeRedirectContent( $newTitle );
				if ( WikiPage::factory( $title )->doEdit(
					$redirectContent->serialize(),
					wfMessage( 'ts-cleanup-ilh-redirect' )->params(
						$newTitle->getFullText(),
						$pageTitle->getPrefixedText(),
						$lang, $interwiki, $pageTitle->getLatestRevID()
					)->text(), EDIT_NEW
				)->isOK() ) {
					$this->output( ' done)' );
					$this->titleKnown[$title->getPrefixedDBKey()] = true;
					$redirected = true;
				} else {
					$this->output( ' ERROR)' );
				}
			}
		       	if ( !$redirected ) {
				$title = $newTitle;
				$this->output( " (alias [[$local]] => [[{$title->getFullText()}]])" );
			}
			return true;
		}

		return false;
	}

	public function executeTitle( $title, $ident = '', $recur = true ) {
		global $wgContLang, $wgLabs;

		static $cleanedup = array();
		static $parserOutput = null;

		if ( $this->getPageSource() == 'random' || $this->getPageSource() == 'start' ) {
			$recur = false;
		}

		$this->output( $ident . $title->getPrefixedText() );
		if ( !$title->exists() ) {
			$this->output( "\tredlink.\n" );
			return;
		}

		if ( !$title->userCan( 'edit', $wgLabs->user ) ) {
			# It's unlikely to be problematic, and usually we don't have the right
			# to clean them up ( = edit them )...
			$this->output( "\tprotected.\n" );
			return;
		}

		if ( isset( $cleanedup[$title->getPrefixedDBKey()] ) ) {
			$this->output( "\tskipping...\n" );
			return;
		} else {
			$cleanedup[$title->getPrefixedDBKey()] = true;
		}

		$otext = $text = Revision::newFromTitle( $title )->getText();
		# First, find out all links to check
		$matches = array();
		$lang = '(ar|be|da|de|el|en|es|fi|fr|hy|id|it|ja|ko|ms|nl|no|pl|pt|ro|ru|sv|tr|uk|uz|vi)';
		$ilhRe = '/\{\{\s*(?:Internal[_ ]link[_ ]helper\/' . $lang
			. '|Link-' . $lang . '|' . $lang . '-link)\s*'
			. '(?:\|\s*(.*?)\s*)?' # Local page name
			. '(?:\|\s*(.*?)\s*)?' # Interwiki page name
			. '(?:\|\s*(.*?)\s*)?' # Text
			. '\}\}/i';
		$transRe = '/\{\{\s*(?:Translink|Tsl)\s*'
			. '(?:\|\s*(.*?)\s*)?' # Lang
			. '(?:\|\s*(.*?)\s*)?' # Interwiki page name
			. '(?:\|\s*(.*?)\s*)?' # Local page name
			. '(?:\|\s*(.*?)\s*)?' # Text
			. '\}\}/i';
		$batch = new LinkBatch();
		$titles = array();
		preg_match_all( $ilhRe, $text, $matches, PREG_PATTERN_ORDER );
		list( $templates, $langs, $langsB, $langsC, $locals, $interwikis, $descs ) = $matches;
		self::fallbackArray( $langs, $langsB );
		self::fallbackArray( $langs, $langsC );
		self::fallbackArray( $interwikis, $locals );
		preg_match_all( $transRe, $text, $matches, PREG_PATTERN_ORDER );
		list( $templates2, $langs2, $interwikis2, $locals2, $descs2 ) = $matches;
		self::fallbackArray( $locals2, $interwikis2 );
		$templates = array_merge( $templates, $templates2 );
		$langs = array_map( 'strtolower', array_merge( $langs, $langs2 ) );
		$interwikis = array_merge( $interwikis, $interwikis2 );
		$locals = array_merge( $locals, $locals2 );
		$descs = array_merge( $descs, $descs2 );
		self::fallbackArray( $descs, $locals );
		$lb = new LinkBatch( $titles );
		foreach ( $locals as $titleText ) {
			$lb->addObj( $titles[] = Title::newFromText( $titleText ) );
			foreach ( $wgContLang->autoConvertToAllVariants( $titleText ) as $titleText ) {
				$lb->addObj( Title::newFromText( $titleText ) );
			}
		}
		$lb->execute();
		for ( $i = 0; $i < count( $templates ); $i++ ) {
			$wgContLang->findVariantLink( $locals[$i], $titles[$i], true );
			$localKnown = $titles[$i] && ( $titles[$i]->isKnown()
				|| isset( $this->titleKnown[$titles[$i]->getPrefixedDBKey()] ) );
			if ( !$localKnown ) {
				$localKnown = $this->findAlias( $title, $titles[$i], $langs[$i], $interwikis[$i], $locals[$i] );
			}
			if ( $localKnown ) {
				$replace = '[[' . self::getOptionalColonForWikiLink( $titles[$i], $title );
				if ( is_string( $descs[$i] ) && trim( $descs[$i] ) !== '' ) {
					$nt = Title::newFromText( $descs[$i] );
					$x = $descs[$i];
					$wgContLang->findVariantLink( $x, $nt, true );
					if ( $nt && $nt->getFullText() === $titles[$i]->getFullText() ) {
						$replace .= $descs[$i];
					} else {
						$replace .= $titles[$i]->getFullText() . '|' . $descs[$i];
					}
				} else {
					$replace .= $titles[$i]->getFullText();
				}
				$replace .= ']]';
				$text = str_replace( $templates[$i], $replace, $text );
			}
		}

		if ( $text === $otext ) {
			$this->output( "\tno change." );
			if ( $recur ) {
				$this->output( '..' );
				if ( !$parserOutput ) {
					$parserOutput = new ParserOutput();
				}
				$u = new LinksUpdate( $title, $parserOutput );
				$u->doUpdate();
				$this->output( ' linksupdated.' );
			}
			$this->output( "\n" );
		} else {
			$this->output( "\tsaving..." );
			if ( WikiPage::factory( $title )->doEdit( $text,
				wfMessage( 'ts-cleanup-ilh' )->text(),
			EDIT_MINOR, $title->getLatestRevID() )->isOK() ) {
				$this->output( " done.\n" );
			} else {
				$this->output( " ERROR.\n" );
			}
		}
		if ( $text === $otext && $recur ) {
			# We should call executeTitle on all templates this page uses.
			# Blue links may be there, maybe invisible from parsed view!
			# But don't do this recursively?
			foreach ( $title->getTemplateLinksFrom() as $template ) {
				$this->executeTitle( $template, $ident . '  ', false );
			}
		}
	}

	public function getRandomQueryInfo() {
		$db = $this->getDatabase();
		return array(
			'tables' => array( 'templatelinks' ),
			'conds' => array(
				'page_id = tl_from',
				$db->makeList( array_map( function( $title ) use ( $db ) {
					return $db->makeList( array(
						'tl_namespace' => $title->getNamespace(),
						'tl_title' => $title->getDBKey(),
					), LIST_AND );
				}, self::$templates ), LIST_OR ),
			),
			'options' => array( 'DISTINCT' ),
		);
	}

	public function execute() {
		if ( is_null( self::$templates ) ) {
			self::$templates = array(
				Title::makeTitleSafe( NS_TEMPLATE, 'Translink' ),
				Title::makeTitleSafe( NS_TEMPLATE, 'Internal link helper' ),
			);
		}
		if ( $this->hasOption( 'maxlag' ) ) {
			$maxlag = intval( $this->getOption( 'maxlag' ) );
			$lag = wfReplag();
			if ( $lag > $maxlag ) {
				$this->output( "Current lag: $lag, required maxlag: $maxlag, exiting.\n" );
				return;
			}
		}
		parent::execute();
	}
}

$maintClass = "CleanupILH";
require_once( RUN_MAINTENANCE_IF_MAIN );

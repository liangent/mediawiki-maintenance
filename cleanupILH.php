<?php
/**
 */

require_once( dirname( __FILE__ ) . '/Maintenance.php' );

class CleanupILH extends Maintenance {

	static $templates = null;
	static $suffix = null;

	public function __construct() {
		parent::__construct();
		$this->addOption( 'maxlag', 'Do not run if DB lags more than this time.' );
		$this->addOption( 'category', 'Process pages in this category.' );
		$this->addOption( 'random-count', 'Process some random pages using related templates.' );
		$this->titleKnown = array();
	}

	static function fallbackArray( &$a, $b ) {
		for ( $i = 0; $i < count( $a ); $i++ ) {
			if ( !is_string( $a[$i] ) || trim( $a[$i] ) === '' ) {
				$a[$i] = $b[$i];
			}
		}
	}

	public function cleanupTitle( $title, $ident = '', $recur = true ) {
		global $wgContLang, $wgLabs, $wgConf, $wgDBname, $wgLocalInterwiki;

		static $cleanedup = array();
		static $parserOutput = null;

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
		$lang = '(ar|de|en|es|fi|fr|it|ja|ms|nl|no|pl|pt|ru|sv|ko|vi|tr|da)';
		$ilhRe = '/\{\{\s*(?:Internal[_ ]link[_ ]helper\/' . $lang
			. '|Link-' . $lang . '|' . $lang . '-link)\s*'
			. '(?:\|\s*([^|}]*?)\s*)?' # Local page name
			. '(?:\|\s*([^|}]*?)\s*)?' # Interwiki page name
			. '(?:\|\s*([^|}]*?)\s*)?' # Text
			. '\}\}/i';
		$transRe = '/\{\{\s*(?:Translink|Tsl)\s*'
			. '(?:\|\s*([^|}]*?)\s*)?' # Lang
			. '(?:\|\s*([^|}]*?)\s*)?' # Interwiki page name
			. '(?:\|\s*([^|}]*?)\s*)?' # Local page name
			. '(?:\|\s*([^|}]*?)\s*)?' # Text
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
				if ( is_null( self::$suffix ) ) {
					list( $site, $lang ) = $wgConf->siteFromDB( $wgDBname );
					self::$suffix = $site == 'wikipedia' ? 'wiki' : $site;
				}
				$fdbn = str_replace( '-', '_', $langs[$i] ) . self::$suffix;
				try {
					$fdbr = wfGetDB( DB_SLAVE, array(), $fdbn );
				} catch ( DBError $e ) {
					$fdbr = false;
					$this->output( " (dbcerror $fdbn)" );
				}
				$ll_title = false;
				if ( $fdbr ) {
					$ftitle = Title::makeTitle( NS_MAIN, $interwikis[$i] );
					if ( $ftitle ) {
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
											$fdbr->makeList( array(
												'rd_interwiki' => null ), LIST_OR ),
											'rd_interwiki' => '',
										), LIST_OR ),
										'dstpage.page_id = ll_from',
										'll_lang' => $wgLocalInterwiki,
									),
									__METHOD__
								);
							}
						} catch ( DBError $e ) {
							$ll_title = false;
							$this->output( " (dbqerror $fdbn)" );
						}
					}
				}
				if ( $ll_title !== false ) {
					$newTitle = Title::newFromText( $ll_title );
					$wgContLang->findVariantLink( $ll_title, $newTitle, true );
					if ( $newTitle && ( $newTitle->isKnown()
						|| $this->titleKnown[$newTitle->getPrefixedDBKey()] )
					) {
						# Hooray we managed to find an alias!
						$localKnown = true;
						if ( $titles[$i] ) {
							$this->output( " (rd [[{$titles[$i]->getPrefixedText()}]] "
								. "=> [[{$newTitle->getPrefixedText()}]]" );
							# Create redirect
							$contentHandler = ContentHandler::getForTitle( $titles[$i] );
							$redirectContent = $contentHandler->makeRedirectContent( $newTitle );
							if ( WikiPage::factory( $titles[$i] )->doEdit(
								$redirectContent->serialize(),
								wfMessage( 'ts-cleanup-ilh-redirect' )->params(
									$newTitle->getPrefixedText(),
									$title->getPrefixedText(),
									$langs[$i], $interwikis[$i]
								)->text(), EDIT_NEW
							)->isOK() ) {
								$this->output( ' done)' );
								$this->titleKnown[$titles[$i]->getPrefixedDBKey()] = true;
							} else {
								$this->output( ' ERROR)' );
							}
						} else {
							$titles[$i] = $newTitle;
						}
					}
				}
			}
			if ( $localKnown ) {
				$replace = '[[';
				if ( $titles[$i]->getNamespace() == NS_FILE
					|| $titles[$i]->getNamespace() == NS_CATEGORY
				) {
					$replace .= ':';
				}
				if ( is_string( $descs[$i] ) && trim( $descs[$i] ) !== '' ) {
					$nt = Title::newFromText( $descs[$i] );
					if ( $nt && $nt->equals( $titles[$i] ) ) {
						$replace .= $descs[$i];
					} else {
						$replace .= $titles[$i]->getPrefixedText() . '|' . $descs[$i];
					}
				} else {
					$replace .= $titles[$i]->getPrefixedText();
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
			# We should call cleanupTitle on all templates this page uses.
			# Blue links may be there, maybe invisible from parsed view!
			# But don't do this recursively?
			foreach ( $title->getTemplateLinksFrom() as $template ) {
				$this->cleanupTitle( $template, $ident . '  ', false );
			}
		}
	}

	public function execute() {
		global $wgContLang;
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
		$titles = null;
		$recursive = null;
		if ( $this->hasOption( 'category' ) ) {
			$cat = Category::newFromName( $this->getOption( 'category' ) );
			if ( $cat ) {
				$titles = $cat->getMembers();
				$recursive = true;
				$this->output( "Working on pages in category {$cat->getName()}.\n" );
			}
		}
		if ( is_null( $titles ) && $this->hasOption( 'random-count' ) ) {
			$count = intval( $this->getOption( 'random-count' ) );
			if ( $count > 0 ) {
				$dbr = wfGetDB( DB_SLAVE );
				$rand = wfRandom();
				$res = $dbr->select(
					array( 'page', 'templatelinks' ),
					array( 'page.*' ),
					array(
						'page_id = tl_from',
						'page_random > ' . $rand,
						$dbr->makeList( array_map( function( $title ) use ( $dbr ) {
							return $dbr->makeList( array(
								'tl_namespace' => $title->getNamespace(),
								'tl_title' => $title->getDBKey(),
							), LIST_AND );
						}, self::$templates ), LIST_OR ),
					),
					__METHOD__,
					array(
						'DISTINCT',
						'LIMIT' => $count,
						'ORDER BY' => 'page_random',
					)
				);
				$titles = TitleArray::newFromResult( $res );
				$recursive = false;
				$this->output( "Working on < $count random pages starting from $rand.\n" );
			}
		}
		if ( is_null( $titles ) ) {
			$this->output( "Please specify either category or random-count.\n" );
			return;
		}
		foreach ( $titles as $title ) {
			$this->cleanupTitle( $title, '', $recursive );
		}
		$this->output( "done\n" );
	}
}

$maintClass = "CleanupILH";
require_once( RUN_MAINTENANCE_IF_MAIN );

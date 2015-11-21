<?php

require_once( dirname( __FILE__ ) . '/PageDomMaintenanceExt.php' );

class CleanupILH_DOM extends PageDomMaintenanceExt {

	static $templates = null;
	static $suffix = null;

	public function __construct() {
		parent::__construct();
		$this->addOption( 'maxlag', 'Do not run if DB lags more than this time.' );
		$this->titleKnown = array();
		$this->wbLinkTitlesInvocations = array();
	}

	public function isTitleKnown( $title ) {
		if ( $title ) {
			if ( isset( $this->titleKnown[$title->getPrefixedDBKey()] ) ) {
				return null;
			} elseif ( $title->isKnown() ) {
				$rev = $title->getFirstRevision();
				if ( $rev ) {
					if ( wfTimestamp( TS_UNIX ) - wfTimestamp( TS_UNIX, $rev->getTimestamp() ) > 86400 * 10 ) {
						return true;
					} else {
						return null;
					}
				} else { # Non-DB pages?
					return true;
				}
			}
		}
		return false;
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

	private function langToDB( $lang ) {
		global $wgConf, $wgDBname, $wgLocalDatabases;

		if ( is_null( self::$suffix ) ) {
			list( $site, $_ ) = $wgConf->siteFromDB( $wgDBname );
			self::$suffix = $site == 'wikipedia' ? 'wiki' : $site;
		}
		$maybeDb = str_replace( '-', '_', $lang ) . self::$suffix;

		if ( in_array( $maybeDb, $wgLocalDatabases ) ) {
			return $maybeDb;
		} else {
			return false;
		}
	}

	public function checkRedirect( $pageTitle, $title, $newTitle, $fdbn, $interwiki, $interwikiData, $ll_title, $rd ) {
		static $interpreter = null;
		static $checkRedirect = null;
		if ( $checkRedirect === true ) {
			return true;
		}
		if ( !$checkRedirect ) {
			global $wgScribuntoEngineConf, $wgScribuntoDefaultEngine, $maintClass;
			$parser = new Parser();
			$parser->startExternalParse( Title::newMainPage(), new ParserOptions(), Parser::OT_HTML );
			$conf = array( 'cpuLimit' => PHP_INT_MAX, 'parser' => $parser );
			$conf += $wgScribuntoEngineConf[$wgScribuntoDefaultEngine];
			$engine = new $conf['class']( $conf );
			$engine->setTitle( $parser->getTitle() );
			$interpreter = $engine->getInterpreter();
			try {
				$invoker = $interpreter->loadString( <<<"LUA"
local module = require( 'Module:$maintClass' )

return function()
	return true
end
LUA
				, 'invoker' );
				$checkRedirect = $interpreter->callFunction( $invoker );
				$checkRedirect = $checkRedirect[0];
			} catch ( Exception $e ) {
				$this->output( ' (lua-load-error)' );
				$checkRedirect = true;
				return true;
			}
		}
		try {
			$ret = $interpreter->callFunction( $checkRedirect, 'TEST' );
			return $ret[0];
		} catch ( Exception $e ) {
			$this->output( ' (lua-exec-error)' );
			return true;
		}
	}

	private function findAlias( $pageTitle, &$title, $lang, $interwiki, $local ) {
		global $wgContLang, $wgLocalInterwikis, $IP;

		$fdbn = $this->langToDB( $lang );
		if ( $fdbn === false ) {
			return false;
		}
		try {
			$fdbr = wfGetDB( DB_SLAVE, array(), $fdbn );
		} catch ( DBError $e ) {
			$fdbr = false;
			$this->output( " (dbcerror $fdbn)" );
		}
		if ( !$fdbr ) {
			return false;
		}

		# $fdbn is already validated with wfGetDB().
		$cmd = wfShellWikiCmd( "$IP/maintenance/parseTitle.php", array( '--wiki', $fdbn, $interwiki ) );
		$retVal = 1;
		$data = FormatJson::decode( trim( wfShellExec( $cmd, $retVal, array(), array( 'memory' => 0 ) ) ) );
		if ( $retVal != 0 || !isset( $data->namespace ) || !isset( $data->dbkey )
			|| !isset( $data->interwiki ) || $data->interwiki !== '' ) {
				return false;
		}

		try {
			$ll_title = $fdbr->selectField(
				array( 'page', 'langlinks' ),
				'll_title',
				array(
					'page_namespace' => $data->namespace,
					'page_title' => $data->dbkey,
					'page_id = ll_from',
					'll_lang' => $wgLocalInterwikis,
				),
				__METHOD__
			);
			if ( $ll_title === false ) {
				$fres = $fdbr->select(
					array(
						'redirect', 'langlinks',
						'rdpage' => 'page', 'dstpage' => 'page',
					),
					array( 'll_title', 'rd_from', 'rd_namespace', 'rd_title', 'rd_fragment' ),
					array(
						'rdpage.page_namespace' => $data->namespace,
						'rdpage.page_title' => $data->dbkey,
						'rdpage.page_is_redirect' => true,
						'rdpage.page_id = rd_from',
						'dstpage.page_namespace = rd_namespace',
						'dstpage.page_title = rd_title',
						'rd_interwiki' => array( '', null ),
						'dstpage.page_id = ll_from',
						'll_lang' => $wgLocalInterwikis,
					),
					__METHOD__
				);
				if ( $fres === false || !$fdbr->numRows( $fres ) ) {
					$ll_title = false;
					$rd = null;
				} else {
					$frow = $fdbr->fetchObject( $fres );
					if ( $frow !== false ) {
						$ll_title = $frow->ll_title;
						$rd = $frow;
					} else {
						$ll_title = false;
						$rd = null;
					}
				}
			} else {
				$rd = null;
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
		$newTitleKnown = $this->isTitleKnown( $newTitle );
		if ( $newTitleKnown !== false ) {
			# Hooray we managed to find an alias!
			$redirected = false;
			if ( $title ) {
				$checkResult = $this->checkRedirect( $pageTitle, $title, $newTitle, $fdbn,
					$interwiki, $data, $ll_title, $rd );
				if ( is_string( $checkResult ) ) {
					$checkTitle = Title::newFromText( $checkResult );
					if ( $checkTitle ) {
						$newTitle = $checkTitle;
					}
					$checkResult = true;
				}
				if ( $checkResult ) {
					$this->output( " (rd [[{$title->getPrefixedText()}]] "
						. "=> [[{$newTitle->getFullText()}]]" );
					# Create redirect
					$contentHandler = ContentHandler::getForTitle( $title );
					$redirectContent = $contentHandler->makeRedirectContent( $newTitle );
					if ( WikiPage::factory( $title )->doEditContent( $redirectContent,
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
				} else if ( $checkResult === null ) {
					return null;
				}
			}
			if ( $redirected ) {
				return null; // the redirect must be a new page
			} elseif ( $newTitleKnown ) {
				$title = $newTitle;
				$this->output( " (alias [[$local]] => [[{$title->getFullText()}]])" );
				return true;
			} else {
				return null; // $newTitleKnown must be null
			}
		}

		return false;
	}

	public function executeTitle( $title, $ident = '', $recur = true ) {
		global $wgContLang, $wgLabs;

		static $cleanedup = array();

		if ( $this->getPageSource() == 'random' || $this->getPageSource() == 'start' ) {
			$recur = false;
		}

		$this->output( $ident . $title->getPrefixedText() );
		if ( !$title->exists() ) {
			$this->output( "\tredlink.\n" );
			return;
		}

		if ( false ) if ( !$title->userCan( 'edit', $wgLabs->user ) ) {
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

		$this->output( "\n" );
		parent::executeTitle( $title, array(
			'recur' => $recur,
			'ident' => $ident,
		) );
	}

	public function executeTitleDom( $title, $dom, $rev, $data ) {
		global $wgContLang;
		static $parserOutput = null;

		$this->domModified = false;
		$this->links = array();
		$this->title = $title;
		$text = $this->nodeToWikitext( $dom );
		if ( $this->domModified ) {
			$this->output( "\tsaving..." );
			if ( WikiPage::factory( $title )->doEdit( $text,
				wfMessage( 'ts-cleanup-ilh' )->params(
					$wgContLang->listToText( array_map( function( $link ) {
						return "[[$link]]";
					}, array_keys( $this->links ) ) )
				)->text(), EDIT_MINOR,
				$rev ? $rev->getId() : false
			)->isOK() ) {
				$this->output( " done.\n" );
			} else {
				$this->output( " ERROR.\n" );
			}
		} else {
			$this->output( "\tno change." );
			if ( $data['recur'] ) {
				$this->output( '..' );
				if ( !$parserOutput ) {
					$parserOutput = new ParserOutput();
				}
				$u = new LinksUpdate( $title, $parserOutput );
				$u->doUpdate();
				$this->output( ' linksupdated.' );
			}
			$this->output( "\n" );
		}
		if ( !$this->domModified && $data['recur'] ) {
			# We should call executeTitle on all templates this page uses.
			# Blue links may be there, maybe invisible from parsed view!
			# But don't do this recursively?
			foreach ( $title->getTemplateLinksFrom() as $template ) {
				$this->executeTitle( $template, $data['ident'] . '  ', false );
			}
		}
	}

	public function executeTemplate( $node, $arrayNode ) {
		global $wgDBname, $wgContLang, $IP, $wmgUseWikibaseClient;

		$template = ''; # Currently: zhwiki-ilh, zhwiki-tsl, arwiki
		$lang = ''; # Interwiki language code
		$interwiki = ''; # Interwiki title
		$local = ''; # Local page name
		$desc = ''; # Display text
		$noop = function( $x ) { return $x; };

		for ( $i = 0; $i < $arrayNode->getLength(); $i++ ) {
			$childNode = $arrayNode->item( $i );
			switch ( $childNode->getName() ) {
			case 'title':
				$templateName = $this->nodeToWikitext( $childNode );
				$templateTitle = Title::newFromText( trim( $templateName ), NS_TEMPLATE );
				if ( !$templateTitle ) {
					continue;
				}
				try {
					$templatePage = WikiPage::factory( $templateTitle );
				} catch ( MWException $e ) {
					$templatePage = null;
				}
				if ( $templatePage ) {
					$redirectTitle = $templatePage->getRedirectTarget();
					if ( $redirectTitle ) {
						$templateTitle = $redirectTitle;
					}
				}
				if ( $wgDBname === 'arwiki' && $templateTitle->getPrefixedText() === 'قالب:وصلة إنترويكي' ) {
					$template = 'arwiki';
				} elseif ( $wgDBname === 'zhwiki' && $templateTitle->getPrefixedText() === 'Template:Translink' ) {
					$template = 'zhwiki-tsl';
				} elseif ( $wgDBname === 'zhwiki' &&
					$templateTitle->getBaseTitle()->getPrefixedText() === 'Template:Internal link helper'
				) {
					$maybeLang = strtolower( $templateTitle->getSubpageText() );
					if ( Language::isKnownLanguageTag( $maybeLang ) ) {
						$template = 'zhwiki-ilh';
						$lang = $maybeLang;
					}
				}
				break;
			case 'part':
				$arg = $childNode->splitArg();
				$func = 'trim';
				switch ( $template . '!' . $arg['index'] . '!' . trim( $this->nodeToWikitext( $arg['name'] ) ) ) {
				case 'zhwiki-tsl!1!':
				case 'arwiki!3!':
				case 'zhwiki-tsl!!1':
				case 'arwiki!!3':
				case 'arwiki!!لغ':
					$maybeLang = strtolower( $func( $this->nodeToWikitext( $arg['value'] ) ) );
					if ( Language::isKnownLanguageTag( $maybeLang ) ) {
						$lang = $maybeLang;
					}
					break;
				case 'zhwiki-ilh!1!':
				case 'zhwiki-tsl!3!':
				case 'arwiki!1!':
					$func = $noop;
				case 'zhwiki-ilh!!1':
				case 'zhwiki-tsl!!3':
				case 'arwiki!!1':
				case 'arwiki!!عر':
					$local = $func( $this->nodeToWikitext( $arg['value'] ) );
					break;
				case 'zhwiki-ilh!2!':
				case 'zhwiki-tsl!2!':
				case 'arwiki!2!':
					$func = $noop;
				case 'zhwiki-ilh!!2':
				case 'zhwiki-tsl!!2':
				case 'arwiki!!2':
				case 'arwiki!!تر':
					$interwiki = $func( $this->nodeToWikitext( $arg['value'] ) );
					break;
				case 'zhwiki-ilh!3!':
				case 'zhwiki-tsl!4!':
				case 'arwiki!4!':
					$func = $noop;
				case 'zhwiki-ilh!!3':
				case 'zhwiki-ilh!!d':
				case 'zhwiki-tsl!!4':
				case 'arwiki!!4':
				case 'arwiki!!نص':
					$desc = $func( $this->nodeToWikitext( $arg['value'] ) );
					break;
				}
				break;
			}
		}

		if ( !$template ) {
			return;
		}

		if ( $template === 'zhwiki-ilh' ) {
			if ( trim( $interwiki ) === '' ) {
				$interwiki = $local;
			}
		}

		if ( $template === 'zhwiki-tsl' ) {
			if ( trim( $local ) === '' ) {
				$local = $interwiki;
			}
		}

		if ( $template === 'arwiki' ) {
			if ( trim( $lang ) === '' ) {
				$lang = 'en';
			}
		}

		if ( trim( $desc ) === '' ) {
			$desc = $local;
		}

		$title = Title::newFromText( $local );
		$wgContLang->findVariantLink( $local, $title, true );
		$localKnown = $this->isTitleKnown( $title );
		if ( $localKnown === false && trim( $lang ) !== '' && trim( $interwiki ) !== '' ) {
			$localKnown = $this->findAlias( $this->title, $title, $lang, $interwiki, $local );
		}
		if ( $localKnown ) {
			$foreignDb = $this->langToDB( $lang );
			if ( trim( $interwiki ) !== '' && $title->getInterwiki() === '' &&
				$foreignDb !== false && $wmgUseWikibaseClient
			) {
				if ( isset( $this->wbLinkTitlesInvocations[$title->getFullText()][$foreignDb][$interwiki] ) ) {
					$data = $this->wbLinkTitlesInvocations[$title->getFullText()][$foreignDb][$interwiki];
					$this->output( " (wbc [[{$title->getFullText()}]] <=> $foreignDb: [[$interwiki]] = $data)" );
				} else {
					$cmd = wfShellWikiCmd( "$IP/maintenance/wbLinkTitlesLocal.php", array(
						'--bot', '--wiki', Wikibase\Settings::singleton()->getSetting( 'repoDatabase' ),
						'--report', wfMessage( 'ts-cleanupilh-wb-report' )->text(),
						'--report-message', wfMessage( 'ts-cleanupilh-wb-report-message' )->params(
							$wgDBname, $title->getFullText(), $this->title->getPrefixedText(),
							$foreignDb, $interwiki
						)->text(),
						$wgDBname, $title->getFullText(), $foreignDb, $interwiki
					) );
					$retVal = 1;
					$this->output( " (wb [[{$title->getFullText()}]] <=> $foreignDb: [[$interwiki]] ..." );
					$data = trim( wfShellExec( $cmd, $retVal, array(), array( 'memory' => 0 ) ) );
					if ( $data ) {
						$this->output( " $data)" );
						$this->wbLinkTitlesInvocations[$title->getFullText()][$foreignDb][$interwiki] = $data;
					} else {
						$this->output( ' ERROR)' );
					}
				}
			}
			$this->links[$title->getPrefixedText()] = true;
			$replace = '[[' . self::getOptionalColonForWikiLink( $title, $this->title );
			$nt = Title::newFromText( $desc );
			$x = $desc;
			$wgContLang->findVariantLink( $x, $nt, true );
			if ( $nt && $nt->getFullText() === $title->getFullText() ) {
				$replace .= $desc;
			} elseif ( $desc !== '' ) {
				$replace .= $title->getFullText() . '|' . $desc;
			} else {
				$replace .= $title->getFullText();
			}
			$replace .= ']]';
			$this->domModified = true;
			return $replace;
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
		global $wgDBname;
		if ( $wgDBname === 'zhwiki' ) {
			if ( is_null( self::$templates ) ) {
				self::$templates = array(
					Title::makeTitleSafe( NS_TEMPLATE, 'Translink' ),
					Title::makeTitleSafe( NS_TEMPLATE, 'Internal link helper' ),
				);
			}
		} elseif ( $wgDBname === 'arwiki' ) {
			if ( is_null( self::$templates ) ) {
				self::$templates = array(
					Title::makeTitleSafe( NS_TEMPLATE, 'وصلة إنترويكي' ),
				);
			}
		} else {
			$this->output( "Unsupported wiki: $wgDBname.\n" );
			return;
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

$maintClass = "CleanupILH_DOM";
require_once( RUN_MAINTENANCE_IF_MAIN );

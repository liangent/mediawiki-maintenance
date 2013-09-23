<?php
/**
 */

require_once( dirname( __FILE__ ) . '/PageDomMaintenance.php' );

class CleanupILH_DOM extends PageDomMaintenance {

	static $templates = null;
	static $suffix = null;

	public function __construct() {
		parent::__construct();
		$this->addOption( 'maxlag', 'Do not run if DB lags more than this time.' );
		$this->titleKnown = array();
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
		global $wgContLang, $wgConf, $wgDBname, $wgLocalInterwiki, $IP;

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
						'rdpage.page_namespace' => $data->namespace,
						'rdpage.page_title' => $data->dbkey,
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

		$this->output( "\n" );
		parent::executeTitle( $title, array(
			'recur' => $recur,
			'ident' => $ident,
		) );
	}

	public function executeTitleDom( $title, $dom, $data ) {
		static $parserOutput = null;

		$this->domModified = false;
		$this->title = $title;
		$text = $this->nodeToWikitext( $dom );
		if ( $this->domModified ) {
			$this->output( "\tsaving..." );
			if ( WikiPage::factory( $title )->doEdit( $text,
				wfMessage( 'ts-cleanup-ilh' )->text(), EDIT_MINOR )->isOK()
			) {
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
		global $wgDBname, $wgContLang;

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
				$templateTitle = Title::newFromText( $templateName, NS_TEMPLATE );
				if ( !$templateTitle ) {
					continue;
				}
				$templatePage = WikiPage::factory( $templateTitle );
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
		$localKnown = $title && ( $title->isKnown() || isset( $this->titleKnown[$title->getPrefixedDBKey()] ) );
		if ( !$localKnown && trim( $lang ) !== '' && trim( $interwiki ) !== '' ) {
			$localKnown = $this->findAlias( $this->title, $title, $lang, $interwiki, $local );
		}
		if ( $localKnown ) {
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

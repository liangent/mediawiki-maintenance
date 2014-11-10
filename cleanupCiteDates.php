<?php

require_once( dirname( __FILE__ ) . '/PageDomMaintenance.php' );

class CleanupCiteDates extends PageDomMaintenance {

	static $citeTemplates = array(
		'Template:Cite web',
		'Template:Cite news',
		'Template:Cite journal',
		'Template:Cite book',
		'Template:Cite encyclopedia',
		'Template:Cite techreport',
		'Template:Cite interview',
		'Template:Cite conference',
		'Template:Cite episode',
		'Template:Cite map',
		'Template:Cite podcast',
		'Template:Cite pressrelease',
		'Template:Cite thesis',
		'Template:Cite AV media notes',
		'Template:Cite DVD notes',
		'Template:Cite speech',
		'Template:Citation',
	);

	static $dateArguments = array(
		'access-date', 'accessdate',
		'air-date', 'airdate',
		'archive-date', 'archivedate',
		'date',
		'doi-broken', 'doi-broken-date', 'doi-inactive-date', 'DoiBroken', 'doi_brokendate', 'doi_inactivedate',
		'lay-date', 'laydate',
		'publicationdate', 'publication-date',
	);

	public function __construct() {
		parent::__construct();
		$this->addOption( 'bot', 'Mark edits as "bot".', false );
	}

	public function executeTitleDom( $title, $dom, $rev, $data ) {
		$this->domModified = false;
		$this->title = $title;
		$text = $this->nodeToWikitext( $dom );
		if ( $this->domModified ) {
			$this->output( "saving..." );
			if ( WikiPage::factory( $title )->doEdit( $text,
				wfMessage( 'ts-cleanup-citedates' )->text(),
				EDIT_MINOR | ( $this->hasOption( 'bot' ) ? EDIT_SUPPRESS_RC : 0 ),
				$rev ? $rev->getId() : false
			)->isOK() ) {
				$this->output( " done.\n" );
			} else {
				$this->output( " ERROR.\n" );
			}
		} else {
			$this->output( "no change.\n" );
		}
	}

	public function cleanupDateString( $date ) {
		if ( preg_match( '/^(\d{4})\s*年\s*(\d{1,2})\s*月\s*(\d{1,2})\s*日$/', $date, $matches ) ) {
			list( $_, $year, $month, $day ) = array_map( 'intval', $matches );
			$date = "{$year}年{$month}月{$day}日";
		} elseif ( preg_match( '/^(\d{4})\s*[\-\/\.]\s*(\d{1,2})\s*[\-\/\.]\s*(\d{1,2})$/', $date, $matches ) ) {
			list( $_, $year, $month, $day ) = array_map( 'intval', $matches );
			$date = sprintf( '%04d-%02d-%02d', $year, $month, $day );
		}
		return $date;
	}

	public function executeTemplate( $node, $arrayNode ) {
		$args = array();
		$pieces = array( '{{' );
		for ( $i = 0; $i < $arrayNode->getLength(); $i++ ) {
			$childNode = $arrayNode->item( $i );
			switch ( $childNode->getName() ) {
			case 'title':
				$pieces[] = $templateName = $this->nodeToWikitext( $childNode );
				$templateTitle = Title::newFromText( $templateName, NS_TEMPLATE );
				if ( !$templateTitle ) {
					return;
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
				if ( !in_array( $templateTitle->getPrefixedText(), static::$citeTemplates ) ) {
					return;
				}
				break;
			case 'part':
				$arg = $childNode->splitArg();
				if ( $arg['index'] ) {
					$argkr = $argk = $arg['index'];
					$argvr = $argv = $this->nodeToWikitext( $arg['value'] );
				} else {
					$argk = $this->nodeToWikitext( $arg['name'] );
					$argkr = trim( $argk );
					$argv = $this->nodeToWikitext( $arg['value'] );
					$argvr = trim( $argv );
				}
				if ( in_array( $argkr, static::$dateArguments ) ) {
					$argvc = $this->cleanupDateString( $argvr );
					if ( $argvc !== $argvr ) {
						$this->domModified = true;
						$this->output( "Replacing $argkr = $argvr -> $argvc\n" );
						$argv = $argvc;
					}
				}
				$pieces[] = '|';
				if ( !$arg['index'] ) {
					$pieces[] = $argk;
					$pieces[] = '=';
				}
				$pieces[] = $argv;
				break;
			}
		}
		$pieces[] = '}}';
		return implode( '', $pieces );
	}

	public function executeExt( $node, $arrayNode ) {
		$ext = $node->splitExt();
		$pieces = array( '<' );
		$pieces[] = $name = $this->nodeToWikitext( $ext['name'] );
		$pieces[] = $this->nodeToWikitext( $ext['attr'] );
		if ( isset( $ext['inner'] ) ) {
			$pieces[] = '>';
			$inner = $this->nodeToWikitext( $ext['inner'] );
			if ( in_array( $name, array( 'ref', 'references' ) ) ) {
				global $wgParser;
				$dom = $wgParser->preprocessToDom( $inner );
				if ( !( $dom instanceof PPNode_DOM ) ) {
					$dom = RemoteUtils::preprocessXmlToDom( $dom->__toString() );
				}
				$inner = $this->nodeToWikitext( $dom );
			}
			$pieces[] = $inner;
		}
		if ( isset( $ext['close'] ) ) {
			$pieces[] = $this->nodeToWikitext( $ext['close'] );
		} elseif ( isset( $ext['inner'] ) ) {
			throw new MWException( 'Unexpected <ext> structure' );
		} else {
			$pieces[] = '/>';
		}
		return implode( '', $pieces );
	}
}

$maintClass = "CleanupCiteDates";
require_once( RUN_MAINTENANCE_IF_MAIN );

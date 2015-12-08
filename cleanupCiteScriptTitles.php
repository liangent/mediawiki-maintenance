<?php

require_once( dirname( __FILE__ ) . '/PageDomMaintenanceExt.php' );

class CleanupCiteScriptTitles extends PageDomMaintenanceExt {

	static $citeTemplates = array(
		'Template:Citation',
		'Template:Cite arXiv',
		'Template:Cite AV media',
		'Template:Cite AV media notes',
		'Template:Cite book',
		'Template:Cite conference',
		'Template:Cite DVD notes',
		'Template:Cite encyclopedia',
		'Template:Cite episode',
		'Template:Cite interview',
		'Template:Cite journal',
		'Template:Cite mailing list',
		'Template:Cite map',
		'Template:Cite news',
		'Template:Cite newsgroup',
		'Template:Cite podcast',
		'Template:Cite press release',
		'Template:Cite report',
		'Template:Cite serial',
		'Template:Cite sign',
		'Template:Cite speech',
		'Template:Cite techreport',
		'Template:Cite thesis',
		'Template:Cite web',
	);

	public function __construct() {
		parent::__construct();
		$this->addOption( 'bot', 'Mark edits as "bot".', false );
		$this->insideTitle = 0;
		$this->replaced = array();
	}

	public function executeTitleDom( $title, $dom, $rev, $data ) {
		$this->domModified = false;
		$this->title = $title;
		$text = $this->nodeToWikitext( $dom );
		if ( $this->domModified ) {
			$this->output( "saving..." );
			if ( WikiPage::factory( $title )->doEdit( $text,
				wfMessage( 'ts-cleanup-citescripttitles' )->text(),
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

	public function executeTemplate( $node, $arrayNode ) {
		$args = array();
		$pieces = array( '{{' );
		$isCite = false;
		$isLang = false;
		$langLang = '';
		$langStr = '';
		for ( $i = 0; $i < $arrayNode->getLength(); $i++ ) {
			$childNode = $arrayNode->item( $i );
			switch ( $childNode->getName() ) {
			case 'title':
				$pieces[] = $templateName = $this->nodeToWikitext( $childNode );
				$templateTitle = Title::newFromText( trim( $templateName ), NS_TEMPLATE );
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
				if ( in_array( $templateTitle->getPrefixedText(), static::$citeTemplates ) ) {
					$isCite = true;
				}
				if ( $templateTitle->getPrefixedText() == 'Template:Lang' ) {
					$isLang = true;
				}
				break;
			case 'part':
				$arg = $childNode->splitArg();
				if ( $arg['index'] ) {
					$argkr = $argk = $arg['index'];
					$argvr = $argv = $this->nodeToWikitext( $arg['value'] );
					$argvls = $argvrs = '';
					if ( $isLang && $argk == '1' ) {
						$langLang = $argv;
					}
					if ( $isLang && $argk == '2' ) {
						$langStr = $argv;
					}
				} else {
					$argk = $this->nodeToWikitext( $arg['name'] );
					$argkr = trim( $argk );
					if ( $isCite && in_array( $argkr, array( 'title', 'chapter' ) ) ) {
						$this->replaced[++$this->insideTitle] = null;
					}
					$argv = $this->nodeToWikitext( $arg['value'] );
					$argvr = trim( $argv );
					$argvls = substr( $argv, 0, strlen( $argv ) - strlen( ltrim( $argv ) ) );
					$argvrs = rtrim( $argv ) !== $argv ? substr( $argv, strlen( rtrim( $argv ) ) - strlen( $argv ) ) : '';
					if ( $isCite && in_array( $argkr, array( 'title', 'chapter' ) ) ) {
						if ( $this->replaced[$this->insideTitle--] !== null ) {
							$this->domModified = true;
							$argk = str_replace( 'title', 'script-title', $argk );
							$argk = str_replace( 'chapter', 'script-chapter', $argk );
							$script = $this->replaced[$this->insideTitle + 1];
							if ( $script !== '' ) {
								$script .= ':';
							}
							$argv = $argvls . $script . $argvr . $argvrs;
						}
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
		if ( $isLang && $this->insideTitle > 0 ) {
			$this->replaced[$this->insideTitle] = $langLang;
			return $langStr;
		}
		return implode( '', $pieces );
	}
}

$maintClass = "CleanupCiteScriptTitles";
require_once( RUN_MAINTENANCE_IF_MAIN );

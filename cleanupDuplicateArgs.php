<?php

require_once( dirname( __FILE__ ) . '/PageDomMaintenanceExt.php' );

class CleanupDuplicateArgs extends PageDomMaintenanceExt {

	static $exemptedTemplates = array(
		'Template:Chess diagram',
		'Template:Xiangqi diagram',
		'Template:Xiangqi-position',
		'Template:Shogi diagram',
	);

	public function __construct() {
		parent::__construct();
		$this->addOption( 'bot', 'Mark edits as "bot".', false );
		$this->insideTemplateName = 0;
	}

	public function executeTitleDom( $title, $dom, $rev, $data ) {
		$this->domModified = false;
		$this->title = $title;
		$text = $this->nodeToWikitext( $dom );
		if ( $this->domModified ) {
			$this->output( "saving..." );
			if ( WikiPage::factory( $title )->doEdit( $text,
				wfMessage( 'ts-cleanup-dupargs' )->text(),
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
		for ( $i = 0; $i < $arrayNode->getLength(); $i++ ) {
			$childNode = $arrayNode->item( $i );
			switch ( $childNode->getName() ) {
			case 'title':
				$pieces[] = $this->nodeToWikitext( $childNode );
				$this->insideTemplateName++;
				$templateName = $this->nodeToWikitext( $childNode );
				$this->insideTemplateName--;
				$templateName = trim( $templateName );
				$templateTitle = Title::newFromText( $templateName, NS_TEMPLATE );
				if ( !$templateTitle ) {
					$this->output( "Skipping non-direct template call: $templateName\n" );
					return;
				}

				$colonPos = strpos( $templateName, ':' );
				if ( $colonPos !== false ) {
					$function = substr( $templateName, 0, $colonPos );
					global $wgParser;
					if ( isset( $wgParser->mFunctionSynonyms[1][$function] ) ) {
						$this->output( "Skipping case-sensitive parser function: $function\n" );
						return;
					}
					global $wgContLang;
					$function = $wgContLang->lc( $function );
					if ( isset( $wgParser->mFunctionSynonyms[0][$function] ) ) {
						$this->output( "Skipping case-insensitive parser function: $function\n" );
						return;
					}
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
				if ( in_array( $templateTitle->getPrefixedText(), static::$exemptedTemplates ) ) {
					$this->output( "Skipping exempted template: {$templateTitle->getPrefixedText()}\n" );
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
				if ( isset( $args[$argkr][$argvr] ) ) {
					# Drop this argument.
					$this->domModified = true;
					$this->output( "Dropping duplicated argument: $argk=$argv\n" );
					break;
				}
				$args[$argkr][$argvr] = 0;
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

	public function executeComment( $node, $arrayNode ) {
		if ( $this->insideTemplateName ) {
			return '';
		} else {
			return null;
		}
	}
}

$maintClass = "CleanupDuplicateArgs";
require_once( RUN_MAINTENANCE_IF_MAIN );

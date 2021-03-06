<?php

require_once( dirname( __FILE__ ) . '/PageDomMaintenanceExt.php' );

class CollectTemplateUsage extends PageDomMaintenanceExt {

	public function __construct() {
		parent::__construct();
		$this->addOption( 'template', 'Template name to collect.', true, true );
		$this->addOption( 'empty', 'Placeholder for non-existence argument name.', false, true );
		$this->addOption( 'output', 'File path/name for output file (CSV).', false, true );
		$this->usage = array();
		$this->args = array();
	}

	public function executeTitleDom( $title, $dom, $rev, $data = null ) {

		$templateNodes = new PPNode_DOM( $dom->getXPath()->query( '//template', $dom->node ) );
		$length = $templateNodes->getLength();

		for ( $i = 0; $i < $length; $i++ ) {
			$templateNode = $templateNodes->item( $i );
			$templateName = null;
			$templateArgs = array();
			$templateChildren = $templateNode->getChildren();
			$templateChildrenLength = $templateChildren->getLength();
			for ( $j = 0; $j < $templateChildrenLength; $j++ ) {
				$templateArg = $templateChildren->item( $j );
				$breakFor = false;
				switch ( $templateArg->getName() ) {
				case 'title':
					$templateName = trim( $this->nodeToWikitext( $templateArg ) );
					$templateTitle = Title::newFromText( $templateName, NS_TEMPLATE );

					if ( !$templateTitle || !$templateTitle->equals( $this->template ) ) {
						if ( $templateTitle ) {
							try {
								$page = WikiPage::factory( $templateTitle );
							} catch ( MWException $e ) {
								$page = null;
							}
							if ( $page ) {
								$target = $page->getRedirectTarget();
								if ( $target && $target->equals( $this->template ) ) {
									break;
								}
							}
						}
						$templateName = null;
						$breakFor = true;
					}
					break;
				case 'part':
					$partVal = $templateArg->splitArg();
					$argValue = $this->nodeToWikitext( $partVal['value'] );
					if ( $partVal['index'] === '' ) {
						$argName = trim( $this->nodeToWikitext( $partVal['name'] ) );
						$argValue = trim( $argValue );
					} else {
						$argName = $partVal['index'];
					}
					$templateArgs[$argName] = $argValue;
					break;
				}
				if ( $breakFor ) {
					break;
				}
			}
			if ( $templateName !== null ) {
				# Valid template invocation
				$this->usage[$title->getPrefixedText()][] = array(
					'title' => $templateName,
					'parts' => $templateArgs,
				);
				foreach ( $templateArgs as $argKey => $_ ) {
					if ( isset( $this->args[$argKey] ) ) {
						$this->args[$argKey]++;
					} else {
						$this->args[$argKey] = 1;
					}
				}
			}
		}
	}

	public function finalize() {
		arsort( $this->args );
		$args = array_keys( $this->args );
		$fp = fopen( $this->getOption( 'output', 'php://stdout' ), 'w' );
		if ( !$fp ) {
			$fp = fopen( 'php://stdout', 'w' );
		}
		fputcsv( $fp, array_merge( array( '{{page}}', '{{template}}' ), $args ) );
		foreach ( $this->usage as $titleText => $titleUsages ) {
			foreach ( $titleUsages as $usageId => $usageInfo ) {
				$templateName = $usageInfo['title'];
				$templateArgs = $usageInfo['parts'];
				$csvRow = array( "$titleText#$usageId", $templateName );
				foreach ( $args as $arg ) {
					if ( isset( $templateArgs[$arg] ) ) {
						$csvRow[] = $templateArgs[$arg];
					} else {
						$csvRow[] = $this->getOption( 'empty', '' );
					}
				}
				fputcsv( $fp, $csvRow );
			}
		}
	}

	public function execute() {
		$this->template = Title::newFromText( $this->getOption( 'template' ), NS_TEMPLATE );

		if ( !$this->template ) {
			$this->output( "Invalid template name {{{$this->getOption( 'template' )}}}.\n" );
			return;
		}

		try {
			$page = WikiPage::factory( $this->template );
		} catch ( MWException $e ) {
			$page = null;
		}
		if ( $page ) {
			$target = $page->getRedirectTarget();
			if ( $target ) {
				$this->template = $target;
			}
		}

		$this->output( "Collecting template usage of [[{$this->template->getPrefixedText()}]].\n" );

		parent::execute();
	}
}

$maintClass = "CollectTemplateUsage";
require_once( RUN_MAINTENANCE_IF_MAIN );

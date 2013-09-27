<?php

require_once( dirname( __FILE__ ) . '/Maintenance.php' );

class MakeRedirect extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addArg( 'from', 'Redirect from', true );
		$this->addArg( 'to', 'Redirect to', true );
		$this->addOption( 'bot', 'Mark edits as bot', false );
		$this->addOption( 'no-edit', 'Do not edit existing pages', false );
	}

	public function execute() {
		$fromTitle = Title::newFromText( $this->getArg( 0 ) );
		$toTitle = Title::newFromText( $this->getArg( 1 ) );
		if ( !$fromTitle || !$toTitle ) {
			die( 1 );
		}
		try {
			$fromWikiPage = WikiPage::factory( $fromTitle );
		} catch ( MWException $e ) {
			die( 1 );
		}
		if ( $fromWikiPage->exists() ) {
			$currentRedir = $fromWikiPage->getRedirectTarget();
			if ( $currentRedir && $currentRedir->equals( $toTitle ) ) {
				return;
			} elseif ( $this->getOption( 'no-edit' ) ) {
				die( 2 );
			}
		}
		$contentHandler = ContentHandler::getForTitle( $fromTitle );
		$redirectContent = $contentHandler->makeRedirectContent( $toTitle );
		if ( $fromWikiPage->doEditContent( $redirectContent, '',
			$this->hasOption( 'bot' ) ? EDIT_SUPPRESS_RC : 0
		)->isOK() ) {
			return;
		} else {
			die( 4 );
		}
	}
}

$maintClass = "MakeRedirect";
require_once( RUN_MAINTENANCE_IF_MAIN );

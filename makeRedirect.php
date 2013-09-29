<?php

require_once( dirname( __FILE__ ) . '/Maintenance.php' );

class MakeRedirect extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addArg( 'from', 'Redirect from', true );
		$this->addArg( 'to', 'Redirect to', true );
		$this->addOption( 'bot', 'Mark edits as bot', false );
		$this->addOption( 'no-edit', 'Do not edit existing pages', false );
		$this->addOption( 'no-self', 'Do not create self redirects', false );
	}

	public function execute() {
		$fromTitle = Title::newFromText( $this->getArg( 0 ) );
		$toTitle = Title::newFromText( $this->getArg( 1 ) );
		if ( !$fromTitle || !$toTitle ) {
			$this->output( "invalid-title\n" );
			die( 1 );
		}
		if ( $fromTitle->equals( $toTitle ) && $this->hasOption( 'no-self' ) ) {
			$this->output( "no-self\n" );
			die( 2 );
		}
		try {
			$fromWikiPage = WikiPage::factory( $fromTitle );
		} catch ( MWException $e ) {
			$this->output( "invalid-page\n" );
			die( 1 );
		}
		if ( $fromWikiPage->exists() ) {
			$currentRedir = $fromWikiPage->getRedirectTarget();
			if ( $currentRedir && $currentRedir->equals( $toTitle ) ) {
				$this->output( "no-change\n" );
				return;
			} elseif ( $this->getOption( 'no-edit' ) ) {
				$this->output( "no-edit\n" );
				die( 2 );
			}
		}
		$contentHandler = ContentHandler::getForTitle( $fromTitle );
		$redirectContent = $contentHandler->makeRedirectContent( $toTitle );
		if ( $fromWikiPage->doEditContent( $redirectContent, '',
			$this->hasOption( 'bot' ) ? EDIT_SUPPRESS_RC : 0
		)->isOK() ) {
			$this->output( "success\n" );
			return;
		} else {
			$this->output( "failure\n" );
			die( 4 );
		}
	}
}

$maintClass = "MakeRedirect";
require_once( RUN_MAINTENANCE_IF_MAIN );

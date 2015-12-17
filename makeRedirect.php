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
		$this->addOption( 'no-red', 'Do not create redirects to red titles', false );
		$this->addOption( 'text', 'Extra text to add, if supported', false );
		$this->addOption( 'summary', 'Edit summary to use', false );
		$this->addOption( 'force', 'Force a re-edit when no change of redirect target is made', false );
	}

	public function doMake( $fromText, $toText ) {
		$fromTitle = Title::newFromText( $fromText );
		$toTitle = Title::newFromText( $toText );
		if ( !$fromTitle || !$toTitle ) {
			return array( 1, 'invalid-title' );
		}
		if ( $fromTitle->equals( $toTitle ) && $this->hasOption( 'no-self' ) ) {
			return array( 2, 'no-self' );
		}
		if ( !$toTitle->isKnown() && $this->hasOption( 'no-red' ) ) {
			return array( 2, 'no-red' );
		}
		try {
			$fromWikiPage = WikiPage::factory( $fromTitle );
		} catch ( MWException $e ) {
			return array( 1, 'invalid-page' );
		}
		if ( $fromWikiPage->exists() ) {
			$currentRedir = $fromWikiPage->getRedirectTarget();
			if ( $currentRedir && $currentRedir->equals( $toTitle ) ) {
				if ( !$this->hasOption( 'force' ) ) {
					return array( 0, 'no-change' );
				}
			} elseif ( $this->getOption( 'no-edit' ) ) {
				return array( 2, 'no-edit' );
			}
		}
		$contentHandler = ContentHandler::getForTitle( $fromTitle );
		$redirectContent = $contentHandler->makeRedirectContent( $toTitle, $this->getOption( 'text', '' ) );
		if ( $fromWikiPage->doEditContent( $redirectContent,
			$this->getOption( 'summary', '' ),
			$this->hasOption( 'bot' ) ? EDIT_SUPPRESS_RC : 0
		)->isOK() ) {
			return array( 0, 'success' );
		} else {
			return array( 4, 'failure' );
		}
	}

	public function execute() {
		$fromText = null;
		$retVal = 0;
		foreach ( $this->mArgs as $arg ) {
			if ( $fromText === null ) {
				$fromText = $arg;
			} else {
				list( $thisRetVal, $message ) = $this->doMake( $fromText, $arg );
				$retVal = max( $retVal, $thisRetVal );
				$this->output( "$message\n" );
				$fromText = null;
			}
		}
		die( $retVal );
	}
}

$maintClass = "MakeRedirect";
require_once( RUN_MAINTENANCE_IF_MAIN );

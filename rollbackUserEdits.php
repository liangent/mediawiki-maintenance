<?php

require_once( __DIR__ . '/PageMaintenance.php' );

class RollbackUserEdits extends PageMaintenance {

	public function __construct() {
		parent::__construct();
		$this->mDescription = "Revert to the lastest revision not generated by specified users";
		$this->addOption( 'group', 'User groups (explicit only) to skip, use pipe as separator', false, true );
		$this->addOption( 'user', 'Users (no anons) to skip, use pipe as separator', false, true );
		$this->addOption( 'ip', 'IP (ranges) to skip, use pipe as separator', false, true );
		$this->addOption( 'blocked', 'Skip blocked user' );
	}

	public function execute() {
		global $wgContLang;

		$this->groups = !$this->hasOption( 'group' ) ? array() : explode( '|', $this->getOption( 'group' ) );
		$this->users = !$this->hasOption( 'user' ) ? array() : array_filter( array_map(
			'User::getCanonicalName', explode( '|', $this->getOption( 'user' ) )
		), function( $userName ) {
			return $userName !== false;
		} );
		$this->ips = !$this->hasOption( 'ip' ) ? array() : explode( '|', $this->getOption( 'ip' ) );
		$this->blocked = $this->hasOption( 'blocked' );

		$summaryItems = array();
		if ( count( $this->groups ) ) {
			$summaryGroups = array_map( 'User::makeGroupLinkWiki', $this->groups );
			$summaryItems[] = wfMessage( 'ts-rollback-useredits-group' )->params( $wgContLang->listToText( $summaryGroups ) )->text();
		}
		if ( count( $this->users ) ) {
			$summaryUsers = array();
			foreach ( $this->users as $user ) {
				$summaryUsers[] = wfMessage( 'ts-rollback-useredits-user-item' )->params( $user )->text();
			}
			$summaryItems[] = wfMessage( 'ts-rollback-useredits-user' )->params( $wgContLang->listToText( $summaryUsers ) )->text();
		}
		if ( count( $this->ips ) ) {
			$summaryIPs = array();
			foreach ( $this->ips as $ip ) {
				$summaryIPs[] = wfMessage( 'ts-rollback-useredits-ip-item' )->params( $ip )->text();
			}
			$summaryItems[] = wfMessage( 'ts-rollback-useredits-ip' )->params( $wgContLang->listToText( $summaryIPs ) )->text();
		}
		if ( $this->blocked ) {
			$summaryItems[] = wfMessage( 'ts-rollback-useredits-blocked' )->text();
		}
		if ( !count( $summaryItems ) ) {
			$this->error( 'No rollback criteria specified.', 1 );
		}

		$this->summaryUser = $wgContLang->listToText( $summaryItems );
		parent::execute();
	}

	public function isGoodUser( $user, $ip ) {
		if ( $user ) {
			if ( count( $this->groups ) && count( array_intersect( $user->getGroups(), $this->groups ) ) ) {
				return false;
			}

			if ( in_array( $user->getName(), $this->users ) ) {
				return false;
			}

			if ( $this->blocked && $user->isBlocked() ) {
				return false;
			}
		}

		if ( $ip ) {
			foreach ( $this->ips as $targetIP ) {
				if ( IP::isInRange( $ip, $targetIP ) ) {
					return false;
				}
			}
		}

		return true;
	}

	public function executeTitle( $title ) {
		global $wgContLang;

		$firstRev = $rev = Revision::newFromTitle( $title );
		$rollback = false;

		while ( true ) {
			if ( !$rev ) {
				$rollback = false;
				break;
			}
			$userText = $rev->getUserText( Revision::RAW );
			$user = $ip = null;
			if ( User::isIP( $userText ) ) {
				$ip = $userText;
			} else {
				$user = User::newFromName( $userText );
			}

			$this->output( "... revision {$rev->getId()}, {$rev->getTimestamp()}, {$userText}:" );

			if ( $this->isGoodUser( $user, $ip ) ) {
				$this->output( " good.\n" );
				break;
			} else {
				$this->output( " BAD.\n" );
				$rev = $rev->getPrevious();
				$rollback = true;
			}
		}

		if ( $rollback ) {
			$this->output( '... rollback:' );
			if ( WikiPage::factory( $title )->doEditContent( $rev->getContent(),
				wfMessage( 'ts-rollback-useredits-summary' )->params(
					$this->summaryUser, $rev->getUserText(), $wgContLang->timeanddate( $rev->getTimestamp(), false, false )
				)->text(), EDIT_UPDATE | EDIT_MINOR | EDIT_SUPPRESS_RC, $firstRev->getId()
			)->isOK() ) {
				$this->output( " done.\n" );
			} else {
				$this->output( " ERROR.\n" );
			}
		}
	}
}

$maintClass = "RollbackUserEdits";
require_once( RUN_MAINTENANCE_IF_MAIN );

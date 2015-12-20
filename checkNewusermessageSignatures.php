<?php

require_once( __DIR__ . '/PageMaintenance.php' );

class CheckNewusermessageSignatures extends PageMaintenance {

	public function __construct() {
		parent::__construct();
		$this->mDescription = "Check MediaWiki:Newusermessage-signatures for bad signatures.";
		$this->addOption( 'maxlag', 'Do not run if DB lags more than this time.' );
	}

	private function processSignature( &$sign ) {
		global $wgParser, $wgContLang, $wgActiveUserDays;
		static $opts = null;

		$this->output( "$sign\n" );
		if ( $sign === '' ) {
			return;
		}
		$firstChar = $sign[0];
		$rest = trim( substr( $sign, 1 ) );
		switch ( $firstChar ) {
		case '#':
		case ':':
			$parts = explode( '|', $rest, 2 );
			if ( count( $parts ) == 2 ) {
				list( $oldstatus, $rawsign ) = $parts;
				if ( $oldstatus == 'disabled' ) {
					return;
				}
				break;
			}
		case '*':
			$oldstatus = '';
			$rawsign = $rest;
			break;
		default:
			return;
		}

		$updateStatus = function( $username, $newstatus ) use ( $oldstatus ) {
			if ( $newstatus != $oldstatus ) {
				$this->output( "$username status: $oldstatus -> $newstatus\n" );
				$this->changes[] = array( $username, $oldstatus, $newstatus );
			}
		};

		# First, figure out who is this:

		if ( !$opts ) {
			$opts = new ParserOptions();
		}
		$username = false;
		$po = $wgParser->parse( $rawsign, SpecialPage::getTitleFor( 'Blankpage' ), $opts );
		foreach ( $po->getLinks() as $ns => $page ) {
			foreach ( $page as $title => $pageid ) {
				$t = Title::makeTitle( $ns, $title );
				$linkuser = false;
				switch ( $t->getNamespace() ) {
				case NS_USER:
				case NS_USER_TALK:
					$linkuser = $t->getRootText();
					break;
				case NS_SPECIAL:
					if ( $t->isSpecial( 'Contributions' ) ) {
						$linkuser = $t->getSubpageText();
					}
				}
				if ( $linkuser !== false ) {
					$linkuser = $wgContLang->ucFirst( $linkuser );
					if ( $username === false ) {
						$username = $linkuser;
					#} elseif ( $username !== $linkuser ) {
					#	$username = null;
					}
				}
			}
		}
		if ( is_string( $username ) ) {
			$this->output( ".. user name: $username\n" );
		} else {
			$this->output( ".. noname\n" );
			$sign = ": noname|$rawsign";
			$updateStatus( '', 'noname' );
			return;
		}

		# Second, get the user object:

		$user = User::newFromName( $username );
		if ( $user && $user->getId() ) {
			$this->output( ".. user id: {$user->getId()}\n" );
		} else {
			$this->output( ".. nouser\n" );
			$sign = ": nouser|$rawsign";
			$updateStatus( $user ? $user->getName() : $username, 'nouser' );
			return;
		}

		# Get this user's current signature:

		$rawsign = $wgParser->getUserSig( $user );
		# Not perfect, but better than nothing.
		$rawsign = $wgParser->mStripState->unstripBoth( $rawsign );
		$this->output( ".. new sig: $rawsign\n" );

		# Is this user blocked?

		if ( $user->isBlocked() ) {
			$this->output( ".. blocked\n" );
			$sign = ": blocked|$rawsign";
			$updateStatus( $user->getName(), 'blocked' );
			return;
		} else {
			$this->output( ".. not blocked\n" );
		}

		# Is this user active?

		# Taken from Special:ActiveUsers
		$dbr = wfGetDB( DB_SLAVE );
		$activeUserSeconds = $wgActiveUserDays * 86400;
		$timestamp = $dbr->timestamp( wfTimestamp( TS_UNIX ) - $activeUserSeconds );
		$active = $dbr->selectField(
			'recentchanges',
			'1',
			array(
				'rc_user_text' => $user->getName(),
				'rc_type != ' . $dbr->addQuotes( RC_EXTERNAL ), // Don't count wikidata.
				'rc_log_type IS NULL OR rc_log_type != ' . $dbr->addQuotes( 'newusers' ),
				'rc_timestamp >= ' . $dbr->addQuotes( $timestamp ),
			),
			__METHOD__
		);

		if ( !$active ) {
			$this->output( ".. inactive\n" );
			$sign = ": inactive|$rawsign";
			$updateStatus( $user->getName(), 'inactive' );
			return;
		} else {
			$this->output( ".. active\n" );
		}

		$updateStatus( $user->getName(), '' );
		$sign = "* $rawsign";
	}

	public function execute() {
		global $wgLabs;
		if ( $this->hasOption( 'maxlag' ) ) {
			$maxlag = intval( $this->getOption( 'maxlag' ) );
			$lag = $wgLabs->replag();
			if ( $lag > $maxlag ) {
				$this->output( "Current lag: $lag, required maxlag: $maxlag, exiting.\n" );
				return;
			}
		}
		parent::execute();
	}

	public function executeTitle( $t ) {
		global $wgContLang;
		$r = Revision::newFromTitle( $t );
		$c = $r ? $r->getContent() : null;
		if ( !$c ) {
			$this->output( "{$t->getPrefixedText()} is not in use.\n" );
			return;
		}
		$text = $c->serialize();
		$this->output( "checking...\n" );
		$signatures = explode( "\n", $text );
		$this->changes = array();
		foreach ( $signatures as &$signature ) {
			$this->processSignature( $signature );
		}
		$changePieces = array();
		foreach ( $this->changes as $change ) {
			$changePieces[] = wfMessage( 'ts-newusermessage-signatures-change' )
				->params( $change )->inContentLanguage()->text();
		}
		$newtext = implode( "\n", $signatures );
		if ( $newtext !== $text ) {
			$this->output( 'editing...' );
			$page = WikiPage::factory( $t );
			$msg = count( $changePieces ) > 0
				? wfMessage( 'ts-newusermessage-signatures-update' )
					->params( $wgContLang->listToText( $changePieces ) )
				: wfMessage( 'ts-newusermessage-signatures-nochange' );
			$st = $page->doEdit( $newtext, $msg->inContentLanguage()->text(), 0, $r->getId() );
			if ( $st->isGood() ) {
				$this->output( " ok.\n" );
			} else {
				$this->output( " ERROR.\n" );
			}
		} else {
			$this->output( "no change.\n" );
		}
	}
}

$maintClass = "CheckNewusermessageSignatures";
require_once( RUN_MAINTENANCE_IF_MAIN );

<?php

require_once( __DIR__ . '/PageMaintenance.php' );

class CheckNewusermessageSignatures extends PageMaintenance {

	public function __construct() {
		parent::__construct();
		$this->mDescription = "Check MediaWiki:Newusermessage-signatures for bad signatures.";
		$this->addOption( 'maxlag', 'Do not run if DB lags more than this time.' );
	}

	private function processSignature( &$sign ) {
		global $wgParser, $wgContLang;
		static $opts = null;

		$this->output( "$sign\n" );
		if ( $sign === '' ) {
			return;
		}
		$firstChar = $sign[0];
		$rest = trim( substr( $sign, 1 ) );
		switch ( $firstChar ) {
		case '#':
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
					}# elseif ( $username !== $linkuser ) {
					#	$username = null;
					#}
				}
			}
		}
		if ( is_string( $username ) ) {
			$this->output( ".. user name: $username\n" );
		} else {
			$this->output( ".. noname\n" );
			$sign = "# noname|$rawsign";
			return;
		}

		# Second, get the user object:

		$user = User::newFromName( $username );
		if ( $user && $user->getId() ) {
			$this->output( ".. user id: {$user->getId()}\n" );
		} else {
			$this->output( ".. nouser\n" );
			$sign = "# nouser|$rawsign";
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
			$sign = "# blocked|$rawsign";
			return;
		} else {
			$this->output( ".. not blocked\n" );
		}

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
		$r = Revision::newFromTitle( $t );
		$c = $r ? $r->getContent() : null;
		if ( !$c ) {
			$this->output( "{$t->getPrefixedText()} is not in use.\n" );
			return;
		}
		$text = $c->serialize();
		$this->output( "checking...\n" );
		$signatures = explode( "\n", $text );
		foreach ( $signatures as &$signature ) {
			$this->processSignature( $signature );
		}
		$newtext = implode( "\n", $signatures );
		if ( $newtext !== $text ) {
			$this->output( 'editing...' );
			$page = WikiPage::factory( $t );
			$st = $page->doEdit( $newtext, wfMessage( 'ts-newusermessage-signatures-update' )->text(), 0, $r->getId() );
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

<?php

require_once __DIR__ . '/Maintenance.php';

/**
 * Maintenance script to stash some text content.
 *
 * @ingroup Maintenance
 */
class StashCLI extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = "Stash some text from the command line, text is from stdin";
		$this->addOption( 'user', 'Username', false, true, 'u' );
		$this->addOption( 'summary', 'Edit summary', false, true, 's' );
		$this->addOption( 'minor', 'Minor edit', false, false, 'm' );
		$this->addOption( 'bot', 'Bot edit', false, false, 'b' );
		$this->addOption( 'autosummary', 'Enable autosummary', false, false, 'a' );
		$this->addOption( 'no-rc', 'Do not show the change in recent changes', false, false, 'r' );
		$this->addOption( 'nocreate', 'Don\'t create new pages', false, false );
		$this->addOption( 'createonly', 'Only create new pages', false, false );
	}

	public function execute() {
		global $wgUser, $wgMaintStashPrefix;

		$userName = $this->getOption( 'user', 'Maintenance script' );
		$summary = $this->getOption( 'summary', '' );
		$minor = $this->hasOption( 'minor' );
		$bot = $this->hasOption( 'bot' );
		$autoSummary = $this->hasOption( 'autosummary' );
		$noRC = $this->hasOption( 'no-rc' );

		$wgUser = User::newFromName( $userName );
		if ( !$wgUser ) {
			$this->error( "Invalid username", true );
		}
		if ( $wgUser->isAnon() ) {
			# $wgUser->addToDatabase();
		}

		# Read the text
		$text = $this->getStdin( Maintenance::STDIN_ALL );

		$title = Title::newFromText( $wgMaintStashPrefix . md5( $text ) );
		if ( !$title ) {
			$this->error( "Invalid title", true );
		}

		if ( $this->hasOption( 'nocreate' ) && !$title->exists() ) {
			$this->error( "Page does not exist", true );
		} elseif ( $this->hasOption( 'createonly' ) && $title->exists() ) {
			$this->error( "Page already exists", true );
		}

		$page = WikiPage::factory( $title );
		$content = ContentHandler::makeContent( $text, $title );

		# Do the edit
		$this->output( "Saving to [[{$title->getPrefixedText()}]]... " );
		$status = $page->doEditContent( $content, $summary,
			( $minor ? EDIT_MINOR : 0 ) |
			( $bot ? EDIT_FORCE_BOT : 0 ) |
			( $autoSummary ? EDIT_AUTOSUMMARY : 0 ) |
			( $noRC ? EDIT_SUPPRESS_RC : 0 ) );
		if ( $status->isOK() ) {
			$this->output( "done\n" );
			$exit = 0;
		} else {
			$this->output( "failed\n" );
			$exit = 1;
		}
		if ( !$status->isGood() ) {
			$this->output( $status->getWikiText() . "\n" );
		}
		exit( $exit );
	}
}

$maintClass = "StashCLI";
require_once RUN_MAINTENANCE_IF_MAIN;

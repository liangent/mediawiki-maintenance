<?php

require_once __DIR__ . '/Maintenance.php';

/**
 * Maintenance script that outputs revision text to stdout.
 *
 * @ingroup Maintenance
 */
class GetRevisionMaint extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = 'Outputs revision text to stdout';
		$this->addOption( 'show-private', 'Show the text even if it\'s not available to the public' );
		$this->addArg( 'revision', 'Revision ID' );
	}

	public function execute() {
		$this->db = wfGetDB( DB_SLAVE );

		$revisionText = $this->getArg( 0 );

		$rev = Revision::newFromId( $revisionText );
		if ( !$rev ) {
			$this->error( "Revision $revisionText does not exist.\n", true );
		}
		$content = $rev->getContent( $this->hasOption( 'show-private' )
			? Revision::RAW
			: Revision::FOR_PUBLIC );

		if ( $content === false ) {
			$titleText = $title->getPrefixedText();
			$this->error( "Couldn't extract the text from revision $revisionText.\n", true );
		}
		$this->output( $content->serialize() );
	}
}

$maintClass = "GetRevisionMaint";
require_once RUN_MAINTENANCE_IF_MAIN;

<?php

require_once( dirname( __FILE__ ) . '/Maintenance.php' );

class ParseTitle extends Maintenance {

	public function __construct() {
		parent::__construct();
	}

	public function executeLine( $line ) {
		$title = Title::newFromText( $line );
		$data = array();

		if ( $title ) {
			$data['interwiki'] = $title->getInterwiki();
			$data['namespace'] = $title->getNamespace();
			$data['fragment'] = $title->getFragment();
			$data['dbkey'] = $title->getDBKey();
		}

		$this->output( FormatJson::encode( $data ) );
		$this->output( "\n" );
	}

	public function execute() {
		foreach ( $this->mArgs as $line ) {
			$this->executeLine( $line );
		}
	}
}

$maintClass = "ParseTitle";
require_once( RUN_MAINTENANCE_IF_MAIN );

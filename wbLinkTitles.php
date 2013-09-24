<?php

require_once( dirname( __FILE__ ) . '/Maintenance.php' );

class WbLinkTitles extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addOption( 'bot', 'Mark edits as "bot".', false );
	}

	public function executePair( $fromSite, $fromPage, $toSite, $toPage ) {
		global $wgLabs;

		$resp = $wgLabs->apiRequest( array(
			'action' => 'wblinktitles',
			'fromsite' => $fromSite,
			'fromtitle' => $fromPage,
			'tosite' => $toSite,
			'totitle' => $toPage,
			'token' => array(
				'type' => 'token',
				'token' => 'edit',
			),
			( $this->hasOption( 'bot' ) ? 'bot' : 'notbot' ) => '',
		) );

		if ( !$resp ) {
			return '*';
		}

		if ( isset( $resp->error ) ) {
			return $resp->error->code;
		}

		return '';
	}

	public function execute() {
		$fromSite = null;
		$fromPage = null;
		$toSitePages = array();
		$siteId = null;
		$pageName = null;
		foreach ( $this->mArgs as $arg ) {
			if ( $siteId === null ) {
				$siteId = $arg;
				continue;
			} else {
				$pageName = $arg;
			}
			if ( $fromSite === null ) {
				$fromSite = $siteId;
				$fromPage = $pageName;
			} else {
				$toSitePages[] = array( $siteId, $pageName );
			}
			$siteId = null;
		}

		$result = array();
		foreach ( $toSitePages as $toSitePage ) {
			list( $toSite, $toPage ) = $toSitePage;
			$result[$toSite] = $this->executePair( $fromSite, $fromPage, $toSite, $toPage );
		}

		$this->output( FormatJson::encode( $result ) );
		$this->output( "\n" );
	}
}

$maintClass = "WbLinkTitles";
require_once( RUN_MAINTENANCE_IF_MAIN );

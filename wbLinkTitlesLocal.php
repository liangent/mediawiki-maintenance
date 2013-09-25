<?php

use Wikibase\Entity;

require_once( dirname( __FILE__ ) . '/Maintenance.php' );

class WbLinkTitlesLocal extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addOption( 'bot', 'Mark edits as "bot".', false );
		$this->addOption( 'report', 'Report conflicts automatically to the given page.', false, true );
		$this->addOption( 'report-bot', 'Mark report edits as "bot".', false );
		$this->addOption( 'report-key', 'Report "key" (embedded in message keys) for this run (default: conflict).', false, true );
		$this->addOption( 'report-message', 'Include this message in the conflict report.', false, true );
		$this->addOption( 'max-retries', 'Maximum number of retries (default: 3).', false, true );
	}

	public function execute() {
		$sitePages = array();
		$siteId = null;
		$pageName = null;
		foreach ( $this->mArgs as $arg ) {
			if ( $siteId === null ) {
				$siteId = $arg;
				continue;
			} else {
				$pageName = $arg;
			}
			$sitePages[$siteId] = $pageName;
			$siteId = null;
		}

		$result = array( '*' => '' );
		$allSites = SiteSQLStore::newInstance();
		foreach ( $sitePages as $siteId => &$pageName ) {
			$site = $allSites->getSite( $siteId );
			if ( !$site ) {
				$this->error( "Invalid site: $siteId.", 1 );
			}
			$normalizedPageName = $site->normalizePageName( $pageName );
			if ( $normalizedPageName === false ) {
				$result[$siteId] = 'missing';
				unset( $sitePages[$siteId] );
			} else {
				$pageName = $normalizedPageName;
			}
		}
		if ( count( $sitePages ) < 2 ) {
			$result += array_fill_keys( array_keys( $sitePages ), '' );
			$this->output( FormatJson::encode( $result ) );
			$this->output( "\n" );
			return;
		}

		$result = $this->executeSitePages( $sitePages, $result );
		$this->output( FormatJson::encode( $result ) );
		$this->output( "\n" );
	}

	public function executeSitePages( $sitePages, $preResult, $tries = 0 ) {
		$itemIds = array();
		$result = $preResult;
		$uniqueItemId = false;
		$siteLinkCache = Wikibase\StoreFactory::getStore()->newSiteLinkCache();
		foreach ( $sitePages as $siteId => $pageName ) {
			$itemId = $siteLinkCache->getEntityIdForSiteLink(
				new Wikibase\DataModel\SimpleSiteLink( $siteId, $pageName )
			);
			if ( !$itemId ) {
				$itemIds[$siteId] = null;
				continue;
			}
			$itemIds[$siteId] = $itemId;
			$result[$siteId] = $itemId->getSerialization();
			if ( $uniqueItemId === false ) {
				$uniqueItemId = $itemId;
			} elseif ( $uniqueItemId !== null && !$uniqueItemId->equals( $itemId ) ) {
				$uniqueItemId = null;
			}
		}

		$conflict = false;
		if ( $uniqueItemId === false ) {
			# All unlinked
			$itemContent = Wikibase\ItemContent::newEmpty();
			$itemTouched = false;
			$baseRevId = false;
			$linkedPieces = array();
			foreach ( $sitePages as $siteId => $pageName ) {
				$siteLink = new Wikibase\DataModel\SimpleSiteLink( $siteId, $pageName );
				$itemContent->getItem()->addSimpleSiteLink( $siteLink );
				$itemTouched = true;
				$linkedPieces[] = wfMessage( 'ts-wblinktitles-summary-item' )->params( $siteId, $pageName )->plain();
			}
		} elseif ( $uniqueItemId ) {
			$entityContentFactory = Wikibase\Repo\WikibaseRepo::getDefaultInstance()->getEntityContentFactory();
			$itemContent = $entityContentFactory->getFromId( $uniqueItemId );
			$itemTouched = false;
			$baseRevId = $itemContent->getWikiPage()->getLatest();
			$linkedPieces = array();
			foreach ( $sitePages as $siteId => $pageName ) {
				if ( !$itemIds[$siteId] ) {
					try {
						$existingSiteLink = $itemContent->getItem()->getSimpleSiteLink( $siteId );
					} catch ( OutOfBoundsException $e ) {
						$existingSiteLink = null;
					}
					if ( $existingSiteLink && $existingSiteLink->getPageName() !== $pageName ) {
						# Ouch, skip this link and mark as conflict now.
						$conflict = true;
						$result[$siteId] = '*';
						continue;
					}
					$siteLink = new Wikibase\DataModel\SimpleSiteLink( $siteId, $pageName );
					$itemContent->getItem()->addSimpleSiteLink( $siteLink );
					$itemTouched = true;
					$itemIds[$siteId] = $itemContent->getItem()->getId();
					$linkedPieces[] = wfMessage( 'ts-wblinktitles-summary-item' )->params( $siteId, $pageName )->plain();
				}
			}
		} else {
			# Conflict!
			$itemContent = null;
			$itemTouched = false;
			$result += array_fill_keys( array_keys( $sitePages ), '*' );
			$conflict = true;
		}

		if ( $conflict ) {
			if ( $this->hasOption( 'report' ) ) {
				$reportTitle = Title::newFromText( $this->getOption( 'report' ) );
			} else {
				$reportTitle = null;
			}
			if ( $reportTitle ) {
				$pageArray = array();
				$key = $this->getOption( 'report-key', 'conflict' );
				foreach ( $itemIds as $siteId => $itemId ) {
					if ( $itemId ) {
						$pageArray[] = wfMessage( "ts-wblinktitles-$key-item" )
							->params( $siteId, $sitePages[$siteId], $itemId->getSerialization() )->plain();
					} else {
						$pageArray[] = wfMessage( "ts-wblinktitles-$key-item-unlinked" )
							->params( $siteId, $sitePages[$siteId] )->plain();
					}
				}
				$message = wfMessage( "ts-wblinktitles-$key" )->params(
					implode( "\n", $pageArray ), $this->getOption( 'report-message', '' )
				)->plain();
				$status = RemoteUtils::insertText( $reportTitle, '', $message,
					wfMessage( "ts-wblinktitles-$key-summary" )->text(),
					$this->hasOption( 'report-bot' ) ? RC_SUPPRESS_RC : 0
				);
				if ( $status->isGood() ) {
					$result['*'] = 'reported';
				} else {
					$result['*'] = 'report-error';
				}
			} else {
				$result['*'] = 'conflict';
			}
		}

		if ( $itemContent && $itemTouched ) {
			global $wgContLang;
			$summary = wfMessage( 'ts-wblinktitles-summary' )->params( $wgContLang->listToText( $linkedPieces ) )->text();
			$status = $itemContent->save( $summary, null,
				( $baseRevId ? 0 : EDIT_NEW ) |
				( $this->hasOption( 'bot' ) ? EDIT_SUPPRESS_RC : 0 ),
			$baseRevId );
			if ( $status->isGood() ) {
				# Nice
				$savedItemId = $itemContent->getItem()->getId();
				$result += array_fill_keys( array_keys( $sitePages ), $savedItemId->getSerialization() );
			} elseif ( $tries < intval( $this->getOption( 'max-retries', 3 ) ) ) {
				$result = $this->executeSitePages( $sitePages, $preResult, $tries + 1 );
			} else {
				$result += array_fill_keys( array_keys( $sitePages ), '?' );
			}
		}

		return $result;
	}
}

$maintClass = "WbLinkTitlesLocal";
require_once( RUN_MAINTENANCE_IF_MAIN );

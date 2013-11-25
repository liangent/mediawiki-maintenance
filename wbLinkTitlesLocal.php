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
		$this->addOption( 'merge', 'Try to merge items.', false );
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
		$siteLinkGroups = Wikibase\Settings::get( 'siteLinkGroups' );
		foreach ( $sitePages as $siteId => &$pageName ) {
			$site = $allSites->getSite( $siteId );
			if ( !$site || !in_array( $site->getGroup(), $siteLinkGroups ) ) {
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

		$this->entityContentFactory = Wikibase\Repo\WikibaseRepo::getDefaultInstance()->getEntityContentFactory();
		$this->result = $result;
		$this->sitePages = $sitePages;
		$result = $this->executeSitePages();
		$this->output( FormatJson::encode( $result ) );
		$this->output( "\n" );
	}

	public function getLinkedSitePagesText( $linkedSitePages ) {
		global $wgContLang;
		$linkedPieces = array();
		foreach ( $linkedSitePages as $siteId => $pageName ) {
			$linkedPieces[] = wfMessage( 'ts-wblinktitles-summary-item' )->params( $siteId, $pageName )->plain();
		}
		return $wgContLang->listToText( $linkedPieces );
	}

	public function saveOneItem( $item, $itemContent, $linkedSitePages, $result, $tries ) {
		if ( $itemContent ) {
			$baseRevId = $itemContent->getWikiPage()->getLatest();
			if ( $item ) {
				$itemContent->setItem( $item );
			}
		} else {
			$itemContent = $this->entityContentFactory->newFromEntity( $item );
			$baseRevId = false;
		}
		$summary = wfMessage( 'ts-wblinktitles-summary' )->params( $this->getLinkedSitePagesText( $linkedSitePages ) )->text();
		$status = $itemContent->save( $summary, null,
			( $baseRevId ? 0 : EDIT_NEW ) |
			( $this->hasOption( 'bot' ) ? EDIT_SUPPRESS_RC : 0 ),
		$baseRevId );
		if ( $status->isGood() ) {
			# Nice
			$savedItemId = $itemContent->getItem()->getId();
			$result = array_fill_keys( array_keys( $linkedSitePages ), $savedItemId->getSerialization() ) + $result;
		} elseif ( $tries < intval( $this->getOption( 'max-retries', 3 ) ) ) {
			$result = $this->executeSitePages( $tries + 1 );
		} else {
			$result = array_fill_keys( array_keys( $linkedSitePages ), '?' ) + $result;
		}
		return $result;
	}

	public function editUnlinked( $itemUnlinked, $result, $tries ) {
		$linkedSitePages = array();
		foreach ( $itemUnlinked->getSimpleSiteLinks() as $siteLink ) {
			$linkedSitePages[$siteLink->getSiteId()] = $siteLink->getPageName();
		}
		$result = $this->saveOneItem( $itemUnlinked, null, $linkedSitePages, $result, $tries );
		return array( false, $result );
	}

	public function editUnique( $itemUnlinked, $uniqueItemId, $itemIds, $result, $tries ) {
		$itemContent = $this->entityContentFactory->getFromId( $uniqueItemId );
		$itemTouched = false;
		$linkedSitePages = array();
		$conflict = false;
		$result = array_fill_keys( array_keys( $itemIds ), $uniqueItemId->getSerialization() ) + $result;
		foreach ( $itemUnlinked->getSimpleSiteLinks() as $siteLink ) {
			$siteId = $siteLink->getSiteId();
			$pageName = $siteLink->getPageName();
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
			$itemContent->getItem()->addSimpleSiteLink( $siteLink );
			$itemTouched = true;
			$linkedSitePages[$siteId] = $pageName;
		}
		if ( $itemTouched ) {
			$result = $this->saveOneItem( null, $itemContent, $linkedSitePages, $result, $tries );
		}
		return array( $conflict, $result );
	}

	public function tryMerge( $items, $itemUnlinked, &$linkedSitePages ) {
		$random = array_rand( $items );
		$items[''] = $itemUnlinked;
		$labels = array();
		$descriptions = array();
		$siteLinks = array();
		$claims = null;
		$itemId = null;
		foreach ( $items as $itemKey => $item ) {
			if ( $item->getLabels() + $labels == $labels + $item->getLabels() ) {
				$labels += $item->getLabels();
			} else {
				return null;
			}
			if ( $item->getDescriptions() + $descriptions == $descriptions + $item->getDescriptions() ) {
				$descriptions += $item->getDescriptions();
			} else {
				return null;
			}
			if ( count( $item->getClaims() ) !== 0 ) {
				if ( $claims === null ) {
					$claims = $item->getClaims();
					$itemId = $item->getId();
				} else {
					return null;
				}
			}
			foreach ( $item->getSimpleSiteLinks() as $siteLink ) {
				if ( isset( $siteLinks[$siteLink->getSiteId()] ) ) {
					if ( $siteLinks[$siteLink->getSiteId()]->getPageName() !== $siteLink->getPageName() ) {
						return null;
					}
				} else {
					$siteLinks[$siteLink->getSiteId()] = $siteLink;
					if ( $itemKey === '' ) {
						$linkedSitePages[$siteLink->getSiteId()] = $siteLink->getPageName();
					}
				}
			}
		}
		$claims = new Wikibase\Claims( $claims );
		$item = Wikibase\Item::newEmpty();
		if ( $itemId === null ) {
			$itemId = $items[$random]->getId();
		}
		$item->setId( $itemId );
		$item->setLabels( $labels );
		$item->setDescriptions( $descriptions );
		$item->setClaims( $claims );
		foreach ( $siteLinks as $siteLink ) {
			$item->addSimpleSiteLink( $siteLink );
		}
		return $item;
	}

	public function doMerge( $targetItem, $clearItemIds, $linkedSitePages, $result, $tries ) {
		global $wgContLang;

		$clearMessage = wfMessage( 'ts-wblinktitles-clear-summary' )->params( $targetItem->getId()->getSerialization() )->text();
		$clearItem = Wikibase\Item::newEmpty();
		$maxRetries = intval( $this->getOption( 'max-retries', 3 ) );
		$clearedPieces = array();
		foreach ( $clearItemIds as $clearItemId ) {
			$clearItem->setId( $clearItemId );
			$itemContent = $this->entityContentFactory->newFromEntity( $clearItem );
			for ( $i = 0; $i <= $maxRetries; $i++ ) {
				$status = $itemContent->save( $clearMessage, null, $this->hasOption( 'bot' ) ? EDIT_SUPPRESS_RC : 0, false );
				if ( $status->isGood() ) {
					break;
				}
			}
			$clearedPieces[] = wfMessage( 'ts-wblinktitles-merge-summary-item' )
				->params( $clearItemId->getSerialization() )->plain();
		}

		$clearedText = $wgContLang->listToText( $clearedPieces );
		if ( count( $linkedSitePages ) ) {
			$mergeMessage = wfMessage( 'ts-wblinktitles-merge-link-summary' )
				->params( $clearedText, $this->getLinkedSitePagesText( $linkedSitePages ) )->text();
		} else {
			$mergeMessage = wfMessage( 'ts-wblinktitles-merge-summary' )->params( $clearedText )->text();
		}
		$itemContent = $this->entityContentFactory->newFromEntity( $targetItem );
		for ( $i = 0; $i <= $maxRetries; $i++ ) {
			$status = $itemContent->save( $mergeMessage, null, $this->hasOption( 'bot' ) ? EDIT_SUPPRESS_RC : 0, false );
			if ( $status->isGood() ) {
				foreach ( $this->sitePages as $siteId => $pageName ) {
					try {
						$siteLink = $targetItem->getSimpleSiteLink( $siteId );
					} catch ( OutOfBoundsException $e ) {
						continue;
					}
					if ( $siteLink->getPageName() === $pageName ) {
						$result[$siteId] = $itemContent->getItem()->getId()->getSerialization();
					}
				}
				break;
			}
		}
		$result += array_fill_keys( array_keys( $this->sitePages ), '?' );
		return $result;
	}

	public function attemptMerge( $itemUnlinked, $itemIds, $uniqueItemIds, $result, $tries ) {
		$itemContents = array();
		$items = array();
		foreach ( $uniqueItemIds as $itemIdStr => $itemId ) {
			$itemContent = $this->entityContentFactory->getFromId( $itemId );
			$itemContents[$itemIdStr] = $itemContent;
			$items[$itemIdStr] = $itemContent->getItem();
		}
		$linkedSitePages = array();
		$targetItem = $this->tryMerge( $items, $itemUnlinked, $linkedSitePages );
		if ( $targetItem === null ) {
			return null;
		}
		$targetItemId = $targetItem->getId();
		$clearItemIds = array();
		foreach ( $uniqueItemIds as $itemId ) {
			if ( !$itemId->equals( $targetItemId ) ) {
				$clearItemIds[] = $itemId;
			}
		}
		return array( false, $this->doMerge( $targetItem, $clearItemIds, $linkedSitePages, $result, $tries ) );
	}

	public function executeEdit( $itemUnlinked, $itemIds, $uniqueItemIds, $result, $tries ) {
		if ( count( $uniqueItemIds ) === 0 ) {
			return $this->editUnlinked( $itemUnlinked, $result, $tries );
		} elseif ( count( $uniqueItemIds ) === 1 ) {
			$uniqueItemId = reset( $uniqueItemIds );
			return $this->editUnique( $itemUnlinked, $uniqueItemId, $itemIds, $result, $tries );
		} elseif ( $this->hasOption( 'merge' ) ) {
			$merge = $this->attemptMerge( $itemUnlinked, $itemIds, $uniqueItemIds, $result, $tries );
			if ( $merge !== null ) {
				return $merge;
			}
		}
		# Conflict!
		foreach ( $itemIds as $siteId => $itemId ) {
			$result[$siteId] = $itemId->getSerialization();
		}
		foreach ( $itemUnlinked->getSimpleSiteLinks() as $siteLink ) {
			$result[$siteLink->getSiteId()] = '*';
		}
		return array( true, $result );
	}

	public function executeSitePages( $tries = 0 ) {
		$itemIds = array();
		$uniqueItemIds = array();
		$uniqueItemId = false;
		$siteLinkCache = Wikibase\StoreFactory::getStore()->newSiteLinkCache();
		$itemUnlinked = Wikibase\Item::newEmpty();
		$sitePages = $this->sitePages;
		foreach ( $sitePages as $siteId => $pageName ) {
			$siteLink = new Wikibase\DataModel\SimpleSiteLink( $siteId, $pageName );
			$itemId = $siteLinkCache->getEntityIdForSiteLink( $siteLink );
			if ( $itemId ) {
				$itemIds[$siteId] = $itemId;
				$uniqueItemIds[$itemId->getSerialization()] = $itemId;
			} else {
				$itemUnlinked->addSimpleSiteLink( $siteLink );
			}
		}

		list( $conflict, $result ) = $this->executeEdit( $itemUnlinked, $itemIds, $uniqueItemIds, $this->result, $tries );

		if ( $conflict ) {
			if ( $this->hasOption( 'report' ) ) {
				$reportTitle = Title::newFromText( $this->getOption( 'report' ) );
			} else {
				$reportTitle = null;
			}
			if ( $reportTitle ) {
				$pageArray = array();
				$key = $this->getOption( 'report-key', 'conflict' );
				foreach ( $sitePages as $siteId => $pageName ) {
					$itemIdText = isset( $itemIds[$siteId] ) ? $itemIds[$siteId]->getSerialization() : '*';
					$resultText = isset( $result[$siteId] ) ? $result[$siteId] : '!';
					$pageArray[] = wfMessage( "ts-wblinktitles-$key-item" )
						->params( $siteId, $pageName, $itemIdText, $resultText )->plain();
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

		return $result;
	}
}

$maintClass = "WbLinkTitlesLocal";
require_once( RUN_MAINTENANCE_IF_MAIN );

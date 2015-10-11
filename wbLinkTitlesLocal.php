<?php

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
		$siteLinkGroups = Wikibase\Settings::singleton()->getSetting( 'siteLinkGroups' );
		$specialSiteLinkGroups = Wikibase\Settings::singleton()->getSetting( 'specialSiteLinkGroups' );
		if ( in_array( 'special', $siteLinkGroups ) ) {
			$siteLinkGroups = array_diff( $siteLinkGroups, array( 'special' ) );
			$siteLinkGroups = array_merge( $siteLinkGroups, $specialSiteLinkGroups );
		}
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
		$this->entityStore = Wikibase\Repo\WikibaseRepo::getDefaultInstance()->getEntityStore();
		$this->result = $result;
		$this->sitePages = $sitePages;
		$result = $this->executeSitePages();
		$this->output( FormatJson::encode( $result ) );
		$this->output( "\n" );
	}

	public function getContentFromId( $id ) {
		$wikiPage = $this->entityStore->getWikiPageForEntity( $id );
		return $wikiPage->getContent();
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
			$wikiPage = $this->entityStore->getWikiPageForEntity( $itemContent->getItem()->getId() );
			$baseRevId = $wikiPage->getLatest();
			if ( $item ) {
				$itemContent->setItem( $item );
			}
		} else {
			$itemContent = $this->entityContentFactory->newFromEntity( $item );
			$baseRevId = false;
		}
		$summary = wfMessage( 'ts-wblinktitles-summary' )->params(
			$this->getLinkedSitePagesText( $linkedSitePages ), count( $linkedSitePages )
		)->text();
		try {
			$entityRevision = $this->entityStore->saveEntity(
				$itemContent->getItem(), $summary, new User(),
				( $baseRevId ? 0 : EDIT_NEW ) |
				( $this->hasOption( 'bot' ) ? EDIT_SUPPRESS_RC : 0 ),
				$baseRevId
			);
			$savedItemId = $itemContent->getItem()->getId();
			$result = array_fill_keys( array_keys( $linkedSitePages ), $savedItemId->getSerialization() ) + $result;
		} catch ( Wikibase\Lib\Store\StorageException $e ) {
			if ( $tries < intval( $this->getOption( 'max-retries', 3 ) ) ) {
				$result = $this->executeSitePages( $tries + 1 );
			} else {
				$result = array_fill_keys( array_keys( $linkedSitePages ), '?' ) + $result;
			}
		}
		return $result;
	}

	public function editUnlinked( $itemUnlinked, $result, $tries ) {
		$linkedSitePages = array();
		foreach ( $itemUnlinked->getSiteLinks() as $siteLink ) {
			$linkedSitePages[$siteLink->getSiteId()] = $siteLink->getPageName();
		}
		$result = $this->saveOneItem( $itemUnlinked, null, $linkedSitePages, $result, $tries );
		return array( false, $result );
	}

	public function editUnique( $itemUnlinked, $uniqueItemId, $itemIds, $result, $tries ) {
		$itemContent = $this->getContentFromId( $uniqueItemId );
		$itemTouched = false;
		$linkedSitePages = array();
		$conflict = false;
		$result = array_fill_keys( array_keys( $itemIds ), $uniqueItemId->getSerialization() ) + $result;
		foreach ( $itemUnlinked->getSiteLinks() as $siteLink ) {
			$siteId = $siteLink->getSiteId();
			$pageName = $siteLink->getPageName();
			try {
				$existingSiteLink = $itemContent->getItem()->getSiteLink( $siteId );
			} catch ( OutOfBoundsException $e ) {
				$existingSiteLink = null;
			}
			if ( $existingSiteLink && $existingSiteLink->getPageName() !== $pageName ) {
				# Ouch, skip this link and mark as conflict now.
				$conflict = true;
				$result[$siteId] = '*';
				continue;
			}
			$itemContent->getItem()->addSiteLink( $siteLink );
			$itemTouched = true;
			$linkedSitePages[$siteId] = $pageName;
		}
		if ( $itemTouched ) {
			$result = $this->saveOneItem( null, $itemContent, $linkedSitePages, $result, $tries );
		}
		return array( $conflict, $result );
	}

	public function pickPreferredTarget( $items ) {
		$keyArray = array();
		$numericId = array();
		foreach ( $items as $key => $item ) {
			$keyArray[] = $key;
			$numericId[] = $item->getId()->getNumericId();
		}
		array_multisort( $numericId, SORT_ASC, SORT_NUMERIC, $keyArray );
		return isset( $keyArray[0] ) ? $keyArray[0] : $keyArray;
	}

	public function tryMerge( $items, $itemUnlinked, &$linkedSitePages ) {
		$preferred = $this->pickPreferredTarget( $items );
		$items[''] = $itemUnlinked;
		$labels = array();
		$descriptions = array();
		$aliases = array();
		$siteLinks = array();
		$claims = null;
		$itemId = null;
		$allSites = SiteSQLStore::newInstance();
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
			foreach ( $item->getAllAliases() as $languageCode => $itemAliases ) {
				if ( isset( $aliases[$languageCode] ) ) {
					$aliases[$languageCode] = array_unique( array_merge( $aliases[$languageCode], $itemAliases ) );
				} else {
					$aliases[$languageCode] = $itemAliases;
				}
			}
			if ( count( $item->getClaims() ) !== 0 ) {
				if ( $claims === null ) {
					$claims = $item->getClaims();
					$itemId = $item->getId();
				} else {
					return null;
				}
			}
			foreach ( $item->getSiteLinks() as $siteLink ) {
				if ( isset( $siteLinks[$siteLink->getSiteId()] ) ) {
					$storedSiteLink = $siteLinks[$siteLink->getSiteId()];
				} else {
					$storedSiteLink = new Wikibase\DataModel\SiteLink(
						$siteLink->getSiteId(), '' # Make up an invalid site link
					);
				}
				$pageName1 = $storedSiteLink->getPageName();
				$pageName2 = $siteLink->getPageName();
				if ( $pageName1 === $pageName2 ) {
					$pageName = $pageName1; # The same
				} else {
					$site = $allSites->getSite( $siteLink->getSiteId() );
					$pageName2 = $site->normalizePageName( $pageName2 );
					if ( $pageName2 === false ) {
						# The site link to append is not valid.
						continue;
					} else {
						$pageName1 = $site->normalizePageName( $pageName1 );
						if ( $pageName1 === false ) {
							# The existing site link is not valid.
							$siteLinks[$siteLink->getSiteId()] = $siteLink;
							if ( $itemKey === '' ) {
								$linkedSitePages[$siteLink->getSiteId()] = $siteLink->getPageName();
							}
							continue;
						} else {
							if ( $pageName1 === $pageName2 ) {
								# They're actually the same.
								$pageName = $pageName1;
							} else {
								# Can't merge.
								return null;
							}
						}

					}
				}
				$badges1 = $storedSiteLink->getBadges();
				$badges2 = $siteLink->getBadges();
				$badges = array_unique( array_merge( $badges1, $badges2 ) );
				$siteLinks[$siteLink->getSiteId()] = new Wikibase\DataModel\SiteLink(
					$siteLink->getSiteId(), $pageName, $badges
				);
			}
		}
		$claims = new Wikibase\DataModel\Claim\Claims( $claims );
		$item = Wikibase\DataModel\Entity\Item::newEmpty();
		if ( $itemId === null ) {
			$itemId = $items[$preferred]->getId();
		}
		$item->setId( $itemId );
		$item->setLabels( $labels );
		$item->setDescriptions( $descriptions );
		$item->setAllAliases( $aliases );
		$item->setClaims( $claims );
		foreach ( $siteLinks as $siteLink ) {
			$item->addSiteLink( $siteLink );
		}
		return $item;
	}

	public function doMerge( $targetItem, $clearItemIds, $linkedSitePages, $result, $tries ) {
		global $wgContLang;

		$clearMessage = wfMessage( 'ts-wblinktitles-clear-summary' )->params( $targetItem->getId()->getSerialization() )->text();
		$clearItem = Wikibase\DataModel\Entity\Item::newEmpty();
		$redirectMessage = wfMessage( 'ts-wblinktitles-redirect-summary' )->params( $targetItem->getId()->getSerialization() )->text();
		$maxRetries = intval( $this->getOption( 'max-retries', 3 ) );
		$clearedPieces = array();
		foreach ( $clearItemIds as $clearItemId ) {
			$redirect = new Wikibase\Lib\Store\EntityRedirect( $clearItemId, $targetItem->getId() );
			$saved = false;
			for ( $i = 0; $i <= $maxRetries; $i++ ) {
				try {
					$entityRevision = $this->entityStore->saveRedirect(
						$redirect, $redirectMessage, new User(),
						$this->hasOption( 'bot' ) ? EDIT_SUPPRESS_RC : 0, false
					);
					$saved = true;
					break;
				} catch ( Wikibase\Lib\Store\StorageException $e ) {
				}
			}
			if ( !$saved ) {
				$clearItem->setId( $clearItemId );
				$itemContent = $this->entityContentFactory->newFromEntity( $clearItem );
				for ( $i = 0; $i <= $maxRetries; $i++ ) {
					try {
						$entityRevision = $this->entityStore->saveEntity(
							$itemContent->getItem(), $clearMessage, new User(),
							$this->hasOption( 'bot' ) ? EDIT_SUPPRESS_RC : 0, false
						);
						break;
					} catch ( Wikibase\Lib\Store\StorageException $e ) {
					}
				}
			}
			$clearedPieces[] = wfMessage( 'ts-wblinktitles-merge-summary-item' )
				->params( $clearItemId->getSerialization() )->plain();
		}

		if ( count( $linkedSitePages ) ) {
			$mergeMessage = wfMessage( 'ts-wblinktitles-merge-link-summary' )
				->params(
					$wgContLang->listToText( $clearedPieces ),
					$this->getLinkedSitePagesText( $linkedSitePages ),
					count( $clearedPieces ), count( $linkedSitePages )
				)->text();
		} else {
			$mergeMessage = wfMessage( 'ts-wblinktitles-merge-summary' )->params(
				$wgContLang->listToText( $clearedPieces ), count( $clearedPieces )
			)->text();
		}
		$itemContent = $this->entityContentFactory->newFromEntity( $targetItem );
		$wikiPage = $this->entityStore->getWikiPageForEntity( $targetItem->getId() );
		$baseRevId = $wikiPage->getLatest();
		$break2 = false;
		foreach ( array( $baseRevId, false ) as $effectiveBaseRevId ) {
			for ( $i = 0; $i <= $maxRetries; $i++ ) {
				try {
					$entityRevision = $this->entityStore->saveEntity(
						$itemContent->getItem(), $mergeMessage, new User(),
						$this->hasOption( 'bot' ) ? EDIT_SUPPRESS_RC : 0,
						$effectiveBaseRevId
					);
					$result['*'] = ( $effectiveBaseRevId === false ? 'merged-force' : 'merged' );
					foreach ( $this->sitePages as $siteId => $pageName ) {
						try {
							$siteLink = $targetItem->getSiteLink( $siteId );
						} catch ( OutOfBoundsException $e ) {
							continue;
						}
						if ( $siteLink->getPageName() === $pageName ) {
							$result[$siteId] = $itemContent->getItem()->getId()->getSerialization();
						}
					}
					$break2 = true;
					break;
				} catch ( Wikibase\Lib\Store\StorageException $e ) {
				}
			}
			if ( $break2 ) {
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
			$itemContent = $this->getContentFromId( $itemId );
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
		foreach ( $itemUnlinked->getSiteLinks() as $siteLink ) {
			$result[$siteLink->getSiteId()] = '*';
		}
		return array( true, $result );
	}

	public function executeSitePages( $tries = 0 ) {
		$itemIds = array();
		$uniqueItemIds = array();
		$uniqueItemId = false;
		$siteLinkCache = Wikibase\StoreFactory::getStore()->newSiteLinkCache();
		$itemUnlinked = Wikibase\DataModel\Entity\Item::newEmpty();
		$sitePages = $this->sitePages;
		foreach ( $sitePages as $siteId => $pageName ) {
			$siteLink = new Wikibase\DataModel\SiteLink( $siteId, $pageName );
			$itemId = $siteLinkCache->getEntityIdForSiteLink( $siteLink );
			if ( $itemId ) {
				$itemIds[$siteId] = $itemId;
				$uniqueItemIds[$itemId->getSerialization()] = $itemId;
			} else {
				$itemUnlinked->addSiteLink( $siteLink );
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

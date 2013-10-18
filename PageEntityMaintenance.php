<?php

use Wikibase\DataModel\Entity;

require_once __DIR__ . '/PageMaintenance.php';

class PageEntityMaintenance extends PageMaintenance {

	public function __construct() {
		parent::__construct();
	}

	public function executeTitle( $title ) {
		try {
			$page = WikiPage::factory( $title );
		} catch ( MWException $e ) {
			return;
		}

		$rev = $page->getRevision();
		if ( !$rev ) {
			return;
		}

		$content = $rev->getContent();
		if ( !( $content instanceof Wikibase\EntityContent ) ) {
			return;
		}

		$entity = $content->getEntity();
		$this->output( "{$entity->getId()->getSerialization()}\n" );
		$this->executeEntity( $entity, $title, $page );
	}

	public function executeEntity( $entity, $title, $page ) {
	}

	public function entitySmartAddClaim( $entity, $claim, $params = array() ) {
		$hasOne = false;
		$touched = false;
		foreach ( $entity->getClaims() as $existingClaim ) {
			if ( !$claim->getMainSnak()->equals( $existingClaim->getMainSnak() ) ) {
				if ( $claim->getMainSnak()->getPropertyId()->equals(
					$existingClaim->getMainSnak()->getPropertyId() )
				) {
					$hasOne = true;
				}
				continue;
			}
			foreach ( $claim->getQualifiers() as $snak ) {
				if ( $existingClaim->getQualifiers()->hasSnak( $snak ) ) {
					continue;
				}
				$existingClaim->getQualifiers()->addSnak( $snak );
				$touched = true;
			}
			if ( $existingClaim instanceof Wikibase\Statement
				&& $claim instanceof Wikibase\Statement
			) {
				foreach ( $claim->getReferences() as $reference ) {
					if ( $existingClaim->getReferences()->hasReference( $reference ) ) {
						continue;
					}
					$existingClaim->getReferences()->addReference( $reference );
					$touched = true;
				}
			}
			return $touched;
		}
		if ( $hasOne && isset( $params['unique'] ) && $params['unique'] ) {
			return null;
		}
		$entity->addClaim( $claim );
		return true;
	}
}

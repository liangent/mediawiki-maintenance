<?php

require_once( dirname( __FILE__ ) . '/PageEntityMaintenance.php' );
require_once( dirname( __FILE__ ) . '/prcadmin/DivisionCode.php' );

class WdP132FromP442 extends PageEntityMaintenance {
	public function executeEntity( $entity, $title, $page ) {
		$added = false;
		foreach ( $entity->getClaims() as $claim ) {
			if ( $claim->getMainSnak()->getPropertyId()->equals(
				new Wikibase\DataModel\Entity\PropertyId( 'P442' ) )
				&& $claim->getMainSnak() instanceof Wikibase\PropertyValueSnak
			) {
				$newClaim = $this->makeP132FromP442( $entity, $claim );
				if ( $newClaim ) {
					$added = $added || $this->entitySmartAddClaim( $entity, $newClaim );
				}
			}
		}
		if ( $added ) {
			$factory = Wikibase\Repo\WikibaseRepo::getDefaultInstance()->getEntityContentFactory();
			$entityContent = $factory->newFromEntity( $entity );
			$status = $entityContent->save(
				wfMessage( 'ts-wdp132fromp442-summary' )->text(), null, EDIT_SUPPRESS_RC, $page->getLatest()
			);
			if ( $status->isGood() ) {
				$this->output( "\tok.\n" );
			} else {
				$this->output( "\tERROR.\n" );
			}
		} else {
			$this->output( "\tno change.\n" );
		}
	}

	public function makeP132FromP442( $entity, $claim ) {
		$name = $entity->getLabel( 'zh-cn' );
		$value = $claim->getMainSnak()->getDataValue()->getValue();
		$this->output( "\t$value" );
		try {
			$divisionCode = new DivisionCode( $value );
		} catch ( DivisionCodeException $e ) {
			$this->output( "\tbad code.\n" );
			return null;
		}
		$newSnakValue = null;
		switch ( $divisionCode->getLevel() ) {
		case 2:
			$code = $divisionCode->getCode2();
			if ( ( $code >= '01' && $code <= '20' )
				|| ( $code >= '51' && $code <= '70' )
			) {
				$newSnakValue = new Wikibase\DataModel\Entity\ItemId( 'Q748149' );
			} elseif ( $code >= '21' && $code <= '50' && $name !== false ) {
				if ( preg_match( '/地区$/', $name ) ) {
					$newSnakValue = new Wikibase\DataModel\Entity\ItemId( 'Q1045608' );
				} elseif ( preg_match( '/自治州$/', $name ) ) {
					$newSnakValue = new Wikibase\DataModel\Entity\ItemId( 'Q788104' );
				} elseif ( preg_match( '/盟$/', $name ) ) {
					$newSnakValue = new Wikibase\DataModel\Entity\ItemId( 'Q288653' );
				}
			}
			break;
		}
		if ( $newSnakValue !== null ) {
			$this->output( "\t{$newSnakValue->getSerialization()}.\n" );
			$newSnakValue = new Wikibase\DataModel\Entity\EntityIdValue( $newSnakValue );
			$newSnak = new Wikibase\PropertyValueSnak(
				new Wikibase\DataModel\Entity\PropertyId( 'P132' ), $newSnakValue
			);
			$newClaim = $claim->newFromArray( $claim->toArray() );
			$guidGenerator = new Wikibase\Lib\ClaimGuidGenerator( $entity->getId() );
			$newClaim->setGuid( $guidGenerator->newGuid() );
			$newClaim->setMainSnak( $newSnak );
			return $newClaim;
		} else {
			$this->output( "\tunknown.\n" );
			return null;
		}
	}
}

$maintClass = "WdP132FromP442";
require_once( RUN_MAINTENANCE_IF_MAIN );

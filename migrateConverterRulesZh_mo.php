<?php

require_once( dirname( __FILE__ ) . '/MigrateConverterRules.php' );

class MigrateConverterRulesZh_mo extends MigrateConverterRules {

	public function getEditSummary() {
		return wfMessage( 'ts-migrate-converter-rules-zh-mo' )->text();
	}

	public function isCacheable() {
		return true;
	}

	public function migrateRule( $rule, $title ) {
		# Unidtable
		$unidMO = isset( $rule->mUnidtable['zh-mo'] ) ? $rule->mUnidtable['zh-mo'] : array();
		$unidHK = isset( $rule->mUnidtable['zh-hk'] ) ? $rule->mUnidtable['zh-hk'] : array();
		$unidMOx = $unidMO + $unidHK;
		if ( count( $unidMO ) < count( $unidMOx ) ) {
			$rule->mRules = null;
			$rule->mUnidtable['zh-mo'] = $unidMOx;
		}

		# Bidtable
		$bid = &$rule->mBidtable;
		if ( isset( $bid['zh-mo'] ) || !isset( $bid['zh-hk'] ) || !isset( $bid['zh-hant'] ) ) {
			return;
		}
		if ( isset( $bid['zh-tw'] ) ) {
			$bid['zh-mo'] = $bid['zh-hk'];
		} else {
			$bid['zh-tw'] = $bid['zh-hant'];
			unset( $bid['zh-hant'] );
		}
		$rule->mRules = null;
	}
}

$maintClass = "MigrateConverterRulesZh_mo";
require_once( RUN_MAINTENANCE_IF_MAIN );

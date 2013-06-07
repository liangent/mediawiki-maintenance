<?php

require_once( dirname( __FILE__ ) . '/PageMaintenance.php' );

class MigrateConverterRules extends PageMaintenance {

	private $migrationCache = array();

	public function isCacheable() {
		return false;
	}

	public function migrateRule( $rule, $title ) {
		return $rule;
	}

	public function getEditSummary( $title ) {
		return wfMessage( 'ts-migrate-converter-rules' )->text();
	}

	public function rebuildRule( $rule ) {
		if ( $rule->mRules !== null ) {
			return;
		}
		$rules = '';
		foreach ( $rule->mBidtable as $variant => $value ) {
			$rules .= "$variant:$value;";
		}
		foreach ( $rule->mUnidtable as $variant => $pairs ) {
			foreach ( $pairs as $from => $to ) {
				$rules .= "$from=>$variant:$to;";
			}
		}
		$rule->mRules = $rules;
	}

	public function executeTitle( $title ) {
		global $wgContLang;

		if ( $title->isRedirect() ) {
			$this->output( "redirect.\n" );
			return;
		}

		$rev = Revision::newFromTitle( $title );
		if ( !$rev ) {
			$this->output( "no-rev.\n" );
			return;
		}

		if ( $rev->getContentModel() != CONTENT_MODEL_WIKITEXT ) {
			$this->output( "non-wikitext.\n" );
			return;
		}

		$wikitext = $rev->getText();
		$expanded = RemoteUtils::preprocessRevision( $rev );
		if ( $wikitext === '' || is_null( $expanded ) ) {
			return;
		}

		$matches = array();
		preg_match_all( '/-\{((?:(?!-\{).)*?)\}-/', $expanded, $matches, PREG_PATTERN_ORDER );
		$ruleText = $matches[1];
		$migrations = array();

		foreach ( $ruleText as $text ) {
			if ( isset( $this->migrationCache[$text] ) ) {
				list( $rules, $newRules ) = $this->migrationCache[$text];
				$migrations[$rules] = $newRules;
				continue;
			}
			$ruleObj = new ConverterRule( $text, $wgContLang->getConverter() );
			$ruleObj->parseFlags();
			$ruleObj->parseRules();
			$rules = $ruleObj->getRules();
			if ( isset( $migrations[$rules] ) ) {
				continue;
			}
			$this->migrateRule( $ruleObj, $title );
			$this->rebuildRule( $ruleObj );
			$newRules = $ruleObj->getRules();
			if ( $rules === $newRules ) {
				continue;
			}
			$migrations[$rules] = $newRules;
			if ( $this->isCacheable() ) {
				$this->migrationCache[$text] = array( $rules, $newRules );
			}
		}

		if ( count( $migrations ) > 0 ) {
			$newWikitext = strtr( $wikitext, $migrations );
			if ( $newWikitext !== $wikitext ) {
				$this->output( "saving... " );
				$status = WikiPage::factory( $title )->doEdit(
					$newWikitext, $this->getEditSummary( $title ),
					EDIT_UPDATE | EDIT_MINOR | EDIT_SUPPRESS_RC, $rev->getId()
				);
				if ( $status->isGood() ) {
					$this->output( "ok\n" );
				} else {
					$this->output( "ERROR\n" );
				}
			} else {
				$this->output( "no change.\n" );
			}
		} else {
			$this->output( "up-to-date.\n" );
		}
	}
}

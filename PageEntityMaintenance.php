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
}

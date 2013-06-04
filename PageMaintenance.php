<?php

require_once( dirname( __FILE__ ) . '/Maintenance.php' );

class PageMaintenance extends Maintenance {

	protected $pageSource;

	public function __construct() {
		parent::__construct();
		$this->addOption( 'page', 'Specify a page to work on.' );
		$this->addOption( 'category', 'Work on pages in a category.' );
		$this->addOption( 'random', 'Specify a point to start random processing.' );
		$this->addOption( 'random-count', 'The maximum number of pages for random processing.' );
	}

	public function getPageSource() {
		return $this->pageSource;
	}

	public function getRandomQueryInfo() {
		return array();
	}

	public function getDatabase() {
		return wfGetDB( DB_SLAVE );
	}

	public function executeTitle( $title ) {
	}

	public function execute() {
		$titles = null;
		$source = null;
		if ( $this->hasOption( 'page' ) ) {
			$title = Title::newFromText( $this->getOption( 'page' ) );
			if ( $title ) {
				$titles = array( $title );
				$source = 'page';
				$this->output( "Working on given page [[{$title->getPrefixedText()}]].\n" );
			}
		}
		if ( is_null( $titles ) && $this->hasOption( 'category' ) ) {
			$cat = Category::newFromName( $this->getOption( 'category' ) );
			if ( $cat ) {
				$titles = $cat->getMembers();
				$source = 'category';
				$this->output( "Working on pages in category {$cat->getName()}.\n" );
			}
		}
		if ( is_null( $titles ) && $this->hasOption( 'random-count' ) ) {
			$count = intval( $this->getOption( 'random-count' ) );
			if ( $count > 0 ) {
				$db = $this->getDatabase();
				if ( $this->hasOption( 'random' ) ) {
					$rand = floatval( $this->getOption( 'random' ) );
				} else {
					$rand = wfRandom();
				}
				$info = $this->getRandomQueryInfo();
				$tables = isset( $info['tables'] ) ? $info['tables'] : array();
				$fields = isset( $info['fields'] ) ? $info['fields'] : array();
				$conds = isset( $info['conds'] ) ? $info['conds'] : array();
				$options = isset( $info['options'] ) ? $info['options'] : array();
				$join_conds = isset( $info['join_conds'] ) ? $info['join_conds'] : array();
				$tables = array_unique( array_merge( $tables, array( 'page' ) ) );
				$fields = array_unique( array_merge( $fields, array( 'page.*' ) ) );
				$conds[] = 'page_random > ' . $rand;
				$options['LIMIT'] = $count;
				$options['ORDER BY'] = 'page_random';
				$res = $db->select( $tables, $fields, $conds, __METHOD__, $options, $join_conds );
				$titles = TitleArray::newFromResult( $res );
				$source = 'random';
				$this->output( "Working on < $count random pages starting from $rand.\n" );
			}
		}
		if ( is_null( $titles ) ) {
			$this->output( "Please specify one of page, category or random-count.\n" );
			return;
		}
		$this->pageSource = $source;
		foreach ( $titles as $title ) {
			$this->output( "[[{$title->getPrefixedText()}]]\n" );
			$this->executeTitle( $title );
		}
		$this->output( "done\n" );
	}
}

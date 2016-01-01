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
		$this->addOption( 'random-ns', 'Only process random pages in given namespace(s).' );
		$this->addOption( 'start', 'Specify a page ID to start processing.' );
		$this->addOption( 'file', 'Specify a list of titles from the given file to work on.' );
		$this->addOption( 'links', 'Process pages having links to some target. "template" or "page" only currently.' );
		$this->addOption( 'links-page', 'Link target for --links= parameter.' );
		$this->addOption( 'links-reverse', 'Use the other direction in --links= parameter.' );
		$this->addOption( 'links-latest', 'Do page_latest chasing using this as cache key.' );
		$this->setBatchSize( 50 );
	}

	public function getPageSource() {
		return $this->pageSource;
	}

	public function getRandomQueryInfo() {
		return array();
	}

	public function getStartQueryInfo() {
		return $this->getRandomQueryInfo();
	}

	public function getLinksQueryInfo( $linkType ) {
		return $this->getRandomQueryInfo();
	}

	public function prepareQueryInfo( $info ) {
		$tables = isset( $info['tables'] ) ? $info['tables'] : array();
		$fields = isset( $info['fields'] ) ? $info['fields'] : array();
		$conds = isset( $info['conds'] ) ? $info['conds'] : array();
		$options = isset( $info['options'] ) ? $info['options'] : array();
		$join_conds = isset( $info['join_conds'] ) ? $info['join_conds'] : array();
		$tables = array_unique( array_merge( $tables, array( 'page' ) ) );
		$fields = array_unique( array_merge( $fields, array( 'page.*' ) ) );
		return array( $tables, $fields, $conds, $options, $join_conds );
	}

	public function getDatabase() {
		return wfGetDB( DB_SLAVE );
	}

	public function executeTitle( $title ) {
	}

	public function finalize() {
	}

	public function getLinksLatestCacheKey() {
		if ( $this->hasOption( 'links-latest' ) ) {
			return wfMemcKey( 'PageMaintenance', 'LinksLatest', $this->getOption( 'links-latest' ) );
		} else {
			return false;
		}
	}

	public function getLinksLatest() {
		$cacheKey = $this->getLinksLatestCacheKey();
		if ( $cacheKey === false ) {
			return false;
		} else {
			$cache = ObjectCache::getInstance( CACHE_DB );
			$latest = $cache->get( $cacheKey );
			return $latest === false ? 0 : $latest;
		}
	}

	public function setLinksLatest( $latest ) {
		$cacheKey = $this->getLinksLatestCacheKey();
		if ( $cacheKey === false ) {
			return false;
		} else {
			$cache = ObjectCache::getInstance( CACHE_DB );
			$this->output( "Setting current links-latest revision to $latest..." );
			$ret = $cache->set( $cacheKey, $latest );
			if ( $ret ) {
				$this->output( " done.\n" );
			} else {
				$this->output( " ERROR.\n" );
			}
			return $ret;
		}
	}

	public function execute() {
		$titles = null;
		$source = null;
		$continue = null;
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
				$ns = MWNamespace::getValidNamespaces();
				if ( $this->hasOption( 'random-ns' ) ) {
					$ns = array_intersect( $ns, array_map( 'intval', preg_split(
						'/,|:/', $this->getOption( 'random-ns' ), null, PREG_SPLIT_NO_EMPTY
					) ) );
				}
				$info = $this->getRandomQueryInfo();
				list( $tables, $fields, $conds, $options, $join_conds ) = $this->prepareQueryInfo( $info );
				$conds[] = 'page_random > ' . $rand;
				$conds['page_namespace'] = $ns;
				$options['LIMIT'] = $count;
				$options['ORDER BY'] = 'page_random';
				$res = $db->select( $tables, $fields, $conds, __METHOD__, $options, $join_conds );
				$titles = TitleArray::newFromResult( $res );
				$source = 'random';
				$ns = implode( ', ', $ns );
				$this->output( "Working on < $count random pages starting from $rand in namespace(s) $ns.\n" );
			}
		}
		if ( is_null( $titles ) && $this->hasOption( 'start' ) ) {
			$db = $this->getDatabase();
			$start = intval( $this->getOption( 'start' ) );
			$info = $this->getStartQueryInfo();
			list( $tables, $fields, $conds, $options, $join_conds ) = $this->prepareQueryInfo( $info );
			$conds[-1] = 'page_id > ' . $start;
			$options['LIMIT'] = $this->mBatchSize;
			$options['ORDER BY'] = 'page_id';
			$res = $db->select( $tables, $fields, $conds, __METHOD__, $options, $join_conds );
			$titles = TitleArray::newFromResult( $res );
			$source = 'start';
			$continue = function( $title, $id, $latest ) use ( $db, $tables, $fields, $conds, $options, $join_conds ) {
				$conds[-1] = 'page_id > ' . $id;
				$res = $db->select( $tables, $fields, $conds, __METHOD__, $options, $join_conds );
				return TitleArray::newFromResult( $res );
			};
			$this->output( "Working on pages starting from page ID > $start.\n" );
		}
		if ( is_null( $titles ) && $this->hasOption( 'file' ) ) {
			$titles = array();
			$source = 'file';
			$fileName = $this->getOption( 'file' );
			$file = fopen( $fileName, 'r' );
			while ( $line = fgets( $file ) ) {
				$title = Title::newFromText( trim( $line ) );
				if ( $title ) {
					$titles[] = $title;
				}
			}
			fclose( $file );
			$this->output( "Working on pages listed in $fileName.\n" );
		}
		if ( is_null( $titles ) && $this->hasOption( 'links' ) ) {
			$linkType = $this->getOption( 'links' );
			$linkInfo = array(
				# TODO: Merge --category= into --links=.
				'template' => array(
					'table' => 'templatelinks',
					'from' => 'tl_from',
					'namespace' => 'tl_namespace',
					'title' => 'tl_title',
					'default' => NS_TEMPLATE,
				),
				'category' => array(
					'table' => 'categorylinks',
					'from' => 'cl_from',
					'namespace' => NS_CATEGORY,
					'title' => 'cl_to',
					'default' => NS_CATEGORY,
				),
				'page' => array(
					'table' => 'pagelinks',
					'from' => 'pl_from',
					'namespace' => 'pl_namespace',
					'title' => 'pl_title',
					'default' => NS_MAIN,
				),
			);
			if ( isset( $linkInfo[$linkType] ) ) {
				$linkInfo = $linkInfo[$linkType];
			} else {
				$this->output( "Unsupported link type: $linkType.\n" );
				return;
			}
			$target = Title::newFromText( $this->getOption( 'links-page' ), $linkInfo['default'] );
			if ( !$target ) {
				$target = Title::newMainPage();
			}
			$db = $this->getDatabase();
			$info = $this->getLinksQueryInfo( $linkType );
			list( $tables, $fields, $conds, $options, $join_conds ) = $this->prepareQueryInfo( $info );
			$tables[] = $linkInfo['table'];
			$reverse = $this->hasOption( 'links-reverse' );
			if ( $reverse ) {
				if ( is_int( $linkInfo['namespace'] ) ) {
					$conds['page_namespace'] = $linkInfo['namespace'];
				} else {
					$conds[] = 'page_namespace = ' . $linkInfo['namespace'];
				}
				$conds[] = 'page_title = ' . $linkInfo['title'];
				$conds[$linkInfo['from']] = $target->getArticleID();
			} else {
				$conds[] = 'page_id = ' . $linkInfo['from'];
				if ( is_int( $linkInfo['namespace'] ) ) {
					$conds[] = intval( $target->getNamespace() == $linkInfo['namespace'] );
				} else {
					$conds[$linkInfo['namespace']] = $target->getNamespace();
				}
				$conds[$linkInfo['title']] = $target->getDBKey();
			}
			$options['LIMIT'] = $this->mBatchSize;
			$latest = $this->getLinksLatest();
			if ( $latest !== false ) {
				$options['ORDER BY'] = 'page_latest';
				$continueMode = 'latest';
				$conds[-1] = 'page_latest > ' . $latest;
			} else {
				$options['ORDER BY'] = 'page_id';
				$continueMode = 'id';
			}
			$res = $db->select( $tables, $fields, $conds, __METHOD__, $options, $join_conds );
			$titles = TitleArray::newFromResult( $res );
			$source = 'links';
			$continue = function( $title, $id, $latest ) use ( $db, $tables, $fields, $conds, $options, $join_conds, $continueMode ) {
				if ( $continueMode === 'latest' ) {
					$conds[-1] = 'page_latest > ' . $latest;
				} else {
					$conds[-1] = 'page_id > ' . $id;
				}
				$res = $db->select( $tables, $fields, $conds, __METHOD__, $options, $join_conds );
				return TitleArray::newFromResult( $res );
			};
			$prep = $reverse ? 'from' : 'to';
			$this->output( "Working on pages having $linkType links $prep [[{$target->getPrefixedText()}]]"
				. ( $latest === false ? '' : " from revision $latest" ) . ".\n"
			);
		}
		if ( is_null( $titles ) ) {
			$this->output( "Please specify one of page generation arguments.\n" );
			return;
		}
		$this->pageSource = $source;
		while ( true ) {
			$prevTitle = null;
			$id = null;
			$latest = null;
			foreach ( $titles as $title ) {
				$id = $title->getArticleID();
				$latest = $title->getLatestRevID();
				$this->output( "[[{$title->getPrefixedText()}]], id $id, revision $latest\n" );
				$this->executeTitle( $title );
				$this->setLinksLatest( $latest );
				$prevTitle = $title;
			}
			if ( $continue && $prevTitle ) {
				$titles = $continue( $prevTitle, $id, $latest );
			} else {
				break;
			}
		}
		$this->output( "done\n" );
		$this->finalize();
	}
}

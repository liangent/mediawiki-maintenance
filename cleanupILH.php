<?php
/**
 */

require_once( dirname( __FILE__ ) . '/Maintenance.php' );

class CleanupILH extends Maintenance {

	static $templates = null;

	public function __construct() {
		parent::__construct();
		$this->addOption( 'maxlag', 'Do not run if DB lags more than this time.' );
		$this->addOption( 'category', 'Process pages in this category.' );
		$this->addOption( 'random-count', 'Process some random pages using related templates.' );
	}

	static function fallbackArray( &$a, $b ) {
		for ( $i = 0; $i < count( $a ); $i++ ) {
			if ( !$a[$i] ) {
				$a[$i] = $b[$i];
			}
		}
	}

	public function cleanupTitle( $title, $ident = '', $recur = true ) {
		global $wgContLang, $wgLabs;

		static $cleanedup = array();
		static $parserOutput = null;

		$this->output( $ident . $title->getPrefixedText() );
		if ( !$title->exists() ) {
			$this->output( "\tredlink.\n" );
			return;
		}

		if ( $title->isProtected( 'edit' ) && !( $wgLabs->user->isAllowed( 'protect' ) ) ) {
			# It's unlikely to be problematic, and usually we don't have the right
			# to clean them up ( = edit them )...
			$this->output( "\tprotected.\n" );
			return;
		}

		if ( isset( $cleanedup[$title->getPrefixedDBKey()] ) ) {
			$this->output( "\tskipping...\n" );
			return;
		} else {
			$cleanedup[$title->getPrefixedDBKey()] = true;
		}

		$otext = $text = Revision::newFromTitle( $title )->getText();
		# First, find out all links to check
		$matches = array();
		$lang = '(?:ar|de|en|es|fi|fr|it|ja|ms|nl|no|pl|pt|ru|sv|ko|vi|tr|da)';
		$ilhRe = '/\{\{\s*(?:Internal[_ ]link[_ ]helper\/' . $lang
			. '|Link-' . $lang . '|' . $lang . '-link)\s*'
			. '(?:\|\s*([^|}]*?)\s*)?' # Local page name
			. '(?:\|\s*([^|}]*?)\s*)?' # Interwiki page name
			. '(?:\|\s*([^|}]*?)\s*)?' # Text
			. '\}\}/i';
		$transRe = '/\{\{\s*(?:Translink|Tsl)\s*'
			. '(?:\|\s*[^|}]*\s*)?' # Lang
			. '(?:\|\s*([^|}]*?)\s*)?' # Interwiki page name
			. '(?:\|\s*([^|}]*?)\s*)?' # Local page name
			. '(?:\|\s*([^|}]*?)\s*)?' # Text
			. '\}\}/i';
		$batch = new LinkBatch();
		$titles = array();
		preg_match_all( $ilhRe, $text, $matches, PREG_PATTERN_ORDER );
		list( $templates, $locals, $interwikis, $descs ) = $matches;
		self::fallbackArray( $interwikis, $locals );
		preg_match_all( $transRe, $text, $matches, PREG_PATTERN_ORDER );
		list( $templates2, $interwikis2, $locals2, $descs2 ) = $matches;
		self::fallbackArray( $locals2, $interwikis2 );
		$templates = array_merge( $templates, $templates2 );
		$interwikis = array_merge( $interwikis, $interwikis2 );
		$locals = array_merge( $locals, $locals2 );
		$descs = array_merge( $descs, $descs2 );
		$lb = new LinkBatch( $titles );
		foreach ( $locals as $titleText ) {
			$lb->addObj( $titles[] = Title::newFromText( $titleText ) );
			foreach ( $wgContLang->autoConvertToAllVariants( $titleText ) as $titleText ) {
				$lb->addObj( Title::newFromText( $titleText ) );
			}
		}
		$lb->execute();
		for ( $i = 0; $i < count( $templates ); $i++ ) {
			$wgContLang->findVariantLink( $locals[$i], $titles[$i] );
			if ( $titles[$i] && $titles[$i]->isKnown() ) {
				$replace = '[[';
				if ( $titles[$i]->getNamespace() == NS_FILE
					|| $titles[$i]->getNamespace() == NS_CATEGORY
				) {
					$replace .= ':';
				}
				if ( $descs[$i] ) {
					$nt = Title::newFromText( $descs[$i] );
					if ( $nt && $nt->equals( $titles[$i] ) ) {
						$replace .= $descs[$i];
					} else {
						$replace .= $titles[$i]->getPrefixedText() . '|' . $descs[$i];
					}
				} else {
					$replace .= $titles[$i]->getPrefixedText();
				}
				$replace .= ']]';
				$text = str_replace( $templates[$i], $replace, $text );
			}
		}

		if ( $text === $otext ) {
			$this->output( "\tno change." );
			if ( $recur ) {
				$this->output( '..' );
				if ( !$parserOutput ) {
					$parserOutput = new ParserOutput();
				}
				$u = new LinksUpdate( $title, $parserOutput );
				$u->doUpdate();
				$this->output( ' linksupdated.' );
			}
			$this->output( "\n" );
		} else {
			$this->output( "\tsaving..." );
			if ( WikiPage::factory( $title )->doEdit( $text,
				wfMessage( 'ts-cleanup-ilh' )->text(),
			EDIT_MINOR )->isOK() ) {
				$this->output( " done.\n" );
			} else {
				$this->output( " ERROR.\n" );
			}
		}
		if ( $text === $otext && $recur ) {
			# We should call cleanupTitle on all templates this page uses.
			# Blue links may be there, maybe invisible from parsed view!
			# But don't do this recursively?
			foreach ( $title->getTemplateLinksFrom() as $template ) {
				$this->cleanupTitle( $template, $ident . '  ', false );
			}
		}
	}

	public function execute() {
		global $wgContLang;
		if ( is_null( self::$templates ) ) {
			self::$templates = array(
				Title::makeTitleSafe( NS_TEMPLATE, 'Translink' ),
				Title::makeTitleSafe( NS_TEMPLATE, 'Internal link helper' ),
			);
		}
		if ( $this->hasOption( 'maxlag' ) ) {
			$maxlag = intval( $this->getOption( 'maxlag' ) );
			$lag = wfReplag();
			if ( $lag > $maxlag ) {
				$this->output( "Current lag: $lag, required maxlag: $maxlag, exiting.\n" );
				return;
			}
		}
		$titles = null;
		$recursive = null;
		if ( $this->hasOption( 'category' ) ) {
			$cat = Category::newFromName( $this->getOption( 'category' ) );
			if ( $cat ) {
				$titles = $cat->getMembers();
				$recursive = true;
				$this->output( "Working on pages in category {$cat->getName()}.\n" );
			}
		}
		if ( is_null( $titles ) && $this->hasOption( 'random-count' ) ) {
			$count = intval( $this->getOption( 'random-count' ) );
			if ( $count > 0 ) {
				$dbr = wfGetDB( DB_SLAVE );
				$rand = wfRandom();
				$res = $dbr->select(
					array( 'page', 'templatelinks' ),
					array( 'page.*' ),
					array(
						'page_id = tl_from',
						'page_random > ' . $rand,
						$dbr->makeList( array_map( function( $title ) use ( $dbr ) {
							return $dbr->makeList( array(
								'tl_namespace' => $title->getNamespace(),
								'tl_title' => $title->getDBKey(),
							), LIST_AND );
						}, self::$templates ), LIST_OR ),
					),
					__METHOD__,
					array(
						'DISTINCT',
						'LIMIT' => $count,
						'ORDER BY' => 'page_random',
					)
				);
				$titles = TitleArray::newFromResult( $res );
				$recursive = false;
				$this->output( "Working on < $count random pages starting from $rand.\n" );
			}
		}
		if ( is_null( $titles ) ) {
			$this->output( "Please specify either category or random-count.\n" );
			return;
		}
		foreach ( $titles as $title ) {
			$this->cleanupTitle( $title, '', $recursive );
		}
		$this->output( "done\n" );
	}
}

$maintClass = "CleanupILH";
require_once( RUN_MAINTENANCE_IF_MAIN );

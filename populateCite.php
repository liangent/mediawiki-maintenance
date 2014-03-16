<?php
/**
 */

require_once( dirname( __FILE__ ) . '/Maintenance.php' );

class PopulateCite extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addOption( 'category', 'Category to check', false, true );
		$this->addOption( 'template', 'Template name', true, true );
		$this->addOption( 'source', 'URL to trigger when a cite does not exist in source, $1 for placeholder', false, false );
		$this->addOption( 'dry-run', 'Only list what to do', false );
	}

	public function execute() {
		global $wgParser;

		$process = array();
		$articles = array();
		if ( $this->hasOption( 'category' ) ) {
			$cat = Category::newFromName( $this->getOption( 'category' ) );
			if ( !$cat ) {
				$this->output( "Invalid category name.\n" );
				return;
			}
			$templateRe = strtr( preg_quote( $this->getOption( 'template' ) ), array(
				' ' => '[ _]',
				'_' => '[ _]',
			) );
			$citeRe = '/\{\{\s*' . $templateRe . '\s*\|\s*([^|}]*?)\s*[|}]/i';
			foreach ( $cat->getMembers() as $title ) {
				$this->output( $title->getPrefixedText() );
				$text = Revision::newFromTitle( $title )->getText();
				if ( $text === false ) {
					continue;
				}
				# Search for refs to work on and add them to $process.
				$matches = array();
				preg_match_all( $citeRe, $text, &$matches, PREG_PATTERN_ORDER );
				$count = 0;
				foreach ( $matches[1] as $cite ) {
					$anchor = (string)substr( $wgParser->guessSectionNameFromWikiText( $cite ), 1 );
					if ( !isset( $process[$anchor] ) ) {
						$process[$anchor] = array( $cite, $title );
						$count++;
					}
				}
				$this->output( " ... $count more cites extracted.\n" );
				$articles[] = $title;
			}
		} else { # Redirect-fix mode:
			$prefixtitle = Title::makeTitleSafe( NS_TEMPLATE, $this->getOption( 'template' ) . '/' );
			$dbr = wfGetDB( DB_SLAVE );
			$res = $dbr->select(
				array(
					'source' => 'page',
					'target' => 'page',
					'redirect',
				),
				array(
					'source.page_namespace source_namespace',
					'source.page_title source_title',
					'redirect.rd_namespace target_namespace',
					'redirect.rd_title target_title',
				),
				array(
					'target.page_id' => null,
					# Already filtered out interwikis.
					'rd_namespace' => $prefixtitle->getNamespace(),
					'rd_title' . $dbr->buildLike( $prefixtitle->getDBkey(), $dbr->anyString() ),
				),
				__METHOD__,
				array(),
				array(
					'target' => array(
						'LEFT JOIN',
						array(
							'target.page_namespace = rd_namespace',
							'target.page_title = rd_title',
						),
					),
					'source' => array(
						'JOIN',
						'source.page_id = rd_from',
					),
				)
			);
			while ( $row = $dbr->fetchObject( $res ) ) {
				$anchor = substr( $row->target_title, strlen( $prefixtitle->getDBkey() ) );
				$localtitle = Title::makeTitleSafe( $row->target_namespace, $row->target_title );
				$rdtitle = Title::makeTitleSafe( $row->source_namespace, $row->source_title );
				if ( !isset( $process[$anchor] ) ) {
					$this->output(
						"{$rdtitle->getPrefixedText()} -> $anchor ({$localtitle->getPrefixedText()})\n"
					);
					$process[$anchor] = $rdtitle;
				}
			}
		}

		foreach ( $process as $anchor => $citetitle ) {
			if ( is_array( $citetitle ) ) {
				list( $cite, $title ) = $citetitle;
				$rdtitle = null;
				$this->output( "$anchor: $cite in [[{$title->getPrefixedText()}]] ..." );
			} else {
				$rdtitle = $citetitle;
				$this->output( "$anchor: <- [[{$rdtitle->getPrefixedText()}]] ..." );
			}
			$titletext = $this->getOption( 'template' ) . '/' . $anchor;
			$sourcetitle = MWNamespace::getCanonicalName( NS_TEMPLATE ) . ':' . $titletext;
			$localtitle = Title::makeTitleSafe( NS_TEMPLATE, $titletext );
			if ( $localtitle->exists() ) {
				$this->output( " exists locally.\n" );
				continue;
			}
			$source = Http::get( 'http://en.wikipedia.org/w/index.php?title='
				. wfUrlencode( $sourcetitle ) . '&action=raw' );
			if ( $source === false ) {
				$this->output( " does not exist in source.\n" );
				if ( $this->hasOption( 'source' ) && !$rdtitle ) {
					$url = $this->getOption( 'source' );
					$url = str_replace( '$1', wfUrlencode( $cite ), $url );
					$this->output( "  triggering source: $url ..." );
					# We ignore the result and just wait for our next run.
					Http::get( $url );
					$this->output( "\n" );
				}
				continue;
			}
			if ( $this->hasOption( 'dry-run' ) ) {
				$this->output( " content:\n" );
				$this->output( $source );
				$this->output( "\n" );
				continue;
			}
			$this->output( " creating ..." );
			$page = WikiPage::factory( $localtitle );
			if ( $rdtitle ) {
				$summary = wfMessage( 'ts-populate-cite-redirect' )
					->params( $rdtitle->getPrefixedText(), $rdtitle->getLatestRevId() )
					->text();
			} else {
				$summary = wfMessage( 'ts-populate-cite' )
					->params(
						$this->getOption( 'template' ), $cite,
						$title->getPrefixedText(), $title->getLatestRevId()
					)
					->text();
			}
			$status = $page->doEdit( $source, $summary, EDIT_NEW | EDIT_SUPPRESS_RC );
			if ( $status->isOK() ) {
				$this->output( " ok.\n" );
			} else {
				$this->output( " FAILED.\n" );
			}
		}

		$parserOutput = new ParserOutput();
		foreach ( $articles as $article ) {
			$this->output( "[[{$article->getPrefixedText()}]] linksupdate ..." );
			$linksUpdate = new LinksUpdate( $article, $parserOutput );
			$linksUpdate->doUpdate();
			$this->output( "\n" );
		}
	}
}

$maintClass = "PopulateCite";
require_once( RUN_MAINTENANCE_IF_MAIN );

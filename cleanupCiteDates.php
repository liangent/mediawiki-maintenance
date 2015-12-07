<?php

require_once( dirname( __FILE__ ) . '/PageDomMaintenanceExt.php' );

class CleanupCiteDates extends PageDomMaintenanceExt {

	static $citeTemplates = array(
		'Template:Citation',
		'Template:Cite arXiv',
		'Template:Cite AV media',
		'Template:Cite AV media notes',
		'Template:Cite book',
		'Template:Cite conference',
		'Template:Cite DVD notes',
		'Template:Cite encyclopedia',
		'Template:Cite episode',
		'Template:Cite interview',
		'Template:Cite journal',
		'Template:Cite mailing list',
		'Template:Cite map',
		'Template:Cite news',
		'Template:Cite newsgroup',
		'Template:Cite podcast',
		'Template:Cite press release',
		'Template:Cite report',
		'Template:Cite serial',
		'Template:Cite sign',
		'Template:Cite speech',
		'Template:Cite techreport',
		'Template:Cite thesis',
		'Template:Cite web',
	);

	static $dateArguments = array(
		'access-date', 'accessdate',
		'air-date', 'airdate',
		'archive-date', 'archivedate',
		'date',
		'doi-broken', 'doi-broken-date', 'doi-inactive-date', 'DoiBroken', 'doi_brokendate', 'doi_inactivedate',
		'lay-date', 'laydate',
		'publicationdate', 'publication-date',
	);

	public function __construct() {
		parent::__construct();
		$this->addOption( 'bot', 'Mark edits as "bot".', false );
	}

	public function executeTitleDom( $title, $dom, $rev, $data ) {
		$this->domModified = false;
		$this->title = $title;
		$text = $this->nodeToWikitext( $dom );
		if ( $this->domModified ) {
			$this->output( "saving..." );
			if ( WikiPage::factory( $title )->doEdit( $text,
				wfMessage( 'ts-cleanup-citedates' )->text(),
				EDIT_MINOR | ( $this->hasOption( 'bot' ) ? EDIT_SUPPRESS_RC : 0 ),
				$rev ? $rev->getId() : false
			)->isOK() ) {
				$this->output( " done.\n" );
			} else {
				$this->output( " ERROR.\n" );
			}
		} else {
			$this->output( "no change.\n" );
		}
	}

	public function cleanupDateString( $date ) {
		$original = $date;

		$date = trim( html_entity_decode( preg_replace( '/&nbsp;/', ' ', $date ) ) );
		$date = preg_replace( '/\[\[([^<>\[\]\|\{\}]+)\]\]/', '\1', $date );
		$date = preg_replace( '/^(于|於)\s*|\s*(查阅|查閱|出版)$/', '', $date );

		if ( $date !== $original && $this->validateDateString( $date ) ) {
			return $date;
		}

		# The only place where prefix 0's are required is YYYY-MM-DD.
		$date = preg_replace( '/(?<!\d)0+(?=\d)/', '', $date );

		if ( $date !== $original && $this->validateDateString( $date ) ) {
			return $date;
		}

		if ( preg_match( '/^(\d{4})\s*年\s*(\d{1,2})\s*月\s*(\d{1,2})\s*日(?:\s*\d+\s*:\s*\d+(?:\s*:\s*\d+)?|\s*\d+\s*(?:时|時)(?:\s*\d+\s*分(?:\s*\d+\s*秒)?)?)?$/', $date, $matches ) ) {
			list( $_, $year, $month, $day ) = array_map( 'intval', $matches );
			$date = "{$year}年{$month}月{$day}日";
		} elseif ( preg_match( '/^(\d{4})\s*[–\-\/\.]\s*(\d{1,2})\s*[–\-\/\.]\s*(\d{1,2})(?:\s+\d+\s*:\s*\d+(?:\s*:\s*\d+)?)?$/u', $date, $matches ) ) {
			list( $_, $year, $month, $day ) = array_map( 'intval', $matches );
			$date = sprintf( '%04d-%02d-%02d', $year, $month, $day );
		} elseif ( preg_match( '/^(\d{1,2})\s+(\d{1,2}),\s+(\d{4})$/u', $date, $matches ) ) {
			# ProveIt and Yhz1221-bot
			list( $_, $month, $day, $year ) = array_map( 'intval', $matches );
			$date = sprintf( '%04d-%02d-%02d', $year, $month, $day );
		} elseif ( preg_match( '/^(\d{4})\s*\.\s*(\d{1,2})$/', $date, $matches ) ) {
			list( $_, $year, $month ) = array_map( 'intval', $matches );
			$date = "{$year}年{$month}月";
		}

		if ( $date !== $original && $this->validateDateString( $date ) ) {
			return $date;
		}

		# Punctuations?

		$date = preg_replace( '/(，|,) */', ', ', $date );

		if ( $date !== $original && $this->validateDateString( $date ) ) {
			return $date;
		}

		# Aggressive rules -- the resulting date will be revalidated anyway.

		$date = preg_replace( '/, *| +/', ' ', $date );

		if ( $date !== $original && $this->validateDateString( $date ) ) {
			return $date;
		}

		$date = preg_replace( '/(?<=\d) (?=\d)/', ', ', $date );

		if ( $date !== $original && $this->validateDateString( $date ) ) {
			return $date;
		}
	}

	public function validateDateString( $date ) {
		static $interpreter = null;
		static $validate = null;
		if ( !$validate ) {
			global $wgScribuntoEngineConf, $wgScribuntoDefaultEngine;
			$parser = new Parser();
			$parser->startExternalParse( Title::newMainPage(), new ParserOptions(), Parser::OT_HTML );
			$conf = array( 'cpuLimit' => PHP_INT_MAX, 'parser' => $parser );
			$conf += $wgScribuntoEngineConf[$wgScribuntoDefaultEngine];
			$engine = new $conf['class']( $conf );
			$engine->setTitle( $parser->getTitle() );
			$interpreter = $engine->getInterpreter();
			$validator = $interpreter->loadString( <<<'LUA'
-- From Module:Citation/CS1
function is_set( var )
	return not (var == nil or var == '');
end

local validation = require( 'Module:Citation/CS1/Date validation' )

return function( date )
	anchor_year, COinS_date, error_message = validation.dates( { date } )
	return error_message == ''
end
LUA
			, 'validator' );
			$validate = $interpreter->callFunction( $validator );
			$validate = $validate[0];
		}
		$ret = $interpreter->callFunction( $validate, $date );
		return $ret[0];
	}

	public function tryDateString( $date ) {
		if ( $this->validateDateString( $date ) ) {
			return $date;
		}
		$cleaned = $this->cleanupDateString( $date );
		if ( $cleaned !== null ) {
			return $cleaned;
		} else {
			return $date;
		}
	}

	public function executeTemplate( $node, $arrayNode ) {
		$args = array();
		$pieces = array( '{{' );
		for ( $i = 0; $i < $arrayNode->getLength(); $i++ ) {
			$childNode = $arrayNode->item( $i );
			switch ( $childNode->getName() ) {
			case 'title':
				$pieces[] = $templateName = $this->nodeToWikitext( $childNode );
				$templateTitle = Title::newFromText( trim( $templateName ), NS_TEMPLATE );
				if ( !$templateTitle ) {
					return;
				}
				try {
					$templatePage = WikiPage::factory( $templateTitle );
				} catch ( MWException $e ) {
					$templatePage = null;
				}
				if ( $templatePage ) {
					$redirectTitle = $templatePage->getRedirectTarget();
					if ( $redirectTitle ) {
						$templateTitle = $redirectTitle;
					}
				}
				if ( !in_array( $templateTitle->getPrefixedText(), static::$citeTemplates ) ) {
					return;
				}
				break;
			case 'part':
				$arg = $childNode->splitArg();
				if ( $arg['index'] ) {
					$argkr = $argk = $arg['index'];
					$argvr = $argv = $this->nodeToWikitext( $arg['value'] );
					$argvls = $argvrs = '';
				} else {
					$argk = $this->nodeToWikitext( $arg['name'] );
					$argkr = trim( $argk );
					$argv = $this->nodeToWikitext( $arg['value'] );
					$argvr = trim( $argv );
					$argvls = substr( $argv, 0, strlen( $argv ) - strlen( ltrim( $argv ) ) );
					$argvrs = rtrim( $argv ) !== $argv ? substr( $argv, strlen( rtrim( $argv ) ) - strlen( $argv ) ) : '';
				}
				if ( in_array( $argkr, static::$dateArguments ) && $argvr !== '' ) {
					$argvc = $this->tryDateString( $argvr );
					if ( $argvc !== $argvr ) {
						$this->domModified = true;
						$this->output( "Replacing $argkr = $argvr -> $argvc\n" );
						$argv = $argvls . $argvc . $argvrs;
					}
				}
				$pieces[] = '|';
				if ( !$arg['index'] ) {
					$pieces[] = $argk;
					$pieces[] = '=';
				}
				$pieces[] = $argv;
				break;
			}
		}
		$pieces[] = '}}';
		return implode( '', $pieces );
	}
}

$maintClass = "CleanupCiteDates";
require_once( RUN_MAINTENANCE_IF_MAIN );

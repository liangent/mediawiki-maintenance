<?php

require_once( dirname( __FILE__ ) . '/PageDomMaintenanceExt.php' );

class CleanupCiteYMD extends PageDomMaintenanceExt {

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

	public function __construct() {
		parent::__construct();
		$this->addOption( 'bot', 'Mark edits as "bot".', false );
		require_once( dirname( __FILE__ ) . '/cleanupCiteDates.php' );
		$this->cleanupCiteDates = new CleanupCiteDates();
	}

	public function firstValidDate( $dates ) {
		foreach ( $dates as $date ) {
			if ( $this->cleanupCiteDates->validateDateString( $date ) ) {
				return $date;
			}
		}
		foreach ( $dates as $date ) {
			$cleaned = $this->cleanupCiteDates->cleanupDateString( $date );
			if ( $cleaned !== null ) {
				return $cleaned;
			}
		}
	}

	public function executeTitleDom( $title, $dom, $rev, $data ) {
		$this->domModified = false;
		$this->title = $title;
		$text = $this->nodeToWikitext( $dom );
		if ( $this->domModified ) {
			$this->output( "saving..." );
			if ( WikiPage::factory( $title )->doEdit( $text,
				wfMessage( 'ts-cleanup-citeymd' )->text(),
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

	public function executeTemplate( $node, $arrayNode ) {
		$args = array();
		$pieces = array( '{{' );
		$year = '';
		$month = '';
		$day = '';
		$dates = array();
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
				if ( in_array( $argkr, array( 'year', 'month', 'day' ) ) ) {
					$$argkr = $argvr;
					break;
				}
				if ( $argkr == 'date' ) {
					if ( $argvr !== '' ) {
						$dates[] = array( $argk, $argv );
					}
					break;
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
		# If there is a single year it still works. Since it's not broken and might be used
		# in special cases, do not clean it up for now. See [[en:Template:Cite web#Date]].
		if ( $year !== '' && $month !== '' ) {
			if ( $day !== '' ) {
				$date = $this->firstValidDate( array(
					"$year.$month.$day", # This can never be valid but use it as the first one in the fixing loop
					"$year-$month-$day",
					"{$year}年{$month}月{$day}日", # Not so useful; would be caught by the above one
					"$month $day, $year",
					"$day $month, $year", # Removing comma is one of fixing attempts
					"$year $month $day", # |year=2010年|month=1月|day=1日
				) );
			} else {
				$date = $this->firstValidDate( array(
					"$year.$month", # This can never be valid but use it as the first one in the fixing loop
					"{$year}年{$month}月",
					"$month, $year", # Removing comma is one of fixing attempts
					"$year $month", # |year=2010年|month=1月
				) );
			}
			if ( $date === null ) {
				# Couldn't find a valid combined date string; skip it.
				return;
			}
			$pieces[] = "|date=$date";
			# A new date is inserted. Move all existing dates after it so they have a chance to override the new one.
			foreach ( $dates as $date ) {
				list( $argk, $argv ) = $date;
				$pieces[] = "|$argk=$argv";
			}
			$pieces[] = '}}';
			$this->domModified = true;
			return implode( '', $pieces );
		}
	}
}

$maintClass = "CleanupCiteYMD";
require_once( RUN_MAINTENANCE_IF_MAIN );

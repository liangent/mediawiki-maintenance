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

	static $monthMap = array(
		'January' => 1,
		'February' => 2,
		'March' => 3,
		'April' => 4,
		'May' => 5,
		'June' => 6,
		'July' => 7,
		'August' => 8,
		'September' => 9,
		'October' => 10,
		'November' => 11,
		'December' => 12,
		'Jan' => 1,
		'Feb' => 2,
		'Mar' => 3,
		'Apr' => 4,
		'May' => 5,
		'Jun' => 6,
		'Jul' => 7,
		'Aug' => 8,
		'Sep' => 9,
		'Sept' => 9,
		'Oct' => 10,
		'Nov' => 11,
		'Dec' => 12,
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
					$this->domModified = true;
					$$argkr = $argvr;
					break;
				}
				if ( $argkr == 'date' ) {
					$this->domModified = true;
					$dates[] = $argvr;
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
		$year = preg_replace( '/年$/', '', $year );
		$month = preg_replace( '/月$/', '', $month );
		$day = preg_replace( '/日$/', '', $day );
		# Use dot as separator, so it can be further cleaned up by cleanupCiteDates.php without confusion.
		if ( $year !== '' ) {
			if ( $month !== '' ) {
				if ( isset( self::$monthMap[$month] ) ) {
					$month = self::$monthMap[$month];
				}
				if ( $day !== '' ) {
					$pieces[] = '|date=' . $year . '.' . $month . '.' . $day;
				} else {
					$pieces[] = '|date=' . $year . '.' . $month;
				}
			} else {
				$pieces[] = '|date=' . $year;
			}
		}
		foreach ( $dates as $date ) {
			$pieces[] = '|date=' . $date;
		}
		$pieces[] = '}}';
		return implode( '', $pieces );
	}
}

$maintClass = "CleanupCiteYMD";
require_once( RUN_MAINTENANCE_IF_MAIN );

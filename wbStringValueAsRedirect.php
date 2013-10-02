<?php

use Wikibase\DataModel\Entity;

require_once( dirname( __FILE__ ) . '/Maintenance.php' );

class WbStringValueAsRedirect extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addOption( 'xml', 'A dump XML file, or default to stdin', false, true );
		$this->addOption( 'property', 'Property ID', true, true );
		$this->addOption( 'sites', 'Site to create redirects, separated by commas', true, true );
		$this->addOption( 'regex-replace', 'Do replacement on string values first', false, true );
		$this->addOption( 'regex-replacement', 'Replace with this string, empty by default', false, true );
		$this->addOption( 'dry-run', 'Do not really create redirects', false );
		$this->addOption( 'bot', 'Create redirects with bot flag', false );
		$this->addOption( 'debug', 'Output extra verbose debug information', false );
		$this->addOption( 'no-edit', 'Do not edit existing pages', false );
		$this->addOption( 'no-self', 'Do not create self redirects', false );
		$this->setBatchSize( -1 );
		$this->batchRedirect = array();
	}

	public function getRedirectBasicArgs() {
		static $basicArgs = null;
		if ( $basicArgs === null ) {
			$basicArgs = array_merge(
				$this->hasOption( 'bot' ) ? array( '--bot' ) : array(),
				$this->hasOption( 'no-edit' ) ? array( '--no-edit' ) : array(),
				$this->hasOption( 'no-self' ) ? array( '--no-self' ) : array()
			);
		}
		return $basicArgs;
	}

	public function doBatchRedirect() {
		global $IP;

		if ( $this->mBatchSize < 0 ) {
			return;
		}
		foreach ( $this->batchRedirect as $site => &$redirects ) {
			if ( count( $redirects ) > $this->mBatchSize ) {
				$this->output( "$site: batched redirects:" );
				$args = array_merge(
					$this->getRedirectBasicArgs(), array( '--wiki', $site )
				);
				foreach ( $redirects as $redirect ) {
					$args = array_merge( $args, $redirect );
				}
				$cmd = wfShellWikiCmd( "$IP/maintenance/makeRedirect.php", $args );
				if ( $this->hasOption( 'dry-run' ) ) {
					$this->output( " (dry-run)\n$cmd\n" );
				} else {
					$retVal = 1;
					$msg = wfShellExec( $cmd, $retVal, array(), array( 'memory' => 0 ) );
					if ( $retVal ) {
						$this->output( " ERROR: $retVal");
					} else {
						$this->output( " ok" );
					}
					if ( $msg ) {
						$msg = explode( "\n", trim( $msg ) );
					} else {
						$msg = array();
					}
					if ( count( $msg ) === count( $redirects ) ) {
						$this->output( ":\n" );
						for ( $i = 0; $i < count( $msg ); $i++ ) {
							list( $redirectPageName, $targetPageName ) = $redirects[$i];
							$redirectMsg = trim( $msg[$i] );
							$this->output( "  $site: [[$redirectPageName]] "
								. "=> [[$targetPageName]]: $redirectMsg\n"
							);
						}
					} else {
						$this->output( " (bad-output)\n" );
					}
				}
				$redirects = array();
			}
		}
	}

	public function makeRedirect( $site, $redirectPageName, $targetPageName ) {
		global $IP;

		$this->output( "  $site: [[$redirectPageName]] => [[$targetPageName]] ..." );
		if ( $this->mBatchSize >= 0 ) {
			$this->batchRedirect[$site][] = array( $redirectPageName, $targetPageName );
			$this->output( " (batched)\n" );
			return;
		}
		$cmd = wfShellWikiCmd( "$IP/maintenance/makeRedirect.php", array_merge(
			$this->getRedirectBasicArgs(), array( '--wiki', $site, $redirectPageName, $targetPageName )
		) );
		if ( $this->hasOption( 'dry-run' ) ) {
			$this->output( " (dry-run)\n$cmd\n" );
		} else {
			$retVal = 1;
			$msg = wfShellExec( $cmd, $retVal, array(), array( 'memory' => 0 ) );
			if ( $retVal ) {
				$this->output( " ERROR: $retVal");
			} else {
				$this->output( " ok" );
			}
			if ( $msg ) {
				$this->output( ': ' . trim( $msg ) );
			}
			$this->output( ".\n" );
		}
	}

	public function executeItem( $item, $property, $sites, $replace, $replacement ) {
		foreach ( $item->getClaims() as $claim ) {
			$snak = $claim->getMainSnak();
			if ( !$snak->getPropertyId()->equals( $property ) ) {
				continue;
			}
			if ( $snak->getType() !== 'value' ) {
				continue;
			}
			$value = $snak->getDataValue();
			if ( !( $value instanceof DataValues\StringValue ) ) {
				continue;
			}
			$stringValue = $value->getValue();
			$this->output(
				$item->getId()->getSerialization() . ', '
				. $property->getSerialization() . ": { $stringValue }"
			);
			if ( $replace !== '' ) {
				$stringValue = preg_replace( "/$replace/u", $replacement, $stringValue );
				$this->output( " -> { $stringValue }" );
			}
			$this->output( "...\n" );
			foreach ( $sites as $site ) {
				try {
					$siteLink = $item->getSimpleSiteLink( $site );
				} catch ( OutOfBoundsException $e ) {
					continue;
				}
				$this->makeRedirect( $site, $stringValue, $siteLink->getPageName() );
			}
		}
		$this->doBatchRedirect();
	}

	public function execute() {
		if ( $this->hasOption( 'xml' ) ) {
			$filename = $this->getOption( 'xml' );
			if ( preg_match( '/\.gz$/', $filename ) ) {
				$filename = 'compress.zlib://' . $filename;
			} elseif ( preg_match( '/\.bz2$/', $filename ) ) {
				$filename = 'compress.bzip2://' . $filename;
			} elseif ( preg_match( '/\.7z$/', $filename ) ) {
				$filename = 'mediawiki.compress.7z://' . $filename;
			}
			$file = fopen( $filename, 'rt' );
			if ( $file ) {
				$handle = $file;
			} else {
				$this->error( "Invalid file: $filename", 1 );
			}
		} else {
			$handle = fopen( 'php://stdin', 'rt' );
			if ( self::posix_isatty( $handle ) ) {
				$this->maybeHelp( true );
			}
		}

		$this->property = new Entity\PropertyId( $this->getOption( 'property' ) );
		$this->sites = explode( ',', $this->getOption( 'sites' ) );
		$this->replace = $this->getOption( 'regex-replace', '' );
		$this->replacement = $this->getOption( 'regex-replacement', '' );

		$source = new ImportStreamSource( $handle );
		$importer = new WikiImporter( $source );

		if ( $this->hasOption( 'debug' ) ) {
			$importer->setDebug( true );
		}
		$this->importCallback = $importer->setRevisionCallback(
			array( $this, 'handleRevision' ) );
		$retVal = $importer->doImport();
		$this->mBatchSize = 0;
		$this->doBatchRedirect();
		$this->output( "done.\n" );
		return $retVal;
	}

	public function handleRevision( $rev ) {
		$content = $rev->getContent();
		if ( $content instanceof Wikibase\ItemContent ) {
			$this->executeItem( $content->getItem(), $this->property,
				$this->sites, $this->replace, $this->replacement );
		}
	}
}

$maintClass = "WbStringValueAsRedirect";
require_once( RUN_MAINTENANCE_IF_MAIN );

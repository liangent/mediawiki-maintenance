<?php

require_once( dirname( __FILE__ ) . '/PageDomMaintenance.php' );

class MigrateCGroup extends PageDomMaintenance {

	public function __construct() {
		parent::__construct();
		$this->addOption( 'dry-run', 'Do not do real edits.' );
	}

	public function clearState() {
		$this->name = '';
		$this->description = '';
		$this->pieces = array();
		$this->skipComment = false;
		$this->insideNoinclude = false;
	}

	public function executeTitleDom( $title, $dom, $rev, $data ) {
		$text = null;
		if ( $title->isRedirect() ) {
			try {
				$page = WikiPage::factory( $title );
			} catch ( MWException $e ) {
				$page = null;
			}
			if ( $page ) {
				$redirectTitle = $page->getRedirectTarget();
				if ( $redirectTitle ) {
					$text = $this->buildLuaRedirect(
						Title::makeTitle( NS_MODULE, $redirectTitle->getText() )
					);
				}
			}
		} else {
			$this->clearState();
			$this->nodeToWikitext( $dom );
			$text = $this->buildLua();
		}
		if ( $text === null ) {
			return;
		}
		$moduleTitle = Title::makeTitle( NS_MODULE, $title->getText() );
		$this->output( "Editing [[{$moduleTitle->getPrefixedText()}]] ..." );
		if ( $this->hasOption( 'dry-run' ) ) {
			$this->output( " dry-run.\n" );
			return;
		}
		$status = WikiPage::factory( $moduleTitle )->doEdit( $text,
			wfMessage( 'ts-migrate-cgroup-summary' )->params( $title->getPrefixedText() )->text()
		);
		if ( $status->isGood() ) {
			$this->output( " done.\n" );
		} else {
			$this->output( " ERROR.\n" );
		}
	}

	public function executeTemplate( $node, $arrayNode ) {
		if ( $this->insideNoinclude ) {
			return;
		}
		$template = ''; # cgrouph / citem
		$piece = array();

		for ( $i = 0; $i < $arrayNode->getLength(); $i++ ) {
			$childNode = $arrayNode->item( $i );
			switch ( $childNode->getName() ) {
			case 'title':
				$templateName = $this->nodeToWikitext( $childNode );
				$templateTitle = Title::newFromText( $templateName, NS_TEMPLATE );
				if ( !$templateTitle ) {
					continue;
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
				if ( $templateTitle->getPrefixedText() === 'Template:CGroupH' ) {
					$template = 'cgrouph';
				} elseif ( $templateTitle->getPrefixedText() === 'Template:CItemHidden' ) {
					$template = 'citem';
				}

				break;
			case 'part':
				$arg = $childNode->splitArg();
				# Hackish
				$key = $arg['index'] . trim( $this->nodeToWikitext( $arg['name'] ) );
				if ( $template === 'cgrouph' && $key === 'name' ) {
					$this->name = trim( $this->nodeToWikitext( $arg['value'] ) );
				}
				if ( $template === 'cgrouph' && $key === 'desc' ) {
					$this->description = trim( $this->nodeToWikitext( $arg['value'] ) );
				}
				if ( $template === 'citem' && $key === 'original' ) {
					$piece['type'] = 'item';
					$piece['original'] = trim( $this->nodeToWikitext( $arg['value'] ) );
				}
				if ( $template === 'citem' && $key === '1' ) {
					$piece['type'] = 'item';
					$piece['rule'] = trim( $this->nodeToWikitext( $arg['value'] ) );
				}
			}
		}

		$sibling = $node->getNextSibling();
		if ( $sibling && $sibling->getName() == 'comment' ) {
			$this->executeComment( $sibling, $sibling->getChildren() );
			$this->skipComment = true;
		}

		if ( count( $piece ) ) {
			$this->pieces[] = $piece;
		}
	}

	public function executeComment( $node, $arrayNode ) {
		if ( $this->insideNoinclude ) {
			return;
		}
		if ( $this->skipComment ) {
			$this->skipComment = false;
			return;
		}
		$this->pieces[] = array(
			'type' => 'comment',
			'text' => $this->nodeBracketedImplode( '', '', '', '', '', $arrayNode ),
		);
	}

	public function executeIgnore( $node, $arrayNode ) {
		$text = strtolower( $this->nodeBracketedImplode( '', '', '', '', '', $arrayNode ) );
		if ( $text == '<noinclude>' ) {
			$this->insideNoinclude = true;
			$noincludeText = '';
			$sibling = $node;
			while ( true ) {
				$sibling = $sibling->getNextSibling();
				if ( $sibling === false ) {
					throw new MWException( 'unclosed noinclude' );
				}
				$siblingText = $this->nodeToWikitext( $sibling );
				if ( $sibling->getName() == 'ignore' ) {
					$siblingText = strtolower( $siblingText );
					if ( $siblingText == '<noinclude>' ) {
						throw new MWException( 'nested noinclude' );
					}
					if ( $siblingText == '</noinclude>' ) {
						# Once we got a </noinclude>, this is set back to false.
						$this->insideNoinclude = true;
						break;
					}
				} else {
					$noincludeText .= $siblingText;
				}
			}
			$this->pieces[] = array(
				'type' => 'text',
				'text' => $noincludeText,
			);
		} elseif ( $text == '</noinclude>' ) {
			$this->insideNoinclude = false;
		}
	}

	public function buildLuaString( $str ) {
		if ( strpos( $str, '\\' ) === false && strpos( $str, "\n" ) === false ) {
			if ( strpos( $str, "'" ) === false ) {
				return "'$str'";
			} elseif ( strpos( $str, '"' ) === false ) {
				return "\"$str\"";
			}
		}
		$delim = '';
		while ( true ) {
			$term = "]$delim]";
			if ( strpos( $str, $term ) === false ) {
				return "[{$delim}[$str$term";
			}
			$delim .= '=';
		}
	}

	public function buildLua() {
		$pieces = array(
			"return {\n",
			"name = " . $this->buildLuaString( $this->name ) . ",",
			"description = " . $this->buildLuaString( $this->description ) . ",",
			"content = {\n",
		);
		foreach ( $this->pieces as $piece ) {
			switch ( $piece['type'] ) {
			case 'comment':
				foreach ( explode( "\n", trim( substr( trim( $piece['text'] ), 4, -3 ) ) ) as $commentLine ) {
					$pieces[] = "-- $commentLine";
				}
				break;
			case 'text':
				$pieces[] = "{ type = 'text', text = "
					. $this->buildLuaString( "\n" . trim( $piece['text'] ) . "\n" )
					. " },";
				break;
			case 'item':
				$line = "{ type = 'item'";
				if ( isset( $piece['original'] ) ) {
					$line .= ", original = " . $this->buildLuaString( $piece['original'] );
				}
				if ( isset( $piece['rule'] ) ) {
					$line .= ", rule = " . $this->buildLuaString( $piece['rule'] );
				}
				$pieces[] = $line . " },";
				break;
			}
		}
		$pieces[] = "\n},\n}\n";
		return implode( "\n", $pieces );
	}

	public function buildLuaRedirect( $title ) {
		return 'return require( ' . $this->buildLuaString( $title->getPrefixedText() ) . ' );';
	}
}

$maintClass = "MigrateCGroup";
require_once( RUN_MAINTENANCE_IF_MAIN );

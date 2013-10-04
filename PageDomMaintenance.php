<?php

require_once( dirname( __FILE__ ) . '/PageMaintenance.php' );

class PageDomMaintenance extends PageMaintenance {

	public function __construct() {
		parent::__construct();
		$this->addOption( 'remote', 'Preprocess on remote server.' );
	}

	public function nodeBracketedImplode( $start, $sep, $end, $delimName, $ext, $arrayNode ) {
		$length = $arrayNode->getLength();
		$pieces = array( $start );
		$seen = false;
		for ( $i = 0; $i < $length; $i++ ) {
			$item = $arrayNode->item( $i );
			switch ( $item->getName() ) {
			case $delimName:
				$pieces[] = $sep;
				$seen = true;
				# No break
			default:
				$pieces[] = $this->nodeToWikitext( $item );
				break;
			}
		}
		if ( !$seen ) {
			$pieces[] = $ext;
		}
		$pieces[] = $end;
		return implode( '', $pieces );
	}

	public function nodeToWikitext( $node ) {
		$children = $node->getChildren();
		if ( $children ) { # Tree node
			switch ( $node->getName() ) {
			case 'template':
				return $this->executeTemplateNode( $node, $children );
			case 'tplarg':
				return $this->executeTplargNode( $node, $children );
			case 'ext':
				return $this->executeExtNode( $node, $children );
			case 'h':
				return $this->executeHNode( $node, $children );
			case 'comment':
				return $this->executeCommentNode( $node, $children );
			case 'ignore':
				return $this->executeIgnoreNode( $node, $children );
			default:
				# Anything as a child of template/tplarg/ext/... for plain text
				return $this->nodeBracketedImplode( '', '', '', '', '', $children );
			}
		} else { # Leaf node. We don't accept array nodes
			return $node->node->nodeValue;
		}
	}

	public function executeTitle( $title, $data = null ) {
		if ( $this->hasOption( 'remote' ) ) {
			$dom = RemoteUtils::preprocessTitleToDom( $title );
			$rev = null;
		} else {
			global $wgParser;
			$rev = Revision::newFromTitle( $title );
			if ( !$rev ) {
				return;
			}
			$content = $rev->getContent();
			if ( !$content || $content->getModel() !== CONTENT_MODEL_WIKITEXT ) {
				return;
			}
			$wgParser->startExternalParse( $title, new ParserOptions, OT_PREPROCESS );
			$dom = $wgParser->preprocessToDom( $content->getNativeData() );
			if ( !( $dom instanceof PPNode_DOM ) ) {
				$dom = RemoteUtils::preprocessXmlToDom( $dom->__toString() );
			}
		}
		if ( !$dom ) {
			return;
		}
		$this->output( "* XML DOM loaded.\n" );

		$this->executeTitleDom( $title, $dom, $rev, $data );
	}

	public function executeTitleDom( $title, $dom, $rev, $data ) {
	}

	public function executeTemplateNode( $node, $arrayNode ) {
		$text = $this->executeTemplate( $node, $arrayNode );
		if ( $text !== null ) {
			return $text;
		} else {
			return $this->nodeBracketedImplode( '{{', '|', '}}', 'part', '', $arrayNode );
		}
	}

	public function executeTplargNode( $node, $arrayNode ) {
		$text = $this->executeTplarg( $node, $arrayNode );
		if ( $text !== null ) {
			return $text;
		} else {
			return $this->nodeBracketedImplode( '{{{', '|', '}}}', 'part', '', $arrayNode );
		}
	}

	public function executeExtNode( $node, $arrayNode ) {
		$text = $this->executeExt( $node, $arrayNode );
		if ( $text !== null ) {
			return $text;
		} else {
			return $this->nodeBracketedImplode( '<', '>', '', 'inner', '/>', $arrayNode );
		}
	}

	public function executeHNode( $node, $arrayNode ) {
		$text = $this->executeH( $node, $arrayNode );
		if ( $text !== null ) {
			return $text;
		} else {
			return $this->nodeBracketedImplode( '', '', '', '', '', $arrayNode );
		}
	}

	public function executeCommentNode( $node, $arrayNode ) {
		$text = $this->executeComment( $node, $arrayNode );
		if ( $text !== null ) {
			return $text;
		} else {
			return $this->nodeBracketedImplode( '', '', '', '', '', $arrayNode );
		}
	}

	public function executeIgnoreNode( $node, $arrayNode ) {
		$text = $this->executeIgnore( $node, $arrayNode );
		if ( $text !== null ) {
			return $text;
		} else {
			return $this->nodeBracketedImplode( '', '', '', '', '', $arrayNode );
		}
	}

	public function executeTemplate( $node, $arrayNode ) {
	}

	public function executeTplarg( $node, $arrayNode ) {
	}

	public function executeExt( $node, $arrayNode ) {
	}

	public function executeH( $node, $arrayNode ) {
	}

	public function executeComment( $node, $arrayNode ) {
	}

	public function executeIgnore( $node, $arrayNode ) {
	}
}

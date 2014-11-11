<?php

require_once( dirname( __FILE__ ) . '/PageDomMaintenance.php' );

class PageDomMaintenanceExt extends PageDomMaintenance {

	public function executeExt( $node, $arrayNode ) {
		$ext = $node->splitExt();
		$pieces = array( '<' );
		$pieces[] = $name = $this->nodeToWikitext( $ext['name'] );
		$pieces[] = $this->nodeToWikitext( $ext['attr'] );
		if ( isset( $ext['inner'] ) ) {
			$pieces[] = '>';
			$inner = $this->nodeToWikitext( $ext['inner'] );
			if ( in_array( $name, array( 'ref', 'references', 'poem', 'gallery', 'inputbox' ) ) ) {
				global $wgParser;
				$flags = 0;
				if ( $this->hasOption( 'inclusion' ) ) {
					$flags |= Parser::PTD_FOR_INCLUSION;
				}
				$dom = $wgParser->preprocessToDom( $inner, $flags );
				if ( !( $dom instanceof PPNode_DOM ) ) {
					$dom = RemoteUtils::preprocessXmlToDom( $dom->__toString() );
				}
				$inner = $this->nodeToWikitext( $dom );
			}
			$pieces[] = $inner;
		}
		if ( isset( $ext['close'] ) ) {
			$pieces[] = $this->nodeToWikitext( $ext['close'] );
		} elseif ( !isset( $ext['inner'] ) ) {
			$pieces[] = '/>';
		} // Otherwise: unclosed <ext> tag
		return implode( '', $pieces );
	}
}

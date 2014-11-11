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
			if ( in_array( $name, array( 'ref', 'references', 'poem' ) ) ) {
				global $wgParser;
				$dom = $wgParser->preprocessToDom( $inner );
				if ( !( $dom instanceof PPNode_DOM ) ) {
					$dom = RemoteUtils::preprocessXmlToDom( $dom->__toString() );
				}
				$inner = $this->nodeToWikitext( $dom );
			}
			$pieces[] = $inner;
		}
		if ( isset( $ext['close'] ) ) {
			$pieces[] = $this->nodeToWikitext( $ext['close'] );
		} elseif ( isset( $ext['inner'] ) ) {
			throw new MWException( 'Unexpected <ext> structure' );
		} else {
			$pieces[] = '/>';
		}
		return implode( '', $pieces );
	}
}

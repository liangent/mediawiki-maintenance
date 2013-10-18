<?php

class DivisionCode {

	protected static $regex = '/^(1[1-5]|2[1-3]|3[1-7]|4[1-6]|5[0-4]|6[1-5]|71|8[1-2])(?: ?(?:(0[1-9]|[1-6]\d|70|90)|00)(?: ?(?:(0[1-9]|[1-9]\d)|00)(?: ?(?:(00[1-9]|0[1-9]\d|[1-5]\d\d)|000)(?: ?(?:(00[1-9]|0[1-9]\d|[1-5]\d\d)|000)(?: ?(?:(11[1-2]|12[1-3]|2[1-2]0)|000))?)?)?)?)?$/';

	protected $code1, $code2, $code3, $code4, $code5, $code6;

	public function __construct( $code ) {
		$matches = array();
		if ( preg_match( self::$regex, $code, $matches ) ) {
			$this->code1 = $matches[1];
			$this->code2 = isset( $matches[2] ) && $matches[2] !== '' ? $matches[2] : null;
			$this->code3 = isset( $matches[3] ) && $matches[3] !== '' ? $matches[3] : null;
			$this->code4 = isset( $matches[4] ) && $matches[4] !== '' ? $matches[4] : null;
			$this->code5 = isset( $matches[5] ) && $matches[5] !== '' ? $matches[5] : null;
			$this->code6 = isset( $matches[6] ) && $matches[6] !== '' ? $matches[6] : null;
		} else {
			throw new DivisionCodeException( "Invalid division code: $code." );
		}
	}

	public function toString() {
		$str = $this->code1;
		if ( $this->code2 !== null ) {
			$str .= ' ' . $this->code2;
			if ( $this->code3 !== null ) {
				$str .= ' ' . $this->code3;
				if ( $this->code4 !== null ) {
					$str .= ' ' . $this->code4;
					if ( $this->code5 !== null ) {
						$str .= ' ' . $this->code5;
						if ( $this->code6 !== null ) {
							$str .= ' ' . $this->code6;
						}
					}
				}
			}
		}
		return $str;
	}
}

class DivisionCodeException extends Exception {}

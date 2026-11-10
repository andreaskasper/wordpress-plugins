<?php
/**
 * Tiny, safe expression evaluator for clause/question conditions.
 *
 * Grammar:
 *   expr    := or
 *   or      := and ( 'or' and )*
 *   and     := not ( 'and' not )*
 *   not     := ( 'not' | '!' ) not | compare
 *   compare := primary ( ( '==' | '!=' | 'in' ) primary )?
 *   primary := '(' expr ')' | literal | path
 *   literal := true | false | null | number | 'string'
 *   path    := name ( '.' name )*        looked up in the context array
 *
 * Nothing is ever executed; unknown paths are null. A syntax error evaluates
 * to false and is reported through last_error().
 *
 * @package goo1-privacypilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_PP_Conditions {

	/** @var array */
	private $tokens = array();

	/** @var int */
	private $pos = 0;

	/** @var array */
	private $ctx = array();

	/** @var string */
	private static $last_error = '';

	/** @var array */
	private static $memo = array();

	public static function evaluate( $expr, array $context ) {
		$expr = trim( (string) $expr );
		if ( '' === $expr || 'true' === $expr ) {
			return true;
		}
		self::$last_error = '';
		try {
			$p         = new self();
			$p->ctx    = $context;
			$p->tokens = self::tokenize( $expr );
			$p->pos    = 0;
			$value     = $p->parse_or();
			if ( $p->pos < count( $p->tokens ) ) {
				throw new \InvalidArgumentException( 'unexpected token "' . $p->tokens[ $p->pos ][1] . '"' );
			}
			return self::truthy( $value );
		} catch ( \Throwable $e ) {
			self::$last_error = $expr . ': ' . $e->getMessage();
			return false;
		}
	}

	public static function last_error() {
		return self::$last_error;
	}

	/** Syntax check without a context. */
	public static function is_valid( $expr ) {
		self::evaluate( $expr, array() );
		return '' === self::$last_error;
	}

	public static function truthy( $v ) {
		if ( is_array( $v ) ) {
			return count( $v ) > 0;
		}
		if ( is_string( $v ) ) {
			return '' !== $v && '0' !== $v && 'no' !== strtolower( $v ) && 'false' !== strtolower( $v );
		}
		return (bool) $v;
	}

	private static function tokenize( $s ) {
		if ( isset( self::$memo[ $s ] ) ) {
			return self::$memo[ $s ];
		}
		$tokens = array();
		$len    = strlen( $s );
		$i      = 0;
		while ( $i < $len ) {
			$c = $s[ $i ];
			if ( ctype_space( $c ) ) {
				$i++;
				continue;
			}
			if ( '(' === $c || ')' === $c ) {
				$tokens[] = array( $c, $c );
				$i++;
				continue;
			}
			if ( '=' === $c && isset( $s[ $i + 1 ] ) && '=' === $s[ $i + 1 ] ) {
				$tokens[] = array( 'op', '==' );
				$i       += 2;
				continue;
			}
			if ( '!' === $c ) {
				if ( isset( $s[ $i + 1 ] ) && '=' === $s[ $i + 1 ] ) {
					$tokens[] = array( 'op', '!=' );
					$i       += 2;
				} else {
					$tokens[] = array( 'not', '!' );
					$i++;
				}
				continue;
			}
			if ( "'" === $c ) {
				$end = strpos( $s, "'", $i + 1 );
				if ( false === $end ) {
					throw new \InvalidArgumentException( 'unterminated string' );
				}
				$tokens[] = array( 'lit', substr( $s, $i + 1, $end - $i - 1 ) );
				$i        = $end + 1;
				continue;
			}
			if ( preg_match( '/\G-?\d+(\.\d+)?/', $s, $m, 0, $i ) ) {
				$tokens[] = array( 'lit', $m[0] + 0 );
				$i       += strlen( $m[0] );
				continue;
			}
			if ( preg_match( '/\G[A-Za-z_][A-Za-z0-9_\-]*(\.[A-Za-z0-9_\-]+)*/', $s, $m, 0, $i ) ) {
				$word = $m[0];
				$i   += strlen( $word );
				$low  = strtolower( $word );
				if ( 'and' === $low || 'or' === $low ) {
					$tokens[] = array( $low, $low );
				} elseif ( 'not' === $low ) {
					$tokens[] = array( 'not', 'not' );
				} elseif ( 'in' === $low ) {
					$tokens[] = array( 'op', 'in' );
				} elseif ( 'true' === $low ) {
					$tokens[] = array( 'lit', true );
				} elseif ( 'false' === $low ) {
					$tokens[] = array( 'lit', false );
				} elseif ( 'null' === $low ) {
					$tokens[] = array( 'lit', null );
				} else {
					$tokens[] = array( 'path', $word );
				}
				continue;
			}
			throw new \InvalidArgumentException( 'unexpected character "' . $c . '"' );
		}
		if ( count( self::$memo ) < 500 ) {
			self::$memo[ $s ] = $tokens;
		}
		return $tokens;
	}

	private function peek( $type ) {
		return isset( $this->tokens[ $this->pos ] ) && $this->tokens[ $this->pos ][0] === $type;
	}

	private function parse_or() {
		$v = $this->parse_and();
		while ( $this->peek( 'or' ) ) {
			$this->pos++;
			$r = $this->parse_and();
			$v = self::truthy( $v ) || self::truthy( $r );
		}
		return $v;
	}

	private function parse_and() {
		$v = $this->parse_not();
		while ( $this->peek( 'and' ) ) {
			$this->pos++;
			$r = $this->parse_not();
			$v = self::truthy( $v ) && self::truthy( $r );
		}
		return $v;
	}

	private function parse_not() {
		if ( $this->peek( 'not' ) ) {
			$this->pos++;
			return ! self::truthy( $this->parse_not() );
		}
		return $this->parse_compare();
	}

	private function parse_compare() {
		$left = $this->parse_primary();
		if ( $this->peek( 'op' ) ) {
			$op = $this->tokens[ $this->pos ][1];
			$this->pos++;
			$right = $this->parse_primary();
			switch ( $op ) {
				case '==':
					return self::loose_equal( $left, $right );
				case '!=':
					return ! self::loose_equal( $left, $right );
				case 'in':
					if ( is_array( $right ) ) {
						foreach ( $right as $item ) {
							if ( self::loose_equal( $left, $item ) ) {
								return true;
							}
						}
						return false;
					}
					return is_string( $right ) && is_scalar( $left ) && '' !== (string) $left && false !== strpos( $right, (string) $left );
			}
		}
		return $left;
	}

	private static function loose_equal( $a, $b ) {
		if ( is_bool( $a ) || is_bool( $b ) ) {
			return self::truthy( $a ) === self::truthy( $b );
		}
		if ( null === $a || null === $b ) {
			return ( null === $a || '' === $a ) && ( null === $b || '' === $b );
		}
		if ( is_scalar( $a ) && is_scalar( $b ) ) {
			return strtolower( (string) $a ) === strtolower( (string) $b );
		}
		return $a === $b;
	}

	private function parse_primary() {
		if ( ! isset( $this->tokens[ $this->pos ] ) ) {
			throw new \InvalidArgumentException( 'unexpected end of expression' );
		}
		$tok = $this->tokens[ $this->pos ];
		$this->pos++;
		if ( '(' === $tok[0] ) {
			$v = $this->parse_or();
			if ( ! $this->peek( ')' ) ) {
				throw new \InvalidArgumentException( 'missing )' );
			}
			$this->pos++;
			return $v;
		}
		if ( 'lit' === $tok[0] ) {
			return $tok[1];
		}
		if ( 'path' === $tok[0] ) {
			return self::lookup( $this->ctx, $tok[1] );
		}
		throw new \InvalidArgumentException( 'unexpected token "' . $tok[1] . '"' );
	}

	public static function lookup( array $ctx, $path ) {
		$node = $ctx;
		foreach ( explode( '.', $path ) as $part ) {
			if ( is_array( $node ) && array_key_exists( $part, $node ) ) {
				$node = $node[ $part ];
			} else {
				return null;
			}
		}
		return $node;
	}
}

<?php
/**
 * Minimal Markdown for clause bodies: headings, paragraphs, lists, tables,
 * bold/italic/code, links. Text is escaped first; the result additionally
 * goes through wp_kses_post() in the generator.
 *
 * Placeholders ({{...}}) pass through untouched and are filled afterwards.
 * A line that consists only of a block helper ({{#name:arg}}) is emitted
 * without a surrounding <p>, so helpers can produce tables or lists.
 *
 * @package goo1-privacypilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Goo1_PP_Markdown {

	public static function to_html( $md, $heading_offset = 0 ) {
		$md    = str_replace( array( "\r\n", "\r" ), "\n", (string) $md );
		$lines = explode( "\n", $md );
		$html  = array();
		$para  = array();
		$n     = count( $lines );

		$flush = function () use ( &$para, &$html ) {
			if ( $para ) {
				$html[] = '<p>' . implode( "<br>\n", array_map( array( __CLASS__, 'inline' ), $para ) ) . '</p>';
				$para   = array();
			}
		};

		for ( $i = 0; $i < $n; $i++ ) {
			$line    = $lines[ $i ];
			$trimmed = trim( $line );

			if ( '' === $trimmed ) {
				$flush();
				continue;
			}

			// Block helper on its own line.
			if ( preg_match( '/^\{\{#[^}]+\}\}$/', $trimmed ) ) {
				$flush();
				$html[] = $trimmed;
				continue;
			}

			// Headings.
			if ( preg_match( '/^(#{1,6})\s+(.*)$/', $trimmed, $m ) ) {
				$flush();
				$level  = min( 6, strlen( $m[1] ) + (int) $heading_offset );
				$text   = self::inline( $m[2] );
				$id     = sanitize_title( wp_strip_all_tags( $m[2] ) );
				$html[] = sprintf( '<h%1$d id="pp-%2$s">%3$s</h%1$d>', $level, esc_attr( $id ), $text );
				continue;
			}

			// Tables: header row, separator row, body rows.
			if ( 0 === strpos( $trimmed, '|' ) && $i + 1 < $n && preg_match( '/^\|?\s*:?-{2,}/', trim( $lines[ $i + 1 ] ) ) ) {
				$flush();
				$head = self::cells( $trimmed );
				$i   += 2;
				$rows = array();
				while ( $i < $n && 0 === strpos( trim( $lines[ $i ] ), '|' ) ) {
					$rows[] = self::cells( trim( $lines[ $i ] ) );
					$i++;
				}
				$i--;
				$out = '<div class="pp-table-wrap"><table class="pp-table"><thead><tr>';
				foreach ( $head as $cell ) {
					$out .= '<th>' . self::inline( $cell ) . '</th>';
				}
				$out .= '</tr></thead><tbody>';
				foreach ( $rows as $row ) {
					$out .= '<tr>';
					foreach ( $row as $cell ) {
						$out .= '<td>' . self::inline( $cell ) . '</td>';
					}
					$out .= '</tr>';
				}
				$html[] = $out . '</tbody></table></div>';
				continue;
			}

			// Lists.
			if ( preg_match( '/^([-*]|\d+\.)\s+/', $trimmed, $m ) ) {
				$flush();
				$ordered = ctype_digit( rtrim( $m[1], '.' ) );
				$items   = array();
				while ( $i < $n && preg_match( '/^\s*([-*]|\d+\.)\s+(.*)$/', $lines[ $i ], $mm ) ) {
					$item = $mm[2];
					// Continuation lines (indented) belong to the item.
					while ( $i + 1 < $n && preg_match( '/^\s{2,}\S/', $lines[ $i + 1 ] ) && ! preg_match( '/^\s*([-*]|\d+\.)\s+/', $lines[ $i + 1 ] ) ) {
						$i++;
						$item .= ' ' . trim( $lines[ $i ] );
					}
					$items[] = '<li>' . self::inline( $item ) . '</li>';
					$i++;
				}
				$i--;
				$tag    = $ordered ? 'ol' : 'ul';
				$html[] = "<{$tag}>" . implode( '', $items ) . "</{$tag}>";
				continue;
			}

			$para[] = $trimmed;
		}
		$flush();

		return implode( "\n", $html );
	}

	private static function cells( $row ) {
		$row = trim( $row );
		$row = trim( $row, '|' );
		return array_map( 'trim', explode( '|', $row ) );
	}

	public static function inline( $text ) {
		// Protect placeholders from escaping/formatting.
		$keep = array();
		$text = preg_replace_callback(
			'/\{\{[^}]+\}\}/',
			function ( $m ) use ( &$keep ) {
				$key          = "\x01" . count( $keep ) . "\x01";
				$keep[ $key ] = $m[0];
				return $key;
			},
			(string) $text
		);

		$text = esc_html( $text );

		// Links [text](url) – url may also be a placeholder.
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\(([^)\s]+)\)/',
			function ( $m ) use ( $keep ) {
				$url = strtr( $m[2], $keep );
				if ( 0 === strpos( $url, '{{' ) ) {
					$href = $url; // filled later, escaped there.
				} else {
					$href = esc_url( html_entity_decode( $url ) );
				}
				$external = preg_match( '#^https?://#', $url ) ? ' rel="noopener" target="_blank"' : '';
				return '<a href="' . $href . '"' . $external . '>' . $m[1] . '</a>';
			},
			$text
		);

		$text = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
		$text = preg_replace( '/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/', '<em>$1</em>', $text );
		$text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );

		return strtr( $text, $keep );
	}
}

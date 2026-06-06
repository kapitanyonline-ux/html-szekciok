<?php
/**
 * Content Extractor — HTML kódból kinyeri a text/link/img mezőket,
 * stabil XPath kulccsal, és lehetővé teszi a visszapatchelést.
 *
 * Skip lista: <style>, <script>, <svg> tartalma soha nem extractálódik szövegként.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class HTML_Szekciok_Content_Extractor {

	const SKIP_TAGS = [ 'style', 'script', 'svg', 'noscript', 'template' ];

	/**
	 * HTML-ből kinyeri a mezőket.
	 *
	 * @param string $html
	 * @return array<int, array{tipus:string, xpath_kulcs:string, ertek:string, sorszam:int}>
	 */
	public static function extract( $html ) {
		$html = (string) $html;
		if ( trim( $html ) === '' ) return [];

		$dom = self::load_dom( $html );
		if ( ! $dom ) return [];

		$xpath  = new DOMXPath( $dom );
		$fields = [];

		// 1) Text node-ok (látható szöveg) — kivéve a SKIP_TAGS leszármazottjait.
		$skip_expr = implode( ' or ', array_map(
			static fn( $t ) => "ancestor::{$t}",
			self::SKIP_TAGS
		) );
		$text_nodes = $xpath->query( "//text()[normalize-space() and not({$skip_expr})]" );

		foreach ( $text_nodes as $node ) {
			$value = $node->nodeValue;
			if ( trim( $value ) === '' ) continue;

			$fields[] = [
				'tipus'       => 'text',
				'xpath_kulcs' => $node->getNodePath(),
				'ertek'       => $value,
			];
		}

		// 2) Linkek (<a href>).
		$links = $xpath->query( '//a[@href]' );
		foreach ( $links as $node ) {
			$fields[] = [
				'tipus'       => 'link',
				'xpath_kulcs' => $node->getNodePath() . '/@href',
				'ertek'       => $node->getAttribute( 'href' ),
			];
		}

		// 3) Képek (<img src>).
		$imgs = $xpath->query( '//img[@src]' );
		foreach ( $imgs as $node ) {
			$fields[] = [
				'tipus'       => 'img',
				'xpath_kulcs' => $node->getNodePath() . '/@src',
				'ertek'       => $node->getAttribute( 'src' ),
			];
		}

		// Sorszámozás megjelenítési sorrend szerint.
		foreach ( $fields as $i => &$f ) {
			$f['sorszam'] = $i + 1;
		}

		return $fields;
	}

	/**
	 * Adott HTML-be visszaírja a megadott mezőértékeket az XPath kulcs alapján.
	 *
	 * @param string                                            $html
	 * @param array<int, array{xpath_kulcs:string, ertek:string}> $updates
	 * @return string  A módosított HTML (ha a parse sikertelen, az eredeti).
	 */
	public static function apply_updates( $html, array $updates ) {
		if ( empty( $updates ) || trim( $html ) === '' ) return $html;

		$dom = self::load_dom( $html );
		if ( ! $dom ) return $html;

		$xpath   = new DOMXPath( $dom );
		$changed = false;

		foreach ( $updates as $u ) {
			if ( empty( $u['xpath_kulcs'] ) ) continue;

			$nodes = @$xpath->query( $u['xpath_kulcs'] );
			if ( ! $nodes || $nodes->length === 0 ) continue;

			$node = $nodes->item( 0 );
			if ( ! $node ) continue;

			// Attribútum (link href, img src) vs text node.
			if ( $node->nodeType === XML_ATTRIBUTE_NODE ) {
				$node->value = (string) ( $u['ertek'] ?? '' );
			} elseif ( $node->nodeType === XML_TEXT_NODE ) {
				$node->nodeValue = (string) ( $u['ertek'] ?? '' );
			} else {
				continue;
			}

			$changed = true;
		}

		if ( ! $changed ) return $html;

		return self::serialize_dom( $dom );
	}

	/**
	 * Smart merge: új extract eredményét összefésüli a DB-ben tárolt user-értékekkel.
	 * Ha egy XPath megmaradt és van user-érték → user-érték megmarad.
	 * Ha új XPath jött → új mező üres user-értékkel.
	 * Ha XPath eltűnt → kiesik (orphan-ba nem kerül, egyszerűsítés).
	 *
	 * @param array $new_fields      A friss extract eredménye.
	 * @param array $existing_rows   DB-ből: [ { xpath_kulcs, tipus, eredeti_ertek, felhasznaloi_ertek } ]
	 * @return array  Mezők DB-be írásra kész állapotban.
	 */
	public static function merge( array $new_fields, array $existing_rows ) {
		$existing_by_key = [];
		foreach ( $existing_rows as $row ) {
			$existing_by_key[ $row->xpath_kulcs ] = $row;
		}

		$merged = [];
		foreach ( $new_fields as $f ) {
			$key = $f['xpath_kulcs'];
			$row = $existing_by_key[ $key ] ?? null;

			$merged[] = [
				'tipus'             => $f['tipus'],
				'xpath_kulcs'       => $key,
				'eredeti_ertek'     => $f['ertek'],
				'felhasznaloi_ertek' => $row && $row->felhasznaloi_ertek !== null
					? $row->felhasznaloi_ertek
					: null,
				'sorszam'           => $f['sorszam'],
			];
		}

		return $merged;
	}

	/**
	 * Megjelenítendő érték: ha van user-érték, az; különben az eredeti.
	 */
	public static function display_value( $row ) {
		return $row->felhasznaloi_ertek !== null && $row->felhasznaloi_ertek !== ''
			? $row->felhasznaloi_ertek
			: $row->eredeti_ertek;
	}

	/**
	 * Rövid label generálás (mezőlistában megjelenő hint).
	 */
	public static function build_label( $row ) {
		$val = self::display_value( $row );
		$val = trim( preg_replace( '/\s+/u', ' ', (string) $val ) );

		if ( $row->tipus === 'img' ) {
			$basename = wp_basename( parse_url( $val, PHP_URL_PATH ) ?? $val );
			return $basename ?: 'kép';
		}

		if ( $row->tipus === 'link' ) {
			$host = parse_url( $val, PHP_URL_HOST );
			return $host ?: ( mb_substr( $val, 0, 40 ) );
		}

		// text
		return mb_strlen( $val ) > 50 ? mb_substr( $val, 0, 47 ) . '…' : $val;
	}

	/* ── Belső segédfüggvények ── */

	/**
	 * Biztonságos UTF-8 HTML betöltés.
	 *
	 * @return DOMDocument|null
	 */
	private static function load_dom( $html ) {
		// UTF-8 hint a libxml-nek. A magic meta tag eltünik a serializációkor.
		$prefixed = '<?xml encoding="UTF-8"?>' . $html;

		$dom = new DOMDocument();
		$dom->preserveWhiteSpace = true;
		$dom->formatOutput       = false;

		$prev_use = libxml_use_internal_errors( true );

		$loaded = $dom->loadHTML(
			$prefixed,
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $prev_use );

		if ( ! $loaded ) return null;

		// XML prolog eltávolítása ha a parser benthagyta.
		foreach ( iterator_to_array( $dom->childNodes ) as $child ) {
			if ( $child->nodeType === XML_PI_NODE ) {
				$dom->removeChild( $child );
			}
		}

		return $dom;
	}

	private static function serialize_dom( DOMDocument $dom ) {
		$out = '';
		foreach ( $dom->childNodes as $child ) {
			$out .= $dom->saveHTML( $child );
		}

		// A libxml &amp; entitásokat ad vissza a már escape-elt URL-eknél is
		// (pl. ?a=1&amp;b=2 → ?a=1&amp;amp;b=2), erre nem javítunk:
		// a HTML értelmezés ugyanaz, és a saveHTML default viselkedése.
		return $out;
	}
}

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class HTML_Szekciok_Database {

	public static function create_tables() {
		global $wpdb;
		$collate = $wpdb->get_charset_collate();

		$sql_projektek = "CREATE TABLE {$wpdb->prefix}fejlesztesek_projektek (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nev VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL,
  letrehozva DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug)
) {$collate};";

		$sql_szekciok = "CREATE TABLE {$wpdb->prefix}fejlesztesek_szekciok (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  projekt_id INT UNSIGNED NOT NULL,
  sorszam INT UNSIGNED NOT NULL,
  html_kod LONGTEXT,
  letrehozva DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  modositva DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY projekt_id (projekt_id)
) {$collate};";

		$sql_mezok = "CREATE TABLE {$wpdb->prefix}fejlesztesek_szekcio_mezok (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  szekcio_id INT UNSIGNED NOT NULL,
  tipus VARCHAR(20) NOT NULL,
  xpath_kulcs VARCHAR(500) NOT NULL,
  eredeti_ertek LONGTEXT,
  felhasznaloi_ertek LONGTEXT DEFAULT NULL,
  sorszam INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY szekcio_id (szekcio_id)
) {$collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_projektek );
		dbDelta( $sql_szekciok );
		dbDelta( $sql_mezok );
	}

	/* ── Projektek ── */

	public static function get_projects() {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT p.*, COUNT(s.id) AS szekciok_szama
			 FROM {$wpdb->prefix}fejlesztesek_projektek p
			 LEFT JOIN {$wpdb->prefix}fejlesztesek_szekciok s ON p.id = s.projekt_id
			 GROUP BY p.id
			 ORDER BY p.letrehozva DESC"
		) ?: [];
	}

	public static function get_project( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}fejlesztesek_projektek WHERE id = %d", $id
		) );
	}

	public static function get_project_by_slug( $slug ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}fejlesztesek_projektek WHERE slug = %s", $slug
		) );
	}

	public static function create_project( $nev, $slug ) {
		global $wpdb;
		$wpdb->insert(
			"{$wpdb->prefix}fejlesztesek_projektek",
			[
				'nev'        => $nev,
				'slug'       => $slug,
				'letrehozva' => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s' ]
		);
		return $wpdb->insert_id;
	}

	public static function update_project( $id, $nev, $slug ) {
		global $wpdb;
		return $wpdb->update(
			"{$wpdb->prefix}fejlesztesek_projektek",
			[ 'nev' => $nev, 'slug' => $slug ],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	public static function delete_project( $id ) {
		global $wpdb;
		self::delete_project_sections( $id ); // mezőkkel együtt
		return $wpdb->delete( "{$wpdb->prefix}fejlesztesek_projektek", [ 'id' => $id ], [ '%d' ] );
	}

	/* ── Szekciók ── */

	public static function get_sections( $projekt_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}fejlesztesek_szekciok
			 WHERE projekt_id = %d ORDER BY sorszam ASC",
			$projekt_id
		) ) ?: [];
	}

	public static function get_section( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}fejlesztesek_szekciok WHERE id = %d", $id
		) );
	}

	public static function get_section_numbers( $projekt_id ) {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare(
			"SELECT sorszam FROM {$wpdb->prefix}fejlesztesek_szekciok
			 WHERE projekt_id = %d ORDER BY sorszam ASC",
			$projekt_id
		) ) ?: [];
	}

	public static function get_next_sorszam( $projekt_id ) {
		global $wpdb;
		$max = $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(sorszam) FROM {$wpdb->prefix}fejlesztesek_szekciok WHERE projekt_id = %d",
			$projekt_id
		) );
		return ( $max ?? 0 ) + 1;
	}

	public static function create_section( $projekt_id, $sorszam, $html_kod = '' ) {
		global $wpdb;
		$wpdb->insert(
			"{$wpdb->prefix}fejlesztesek_szekciok",
			[
				'projekt_id' => $projekt_id,
				'sorszam'    => $sorszam,
				'html_kod'   => $html_kod,
				'letrehozva' => current_time( 'mysql' ),
				'modositva'  => current_time( 'mysql' ),
			],
			[ '%d', '%d', '%s', '%s', '%s' ]
		);
		return $wpdb->insert_id;
	}

	public static function update_section( $id, $html_kod ) {
		global $wpdb;
		return $wpdb->update(
			"{$wpdb->prefix}fejlesztesek_szekciok",
			[ 'html_kod' => $html_kod, 'modositva' => current_time( 'mysql' ) ],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	public static function delete_section( $id ) {
		global $wpdb;
		$wpdb->delete( "{$wpdb->prefix}fejlesztesek_szekcio_mezok", [ 'szekcio_id' => $id ], [ '%d' ] );
		return $wpdb->delete( "{$wpdb->prefix}fejlesztesek_szekciok", [ 'id' => $id ], [ '%d' ] );
	}

	public static function delete_project_sections( $projekt_id ) {
		global $wpdb;
		$section_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}fejlesztesek_szekciok WHERE projekt_id = %d",
			$projekt_id
		) );
		if ( $section_ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $section_ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}fejlesztesek_szekcio_mezok WHERE szekcio_id IN ($placeholders)",
				$section_ids
			) );
		}
		return $wpdb->delete( "{$wpdb->prefix}fejlesztesek_szekciok", [ 'projekt_id' => $projekt_id ], [ '%d' ] );
	}

	/* ── Tartalom mezők ── */

	public static function get_section_fields( $szekcio_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}fejlesztesek_szekcio_mezok
			 WHERE szekcio_id = %d ORDER BY sorszam ASC, id ASC",
			$szekcio_id
		) ) ?: [];
	}

	public static function get_section_field( $field_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}fejlesztesek_szekcio_mezok WHERE id = %d",
			$field_id
		) );
	}

	/**
	 * Felülírja egy szekció összes mezőjét. Megőrzi a user-értékeket
	 * egyező XPath kulcs alapján.
	 *
	 * @param int   $szekcio_id
	 * @param array $merged_fields  HTML_Szekciok_Content_Extractor::merge() kimenete.
	 */
	public static function replace_section_fields( $szekcio_id, array $merged_fields ) {
		global $wpdb;
		$table = "{$wpdb->prefix}fejlesztesek_szekcio_mezok";

		$wpdb->delete( $table, [ 'szekcio_id' => $szekcio_id ], [ '%d' ] );

		foreach ( $merged_fields as $f ) {
			$wpdb->insert( $table, [
				'szekcio_id'         => $szekcio_id,
				'tipus'              => $f['tipus'],
				'xpath_kulcs'        => $f['xpath_kulcs'],
				'eredeti_ertek'      => $f['eredeti_ertek'],
				'felhasznaloi_ertek' => $f['felhasznaloi_ertek'],
				'sorszam'            => (int) $f['sorszam'],
			], [ '%d', '%s', '%s', '%s', '%s', '%d' ] );
		}
	}

	public static function update_field_value( $field_id, $felhasznaloi_ertek ) {
		global $wpdb;
		return $wpdb->update(
			"{$wpdb->prefix}fejlesztesek_szekcio_mezok",
			[ 'felhasznaloi_ertek' => $felhasznaloi_ertek ],
			[ 'id' => $field_id ],
			[ '%s' ],
			[ '%d' ]
		);
	}
}

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class HTML_Szekciok_Import_Export {

	public static function export_project( $projekt_id ) {
		$projekt = HTML_Szekciok_Database::get_project( $projekt_id );
		if ( ! $projekt ) return false;

		$szekciok = HTML_Szekciok_Database::get_sections( $projekt_id );
		if ( ! is_array( $szekciok ) ) {
			$szekciok = [];
		}

		$data = [
			'projekt_nev'  => $projekt->nev,
			'projekt_slug' => $projekt->slug,
			'szekciok'     => [],
		];

		foreach ( $szekciok as $s ) {
			$data['szekciok'][] = [
				'sorszam'  => (int) $s->sorszam,
				'cimke'    => $s->cimke,
				'html_kod' => $s->html_kod,
			];
		}

		return json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * @param string      $json_data  Raw JSON string
	 * @param bool        $overwrite  True → overwrite existing project with same slug
	 * @param string|null $new_name   Supply a new project name to create under a different slug
	 * @return array{success: bool, slug_exists?: bool, projekt_id?: int, message: string}
	 */
	public static function import_from_json( $json_data, $overwrite = false, $new_name = null ) {
		$data = json_decode( $json_data, true );

		if ( ! is_array( $data )
			|| ! isset( $data['projekt_nev'], $data['projekt_slug'], $data['szekciok'] )
			|| ! is_array( $data['szekciok'] ) ) {
			return [ 'success' => false, 'message' => 'Érvénytelen JSON formátum.' ];
		}

		$nev  = sanitize_text_field( $new_name ?? $data['projekt_nev'] );
		$slug = $new_name
			? html_szekciok_generate_slug( $new_name )
			: sanitize_key( $data['projekt_slug'] );

		$existing = HTML_Szekciok_Database::get_project_by_slug( $slug );

		if ( $existing && ! $overwrite ) {
			return [
				'success'    => false,
				'slug_exists' => true,
				'message'    => sprintf( 'A „%s" slug már létezik.', $slug ),
			];
		}

		if ( $existing && $overwrite ) {
			HTML_Szekciok_Database::delete_project_sections( $existing->id );
			HTML_Szekciok_Database::update_project( $existing->id, $nev, $slug );
			$projekt_id = (int) $existing->id;
		} else {
			$projekt_id = (int) HTML_Szekciok_Database::create_project( $nev, $slug );
		}

		foreach ( $data['szekciok'] as $s ) {
			$sorszam  = max( 1, (int) ( $s['sorszam'] ?? 1 ) );
			$html_kod = $s['html_kod'] ?? '';
			$cimke    = isset( $s['cimke'] ) ? sanitize_text_field( (string) $s['cimke'] ) : null;
			$sid      = HTML_Szekciok_Database::create_section( $projekt_id, $sorszam, $html_kod, $cimke );

			// Tartalom mezők azonnal kinyerve, hogy az import után is használható legyen az inline edit.
			if ( class_exists( 'HTML_Szekciok_Content_Extractor' ) && $sid ) {
				$fields = HTML_Szekciok_Content_Extractor::extract( $html_kod );
				$merged = HTML_Szekciok_Content_Extractor::merge( $fields, [] );
				HTML_Szekciok_Database::replace_section_fields( (int) $sid, $merged );
			}
		}

		return [
			'success'    => true,
			'projekt_id' => $projekt_id,
			'message'    => "Projekt sikeresen importálva: {$nev}",
		];
	}

	public static function get_import_files() {
		$dir = HTML_SZEKCIOK_IMPORTS_DIR;
		if ( ! is_dir( $dir ) ) return [];

		$files = glob( $dir . '*.json' );
		if ( ! $files ) return [];

		$result = [];
		foreach ( $files as $path ) {
			$result[] = [
				'name'     => basename( $path ),
				'path'     => $path,
				'size'     => filesize( $path ),
				'modified' => filemtime( $path ),
			];
		}

		usort( $result, function ( $a, $b ) { return $b['modified'] - $a['modified']; } );
		return $result;
	}
}

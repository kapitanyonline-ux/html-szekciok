<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class HTML_Szekciok_Admin {

	private static $page_hook = '';

	public static function init() {
		add_action( 'admin_menu',            [ __CLASS__, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );

		// AJAX
		add_action( 'wp_ajax_hs_save_section',   [ __CLASS__, 'ajax_save_section' ] );
		add_action( 'wp_ajax_hs_add_section',    [ __CLASS__, 'ajax_add_section' ] );
		add_action( 'wp_ajax_hs_delete_section', [ __CLASS__, 'ajax_delete_section' ] );
		add_action( 'wp_ajax_hs_import_json',    [ __CLASS__, 'ajax_import_json' ] );
		add_action( 'wp_ajax_hs_save_fields',    [ __CLASS__, 'ajax_save_fields' ] );

		// Form POSTs
		add_action( 'admin_post_hs_new_project',    [ __CLASS__, 'handle_new_project' ] );
		add_action( 'admin_post_hs_update_project', [ __CLASS__, 'handle_update_project' ] );
		add_action( 'admin_post_hs_delete_project', [ __CLASS__, 'handle_delete_project' ] );
		add_action( 'admin_post_hs_export',         [ __CLASS__, 'handle_export' ] );
		add_action( 'admin_post_hs_upload_import',  [ __CLASS__, 'handle_upload_import' ] );
	}

	/* ── Menu ── */

	public static function add_menu() {
		self::$page_hook = add_submenu_page(
			'edit.php?post_type=page',
			'HTML szekciók',
			'HTML szekciók',
			'manage_options',
			'html-szekciok',
			[ __CLASS__, 'render_page' ]
		);
	}

	/* ── Assets ── */

	public static function enqueue_assets( $hook ) {
		if ( $hook !== self::$page_hook ) return;

		// wp_enqueue_code_editor intézi a CodeMirror dependenciákat is.
		$cm_settings = wp_enqueue_code_editor( [ 'type' => 'text/html' ] );
		wp_enqueue_style( 'wp-codemirror' );

		wp_enqueue_style(
			'html-szekciok-admin',
			HTML_SZEKCIOK_URL . 'admin/assets/admin.css',
			[],
			HTML_SZEKCIOK_VERSION
		);

		wp_enqueue_script(
			'html-szekciok-admin',
			HTML_SZEKCIOK_URL . 'admin/assets/admin.js',
			[ 'jquery', 'code-editor' ],
			HTML_SZEKCIOK_VERSION,
			true
		);

		wp_localize_script( 'html-szekciok-admin', 'htmlSzekciok', [
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'adminUrl'   => admin_url( 'edit.php?post_type=page&page=html-szekciok' ),
			'nonce'      => wp_create_nonce( 'hs_nonce' ),
			'cmSettings' => $cm_settings,
			'strings'    => [
				'saved'                => 'Mentve!',
				'saving'               => 'Mentés…',
				'error'                => 'Hiba történt.',
				'confirmDeleteSection' => 'Biztosan törlöd ezt a szekciót?',
				'copied'               => 'Másolva!',
				'slugExists'           => "Ez a slug már létezik.\n\nKattints OK-ra a felülíráshoz, vagy Mégsére az új névmegadáshoz.",
				'newName'              => 'Add meg az új projekt nevét:',
			],
		] );
	}

	/* ── Page routing ── */

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Nincs jogosultságod.' );

		$action     = sanitize_key( $_GET['action'] ?? 'list' );
		$projekt_id = isset( $_GET['projekt_id'] ) ? (int) $_GET['projekt_id'] : 0;

		if ( $action === 'edit' && $projekt_id ) {
			include HTML_SZEKCIOK_PATH . 'admin/views/project-edit.php';
		} else {
			include HTML_SZEKCIOK_PATH . 'admin/views/project-list.php';
		}
	}

	/* ── AJAX handlers ── */

	public static function ajax_save_section() {
		check_ajax_referer( 'hs_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

		$section_id = (int) ( $_POST['section_id'] ?? 0 );
		$html_kod   = wp_unslash( $_POST['html_kod'] ?? '' );

		if ( ! $section_id || ! HTML_Szekciok_Database::get_section( $section_id ) ) {
			wp_send_json_error( 'Érvénytelen szekció.' );
		}

		// Szekció-címke (label) mentése, ha küldték — a sorszám/shortcode érintetlen.
		if ( isset( $_POST['cimke'] ) ) {
			$cimke = sanitize_text_field( wp_unslash( $_POST['cimke'] ) );
			HTML_Szekciok_Database::update_section_cimke( $section_id, $cimke );
		}

		HTML_Szekciok_Database::update_section( $section_id, $html_kod );

		// Tartalom mezők automatikus szinkronizálása.
		$new_fields    = HTML_Szekciok_Content_Extractor::extract( $html_kod );
		$existing_rows = HTML_Szekciok_Database::get_section_fields( $section_id );
		$merged        = HTML_Szekciok_Content_Extractor::merge( $new_fields, $existing_rows );
		HTML_Szekciok_Database::replace_section_fields( $section_id, $merged );

		wp_send_json_success( [
			'message'    => 'Mentve!',
			'section_id' => $section_id,
			'fields'     => self::format_fields_for_response( $section_id ),
		] );
	}

	/**
	 * Új AJAX: szekció tartalom mezőinek mentése — patch a HTML-be, majd újra elment.
	 */
	public static function ajax_save_fields() {
		check_ajax_referer( 'hs_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

		$section_id = (int) ( $_POST['section_id'] ?? 0 );
		$section    = $section_id ? HTML_Szekciok_Database::get_section( $section_id ) : null;
		if ( ! $section ) wp_send_json_error( [ 'message' => 'Érvénytelen szekció.' ] );

		$values_raw = $_POST['fields'] ?? [];
		if ( ! is_array( $values_raw ) ) wp_send_json_error( [ 'message' => 'Érvénytelen mezők.' ] );

		// $values_raw struktúra: [ field_id => érték ]
		$existing = HTML_Szekciok_Database::get_section_fields( $section_id );
		$updates  = [];

		foreach ( $existing as $row ) {
			if ( ! array_key_exists( $row->id, $values_raw ) ) continue;

			$new_val = wp_unslash( (string) $values_raw[ $row->id ] );

			// Mező user-értékének mentése DB-be (üres string → NULL, hogy az eredeti
			// érvényesüljön, ha valaki "üríteni" akarja a felülírást).
			$store = ( $new_val === '' ) ? null : $new_val;
			HTML_Szekciok_Database::update_field_value( $row->id, $store );

			$updates[] = [
				'xpath_kulcs' => $row->xpath_kulcs,
				'ertek'       => $store !== null ? $store : $row->eredeti_ertek,
			];
		}

		// HTML patch + újra-mentés.
		$new_html = HTML_Szekciok_Content_Extractor::apply_updates( $section->html_kod, $updates );
		HTML_Szekciok_Database::update_section( $section_id, $new_html );

		wp_send_json_success( [
			'message'    => 'Mezők mentve.',
			'section_id' => $section_id,
			'html'       => $new_html,
			'fields'     => self::format_fields_for_response( $section_id ),
		] );
	}

	/**
	 * Mezők JSON-barát formátumra alakítása a frontend számára.
	 */
	private static function format_fields_for_response( $section_id ) {
		$rows = HTML_Szekciok_Database::get_section_fields( $section_id );
		$out  = [];
		foreach ( $rows as $row ) {
			$out[] = [
				'id'                 => (int) $row->id,
				'tipus'              => $row->tipus,
				'label'              => HTML_Szekciok_Content_Extractor::build_label( $row ),
				'eredeti_ertek'      => (string) $row->eredeti_ertek,
				'felhasznaloi_ertek' => $row->felhasznaloi_ertek,
				'megjelenitett'      => HTML_Szekciok_Content_Extractor::display_value( $row ),
			];
		}
		return $out;
	}

	public static function ajax_add_section() {
		check_ajax_referer( 'hs_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

		$projekt_id = (int) ( $_POST['projekt_id'] ?? 0 );
		$projekt    = $projekt_id ? HTML_Szekciok_Database::get_project( $projekt_id ) : null;

		if ( ! $projekt ) wp_send_json_error( 'Projekt nem található.' );

		$sorszam    = HTML_Szekciok_Database::get_next_sorszam( $projekt_id );
		$section_id = HTML_Szekciok_Database::create_section( $projekt_id, $sorszam, '' );
		$shortcode  = '[' . $projekt->slug . '_' . $sorszam . ']';

		wp_send_json_success( [
			'section_id' => $section_id,
			'sorszam'    => $sorszam,
			'shortcode'  => $shortcode,
		] );
	}

	public static function ajax_delete_section() {
		check_ajax_referer( 'hs_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

		$section_id = (int) ( $_POST['section_id'] ?? 0 );
		if ( ! $section_id ) wp_send_json_error( 'Érvénytelen szekció.' );

		HTML_Szekciok_Database::delete_section( $section_id );
		wp_send_json_success( [ 'message' => 'Szekció törölve.' ] );
	}

	public static function ajax_import_json() {
		check_ajax_referer( 'hs_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

		$file_name = sanitize_file_name( wp_unslash( $_POST['file_name'] ?? '' ) );
		$overwrite = ! empty( $_POST['overwrite'] );
		$new_name  = sanitize_text_field( wp_unslash( $_POST['new_name'] ?? '' ) );

		if ( ! $file_name ) wp_send_json_error( [ 'message' => 'Hiányzó fájlnév.' ] );

		$file_path = HTML_SZEKCIOK_IMPORTS_DIR . $file_name;

		// Path traversal guard — mindkét realpath() false-t adhat vissza.
		$real_file    = realpath( $file_path );
		$real_imports = realpath( HTML_SZEKCIOK_IMPORTS_DIR );
		if ( ! $real_file || ! $real_imports || strpos( $real_file, $real_imports ) !== 0 ) {
			wp_send_json_error( [ 'message' => 'Érvénytelen fájl útvonal.' ] );
		}

		$json_data = file_get_contents( $real_file );
		$result    = HTML_Szekciok_Import_Export::import_from_json(
			$json_data,
			$overwrite,
			$new_name ?: null
		);

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/* ── Form handlers ── */

	public static function handle_new_project() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
		check_admin_referer( 'hs_new_project' );

		$nev = sanitize_text_field( wp_unslash( $_POST['nev'] ?? '' ) );
		if ( ! $nev ) {
			wp_redirect( admin_url( 'edit.php?post_type=page&page=html-szekciok&hs_error=empty_name' ) );
			exit;
		}

		$slug = html_szekciok_generate_slug( $nev );
		$base = $slug;
		$i    = 1;
		while ( HTML_Szekciok_Database::get_project_by_slug( $slug ) ) {
			$slug = $base . '_' . $i++;
		}

		$projekt_id = HTML_Szekciok_Database::create_project( $nev, $slug );
		wp_redirect( admin_url( "edit.php?post_type=page&page=html-szekciok&action=edit&projekt_id={$projekt_id}" ) );
		exit;
	}

	public static function handle_update_project() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
		check_admin_referer( 'hs_update_project' );

		$projekt_id = (int) ( $_POST['projekt_id'] ?? 0 );
		$nev        = sanitize_text_field( wp_unslash( $_POST['nev'] ?? '' ) );
		$slug_input = sanitize_text_field( wp_unslash( $_POST['slug'] ?? '' ) );

		if ( ! $projekt_id || ! $nev ) {
			wp_redirect( admin_url( 'edit.php?post_type=page&page=html-szekciok&hs_error=invalid' ) );
			exit;
		}

		// Slug normalizálás (ékezet eltávolítás, kisbetű, csak [a-z0-9_]).
		$slug = $slug_input
			? html_szekciok_generate_slug( $slug_input )
			: html_szekciok_generate_slug( $nev );

		if ( ! $slug ) {
			wp_redirect( admin_url( "edit.php?post_type=page&page=html-szekciok&action=edit&projekt_id={$projekt_id}&hs_error=empty_slug" ) );
			exit;
		}

		// Ütközés-ellenőrzés: ha másik projekt használja már ezt a slugot.
		$existing = HTML_Szekciok_Database::get_project_by_slug( $slug );
		if ( $existing && (int) $existing->id !== $projekt_id ) {
			wp_redirect( admin_url( "edit.php?post_type=page&page=html-szekciok&action=edit&projekt_id={$projekt_id}&hs_error=slug_taken" ) );
			exit;
		}

		HTML_Szekciok_Database::update_project( $projekt_id, $nev, $slug );

		wp_redirect( admin_url( "edit.php?post_type=page&page=html-szekciok&action=edit&projekt_id={$projekt_id}&hs_updated=1" ) );
		exit;
	}

	public static function handle_delete_project() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

		$projekt_id = (int) ( $_POST['projekt_id'] ?? 0 );
		check_admin_referer( "hs_delete_project_{$projekt_id}" );

		if ( $projekt_id ) HTML_Szekciok_Database::delete_project( $projekt_id );

		wp_redirect( admin_url( 'edit.php?post_type=page&page=html-szekciok&hs_deleted=1' ) );
		exit;
	}

	public static function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

		$projekt_id = (int) ( $_GET['projekt_id'] ?? 0 );
		check_admin_referer( "hs_export_{$projekt_id}" );

		$projekt = HTML_Szekciok_Database::get_project( $projekt_id );
		if ( ! $projekt ) wp_die( 'Projekt nem található.' );

		$json     = HTML_Szekciok_Import_Export::export_project( $projekt_id );
		$filename = $projekt->slug . '_export_' . gmdate( 'Y-m-d' ) . '.json';

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		echo $json;
		exit;
	}

	public static function handle_upload_import() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
		check_admin_referer( 'hs_upload_import' );

		if ( empty( $_FILES['json_file']['tmp_name'] ) ) {
			wp_redirect( admin_url( 'edit.php?post_type=page&page=html-szekciok&hs_error=no_file' ) );
			exit;
		}

		$file = $_FILES['json_file'];
		if ( pathinfo( $file['name'], PATHINFO_EXTENSION ) !== 'json' ) {
			wp_redirect( admin_url( 'edit.php?post_type=page&page=html-szekciok&hs_error=invalid_type' ) );
			exit;
		}

		$json_data = file_get_contents( $file['tmp_name'] );
		$result    = HTML_Szekciok_Import_Export::import_from_json( $json_data );

		if ( $result['success'] ) {
			wp_redirect( admin_url( "edit.php?post_type=page&page=html-szekciok&action=edit&projekt_id={$result['projekt_id']}&hs_imported=1" ) );
		} elseif ( isset( $result['slug_exists'] ) ) {
			// Store temporarily so the user can decide via AJAX
			set_transient( 'hs_pending_import_' . get_current_user_id(), $json_data, 300 );
			wp_redirect( admin_url( 'edit.php?post_type=page&page=html-szekciok&hs_error=slug_exists' ) );
		} else {
			wp_redirect( admin_url( 'edit.php?post_type=page&page=html-szekciok&hs_error=import_failed' ) );
		}
		exit;
	}
}

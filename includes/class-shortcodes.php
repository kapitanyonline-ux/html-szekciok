<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class HTML_Szekciok_Shortcodes {

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_shortcodes' ] );
	}

	public static function register_shortcodes() {
		global $wpdb;

		// Táblák hiányán ne akadjon meg (pl. aktiválás előtt).
		$table = $wpdb->prefix . 'fejlesztesek_szekciok';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		$results = $wpdb->get_results(
			"SELECT s.id, s.html_kod, s.sorszam, p.slug
			 FROM {$wpdb->prefix}fejlesztesek_szekciok s
			 JOIN {$wpdb->prefix}fejlesztesek_projektek p ON s.projekt_id = p.id"
		);

		if ( empty( $results ) ) return;

		foreach ( $results as $row ) {
			$tag  = sanitize_key( $row->slug . '_' . $row->sorszam );
			$html = $row->html_kod;
			add_shortcode( $tag, static function () use ( $html ) {
				return $html;
			} );
		}
	}
}

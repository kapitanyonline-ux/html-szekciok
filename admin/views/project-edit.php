<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$projekt = HTML_Szekciok_Database::get_project( $projekt_id );
if ( ! $projekt ) {
	echo '<div class="wrap"><div class="notice notice-error"><p>Projekt nem található.</p></div></div>';
	return;
}

$szekciok = HTML_Szekciok_Database::get_sections( $projekt_id );
?>
<div class="wrap hs-wrap hs-edit-wrap">

	<h1>
		<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=page&page=html-szekciok' ) ); ?>" class="hs-back-link">&#8592; Projektek</a>
		<span class="hs-project-title"><?php echo esc_html( $projekt->nev ); ?></span>
	</h1>
	<hr class="wp-header-end">

	<?php if ( isset( $_GET['hs_updated'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p>Projekt frissítve.</p></div>
	<?php endif; ?>

	<!-- Projekt beállítások -->
	<div class="hs-card hs-project-settings">
		<?php
		$error_msgs = [
			'slug_taken' => 'Ez a slug már egy másik projekthez tartozik. Válassz másikat.',
			'empty_slug' => 'A slug nem lehet üres.',
		];
		if ( isset( $_GET['hs_error'] ) && isset( $error_msgs[ sanitize_key( $_GET['hs_error'] ) ] ) ) : ?>
			<div class="notice notice-error is-dismissible" style="margin:0 0 12px"><p><?php echo esc_html( $error_msgs[ sanitize_key( $_GET['hs_error'] ) ] ); ?></p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" class="hs-inline-form">
			<?php wp_nonce_field( 'hs_update_project' ); ?>
			<input type="hidden" name="action" value="hs_update_project">
			<input type="hidden" name="projekt_id" value="<?php echo $projekt->id; ?>">

			<label for="hs-projekt-nev"><strong>Projekt neve:</strong></label>
			<input type="text" id="hs-projekt-nev" name="nev" value="<?php echo esc_attr( $projekt->nev ); ?>" class="regular-text" required>

			<label for="hs-projekt-slug"><strong>Slug:</strong></label>
			<input type="text" id="hs-projekt-slug" name="slug" value="<?php echo esc_attr( $projekt->slug ); ?>" class="regular-text" required
				pattern="[a-z0-9_]+"
				title="Csak kisbetű, szám és aláhúzás engedélyezett">

			<button type="submit" class="button">Frissítés</button>
		</form>
		<p class="description" style="margin-top:10px">
			A shortcode-ok automatikusan a slug alapján generálódnak (pl. <code>[<?php echo esc_html( $projekt->slug ); ?>_1]</code>).
			A név módosítása nem érinti a slugot — manuálisan írd át, ha frissíteni szeretnéd.
		</p>
	</div>

	<!-- Szekciók -->
	<div id="hs-sections-container"
		 data-projekt-id="<?php echo $projekt->id; ?>"
		 data-projekt-slug="<?php echo esc_attr( $projekt->slug ); ?>">

		<?php foreach ( $szekciok as $szekcio ) : ?>
		<div class="hs-section" id="hs-section-<?php echo $szekcio->id; ?>" data-section-id="<?php echo $szekcio->id; ?>">
			<div class="hs-section-header">
				<h3>Section <?php echo (int) $szekcio->sorszam; ?></h3>
				<div class="hs-section-meta">
					<span class="hs-shortcode-wrap">
						<code class="hs-shortcode-chip" data-shortcode="[<?php echo esc_attr( $projekt->slug . '_' . $szekcio->sorszam ); ?>]" title="Kattints a másoláshoz">
							[<?php echo esc_html( $projekt->slug . '_' . $szekcio->sorszam ); ?>]
						</code>
					</span>
					<button class="button button-link hs-delete-section-btn" data-section-id="<?php echo $szekcio->id; ?>">&#128465; Törlés</button>
				</div>
			</div>

			<div class="hs-section-body">
				<div class="hs-editor-col">
					<label class="hs-col-label">HTML szerkesztő</label>
					<textarea
						class="hs-editor"
						id="hs-editor-<?php echo $szekcio->id; ?>"
						data-section-id="<?php echo $szekcio->id; ?>"
						rows="18"
					><?php echo esc_textarea( $szekcio->html_kod ); ?></textarea>
				</div>

				<div class="hs-preview-col">
					<label class="hs-col-label">Előnézet</label>
					<div class="hs-preview-toolbar">
						<button class="hs-size-btn active" data-width="1440">🖥 Asztal</button>
						<button class="hs-size-btn" data-width="1024">💻 Laptop</button>
						<button class="hs-size-btn" data-width="768">📱 Tablet</button>
						<button class="hs-size-btn" data-width="375">📱 Mobil</button>
						<button class="button button-small hs-refresh-btn">&#8635; Frissít</button>
					</div>
					<div class="hs-preview-outer">
						<iframe class="hs-preview-frame" src="about:blank"></iframe>
					</div>
				</div>
			</div>

			<?php
			$mezok = HTML_Szekciok_Database::get_section_fields( $szekcio->id );
			// Csoportosítás típus szerint.
			$mezok_grouped = [ 'text' => [], 'link' => [], 'img' => [] ];
			foreach ( $mezok as $m ) {
				if ( isset( $mezok_grouped[ $m->tipus ] ) ) {
					$mezok_grouped[ $m->tipus ][] = $m;
				}
			}
			$tipus_label = [ 'text' => 'Szövegek', 'link' => 'Linkek', 'img' => 'Képek' ];
			?>
			<div class="hs-fields-panel" data-section-id="<?php echo $szekcio->id; ?>">
				<div class="hs-fields-header">
					<strong>Tartalom mezők</strong>
					<span class="hs-fields-hint">A HTML mentésével automatikusan frissülnek. A módosított értékek visszaíródnak a HTML-be.</span>
				</div>

				<?php if ( empty( $mezok ) ) : ?>
					<p class="hs-empty">Nincsenek kiemelt mezők. Ments egy HTML kódot, amely szöveget, linket vagy képet tartalmaz.</p>
				<?php else : ?>
					<?php foreach ( $tipus_label as $tipus => $cim ) :
						if ( empty( $mezok_grouped[ $tipus ] ) ) continue; ?>
						<div class="hs-fields-group hs-fields-<?php echo esc_attr( $tipus ); ?>">
							<h4><?php echo esc_html( $cim ); ?> (<?php echo count( $mezok_grouped[ $tipus ] ); ?>)</h4>
							<div class="hs-fields-list">
								<?php foreach ( $mezok_grouped[ $tipus ] as $mezo ) :
									$display = HTML_Szekciok_Content_Extractor::display_value( $mezo );
									$label   = HTML_Szekciok_Content_Extractor::build_label( $mezo );
									$has_override = $mezo->felhasznaloi_ertek !== null && $mezo->felhasznaloi_ertek !== '';
								?>
									<div class="hs-field-row<?php echo $has_override ? ' is-overridden' : ''; ?>"
									     data-field-id="<?php echo (int) $mezo->id; ?>"
									     data-original="<?php echo esc_attr( $mezo->eredeti_ertek ); ?>">
										<label class="hs-field-label" title="<?php echo esc_attr( $mezo->eredeti_ertek ); ?>">
											<span class="hs-field-type-badge"><?php echo esc_html( $tipus ); ?></span>
											<?php echo esc_html( $label ); ?>
										</label>
										<?php if ( $tipus === 'text' && mb_strlen( $display ) > 60 ) : ?>
											<textarea
												class="hs-field-input"
												name="fields[<?php echo (int) $mezo->id; ?>]"
												rows="2"><?php echo esc_textarea( $display ); ?></textarea>
										<?php else : ?>
											<input
												type="<?php echo $tipus === 'link' ? 'url' : 'text'; ?>"
												class="hs-field-input"
												name="fields[<?php echo (int) $mezo->id; ?>]"
												value="<?php echo esc_attr( $display ); ?>">
										<?php endif; ?>
										<?php if ( $has_override ) : ?>
											<button class="button button-link hs-field-reset" title="Visszaállítás az eredeti értékre">↺</button>
										<?php endif; ?>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endforeach; ?>

					<div class="hs-fields-actions">
						<button class="button button-primary hs-save-fields-btn" data-section-id="<?php echo $szekcio->id; ?>">
							Mezők mentése
						</button>
						<span class="hs-fields-status" id="hs-fields-status-<?php echo $szekcio->id; ?>"></span>
					</div>
				<?php endif; ?>
			</div>

			<div class="hs-section-footer">
				<button class="button button-primary hs-save-btn" data-section-id="<?php echo $szekcio->id; ?>">
					Mentés
				</button>
				<span class="hs-save-status" id="hs-status-<?php echo $szekcio->id; ?>"></span>
				<?php $mod_ts = $szekcio->modositva ? (int) strtotime( $szekcio->modositva ) : 0; ?>
				<span class="hs-modified-info">
					Módosítva: <?php echo $mod_ts ? esc_html( wp_date( 'Y.m.d H:i', $mod_ts ) ) : '—'; ?>
				</span>
			</div>
		</div>
		<?php endforeach; ?>

	</div><!-- #hs-sections-container -->

	<!-- Szekció hozzáadása -->
	<div class="hs-add-section-wrap">
		<button class="button button-hero" id="hs-add-section-btn" data-projekt-id="<?php echo $projekt->id; ?>">
			+ Szekció hozzáadása
		</button>
	</div>

</div><!-- .hs-wrap -->

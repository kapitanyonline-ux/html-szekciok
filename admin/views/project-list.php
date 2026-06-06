<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap hs-wrap">
	<h1 class="wp-heading-inline">HTML szekciók</h1>
	<hr class="wp-header-end">

	<?php
	$error_map = [
		'empty_name'    => 'A projekt neve nem lehet üres.',
		'slug_exists'   => 'Ez a slug már létezik. Töltsd fel az imports/ mappába, és importáld onnan.',
		'no_file'       => 'Nem lett fájl kiválasztva.',
		'invalid_type'  => 'Csak .json fájlok importálhatók.',
		'import_failed' => 'Importálás sikertelen — ellenőrizd a JSON formátumát.',
		'invalid'       => 'Érvénytelen kérés.',
	];
	if ( isset( $_GET['hs_deleted'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p>Projekt törölve.</p></div>
	<?php elseif ( isset( $_GET['hs_imported'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p>Projekt importálva.</p></div>
	<?php elseif ( isset( $_GET['hs_error'] ) ) :
		$msg = $error_map[ sanitize_key( $_GET['hs_error'] ) ] ?? 'Ismeretlen hiba.'; ?>
		<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
	<?php endif; ?>

	<!-- Új projekt -->
	<div class="hs-card">
		<h2>Új projekt létrehozása</h2>
		<form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" class="hs-inline-form">
			<?php wp_nonce_field( 'hs_new_project' ); ?>
			<input type="hidden" name="action" value="hs_new_project">
			<input type="text" name="nev" placeholder="Projekt neve (pl. Főoldal)" class="regular-text" required>
			<button type="submit" class="button button-primary">Létrehozás</button>
		</form>
	</div>

	<!-- Projektek listája -->
	<div class="hs-card">
		<h2>Projektek</h2>
		<?php $projektek = HTML_Szekciok_Database::get_projects(); ?>
		<?php if ( empty( $projektek ) ) : ?>
			<p class="hs-empty">Még nincsenek projektek. Hozz létre egyet fent!</p>
		<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width:22%">Projekt neve</th>
					<th style="width:14%">Slug</th>
					<th style="width:7%">Szekciók</th>
					<th>Shortcode-ok</th>
					<th style="width:11%">Létrehozva</th>
					<th style="width:18%">Műveletek</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $projektek as $projekt ) :
					$sorszamok = HTML_Szekciok_Database::get_section_numbers( $projekt->id );
				?>
				<tr>
					<td>
						<strong>
							<a href="<?php echo esc_url( admin_url( "edit.php?post_type=page&page=html-szekciok&action=edit&projekt_id={$projekt->id}" ) ); ?>">
								<?php echo esc_html( $projekt->nev ); ?>
							</a>
						</strong>
					</td>
					<td><code><?php echo esc_html( $projekt->slug ); ?></code></td>
					<td><?php echo (int) $projekt->szekciok_szama; ?></td>
					<td>
						<?php foreach ( $sorszamok as $nr ) : ?>
						<code class="hs-shortcode-chip" data-shortcode="[<?php echo esc_attr( $projekt->slug . '_' . $nr ); ?>]" title="Kattints a másoláshoz">[<?php echo esc_html( $projekt->slug . '_' . $nr ); ?>]</code>
						<?php endforeach; ?>
						<?php if ( empty( $sorszamok ) ) : ?><span class="hs-muted">—</span><?php endif; ?>
					</td>
					<?php $ts = $projekt->letrehozva ? (int) strtotime( $projekt->letrehozva ) : 0; ?>
					<td><?php echo $ts ? esc_html( wp_date( 'Y.m.d', $ts ) ) : '—'; ?></td>
					<td class="hs-actions">
						<a href="<?php echo esc_url( admin_url( "edit.php?post_type=page&page=html-szekciok&action=edit&projekt_id={$projekt->id}" ) ); ?>" class="button button-small">Szerkesztés</a>

						<a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin-post.php?action=hs_export&projekt_id={$projekt->id}" ), "hs_export_{$projekt->id}" ) ); ?>" class="button button-small">Exportálás</a>

						<form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="display:inline">
							<?php wp_nonce_field( "hs_delete_project_{$projekt->id}" ); ?>
							<input type="hidden" name="action" value="hs_delete_project">
							<input type="hidden" name="projekt_id" value="<?php echo $projekt->id; ?>">
							<button type="submit" class="button button-small hs-btn-delete" onclick="return confirm('Biztosan törlöd a(z) \'<?php echo esc_js( $projekt->nev ); ?>\' projektet az összes szekciójával?')">Törlés</button>
						</form>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	</div>

	<!-- Import szekció -->
	<div class="hs-card">
		<h2>JSON Import</h2>
		<div class="hs-import-grid">

			<!-- Fájl feltöltése -->
			<div class="hs-import-box">
				<h3>Fájl feltöltése</h3>
				<form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" enctype="multipart/form-data">
					<?php wp_nonce_field( 'hs_upload_import' ); ?>
					<input type="hidden" name="action" value="hs_upload_import">
					<div class="hs-upload-row">
						<input type="file" name="json_file" accept=".json" required id="hs-file-upload">
						<button type="submit" class="button button-secondary">Importálás</button>
					</div>
				</form>
			</div>

			<!-- imports/ mappa tartalom -->
			<div class="hs-import-box">
				<h3>Fájlok az <code>imports/</code> mappából</h3>
				<p class="description">Elérési útvonal: <code><?php echo esc_html( str_replace( ABSPATH, '…/', HTML_SZEKCIOK_IMPORTS_DIR ) ); ?></code></p>
				<?php $import_files = HTML_Szekciok_Import_Export::get_import_files(); ?>
				<?php if ( empty( $import_files ) ) : ?>
					<p class="hs-empty">Nincsenek JSON fájlok a mappában.<br>
					Claude az FTP-n keresztül ide helyezheti a generált fájlokat.</p>
				<?php else : ?>
				<table class="wp-list-table widefat fixed striped hs-imports-table">
					<thead>
						<tr>
							<th>Fájlnév</th>
							<th style="width:80px">Méret</th>
							<th style="width:130px">Módosítva</th>
							<th style="width:110px">Művelet</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $import_files as $f ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $f['name'] ); ?></strong></td>
							<td><?php echo esc_html( size_format( $f['size'] ) ); ?></td>
							<td><?php echo esc_html( wp_date( 'Y.m.d H:i', $f['modified'] ) ); ?></td>
							<td>
								<button
									class="button button-primary hs-import-folder-btn"
									data-filename="<?php echo esc_attr( $f['name'] ); ?>"
								>Importálás</button>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php endif; ?>
			</div>

		</div><!-- .hs-import-grid -->
	</div>

</div><!-- .hs-wrap -->

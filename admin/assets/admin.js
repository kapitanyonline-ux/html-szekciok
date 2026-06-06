/* global jQuery, wp, htmlSzekciok */
jQuery( document ).ready( function ( $ ) {
	'use strict';

	const cfg = window.htmlSzekciok;
	if ( ! cfg ) return;

	/* ============================================================
	   CodeMirror management
	   ============================================================ */

	const cmInstances = {};

	function initCM( textarea ) {
		if ( ! wp || ! wp.codeEditor || ! cfg.cmSettings ) return null;
		try {
			return wp.codeEditor.initialize( textarea, cfg.cmSettings );
		} catch ( e ) {
			return null;
		}
	}

	function getContent( sectionId ) {
		const inst = cmInstances[ sectionId ];
		if ( inst && inst.codemirror ) return inst.codemirror.getValue();
		return $( '#hs-editor-' + sectionId ).val() || '';
	}

	// Init editors on page load
	$( '.hs-editor' ).each( function () {
		const sid = $( this ).data( 'section-id' );
		const inst = initCM( this );
		if ( inst ) {
			cmInstances[ sid ] = inst;
			inst.codemirror.on( 'change', debounce( function () {
				updatePreview( sid );
			}, 600 ) );
		}
		updatePreview( sid );
	} );

	/* ============================================================
	   Preview
	   ============================================================ */

	function buildDoc( html ) {
		return `<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>*{box-sizing:border-box}body{margin:0;padding:0}</style>
</head>
<body>${ html }</body>
</html>`;
	}

	function updatePreview( sectionId ) {
		const $sec    = $( '#hs-section-' + sectionId );
		const iframe  = $sec.find( '.hs-preview-frame' )[ 0 ];
		if ( ! iframe ) return;

		const html  = getContent( sectionId );
		const width = parseInt( $sec.find( '.hs-size-btn.active' ).data( 'width' ) ) || 1440;

		iframe.srcdoc = buildDoc( html );
		scaleFrame( $sec, width );
	}

	function scaleFrame( $sec, targetWidth ) {
		const $outer  = $sec.find( '.hs-preview-outer' );
		const $frame  = $sec.find( '.hs-preview-frame' );
		const avail   = $outer.width() || targetWidth;
		const scale   = Math.min( 1, avail / targetWidth );
		const fHeight = 340 / scale;

		$frame.css( {
			width:            targetWidth + 'px',
			height:           fHeight + 'px',
			transform:        'scale(' + scale + ')',
			'transform-origin': 'top left',
		} );
	}

	// Viewport size buttons
	$( document ).on( 'click', '.hs-size-btn', function () {
		const $sec = $( this ).closest( '.hs-section' );
		const sid  = $sec.data( 'section-id' );
		const w    = parseInt( $( this ).data( 'width' ) );

		$sec.find( '.hs-size-btn' ).removeClass( 'active' );
		$( this ).addClass( 'active' );
		scaleFrame( $sec, w );
	} );

	// Refresh button
	$( document ).on( 'click', '.hs-refresh-btn', function () {
		const sid = $( this ).closest( '.hs-section' ).data( 'section-id' );
		updatePreview( sid );
	} );

	// Re-scale on window resize
	$( window ).on( 'resize', debounce( function () {
		$( '.hs-section' ).each( function () {
			const sid = $( this ).data( 'section-id' );
			const w   = parseInt( $( this ).find( '.hs-size-btn.active' ).data( 'width' ) ) || 1440;
			scaleFrame( $( this ), w );
		} );
	}, 250 ) );

	/* ============================================================
	   Save section
	   ============================================================ */

	$( document ).on( 'click', '.hs-save-btn', function () {
		const sid    = $( this ).data( 'section-id' );
		const $btn   = $( this );
		const $status = $( '#hs-status-' + sid );

		$btn.prop( 'disabled', true ).text( cfg.strings.saving );
		$status.removeClass( 'success error' ).text( '' );

		$.post( cfg.ajaxUrl, {
			action:     'hs_save_section',
			nonce:      cfg.nonce,
			section_id: sid,
			html_kod:   getContent( sid ),
			cimke:      $( '#hs-section-' + sid + ' .hs-section-label' ).val() || '',
		} )
		.done( function ( res ) {
			if ( res.success ) {
				$status.addClass( 'success' ).text( cfg.strings.saved );
				setTimeout( () => $status.text( '' ).removeClass( 'success' ), 3000 );

				// Mezőpanel újrarenderelése (a smart merge eredménye).
				if ( res.data && res.data.fields ) {
					renderFieldsPanel( sid, res.data.fields );
				}
			} else {
				$status.addClass( 'error' ).text( cfg.strings.error );
			}
		} )
		.fail( function () {
			$status.addClass( 'error' ).text( cfg.strings.error );
		} )
		.always( function () {
			$btn.prop( 'disabled', false ).text( 'Mentés' );
		} );
	} );

	/* ============================================================
	   Save content fields (text/link/img inline editor)
	   ============================================================ */

	$( document ).on( 'click', '.hs-save-fields-btn', function () {
		const sid     = $( this ).data( 'section-id' );
		const $btn    = $( this );
		const $status = $( '#hs-fields-status-' + sid );
		const $panel  = $( '.hs-fields-panel[data-section-id="' + sid + '"]' );

		const fields = {};
		$panel.find( '.hs-field-row' ).each( function () {
			const fid = $( this ).data( 'field-id' );
			const val = $( this ).find( '.hs-field-input' ).val();
			fields[ fid ] = val;
		} );

		$btn.prop( 'disabled', true ).text( cfg.strings.saving );
		$status.removeClass( 'success error' ).text( '' );

		$.post( cfg.ajaxUrl, {
			action:     'hs_save_fields',
			nonce:      cfg.nonce,
			section_id: sid,
			fields:     fields,
		} )
		.done( function ( res ) {
			if ( res.success ) {
				$status.addClass( 'success' ).text( cfg.strings.saved );
				setTimeout( () => $status.text( '' ).removeClass( 'success' ), 3000 );

				// HTML editor frissítése a patchelt HTML-lel.
				if ( res.data && typeof res.data.html === 'string' ) {
					setContent( sid, res.data.html );
					updatePreview( sid );
				}
				// Panel újrarenderelése (badge frissítések miatt).
				if ( res.data && res.data.fields ) {
					renderFieldsPanel( sid, res.data.fields );
				}
			} else {
				$status.addClass( 'error' ).text( cfg.strings.error );
			}
		} )
		.fail( function () {
			$status.addClass( 'error' ).text( cfg.strings.error );
		} )
		.always( function () {
			$btn.prop( 'disabled', false ).text( 'Mezők mentése' );
		} );
	} );

	// Mező reset gomb — visszatölti az eredeti értéket.
	$( document ).on( 'click', '.hs-field-reset', function ( e ) {
		e.preventDefault();
		const $row     = $( this ).closest( '.hs-field-row' );
		const original = $row.data( 'original' );
		$row.find( '.hs-field-input' ).val( '' ); // üres = NULL = eredeti érvényesül
		$row.removeClass( 'is-overridden' );
		$( this ).remove();
	} );

	function renderFieldsPanel( sid, fields ) {
		const $panel = $( '.hs-fields-panel[data-section-id="' + sid + '"]' );
		if ( ! $panel.length ) return;

		if ( ! fields.length ) {
			$panel.html( `
				<div class="hs-fields-header">
					<strong>Tartalom mezők</strong>
					<span class="hs-fields-hint">A HTML mentésével automatikusan frissülnek.</span>
				</div>
				<p class="hs-empty">Nincsenek kiemelt mezők.</p>
			` );
			return;
		}

		const groups = { text: [], link: [], img: [] };
		fields.forEach( f => { if ( groups[ f.tipus ] ) groups[ f.tipus ].push( f ); } );
		const labels = { text: 'Szövegek', link: 'Linkek', img: 'Képek' };

		let html = `
			<div class="hs-fields-header">
				<strong>Tartalom mezők</strong>
				<span class="hs-fields-hint">A HTML mentésével automatikusan frissülnek. A módosított értékek visszaíródnak a HTML-be.</span>
			</div>
		`;

		Object.keys( labels ).forEach( tipus => {
			if ( ! groups[ tipus ].length ) return;
			html += `<div class="hs-fields-group hs-fields-${ tipus }">
				<h4>${ labels[ tipus ] } (${ groups[ tipus ].length })</h4>
				<div class="hs-fields-list">`;

			groups[ tipus ].forEach( f => {
				const hasOverride = f.felhasznaloi_ertek !== null && f.felhasznaloi_ertek !== '';
				const display     = f.megjelenitett || '';
				const isLongText  = tipus === 'text' && display.length > 60;
				const inputType   = tipus === 'link' ? 'url' : 'text';

				html += `<div class="hs-field-row${ hasOverride ? ' is-overridden' : '' }"
					data-field-id="${ f.id }"
					data-original="${ escHtml( f.eredeti_ertek ) }">
					<label class="hs-field-label" title="${ escHtml( f.eredeti_ertek ) }">
						<span class="hs-field-type-badge">${ tipus }</span>
						${ escHtml( f.label ) }
					</label>`;

				if ( isLongText ) {
					html += `<textarea class="hs-field-input" name="fields[${ f.id }]" rows="2">${ escHtml( display ) }</textarea>`;
				} else {
					html += `<input type="${ inputType }" class="hs-field-input" name="fields[${ f.id }]" value="${ escHtml( display ) }">`;
				}

				if ( hasOverride ) {
					html += `<button class="button button-link hs-field-reset" title="Visszaállítás az eredeti értékre">↺</button>`;
				}

				html += `</div>`;
			} );

			html += `</div></div>`;
		} );

		html += `<div class="hs-fields-actions">
			<button class="button button-primary hs-save-fields-btn" data-section-id="${ sid }">Mezők mentése</button>
			<span class="hs-fields-status" id="hs-fields-status-${ sid }"></span>
		</div>`;

		$panel.html( html );
	}

	function setContent( sectionId, html ) {
		const inst = cmInstances[ sectionId ];
		if ( inst && inst.codemirror ) {
			inst.codemirror.setValue( html );
		} else {
			$( '#hs-editor-' + sectionId ).val( html );
		}
	}

	/* ============================================================
	   Delete section
	   ============================================================ */

	$( document ).on( 'click', '.hs-delete-section-btn', function () {
		if ( ! confirm( cfg.strings.confirmDeleteSection ) ) return;

		const sid  = $( this ).data( 'section-id' );
		const $sec = $( '#hs-section-' + sid );

		$.post( cfg.ajaxUrl, {
			action:     'hs_delete_section',
			nonce:      cfg.nonce,
			section_id: sid,
		} )
		.done( function ( res ) {
			if ( res.success ) {
				$sec.fadeOut( 250, function () { $( this ).remove(); } );
			} else {
				alert( cfg.strings.error );
			}
		} );
	} );

	/* ============================================================
	   Add section
	   ============================================================ */

	$( '#hs-add-section-btn' ).on( 'click', function () {
		const $btn = $( this );
		$btn.prop( 'disabled', true );

		$.post( cfg.ajaxUrl, {
			action:     'hs_add_section',
			nonce:      cfg.nonce,
			projekt_id: $btn.data( 'projekt-id' ),
		} )
		.done( function ( res ) {
			if ( ! res.success ) { alert( cfg.strings.error ); return; }

			const d        = res.data;
			const $section = $( buildSectionHtml( d.section_id, d.sorszam, d.shortcode ) );
			$( '#hs-sections-container' ).append( $section );

			// Init CodeMirror on the new textarea
			const ta   = $section.find( '.hs-editor' )[ 0 ];
			const inst = initCM( ta );
			if ( inst ) {
				cmInstances[ d.section_id ] = inst;
				inst.codemirror.on( 'change', debounce( function () {
					updatePreview( d.section_id );
				}, 600 ) );
			}

			// Scroll into view
			$( 'html,body' ).animate( { scrollTop: $section.offset().top - 60 }, 300 );
		} )
		.always( function () { $btn.prop( 'disabled', false ); } );
	} );

	function buildSectionHtml( sid, sorszam, shortcode ) {
		const sc = escHtml( shortcode );
		return `<div class="hs-section" id="hs-section-${sid}" data-section-id="${sid}">
  <div class="hs-section-header">
    <h3>Section ${sorszam}</h3>
    <input type="text" class="hs-section-label" data-section-id="${sid}" value="" placeholder="Szekció neve (pl. Hero, Árak)…" maxlength="255" title="Csak az adminban látszik, a shortcode-ot nem érinti.">
    <div class="hs-section-meta">
      <span class="hs-shortcode-wrap">
        <code class="hs-shortcode-chip" data-shortcode="${sc}" title="Kattints a másoláshoz">${sc}</code>
      </span>
      <button class="button button-link hs-delete-section-btn" data-section-id="${sid}">&#128465; Törlés</button>
    </div>
  </div>
  <div class="hs-section-body">
    <div class="hs-editor-col">
      <label class="hs-col-label">HTML szerkesztő</label>
      <textarea class="hs-editor" id="hs-editor-${sid}" data-section-id="${sid}" rows="18"></textarea>
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
  <div class="hs-section-footer">
    <button class="button button-primary hs-save-btn" data-section-id="${sid}">Mentés</button>
    <span class="hs-save-status" id="hs-status-${sid}"></span>
  </div>
  <div class="hs-fields-panel" data-section-id="${sid}">
    <div class="hs-fields-header">
      <strong>Tartalom mezők</strong>
      <span class="hs-fields-hint">A HTML mentésével automatikusan frissülnek.</span>
    </div>
    <p class="hs-empty">Nincsenek kiemelt mezők. Ments egy HTML kódot, amely szöveget, linket vagy képet tartalmaz.</p>
  </div>
</div>`;
	}

	/* ============================================================
	   Copy shortcode chips
	   ============================================================ */

	$( document ).on( 'click', '.hs-shortcode-chip', function () {
		const sc  = $( this ).data( 'shortcode' );
		const $el = $( this );
		if ( ! sc ) return;

		navigator.clipboard.writeText( sc ).then( function () {
			const orig = $el.text();
			$el.addClass( 'copied' ).text( cfg.strings.copied );
			setTimeout( function () {
				$el.removeClass( 'copied' ).text( orig );
			}, 1500 );
		} ).catch( function () {
			// Fallback for older browsers
			const ta = document.createElement( 'textarea' );
			ta.value = sc;
			document.body.appendChild( ta );
			ta.select();
			document.execCommand( 'copy' );
			document.body.removeChild( ta );
			const orig = $el.text();
			$el.addClass( 'copied' ).text( cfg.strings.copied );
			setTimeout( function () { $el.removeClass( 'copied' ).text( orig ); }, 1500 );
		} );
	} );

	/* ============================================================
	   Import from folder
	   ============================================================ */

	$( document ).on( 'click', '.hs-import-folder-btn', function () {
		const $btn     = $( this );
		const filename = $btn.data( 'filename' );
		$btn.prop( 'disabled', true ).text( 'Importálás…' );
		doImport( filename, false, null, $btn );
	} );

	function doImport( filename, overwrite, newName, $btn ) {
		$.post( cfg.ajaxUrl, {
			action:    'hs_import_json',
			nonce:     cfg.nonce,
			file_name: filename,
			overwrite: overwrite ? 1 : 0,
			new_name:  newName || '',
		} )
		.done( function ( res ) {
			if ( res.success ) {
				window.location.href = cfg.adminUrl +
					'&action=edit&projekt_id=' + res.data.projekt_id + '&hs_imported=1';
				return;
			}

			const d = res.data || {};
			if ( d.slug_exists ) {
				const overwriteIt = confirm( cfg.strings.slugExists );
				if ( overwriteIt ) {
					doImport( filename, true, null, $btn );
				} else {
					const nm = prompt( cfg.strings.newName );
					if ( nm && nm.trim() ) {
						doImport( filename, false, nm.trim(), $btn );
					} else {
						$btn.prop( 'disabled', false ).text( 'Importálás' );
					}
				}
			} else {
				alert( cfg.strings.error + ( d.message ? ': ' + d.message : '' ) );
				$btn.prop( 'disabled', false ).text( 'Importálás' );
			}
		} )
		.fail( function () {
			alert( cfg.strings.error );
			$btn.prop( 'disabled', false ).text( 'Importálás' );
		} );
	}

	/* ============================================================
	   Helpers
	   ============================================================ */

	function debounce( fn, wait ) {
		let t;
		return function ( ...args ) {
			clearTimeout( t );
			t = setTimeout( () => fn.apply( this, args ), wait );
		};
	}

	function escHtml( s ) {
		return String( s )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}
} );

/* global CWT_Admin, wp */
( function ( $ ) {
    'use strict';

    // -------------------------------------------------------------------------
    // Farbwähler initialisieren
    // -------------------------------------------------------------------------
    $( '.cwt-color-picker' ).wpColorPicker( {
        change: function () {
            updateDesignPreview();
        },
    } );

    // -------------------------------------------------------------------------
    // Seitenauswahl ein-/ausblenden wenn Anzeigemodus geändert wird
    // -------------------------------------------------------------------------
    $( '#cwt_switcher_display' ).on( 'change', function () {
        if ( $( this ).val() === 'all' ) {
            $( '.cwt-page-selector' ).slideUp( 150 );
        } else {
            $( '.cwt-page-selector' ).slideDown( 150 );
        }
    } );

    // Sprach-Karten: Checkbox-Status visuell aktualisieren
    $( document ).on( 'change', '.cwt-lang-card input[type="checkbox"]', function () {
        $( this ).closest( '.cwt-lang-card' ).toggleClass( 'cwt-lang-card--active', this.checked );
    } );

    // -------------------------------------------------------------------------
    // Übersetzung speichern (AJAX)
    // -------------------------------------------------------------------------
    $( document ).on( 'click', '.cwt-save-translation', function () {
        const $btn    = $( this );
        const $cell   = $btn.closest( 'td' );
        const $input  = $cell.find( '.cwt-translation-input' );
        const $select = $cell.find( '.cwt-status-select' );
        const $badge  = $cell.find( '.cwt-status-badge' );
        const lang     = $btn.data( 'lang' );
        const hash     = $btn.data( 'hash' );
        const original = $btn.data( 'original' );
        const translated = $input.val();
        const status   = $select.val();

        $btn.prop( 'disabled', true ).text( '…' );

        $.post( CWT_Admin.ajaxUrl, {
            action:     'cwt_save_translation',
            nonce:      CWT_Admin.nonce,
            hash:       hash,
            lang:       lang,
            original:   original,
            translated: translated,
            status:     status,
        } )
        .done( function ( res ) {
            if ( res.success ) {
                // Wenn Übersetzungstext vorhanden → Server hat auto-aktiviert → UI anpassen
                const effectiveStatus = translated.trim() !== '' ? 'active' : status;

                $badge
                    .removeClass( 'cwt-status--active cwt-status--pending cwt-status--ignored' )
                    .addClass( 'cwt-status--' + effectiveStatus )
                    .text( CWT_Admin.i18n[ effectiveStatus ] || effectiveStatus );

                $select.val( effectiveStatus );

                $cell.addClass( 'cwt-save-success' );
                setTimeout( () => $cell.removeClass( 'cwt-save-success' ), 700 );
            } else {
                alert( res.data?.message || CWT_Admin.i18n.error );
            }
        } )
        .fail( function () {
            alert( CWT_Admin.i18n.error );
        } )
        .always( function () {
            $btn.prop( 'disabled', false ).text( '✓' );
            setTimeout( () => $btn.text( CWT_Admin.i18n.saveBtn ), 1500 );
        } );
    } );

    // -------------------------------------------------------------------------
    // Status direkt per Select ändern
    // -------------------------------------------------------------------------
    $( document ).on( 'change', '.cwt-status-select', function () {
        const $select  = $( this );
        const entryId  = $select.data( 'entry-id' );
        const status   = $select.val();
        const $badge   = $select.closest( 'td' ).find( '.cwt-status-badge' );

        if ( ! entryId ) return;

        $.post( CWT_Admin.ajaxUrl, {
            action: 'cwt_update_status',
            nonce:  CWT_Admin.nonce,
            id:     entryId,
            status: status,
        } )
        .done( function ( res ) {
            if ( res.success ) {
                $badge
                    .removeClass( 'cwt-status--active cwt-status--pending cwt-status--ignored' )
                    .addClass( 'cwt-status--' + status )
                    .text( CWT_Admin.i18n[ status ] || status );
            }
        } );
    } );

    // -------------------------------------------------------------------------
    // Übersetzung löschen
    // -------------------------------------------------------------------------
    $( document ).on( 'click', '.cwt-delete-translation', function () {
        if ( ! confirm( CWT_Admin.i18n.confirm ) ) return;

        const $btn = $( this );
        const hash = $btn.closest( 'tr' ).data( 'hash' );
        const $row = $btn.closest( 'tr' );

        $.post( CWT_Admin.ajaxUrl, {
            action: 'cwt_delete_translation',
            nonce:  CWT_Admin.nonce,
            hash:   hash,
        } )
        .done( function ( res ) {
            if ( res.success ) {
                $row.fadeOut( 300, () => $row.remove() );
            }
        } );
    } );

    // -------------------------------------------------------------------------
    // Export-Button
    // -------------------------------------------------------------------------
    $( '#cwt-export-btn' ).on( 'click', function () {
        const url = CWT_Admin.ajaxUrl
                  + '?action=cwt_export&nonce=' + encodeURIComponent( CWT_Admin.nonce );
        window.location.href = url;
    } );

    // -------------------------------------------------------------------------
    // Cache leeren (Debug-Seite)
    // -------------------------------------------------------------------------
    $( '#cwt-clear-cache' ).on( 'click', function () {
        const $btn = $( this );
        $btn.prop( 'disabled', true ).text( '…' );

        $.post( CWT_Admin.ajaxUrl, {
            action: 'cwt_clear_cache',
            nonce:  CWT_Admin.nonce,
        } )
        .always( function () {
            $btn.prop( 'disabled', false ).text( 'Cache geleert ✓' );
            setTimeout( () => $btn.text( 'Übersetzungs-Cache leeren' ), 2000 );
        } );
    } );

    // -------------------------------------------------------------------------
    // Datenbank neu installieren (Debug-Seite)
    // -------------------------------------------------------------------------
    $( '#cwt-reinstall-db' ).on( 'click', function () {
        if ( ! confirm( 'Datenbank neu installieren? Bestehende Daten bleiben erhalten (dbDelta).' ) ) return;

        const $btn = $( this );
        $btn.prop( 'disabled', true ).text( '…' );

        $.post( CWT_Admin.ajaxUrl, {
            action: 'cwt_reinstall_db',
            nonce:  CWT_Admin.nonce,
        } )
        .always( function () {
            $btn.prop( 'disabled', false ).text( 'Neu installiert ✓' );
            setTimeout( () => $btn.text( 'Datenbank neu installieren' ), 2000 );
        } );
    } );

    // -------------------------------------------------------------------------
    // Design-Vorschau live aktualisieren
    // -------------------------------------------------------------------------
    function updateDesignPreview() {
        const bg      = $( '#cwt_bg_color' ).val() || '#ffffff';
        const text    = $( '#cwt_text_color' ).val() || '#333333';
        const border  = $( '#cwt_border_color' ).val() || '#cccccc';
        const hover   = $( '#cwt_hover_color' ).val() || '#f0f0f0';
        const radius  = ( $( '#cwt_border_radius' ).val() || '4' ) + 'px';
        const fs      = ( $( '#cwt_font_size' ).val() || '14' ) + 'px';
        const padding = ( $( '#cwt_padding' ).val() || '8' ) + 'px';

        $( '#cwt-design-preview .cwt-switcher' ).css( {
            '--cwt-bg':        bg,
            '--cwt-text':      text,
            '--cwt-border':    border,
            '--cwt-hover':     hover,
            '--cwt-radius':    radius,
            '--cwt-font-size': fs,
            '--cwt-padding':   padding,
        } );
    }

    $( 'input[type="number"]' ).on( 'input', updateDesignPreview );

    // Initialer Call
    updateDesignPreview();

} )( jQuery );

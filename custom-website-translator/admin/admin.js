/* global CWT_Admin, wp */
( function ( $ ) {
    'use strict';

    function showToast( message, type ) {
        const toast = $( '<div class="cwt-toast cwt-toast--' + type + '">' + message + '</div>' );
        $( 'body' ).append( toast );
        setTimeout( () => toast.addClass( 'cwt-toast--visible' ), 10 );
        setTimeout( function () {
            toast.removeClass( 'cwt-toast--visible' );
            setTimeout( () => toast.remove(), 300 );
        }, 3000 );
    }

    $( '.cwt-color-picker' ).wpColorPicker( { change: updateDesignPreview } );

    $( '#cwt_switcher_display' ).on( 'change', function () {
        $( '.cwt-page-selector' ).toggle( $( this ).val() !== 'all' );
    } );

    $( document ).on( 'change', '.cwt-lang-card input[type="checkbox"]', function () {
        $( this ).closest( '.cwt-lang-card' ).toggleClass( 'cwt-lang-card--active', this.checked );
    } );

    // Save a single translation row from the translations table.
    $( document ).on( 'click', '.cwt-save-translation', function () {
        const $btn       = $( this );
        const $cell      = $btn.closest( 'td' );
        const $input     = $cell.find( '.cwt-translation-input' );
        const $select    = $cell.find( '.cwt-status-select' );
        const $badge     = $cell.find( '.cwt-status-badge' );
        const translated = $input.val();

        $btn.prop( 'disabled', true ).text( '…' );

        $.post( CWT_Admin.ajaxUrl, {
            action:     'cwt_save_translation',
            nonce:      CWT_Admin.nonce,
            hash:       $btn.data( 'hash' ),
            lang:       $btn.data( 'lang' ),
            original:   $btn.data( 'original' ),
            translated: translated,
            status:     $select.val(),
        } )
        .done( function ( res ) {
            if ( res.success ) {
                // The server auto-activates when translated_text is non-empty — reflect that here.
                const effectiveStatus = translated.trim() !== '' ? 'active' : $select.val();
                $badge.removeClass( 'cwt-status--active cwt-status--pending cwt-status--ignored' )
                      .addClass( 'cwt-status--' + effectiveStatus )
                      .text( CWT_Admin.i18n[ effectiveStatus ] || effectiveStatus );
                $select.val( effectiveStatus );
                $cell.addClass( 'cwt-save-success' );
                setTimeout( () => $cell.removeClass( 'cwt-save-success' ), 700 );
            } else {
                showToast( res.data?.message || CWT_Admin.i18n.error, 'error' );
            }
        } )
        .fail( () => showToast( CWT_Admin.i18n.error, 'error' ) )
        .always( function () {
            $btn.prop( 'disabled', false ).text( '✓' );
            setTimeout( () => $btn.text( CWT_Admin.i18n.saveBtn ), 1500 );
        } );
    } );

    $( document ).on( 'change', '.cwt-status-select', function () {
        const $select = $( this );
        const entryId = $select.data( 'entry-id' );
        const status  = $select.val();
        const $badge  = $select.closest( 'td' ).find( '.cwt-status-badge' );

        if ( ! entryId ) return;

        $.post( CWT_Admin.ajaxUrl, { action: 'cwt_update_status', nonce: CWT_Admin.nonce, id: entryId, status } )
            .done( function ( res ) {
                if ( res.success ) {
                    $badge.removeClass( 'cwt-status--active cwt-status--pending cwt-status--ignored' )
                          .addClass( 'cwt-status--' + status )
                          .text( CWT_Admin.i18n[ status ] || status );
                }
            } );
    } );

    $( document ).on( 'click', '.cwt-delete-translation', function () {
        if ( ! confirm( CWT_Admin.i18n.confirm ) ) return;

        const $btn = $( this );
        const $row = $btn.closest( 'tr' );

        $.post( CWT_Admin.ajaxUrl, {
            action: 'cwt_delete_translation',
            nonce:  CWT_Admin.nonce,
            hash:   $row.data( 'hash' ),
        } )
        .done( function ( res ) {
            if ( res.success ) {
                $row.fadeOut( 300, () => $row.remove() );
                showToast( 'Deleted.', 'success' );
            } else {
                showToast( res.data?.message || CWT_Admin.i18n.error, 'error' );
            }
        } );
    } );

    $( '#cwt-export-btn' ).on( 'click', function () {
        window.location.href = CWT_Admin.ajaxUrl + '?action=cwt_export&nonce=' + encodeURIComponent( CWT_Admin.nonce );
    } );

    $( '#cwt-clear-cache' ).on( 'click', function () {
        const $btn = $( this );
        $btn.prop( 'disabled', true ).text( '…' );
        $.post( CWT_Admin.ajaxUrl, { action: 'cwt_clear_cache', nonce: CWT_Admin.nonce } )
            .always( function () {
                $btn.prop( 'disabled', false ).text( 'Cache cleared ✓' );
                setTimeout( () => $btn.text( 'Clear translation cache' ), 2000 );
            } );
    } );

    $( '#cwt-reinstall-db' ).on( 'click', function () {
        if ( ! confirm( 'Reinstall database tables? Existing data will be kept (dbDelta).' ) ) return;
        const $btn = $( this );
        $btn.prop( 'disabled', true ).text( '…' );
        $.post( CWT_Admin.ajaxUrl, { action: 'cwt_reinstall_db', nonce: CWT_Admin.nonce } )
            .always( function () {
                $btn.prop( 'disabled', false ).text( 'Reinstalled ✓' );
                setTimeout( () => $btn.text( 'Reinstall database' ), 2000 );
            } );
    } );

    // Keep the design preview in sync as the user adjusts color and size inputs.
    function updateDesignPreview() {
        $( '#cwt-design-preview .cwt-switcher' ).css( {
            '--cwt-bg':        $( '#cwt_bg_color' ).val()     || '#ffffff',
            '--cwt-text':      $( '#cwt_text_color' ).val()   || '#333333',
            '--cwt-border':    $( '#cwt_border_color' ).val() || '#cccccc',
            '--cwt-hover':     $( '#cwt_hover_color' ).val()  || '#f0f0f0',
            '--cwt-radius':    ( $( '#cwt_border_radius' ).val() || '4'  ) + 'px',
            '--cwt-font-size': ( $( '#cwt_font_size' ).val()    || '14' ) + 'px',
            '--cwt-padding':   ( $( '#cwt_padding' ).val()      || '8'  ) + 'px',
        } );
    }

    $( 'input[type="number"]' ).on( 'input', updateDesignPreview );

    updateDesignPreview();

} )( jQuery );

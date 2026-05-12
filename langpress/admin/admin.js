/* global LP_Admin, wp */
( function ( $ ) {
    'use strict';

    function showToast( message, type ) {
        const toast = $( '<div class="lp-toast lp-toast--' + type + '">' + message + '</div>' );
        $( 'body' ).append( toast );
        setTimeout( () => toast.addClass( 'lp-toast--visible' ), 10 );
        setTimeout( function () {
            toast.removeClass( 'lp-toast--visible' );
            setTimeout( () => toast.remove(), 300 );
        }, 3000 );
    }

    $( '.lp-color-picker' ).wpColorPicker( { change: updateDesignPreview } );

    $( '#lp_switcher_display' ).on( 'change', function () {
        $( '.lp-page-selector' ).toggle( $( this ).val() !== 'all' );
    } );

    $( document ).on( 'change', '.lp-lang-card input[type="checkbox"]', function () {
        $( this ).closest( '.lp-lang-card' ).toggleClass( 'lp-lang-card--active', this.checked );
    } );

    // Save a single translation row from the translations table.
    $( document ).on( 'click', '.lp-save-translation', function () {
        const $btn       = $( this );
        const $cell      = $btn.closest( 'td' );
        const $input     = $cell.find( '.lp-translation-input' );
        const $select    = $cell.find( '.lp-status-select' );
        const $badge     = $cell.find( '.lp-status-badge' );
        const translated = $input.val();

        $btn.prop( 'disabled', true ).text( '…' );

        $.post( LP_Admin.ajaxUrl, {
            action:     'lp_save_translation',
            nonce:      LP_Admin.nonce,
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
                $badge.removeClass( 'lp-status--active lp-status--pending lp-status--ignored' )
                      .addClass( 'lp-status--' + effectiveStatus )
                      .text( LP_Admin.i18n[ effectiveStatus ] || effectiveStatus );
                $select.val( effectiveStatus );
                $cell.addClass( 'lp-save-success' );
                setTimeout( () => $cell.removeClass( 'lp-save-success' ), 700 );
            } else {
                showToast( res.data?.message || LP_Admin.i18n.error, 'error' );
            }
        } )
        .fail( () => showToast( LP_Admin.i18n.error, 'error' ) )
        .always( function () {
            $btn.prop( 'disabled', false ).text( '✓' );
            setTimeout( () => $btn.text( LP_Admin.i18n.saveBtn ), 1500 );
        } );
    } );

    $( document ).on( 'change', '.lp-status-select', function () {
        const $select = $( this );
        const entryId = $select.data( 'entry-id' );
        const status  = $select.val();
        const $badge  = $select.closest( 'td' ).find( '.lp-status-badge' );

        if ( ! entryId ) return;

        $.post( LP_Admin.ajaxUrl, { action: 'lp_update_status', nonce: LP_Admin.nonce, id: entryId, status } )
            .done( function ( res ) {
                if ( res.success ) {
                    $badge.removeClass( 'lp-status--active lp-status--pending lp-status--ignored' )
                          .addClass( 'lp-status--' + status )
                          .text( LP_Admin.i18n[ status ] || status );
                }
            } );
    } );

    $( document ).on( 'click', '.lp-delete-translation', function () {
        if ( ! confirm( LP_Admin.i18n.confirm ) ) return;

        const $btn = $( this );
        const $row = $btn.closest( 'tr' );

        $.post( LP_Admin.ajaxUrl, {
            action: 'lp_delete_translation',
            nonce:  LP_Admin.nonce,
            hash:   $row.data( 'hash' ),
        } )
        .done( function ( res ) {
            if ( res.success ) {
                $row.fadeOut( 300, () => $row.remove() );
                showToast( 'Deleted.', 'success' );
            } else {
                showToast( res.data?.message || LP_Admin.i18n.error, 'error' );
            }
        } );
    } );

    $( '#lp-export-btn' ).on( 'click', function () {
        window.location.href = LP_Admin.ajaxUrl + '?action=lp_export&nonce=' + encodeURIComponent( LP_Admin.nonce );
    } );

    $( '#lp-clear-cache' ).on( 'click', function () {
        const $btn = $( this );
        $btn.prop( 'disabled', true ).text( '…' );
        $.post( LP_Admin.ajaxUrl, { action: 'lp_clear_cache', nonce: LP_Admin.nonce } )
            .always( function () {
                $btn.prop( 'disabled', false ).text( 'Cache cleared ✓' );
                setTimeout( () => $btn.text( 'Clear translation cache' ), 2000 );
            } );
    } );

    $( '#lp-reinstall-db' ).on( 'click', function () {
        if ( ! confirm( 'Reinstall database tables? Existing data will be kept (dbDelta).' ) ) return;
        const $btn = $( this );
        $btn.prop( 'disabled', true ).text( '…' );
        $.post( LP_Admin.ajaxUrl, { action: 'lp_reinstall_db', nonce: LP_Admin.nonce } )
            .always( function () {
                $btn.prop( 'disabled', false ).text( 'Reinstalled ✓' );
                setTimeout( () => $btn.text( 'Reinstall database' ), 2000 );
            } );
    } );

    // Keep the design preview in sync as the user adjusts color and size inputs.
    function updateDesignPreview() {
        $( '#lp-design-preview .lp-switcher' ).css( {
            '--lp-bg':        $( '#lp_bg_color' ).val()     || '#ffffff',
            '--lp-text':      $( '#lp_text_color' ).val()   || '#333333',
            '--lp-border':    $( '#lp_border_color' ).val() || '#cccccc',
            '--lp-hover':     $( '#lp_hover_color' ).val()  || '#f0f0f0',
            '--lp-radius':    ( $( '#lp_border_radius' ).val() || '4'  ) + 'px',
            '--lp-font-size': ( $( '#lp_font_size' ).val()    || '14' ) + 'px',
            '--lp-padding':   ( $( '#lp_padding' ).val()      || '8'  ) + 'px',
        } );
    }

    $( 'input[type="number"]' ).on( 'input', updateDesignPreview );

    updateDesignPreview();

} )( jQuery );

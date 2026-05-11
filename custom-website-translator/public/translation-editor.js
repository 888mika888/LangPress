/* global CWT_Editor */
( function () {
    'use strict';

    if ( typeof CWT_Editor === 'undefined' ) return;

    const cfg = CWT_Editor;

    // Tags that should never be targeted
    const SKIP_TAGS = new Set( [
        'script', 'style', 'noscript', 'code', 'pre',
        'textarea', 'iframe', 'svg', 'path', 'input',
        'select', 'option', 'meta', 'link', 'head', 'br', 'hr',
    ] );

    // Selectors for translatable blocks
    const BLOCK_SEL = 'h1, h2, h3, h4, h5, h6, p, li, a, button, label, td, th, figcaption, blockquote, dt, dd';

    let selectedEl   = null;
    let selectedText = '';

    // -----------------------------------------------------------------------
    // Boot
    // -----------------------------------------------------------------------
    document.addEventListener( 'DOMContentLoaded', function () {
        document.body.classList.add( 'cwt-editor-active' );
        wireUpSidebar();
        scanAndMarkElements();
    } );

    // -----------------------------------------------------------------------
    // Sidebar events
    // -----------------------------------------------------------------------
    function wireUpSidebar() {
        // Close
        const closeBtn = document.getElementById( 'cwt-editor-close' );
        if ( closeBtn ) {
            closeBtn.addEventListener( 'click', function () {
                window.location.href = cfg.closeUrl;
            } );
        }

        // Save (top header button)
        const saveTop = document.getElementById( 'cwt-editor-save-top' );
        if ( saveTop ) saveTop.addEventListener( 'click', doSave );

        // Save (footer button)
        const saveBot = document.getElementById( 'cwt-editor-save' );
        if ( saveBot ) saveBot.addEventListener( 'click', doSave );

        // Tab switching (visual only for now)
        document.querySelectorAll( '.cwt-sidebar-tab' ).forEach( function ( tab ) {
            tab.addEventListener( 'click', function () {
                document.querySelectorAll( '.cwt-sidebar-tab' ).forEach( function ( t ) {
                    t.classList.remove( 'cwt-sidebar-tab--active' );
                } );
                tab.classList.add( 'cwt-sidebar-tab--active' );
            } );
        } );
    }

    // -----------------------------------------------------------------------
    // Scan DOM, mark translatable elements and add pencil icons
    // -----------------------------------------------------------------------
    function scanAndMarkElements() {
        document.querySelectorAll( BLOCK_SEL ).forEach( function ( el ) {
            if ( shouldSkip( el ) ) return;

            const text = getCleanText( el );
            if ( ! text || text.length < 2 ) return;
            if ( ! /\p{L}/u.test( text ) ) return;

            // Skip if nested inside another translatable (prevent overlap)
            if ( el.parentElement && el.parentElement.closest( '.cwt-translatable' ) ) return;
            if ( el.querySelector( '.cwt-pencil' ) ) return;

            el.classList.add( 'cwt-translatable' );

            // Pencil button
            const pencil = document.createElement( 'button' );
            pencil.className = 'cwt-pencil';
            pencil.type      = 'button';
            pencil.innerHTML = '&#9998;';
            pencil.title     = 'Übersetzen';
            pencil.setAttribute( 'translate', 'no' );
            pencil.setAttribute( 'aria-label', 'Text übersetzen' );

            const capturedText = text;
            pencil.addEventListener( 'click', function ( e ) {
                e.preventDefault();
                e.stopPropagation();
                activateElement( el, capturedText );
            } );

            // Also allow clicking the element body itself
            el.addEventListener( 'click', function ( e ) {
                if ( e.target === pencil || e.target.closest( '#cwt-editor-sidebar' ) ) return;
                activateElement( el, capturedText );
            } );

            el.appendChild( pencil );
        } );
    }

    function shouldSkip( el ) {
        if ( el.closest( '#cwt-editor-sidebar' ) ) return true;
        if ( el.closest( '#wpadminbar' ) )         return true;
        if ( el.closest( '[translate="no"]' ) )    return true;
        if ( SKIP_TAGS.has( el.tagName.toLowerCase() ) ) return true;

        // Skip hidden elements
        const style = window.getComputedStyle( el );
        if ( style.display === 'none' || style.visibility === 'hidden' || style.opacity === '0' ) return true;

        return false;
    }

    function getCleanText( el ) {
        const clone = el.cloneNode( true );
        clone.querySelectorAll( '.cwt-pencil' ).forEach( function ( b ) { b.remove(); } );
        return ( clone.innerText || clone.textContent || '' ).trim().replace( /\s+/g, ' ' );
    }

    // -----------------------------------------------------------------------
    // Activate an element (select it, show in sidebar)
    // -----------------------------------------------------------------------
    function activateElement( el, text ) {
        // Deselect previous
        if ( selectedEl ) selectedEl.classList.remove( 'cwt-element-selected' );

        selectedEl   = el;
        selectedText = text;
        el.classList.add( 'cwt-element-selected' );

        // Scroll element into view (centered)
        el.scrollIntoView( { behavior: 'smooth', block: 'center' } );

        // Fill original text field
        const deField = document.getElementById( 'cwt-editor-de' );
        if ( deField ) deField.value = text;

        // Clear all translation fields
        ( cfg.targetLangs || [] ).forEach( function ( lang ) {
            const f = document.getElementById( 'cwt-editor-' + lang );
            if ( f ) f.value = '';
        } );

        showFields( true );
        clearMsg();

        // Load existing translations via AJAX
        fetchTranslations( text );

        // Focus first translation textarea
        setTimeout( function () {
            const firstLang = ( cfg.targetLangs || [] )[ 0 ];
            if ( firstLang ) {
                const f = document.getElementById( 'cwt-editor-' + firstLang );
                if ( f ) f.focus();
            }
        }, 80 );
    }

    function showFields( show ) {
        const fields  = document.getElementById( 'cwt-editor-fields' );
        const hint    = document.getElementById( 'cwt-editor-hint' );
        const footer  = document.getElementById( 'cwt-editor-footer' );
        if ( fields ) fields.style.display = show ? 'flex' : 'none';
        if ( hint   ) hint.style.display   = show ? 'none' : 'block';
        if ( footer ) footer.style.display = show ? 'block' : 'none';
    }

    function clearMsg() {
        const msg = document.getElementById( 'cwt-editor-msg' );
        if ( msg ) {
            msg.textContent = '';
            msg.className   = 'cwt-sidebar-message';
        }
    }

    function showMsg( text, type ) {
        const msg = document.getElementById( 'cwt-editor-msg' );
        if ( ! msg ) return;
        msg.textContent = text;
        msg.className   = 'cwt-sidebar-message cwt-sidebar-message--' + type + ' cwt-sidebar-message--visible';
    }

    // -----------------------------------------------------------------------
    // AJAX: load existing translations
    // -----------------------------------------------------------------------
    function fetchTranslations( originalText ) {
        showMsg( 'Lädt…', 'loading' );

        const fd = new FormData();
        fd.append( 'action',   'cwt_get_translation' );
        fd.append( 'nonce',    cfg.nonce );
        fd.append( 'original', originalText );
        if ( cfg.postId ) fd.append( 'post_id', cfg.postId );

        fetch( cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } )
            .then( function ( r ) { return r.json(); } )
            .then( function ( res ) {
                clearMsg();
                if ( ! res.success ) return;
                const t = res.data.translations || {};
                ( cfg.targetLangs || [] ).forEach( function ( lang ) {
                    const f = document.getElementById( 'cwt-editor-' + lang );
                    if ( f ) f.value = t[ lang ] || '';
                } );
            } )
            .catch( function () { clearMsg(); } );
    }

    // -----------------------------------------------------------------------
    // AJAX: save all translations in one call
    // -----------------------------------------------------------------------
    function doSave() {
        if ( ! selectedText ) {
            showMsg( 'Kein Text ausgewählt. Klicke zuerst auf einen Text.', 'error' );
            return;
        }

        const translations = {};
        ( cfg.targetLangs || [] ).forEach( function ( lang ) {
            const val = ( document.getElementById( 'cwt-editor-' + lang )?.value || '' ).trim();
            if ( val ) translations[ lang ] = val;
        } );

        if ( Object.keys( translations ).length === 0 ) {
            showMsg( 'Bitte mindestens eine Übersetzung eingeben.', 'error' );
            return;
        }

        setSaving( true );
        showMsg( 'Speichert…', 'loading' );

        const fd = new FormData();
        fd.append( 'action',   'cwt_save_translation' );
        fd.append( 'nonce',    cfg.nonce );
        fd.append( 'original', selectedText );
        if ( cfg.postId ) fd.append( 'post_id', cfg.postId );

        // Append each language as its own POST key
        Object.entries( translations ).forEach( function ( [ lang, val ] ) {
            fd.append( lang, val );
        } );

        fetch( cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } )
            .then( function ( r ) { return r.json(); } )
            .then( function ( res ) {
                setSaving( false );
                if ( res.success ) {
                    showMsg( '✓ Gespeichert!', 'success' );
                } else {
                    showMsg( ( res.data && res.data.message ) || 'Fehler beim Speichern.', 'error' );
                }
            } )
            .catch( function () {
                setSaving( false );
                showMsg( 'Netzwerkfehler. Bitte erneut versuchen.', 'error' );
            } );
    }

    function setSaving( saving ) {
        [ 'cwt-editor-save', 'cwt-editor-save-top' ].forEach( function ( id ) {
            const btn = document.getElementById( id );
            if ( ! btn ) return;
            btn.disabled    = saving;
            btn.textContent = saving ? '…' : 'Speichern';
        } );
    }

} )();

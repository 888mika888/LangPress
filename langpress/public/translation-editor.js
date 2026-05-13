/* global LP_Editor */
( function () {
    'use strict';

    if ( typeof LP_Editor === 'undefined' ) return;

    const cfg = LP_Editor;

    const SKIP_TAGS = new Set( [
        'script', 'style', 'noscript', 'code', 'pre',
        'textarea', 'iframe', 'svg', 'path', 'input',
        'select', 'option', 'meta', 'link', 'head', 'br', 'hr',
    ] );

    const BLOCK_SELECTORS = 'h1, h2, h3, h4, h5, h6, p, li, a, button, label, td, th, figcaption, blockquote, dt, dd';

    let selectedEl   = null;
    let selectedText = '';

    document.addEventListener( 'DOMContentLoaded', function () {
        document.body.classList.add( 'lp-editor-active' );
        wireSidebarEvents();
        scanAndMarkElements();
    } );

    function wireSidebarEvents() {
        document.getElementById( 'lp-editor-close' )
            ?.addEventListener( 'click', () => { window.location.href = cfg.closeUrl; } );

        document.getElementById( 'lp-editor-save' )?.addEventListener( 'click', doSave );
    }

    function scanAndMarkElements() {
        document.querySelectorAll( BLOCK_SELECTORS ).forEach( function ( el ) {
            if ( shouldSkip( el ) ) return;

            const text = getCleanText( el );
            if ( ! text || text.length < 2 || ! /\p{L}/u.test( text ) ) return;
            if ( el.querySelector( '.lp-pencil' ) ) return;

            el.classList.add( 'lp-translatable' );

            const pencil = document.createElement( 'button' );
            pencil.className = 'lp-pencil';
            pencil.type      = 'button';
            pencil.innerHTML = '&#9998;';
            pencil.title     = 'Translate';
            pencil.setAttribute( 'translate', 'no' );
            pencil.setAttribute( 'aria-label', 'Translate this text' );

            const capturedText = text;
            pencil.addEventListener( 'click', function ( e ) {
                e.preventDefault();
                e.stopPropagation();
                selectElement( el, capturedText );
            } );

            el.addEventListener( 'click', function ( e ) {
                if ( e.target === pencil || e.target.closest( '#lp-editor-sidebar' ) ) return;
                selectElement( el, capturedText );
            } );

            el.appendChild( pencil );
        } );
    }

    function shouldSkip( el ) {
        if ( el.closest( '#lp-editor-sidebar' ) ) return true;
        if ( el.closest( '#wpadminbar' ) )         return true;
        if ( el.closest( '.lp-switcher' ) )        return true;
        if ( el.closest( '[translate="no"]' ) )    return true;
        if ( SKIP_TAGS.has( el.tagName.toLowerCase() ) ) return true;

        const style = window.getComputedStyle( el );
        if ( style.display === 'none' || style.visibility === 'hidden' ) return true;

        return false;
    }

    function getCleanText( el ) {
        const clone = el.cloneNode( true );
        clone.querySelectorAll( '.lp-pencil' ).forEach( b => b.remove() );
        return ( clone.innerText || clone.textContent || '' ).trim().replace( /\s+/g, ' ' );
    }

    function selectElement( el, text ) {
        if ( selectedEl ) selectedEl.classList.remove( 'lp-element-selected' );

        selectedEl   = el;
        selectedText = text;
        el.classList.add( 'lp-element-selected' );
        el.scrollIntoView( { behavior: 'smooth', block: 'center' } );

        const deField = document.getElementById( 'lp-editor-original' );
        if ( deField ) deField.value = text;

        ( cfg.targetLangs || [] ).forEach( function ( lang ) {
            const f = document.getElementById( 'lp-editor-' + lang );
            if ( f ) f.value = '';
        } );

        showFields( true );
        clearMsg();
        fetchTranslations( text );

        // Focus the first translation textarea after a short delay to let the scroll settle.
        setTimeout( function () {
            const firstLang = ( cfg.targetLangs || [] )[ 0 ];
            if ( firstLang ) document.getElementById( 'lp-editor-' + firstLang )?.focus();
        }, 80 );
    }

    function showFields( show ) {
        const fields = document.getElementById( 'lp-editor-fields' );
        const hint   = document.getElementById( 'lp-editor-hint' );
        const footer = document.getElementById( 'lp-editor-footer' );
        if ( fields ) fields.style.display = show ? 'flex' : 'none';
        if ( hint   ) hint.style.display   = show ? 'none' : 'block';
        if ( footer ) footer.style.display = show ? 'block' : 'none';
    }

    function clearMsg() {
        const msg = document.getElementById( 'lp-editor-msg' );
        if ( msg ) { msg.textContent = ''; msg.className = 'lp-sidebar-message'; }
    }

    function showMsg( text, type ) {
        const msg = document.getElementById( 'lp-editor-msg' );
        if ( ! msg ) return;
        msg.textContent = text;
        msg.className   = 'lp-sidebar-message lp-sidebar-message--' + type + ' lp-sidebar-message--visible';
    }

    function fetchTranslations( originalText ) {
        showMsg( 'Loading…', 'loading' );

        const fd = new FormData();
        fd.append( 'action',   'lp_get_translation' );
        fd.append( 'nonce',    cfg.nonce );
        fd.append( 'original', originalText );
        if ( cfg.postId ) fd.append( 'post_id', cfg.postId );

        fetch( cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } )
            .then( function ( r ) {
                if ( ! r.ok ) throw new Error( 'HTTP ' + r.status );
                return r.json();
            } )
            .then( function ( res ) {
                clearMsg();
                if ( ! res.success ) return;
                const t = res.data.translations || {};
                ( cfg.targetLangs || [] ).forEach( function ( lang ) {
                    const f = document.getElementById( 'lp-editor-' + lang );
                    if ( f ) f.value = t[ lang ] || '';
                } );
            } )
            .catch( function () {
                showMsg( 'Could not load existing translations.', 'error' );
            } );
    }

    function doSave() {
        if ( ! selectedText ) {
            showMsg( 'Click a text on the page first.', 'error' );
            return;
        }

        const translations = {};
        ( cfg.targetLangs || [] ).forEach( function ( lang ) {
            const val = ( document.getElementById( 'lp-editor-' + lang )?.value || '' ).trim();
            if ( val ) translations[ lang ] = val;
        } );

        if ( Object.keys( translations ).length === 0 ) {
            showMsg( 'Please enter at least one translation.', 'error' );
            return;
        }

        setSaving( true );
        showMsg( 'Saving…', 'loading' );

        // Send one request per language (same format as the floating quick-translate mode).
        const requests = Object.entries( translations ).map( function ( [ lang, val ] ) {
            const fd = new FormData();
            fd.append( 'action',     'lp_save_translation' );
            fd.append( 'nonce',      cfg.nonce );
            fd.append( 'original',   selectedText );
            fd.append( 'lang',       lang );
            fd.append( 'translated', val );
            fd.append( 'status',     'active' );
            if ( cfg.postId ) fd.append( 'post_id', cfg.postId );
            return fetch( cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } )
                .then( function ( r ) {
                    if ( ! r.ok ) throw new Error( 'HTTP ' + r.status );
                    return r.json();
                } );
        } );

        Promise.all( requests )
            .then( function ( results ) {
                setSaving( false );
                const allOk = results.every( function ( r ) { return r.success; } );
                if ( allOk ) {
                    showMsg( '✓ Saved!', 'success' );
                    if ( selectedEl ) {
                        selectedEl.classList.add( 'lp-element-saved' );
                        setTimeout( () => selectedEl?.classList.remove( 'lp-element-saved' ), 1200 );
                    }
                } else {
                    const failed = results.find( function ( r ) { return ! r.success; } );
                    showMsg( failed?.data?.message || 'Error saving. Please try again.', 'error' );
                }
            } )
            .catch( function () {
                setSaving( false );
                showMsg( 'Network error. Please try again.', 'error' );
            } );
    }

    function setSaving( saving ) {
        [ 'lp-editor-save' ].forEach( function ( id ) {
            const btn = document.getElementById( id );
            if ( ! btn ) return;
            btn.disabled    = saving;
            btn.textContent = saving ? '…' : 'Save';
        } );
    }

} )();


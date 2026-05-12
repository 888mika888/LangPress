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

        document.getElementById( 'lp-editor-save-top' )?.addEventListener( 'click', doSave );
        document.getElementById( 'lp-editor-save' )?.addEventListener( 'click', doSave );

        document.querySelectorAll( '.lp-sidebar-tab' ).forEach( function ( tab, index ) {
            tab.addEventListener( 'click', function () {
                document.querySelectorAll( '.lp-sidebar-tab' ).forEach( function ( t ) {
                    t.classList.remove( 'lp-sidebar-tab--active' );
                    t.setAttribute( 'aria-selected', 'false' );
                } );
                tab.classList.add( 'lp-sidebar-tab--active' );
                tab.setAttribute( 'aria-selected', 'true' );

                const body = document.querySelector( '.lp-sidebar-body' );
                if ( ! body ) return;

                if ( index === 1 ) {
                    // String Translation tab: hide editor fields and show an info panel.
                    showFields( false );
                    document.getElementById( 'lp-editor-hint' ).style.display = 'none';

                    let panel = document.getElementById( 'lp-string-panel' );
                    if ( ! panel ) {
                        panel = document.createElement( 'div' );
                        panel.id        = 'lp-string-panel';
                        panel.className = 'lp-string-panel';
                        panel.innerHTML =
                            '<p class="lp-string-panel__title">String Translation</p>'
                          + '<p class="lp-string-panel__text">Manage all detected strings in the WordPress admin. '
                          + 'Strings are collected automatically as pages are visited in the default language.</p>'
                          + '<a class="lp-string-panel__link" href="' + cfg.adminUrl + '" target="_blank">Open Translations Table ↗</a>';
                        body.appendChild( panel );
                    }
                    panel.style.display = 'flex';
                } else {
                    const panel = document.getElementById( 'lp-string-panel' );
                    if ( panel ) panel.style.display = 'none';

                    const hint = document.getElementById( 'lp-editor-hint' );
                    if ( hint ) hint.style.display = selectedEl ? 'none' : 'block';

                    if ( selectedEl ) showFields( true );
                }
            } );
        } );
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

        const deField = document.getElementById( 'lp-editor-de' );
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
            .then( r => r.json() )
            .then( function ( res ) {
                clearMsg();
                if ( ! res.success ) return;
                const t = res.data.translations || {};
                ( cfg.targetLangs || [] ).forEach( function ( lang ) {
                    const f = document.getElementById( 'lp-editor-' + lang );
                    if ( f ) f.value = t[ lang ] || '';
                } );
            } )
            .catch( clearMsg );
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

        const fd = new FormData();
        fd.append( 'action',   'lp_save_translation' );
        fd.append( 'nonce',    cfg.nonce );
        fd.append( 'original', selectedText );
        if ( cfg.postId ) fd.append( 'post_id', cfg.postId );
        Object.entries( translations ).forEach( ( [ lang, val ] ) => fd.append( lang, val ) );

        fetch( cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } )
            .then( r => r.json() )
            .then( function ( res ) {
                setSaving( false );
                if ( res.success ) {
                    showMsg( '✓ Saved!', 'success' );
                    // Flash the element green so the user can see which text was just saved.
                    if ( selectedEl ) {
                        selectedEl.classList.add( 'lp-element-saved' );
                        setTimeout( () => selectedEl?.classList.remove( 'lp-element-saved' ), 1200 );
                    }
                } else {
                    showMsg( res.data?.message || 'Error saving. Please try again.', 'error' );
                }
            } )
            .catch( function () {
                setSaving( false );
                showMsg( 'Network error. Please try again.', 'error' );
            } );
    }

    function setSaving( saving ) {
        [ 'lp-editor-save', 'lp-editor-save-top' ].forEach( function ( id ) {
            const btn = document.getElementById( id );
            if ( ! btn ) return;
            btn.disabled    = saving;
            btn.textContent = saving ? '…' : 'Save';
        } );
    }

} )();

/* global LP_Editor */
( function () {
    'use strict';

    if ( typeof LP_Editor === 'undefined' ) return;

    const cfg   = LP_Editor;
    const DEBUG = new URLSearchParams( window.location.search ).has( 'lp_debug' );

    function dbg( ...args ) {
        if ( DEBUG && window.console ) console.log( '[LangPress]', ...args );
    }

    const SKIP_TAGS = new Set( [
        'script', 'style', 'noscript', 'code', 'pre',
        'textarea', 'iframe', 'svg', 'path', 'input',
        'select', 'option', 'meta', 'link', 'head', 'br', 'hr',
    ] );

    // Semantic block-level elements — always leaf text containers.
    // Read with getCleanText (full innerText including inline children).
    const SEMANTIC_SEL = 'h1, h2, h3, h4, h5, h6, p, li, blockquote, figcaption, dt, dd, td, th, .wp-block-button__link';

    // Inline / interactive elements — only get pencils when NOT already
    // inside a .lp-translatable ancestor.
    const INLINE_SEL = 'a, button, label, strong, b, em, span';

    let selectedEl   = null;
    let selectedText = '';

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    document.addEventListener( 'DOMContentLoaded', function () {
        document.body.classList.add( 'lp-editor-active' );
        wireSidebarEvents();
        scanAndMarkElements();
    } );

    // -------------------------------------------------------------------------
    // Three-pass scan
    //
    // Pass 1 — Semantic block elements (h1-h6, p, li, …)
    //   These are always individual text units. Use full innerText.
    //
    // Pass 2 — Inline elements (a, button, strong, …)
    //   Skip if already covered by a .lp-translatable ancestor from pass 1.
    //
    // Pass 3 — Divs with DIRECT text nodes only
    //   A div is only a translation unit when it has its own text content
    //   (not just child elements). getDirectText reads only TEXT_NODEs,
    //   never descendant element text — so container wrappers are never
    //   captured as one big text block.
    // -------------------------------------------------------------------------

    function scanAndMarkElements() {
        let count = 0;

        // Pass 1: semantic block elements.
        document.querySelectorAll( SEMANTIC_SEL ).forEach( function ( el ) {
            if ( shouldSkip( el ) ) return;
            const text = getCleanText( el );
            if ( ! isValidText( text ) ) return;
            markElement( el, text );
            count++;
        } );

        // Pass 2: inline elements — only standalone ones (not inside a block).
        document.querySelectorAll( INLINE_SEL ).forEach( function ( el ) {
            if ( shouldSkip( el ) ) return;
            const text = getCleanText( el );
            if ( ! isValidText( text ) ) return;
            markElement( el, text );
            count++;
        } );

        // Pass 3: divs with direct text (e.g. Impressum address blocks).
        // We never use innerText here — only TEXT_NODE children of the div.
        document.querySelectorAll( 'div' ).forEach( function ( el ) {
            if ( shouldSkip( el ) ) return;
            const text = getDirectText( el );
            if ( ! isValidText( text ) ) return;
            markElement( el, text );
            count++;
        } );

        dbg( 'Scan complete. Blocks marked:', count );
    }

    // -------------------------------------------------------------------------
    // shouldSkip — shared by all three passes
    // -------------------------------------------------------------------------

    function shouldSkip( el ) {
        // Exclude our own plugin UI containers first.
        if ( el.closest( '#lp-editor-sidebar' ) ) return true;
        if ( el.closest( '#wpadminbar' ) )         return true;
        if ( el.closest( '.lp-switcher' ) )        return true;
        if ( el.closest( '#lp-fab' ) )             return true;

        // Skip only if THIS element itself is marked translate="no".
        // Do NOT use closest() here — themes often put translate="no" on entire
        // content wrappers to block browser auto-translate, which would incorrectly
        // skip every text block on the page.
        if ( el.getAttribute( 'translate' ) === 'no' ) return true;

        if ( SKIP_TAGS.has( el.tagName.toLowerCase() ) ) return true;

        // Already processed in an earlier pass.
        if ( el.dataset.lpProcessed === '1' ) return true;

        const style = window.getComputedStyle( el );
        if ( style.display === 'none' || style.visibility === 'hidden' ) return true;

        // A .lp-translatable ancestor already owns this element's text.
        // Skipping prevents double pencils and wrong-text-in-sidebar issues.
        if ( el.closest( '.lp-translatable' ) ) return true;

        // Has block-level children — each child will get its own pencil.
        // This stops li>p, blockquote>p, etc. from being captured as one blob.
        if ( el.querySelector( 'h1,h2,h3,h4,h5,h6,p,li,blockquote,dt,dd,td,th,figcaption' ) ) return true;

        return false;
    }

    // -------------------------------------------------------------------------
    // markElement — attach pencil with a direct element reference
    // -------------------------------------------------------------------------

    function markElement( el, text ) {
        el.dataset.lpProcessed    = '1';
        el.dataset.lpOriginalText = text;
        el.classList.add( 'lp-translatable' );

        const pencil = document.createElement( 'button' );
        pencil.className = 'lp-pencil';
        pencil.type      = 'button';
        pencil.innerHTML = '&#9998;';
        pencil.title     = 'Translate';
        pencil.setAttribute( 'translate', 'no' );
        pencil.setAttribute( 'aria-label', 'Translate this text' );

        // Direct closure reference to `el` — no querySelector, no hash lookup.
        pencil.addEventListener( 'click', function ( e ) {
            e.preventDefault();
            e.stopPropagation();
            selectElement( el, el.dataset.lpOriginalText );
        } );

        el.addEventListener( 'click', function ( e ) {
            if ( e.target.classList.contains( 'lp-pencil' ) ) return;
            if ( e.target.closest( '#lp-editor-sidebar' ) ) return;
            e.stopPropagation();
            selectElement( e.currentTarget, e.currentTarget.dataset.lpOriginalText );
        } );

        el.appendChild( pencil );

        dbg( 'Marked', el.tagName, '|', text.substring( 0, 60 ) );
    }

    // -------------------------------------------------------------------------
    // Text helpers
    // -------------------------------------------------------------------------

    // Full visible text of an element including all inline children.
    // Uses the live element (attached to DOM) so innerText has proper layout —
    // innerText on a detached clone returns "" in browsers, causing fallback to
    // textContent which concatenates blocks without spaces ("Herz2Herzc/o…").
    // Safe to call pre-pencil because markElement appends the pencil AFTER this.
    function getCleanText( el ) {
        return normalizeText( el.innerText || el.textContent || '' );
    }

    // Only direct TEXT_NODE children — ignores all descendant element text.
    // Used for div elements so container wrappers never capture combined text.
    // Joins parts with a space so <br>-separated lines don't lose their gap.
    function getDirectText( el ) {
        const parts = [];
        el.childNodes.forEach( function ( node ) {
            if ( node.nodeType === Node.TEXT_NODE ) {
                const t = node.textContent.trim();
                if ( t ) parts.push( t );
            }
        } );
        return normalizeText( parts.join( ' ' ) );
    }

    function normalizeText( text ) {
        return ( text || '' ).trim().replace( /\s+/g, ' ' );
    }

    function isValidText( text ) {
        return !! text && text.length >= 2 && /\p{L}/u.test( text );
    }

    // -------------------------------------------------------------------------
    // Selection
    // -------------------------------------------------------------------------

    function selectElement( el, text ) {
        if ( ! el || ! text ) return;
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

        dbg( 'Selected', el.tagName, '|', text.substring( 0, 80 ) );

        setTimeout( function () {
            const firstLang = ( cfg.targetLangs || [] )[ 0 ];
            if ( firstLang ) document.getElementById( 'lp-editor-' + firstLang )?.focus();
        }, 80 );
    }

    // -------------------------------------------------------------------------
    // Sidebar wiring
    // -------------------------------------------------------------------------

    function wireSidebarEvents() {
        document.getElementById( 'lp-editor-close' )
            ?.addEventListener( 'click', () => { window.location.href = cfg.closeUrl; } );
        document.getElementById( 'lp-editor-save' )?.addEventListener( 'click', doSave );
    }

    function showFields( show ) {
        const fields = document.getElementById( 'lp-editor-fields' );
        const hint   = document.getElementById( 'lp-editor-hint' );
        const footer = document.getElementById( 'lp-editor-footer' );
        if ( fields ) fields.style.display = show ? 'flex' : 'none';
        if ( hint )   hint.style.display   = show ? 'none' : 'block';
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

    // -------------------------------------------------------------------------
    // AJAX
    // -------------------------------------------------------------------------

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
                dbg( 'Translations loaded:', t );
                ( cfg.targetLangs || [] ).forEach( function ( lang ) {
                    const f = document.getElementById( 'lp-editor-' + lang );
                    if ( f ) f.value = t[ lang ] || '';
                } );
            } )
            .catch( function ( err ) {
                if ( window.console ) console.warn( 'LangPress: could not load translations.', err );
                clearMsg();
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

        const requests = Object.entries( translations ).map( function ( [ lang, val ] ) {
            const fd = new FormData();
            fd.append( 'action',     'lp_save_translation' );
            fd.append( 'nonce',      cfg.nonce );
            fd.append( 'original',   selectedText );
            fd.append( 'lang',       lang );
            fd.append( 'translated', val );
            fd.append( 'status',     'active' );
            if ( cfg.postId ) fd.append( 'post_id', cfg.postId );

            dbg( 'Saving lang:', lang, '|', selectedText.substring( 0, 60 ), '->', val.substring( 0, 60 ) );

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
        const btn = document.getElementById( 'lp-editor-save' );
        if ( ! btn ) return;
        btn.disabled    = saving;
        btn.textContent = saving ? '…' : 'Save';
    }

} )();

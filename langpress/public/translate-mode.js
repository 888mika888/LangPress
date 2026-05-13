/* global LP_Translate */
( function () {
    'use strict';

    if ( typeof LP_Translate === 'undefined' ) return;

    const cfg = LP_Translate;

    const SKIP_TAGS = new Set( [
        'script', 'style', 'noscript', 'code', 'pre',
        'textarea', 'iframe', 'svg', 'path', 'input',
        'select', 'option', 'meta', 'link', 'head',
    ] );

    const BLOCK_SEL = 'p, h1, h2, h3, h4, h5, h6, li, td, th, dt, dd, figcaption, blockquote';
    const LEAF_SEL  = 'a, button, label';

    // Language metadata comes from PHP so new languages automatically appear in the sidebar.
    const LANG_META = cfg.langMeta || {};

    let modeActive      = sessionStorage.getItem( 'lp_translate_mode' ) === '1';
    let selectedElement = null;
    let selectedText    = '';
    let targetLang      = getFirstTargetLang();

    document.addEventListener( 'DOMContentLoaded', function () {
        buildToolbar();
        if ( modeActive ) activateMode();
    } );

    function getFirstTargetLang() {
        const active  = cfg.activeLangs  || [ 'de', 'en', 'uk' ];
        const defLang = cfg.defaultLang  || 'de';
        return active.find( function ( l ) { return l !== defLang; } ) || 'en';
    }

    function buildToolbar() {
        const bar = document.createElement( 'div' );
        bar.id = 'lp-toolbar';
        bar.setAttribute( 'translate', 'no' );

        const btn = document.createElement( 'button' );
        btn.id   = 'lp-mode-toggle';
        btn.type = 'button';
        btn.addEventListener( 'click', toggleMode );
        bar.appendChild( btn );

        document.body.appendChild( bar );
        syncToggleLabel();
    }

    function syncToggleLabel() {
        const btn = document.getElementById( 'lp-mode-toggle' );
        if ( ! btn ) return;
        if ( modeActive ) {
            btn.textContent = '✎ Stop Translating';
            btn.classList.add( 'lp-mode-btn--active' );
        } else {
            btn.textContent = '✎ Translate Page';
            btn.classList.remove( 'lp-mode-btn--active' );
        }
    }

    function toggleMode() {
        modeActive = ! modeActive;
        sessionStorage.setItem( 'lp_translate_mode', modeActive ? '1' : '0' );
        syncToggleLabel();
        if ( modeActive ) {
            activateMode();
        } else {
            deactivateMode();
        }
    }

    function activateMode() {
        document.body.classList.add( 'lp-translate-active' );
        buildSidebar();
        addPencilIcons();
    }

    function deactivateMode() {
        document.body.classList.remove( 'lp-translate-active' );
        clearSelectedElement();
        removePencilIcons();
        removeSidebar();
    }

    function buildSidebar() {
        if ( document.getElementById( 'lp-sidebar' ) ) return;

        const active  = cfg.activeLangs  || [ 'de', 'en', 'uk' ];
        const defLang = cfg.defaultLang  || 'de';

        const options = active
            .filter( function ( l ) { return l !== defLang; } )
            .map( function ( l ) {
                const meta = LANG_META[ l ] || { flag: '', native: l.toUpperCase() };
                return '<option value="' + l + '"' + ( l === targetLang ? ' selected' : '' ) + '>'
                     + meta.flag + ' ' + meta.native
                     + '</option>';
            } )
            .join( '' );

        const defMeta   = LANG_META[ defLang ] || { flag: '', native: defLang.toUpperCase() };
        const fromLabel = defMeta.flag + ' ' + defMeta.native;

        const sidebar = document.createElement( 'div' );
        sidebar.id = 'lp-sidebar';
        sidebar.setAttribute( 'translate', 'no' );

        sidebar.innerHTML =
            '<div class="lp-sidebar__header">'
          +     '<span class="lp-sidebar__title">✎ Translation Editor</span>'
          +     '<button class="lp-sidebar__close" id="lp-sidebar-close" type="button" aria-label="Close">&times;</button>'
          + '</div>'
          + '<div class="lp-sidebar__body">'
          +     '<div class="lp-sidebar__lang-row">'
          +         '<label class="lp-sidebar__label" for="lp-target-lang">Target language</label>'
          +         '<select class="lp-sidebar__select" id="lp-target-lang">' + options + '</select>'
          +     '</div>'
          +     '<div class="lp-sidebar__hint" id="lp-hint">'
          +         'Click <strong>✎</strong> on any text to translate it.'
          +     '</div>'
          +     '<div class="lp-sidebar__fields" id="lp-fields" style="display:none">'
          +         '<div class="lp-sidebar__field">'
          +             '<label class="lp-sidebar__label" id="lp-from-label">' + fromLabel + ' Original</label>'
          +             '<textarea class="lp-sidebar__textarea lp-sidebar__textarea--readonly" id="lp-sidebar-original" readonly rows="4"></textarea>'
          +         '</div>'
          +         '<div class="lp-sidebar__field">'
          +             '<label class="lp-sidebar__label" id="lp-to-label"></label>'
          +             '<textarea class="lp-sidebar__textarea" id="lp-sidebar-trans" rows="4" placeholder="Enter translation…"></textarea>'
          +         '</div>'
          +         '<div class="lp-sidebar__message" id="lp-sidebar-msg"></div>'
          +     '</div>'
          + '</div>'
          + '<div class="lp-sidebar__footer" id="lp-sidebar-footer" style="display:none">'
          +     '<button class="lp-sidebar__save-btn" id="lp-sidebar-save" type="button">Save</button>'
          + '</div>';

        document.body.appendChild( sidebar );

        document.getElementById( 'lp-sidebar-close' ).addEventListener( 'click', function () {
            modeActive = false;
            sessionStorage.setItem( 'lp_translate_mode', '0' );
            syncToggleLabel();
            deactivateMode();
        } );

        document.getElementById( 'lp-target-lang' ).addEventListener( 'change', function () {
            targetLang = this.value;
            updateTargetLabel();
            if ( selectedText ) fetchAndFill( selectedText );
        } );

        document.getElementById( 'lp-sidebar-save' ).addEventListener( 'click', doSave );

        updateTargetLabel();
    }

    function removeSidebar() {
        const s = document.getElementById( 'lp-sidebar' );
        if ( s ) s.remove();
    }

    function updateTargetLabel() {
        const meta  = LANG_META[ targetLang ] || { flag: '', native: targetLang.toUpperCase() };
        const label = document.getElementById( 'lp-to-label' );
        if ( label ) label.textContent = meta.flag + ' ' + meta.native;
    }

    function showFields( show ) {
        const fields  = document.getElementById( 'lp-fields' );
        const footer  = document.getElementById( 'lp-sidebar-footer' );
        const hint    = document.getElementById( 'lp-hint' );
        if ( fields ) fields.style.display  = show ? 'flex' : 'none';
        if ( footer ) footer.style.display  = show ? 'block' : 'none';
        if ( hint   ) hint.style.display    = show ? 'none' : 'block';
    }

    function setOriginalText( text ) {
        const ta = document.getElementById( 'lp-sidebar-original' );
        if ( ta ) ta.value = text;
    }

    function setTranslationText( text ) {
        const ta = document.getElementById( 'lp-sidebar-trans' );
        if ( ta ) ta.value = text;
    }

    function clearMsg() {
        const msg = document.getElementById( 'lp-sidebar-msg' );
        if ( ! msg ) return;
        msg.textContent = '';
        msg.className   = 'lp-sidebar__message';
    }

    function showMsg( text, type ) {
        const msg = document.getElementById( 'lp-sidebar-msg' );
        if ( ! msg ) return;
        msg.textContent = text;
        msg.className   = 'lp-sidebar__message lp-sidebar__message--' + type + ' lp-sidebar__message--visible';
    }

    function addPencilIcons() {
        const candidates = [
            ...document.querySelectorAll( BLOCK_SEL ),
            ...document.querySelectorAll( LEAF_SEL ),
        ];

        candidates.forEach( function ( el ) {
            if ( shouldSkip( el ) ) return;

            const text = getCleanText( el );
            if ( ! text || text.length < 2 ) return;
            if ( ! /\p{L}/u.test( text ) ) return;
            if ( el.querySelector( '.lp-pencil-btn' ) ) return;

            el.classList.add( 'lp-has-pencil' );

            const btn = document.createElement( 'button' );
            btn.className = 'lp-pencil-btn';
            btn.type      = 'button';
            btn.innerHTML = '&#9998;';
            btn.title     = 'Translate';
            btn.setAttribute( 'translate', 'no' );

            const capturedText = text;
            btn.addEventListener( 'click', function ( e ) {
                e.preventDefault();
                e.stopPropagation();
                selectElement( el, capturedText );
            } );

            el.appendChild( btn );
        } );
    }

    function removePencilIcons() {
        document.querySelectorAll( '.lp-pencil-btn' ).forEach( function ( b ) { b.remove(); } );
        document.querySelectorAll( '.lp-has-pencil' ).forEach( function ( el ) {
            el.classList.remove( 'lp-has-pencil' );
        } );
    }

    function shouldSkip( el ) {
        if ( el.closest( '#wpadminbar' ) )        return true;
        if ( el.closest( '#lp-toolbar' ) )        return true;
        if ( el.closest( '#lp-sidebar' ) )        return true;
        if ( el.closest( '.lp-switcher' ) )       return true;
        if ( el.getAttribute( 'translate' ) === 'no' )   return true;
        if ( el.closest( '[translate="no"]' ) )           return true;
        if ( SKIP_TAGS.has( el.tagName.toLowerCase() ) )  return true;
        if ( el.parentElement && el.parentElement.closest( '.lp-has-pencil' ) ) return true;
        return false;
    }

    function getCleanText( el ) {
        const clone = el.cloneNode( true );
        clone.querySelectorAll( '.lp-pencil-btn' ).forEach( function ( b ) { b.remove(); } );
        return ( clone.innerText || clone.textContent || '' ).trim().replace( /\s+/g, ' ' );
    }

    function selectElement( el, text ) {
        clearSelectedElement();

        selectedElement = el;
        selectedText    = text;

        el.classList.add( 'lp-element-selected' );

        updateTargetLabel();
        setOriginalText( text );
        setTranslationText( '' );
        clearMsg();
        showFields( true );
        fetchAndFill( text );

        setTimeout( function () {
            const ta = document.getElementById( 'lp-sidebar-trans' );
            if ( ta ) ta.focus();
        }, 80 );

        const sidebar = document.getElementById( 'lp-sidebar' );
        if ( sidebar ) sidebar.scrollTop = 0;
    }

    function clearSelectedElement() {
        if ( selectedElement ) {
            selectedElement.classList.remove( 'lp-element-selected' );
            selectedElement = null;
            selectedText    = '';
        }
    }

    function fetchAndFill( originalText ) {
        const ta = document.getElementById( 'lp-sidebar-trans' );
        if ( ta ) ta.placeholder = 'Loading…';

        const fd = new FormData();
        fd.append( 'action',   'lp_get_translation' );
        fd.append( 'nonce',    cfg.nonce );
        fd.append( 'original', originalText );

        fetch( cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } )
            .then( function ( r ) {
                if ( ! r.ok ) throw new Error( 'HTTP ' + r.status );
                return r.json();
            } )
            .then( function ( res ) {
                if ( ta ) ta.placeholder = 'Enter translation…';
                if ( ! res.success ) return;
                setTranslationText( ( res.data.translations || {} )[ targetLang ] || '' );
            } )
            .catch( function () {
                if ( ta ) ta.placeholder = 'Enter translation…';
            } );
    }

    function doSave() {
        const translated = ( document.getElementById( 'lp-sidebar-trans' )?.value || '' ).trim();
        const saveBtn    = document.getElementById( 'lp-sidebar-save' );

        if ( ! selectedText ) {
            showMsg( 'No text selected.', 'error' );
            return;
        }

        if ( ! translated ) {
            showMsg( 'Please enter a translation.', 'error' );
            return;
        }

        if ( saveBtn ) {
            saveBtn.disabled    = true;
            saveBtn.textContent = '…';
        }

        const fd = new FormData();
        fd.append( 'action',     'lp_save_translation' );
        fd.append( 'nonce',      cfg.nonce );
        fd.append( 'original',   selectedText );
        fd.append( 'lang',       targetLang );
        fd.append( 'translated', translated );
        fd.append( 'status',     'active' );

        fetch( cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } )
            .then( function ( r ) {
                if ( ! r.ok ) throw new Error( 'HTTP ' + r.status );
                return r.json();
            } )
            .then( function ( res ) {
                if ( res.success ) {
                    showMsg( '✓ Saved!', 'success' );
                } else {
                    showMsg( res.data?.message || 'Error saving.', 'error' );
                }
            } )
            .catch( function () {
                showMsg( 'Network error. Please try again.', 'error' );
            } )
            .finally( function () {
                if ( saveBtn ) {
                    saveBtn.disabled    = false;
                    saveBtn.textContent = 'Save';
                }
            } );
    }

} )();

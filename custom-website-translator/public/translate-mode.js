/* global CWT_Translate */
( function () {
    'use strict';

    if ( typeof CWT_Translate === 'undefined' ) return;

    const cfg = CWT_Translate;

    // Tags komplett überspringen
    const SKIP_TAGS = new Set( [
        'script', 'style', 'noscript', 'code', 'pre',
        'textarea', 'iframe', 'svg', 'path', 'input',
        'select', 'option', 'meta', 'link', 'head',
    ] );

    // Ziel-Elemente für Stift-Icons
    const BLOCK_SEL = 'p, h1, h2, h3, h4, h5, h6, li, td, th, dt, dd, figcaption, blockquote';
    const LEAF_SEL  = 'a, button, label';

    // Sprachdaten
    const LANG_META = {
        de: { label: 'Deutsch',    flag: '🇩🇪' },
        en: { label: 'English',    flag: '🇬🇧' },
        uk: { label: 'Українська', flag: '🇺🇦' },
        fr: { label: 'Français',   flag: '🇫🇷' },
        es: { label: 'Español',    flag: '🇪🇸' },
        it: { label: 'Italiano',   flag: '🇮🇹' },
        tr: { label: 'Türkçe',     flag: '🇹🇷' },
        pl: { label: 'Polski',     flag: '🇵🇱' },
    };

    // Status
    let modeActive      = sessionStorage.getItem( 'cwt_translate_mode' ) === '1';
    let selectedElement = null;
    let selectedText    = '';
    let targetLang      = getFirstTargetLang();

    // -----------------------------------------------------------------------
    // Boot
    // -----------------------------------------------------------------------
    document.addEventListener( 'DOMContentLoaded', function () {
        buildToolbar();
        if ( modeActive ) activateMode();
    } );

    // -----------------------------------------------------------------------
    // Erste verfügbare Zielsprache ermitteln
    // -----------------------------------------------------------------------
    function getFirstTargetLang() {
        const active  = cfg.activeLangs  || [ 'de', 'en', 'uk' ];
        const defLang = cfg.defaultLang  || 'de';
        return active.find( function ( l ) { return l !== defLang; } ) || 'en';
    }

    // -----------------------------------------------------------------------
    // Toolbar-Button
    // -----------------------------------------------------------------------
    function buildToolbar() {
        const bar = document.createElement( 'div' );
        bar.id = 'cwt-toolbar';
        bar.setAttribute( 'translate', 'no' );

        const btn = document.createElement( 'button' );
        btn.id   = 'cwt-mode-toggle';
        btn.type = 'button';
        btn.addEventListener( 'click', toggleMode );
        bar.appendChild( btn );

        document.body.appendChild( bar );
        syncToggleLabel();
    }

    function syncToggleLabel() {
        const btn = document.getElementById( 'cwt-mode-toggle' );
        if ( ! btn ) return;
        if ( modeActive ) {
            btn.textContent = '✎ Modus beenden';
            btn.classList.add( 'cwt-mode-btn--active' );
        } else {
            btn.textContent = '✎ Seite übersetzen';
            btn.classList.remove( 'cwt-mode-btn--active' );
        }
    }

    // -----------------------------------------------------------------------
    // Modus umschalten
    // -----------------------------------------------------------------------
    function toggleMode() {
        modeActive = ! modeActive;
        sessionStorage.setItem( 'cwt_translate_mode', modeActive ? '1' : '0' );
        syncToggleLabel();
        if ( modeActive ) {
            activateMode();
        } else {
            deactivateMode();
        }
    }

    function activateMode() {
        document.body.classList.add( 'cwt-translate-active' );
        buildSidebar();
        addPencilIcons();
    }

    function deactivateMode() {
        document.body.classList.remove( 'cwt-translate-active' );
        clearSelectedElement();
        removePencilIcons();
        removeSidebar();
    }

    // -----------------------------------------------------------------------
    // Sidebar aufbauen
    // -----------------------------------------------------------------------
    function buildSidebar() {
        if ( document.getElementById( 'cwt-sidebar' ) ) return;

        const active  = cfg.activeLangs  || [ 'de', 'en', 'uk' ];
        const defLang = cfg.defaultLang  || 'de';

        // Sprachoptionen für Select (ohne Standardsprache)
        const options = active
            .filter( function ( l ) { return l !== defLang; } )
            .map( function ( l ) {
                const meta = LANG_META[ l ] || { flag: '', label: l.toUpperCase() };
                return '<option value="' + l + '"' + ( l === targetLang ? ' selected' : '' ) + '>'
                     + meta.flag + ' ' + meta.label
                     + '</option>';
            } )
            .join( '' );

        const defMeta   = LANG_META[ defLang ] || { flag: '', label: defLang.toUpperCase() };
        const fromLabel = defMeta.flag + ' ' + defMeta.label;

        const sidebar = document.createElement( 'div' );
        sidebar.id = 'cwt-sidebar';
        sidebar.setAttribute( 'translate', 'no' );

        sidebar.innerHTML =
            '<div class="cwt-sidebar__header">'
          +     '<span class="cwt-sidebar__title">✎ Translation Editor</span>'
          +     '<button class="cwt-sidebar__close" id="cwt-sidebar-close" type="button" aria-label="Schließen">&times;</button>'
          + '</div>'
          + '<div class="cwt-sidebar__body">'
          +     '<div class="cwt-sidebar__lang-row">'
          +         '<label class="cwt-sidebar__label" for="cwt-target-lang">Zielsprache</label>'
          +         '<select class="cwt-sidebar__select" id="cwt-target-lang">' + options + '</select>'
          +     '</div>'
          +     '<div class="cwt-sidebar__hint" id="cwt-hint">'
          +         'Klicke auf ein <strong>✎</strong> um einen Text zu übersetzen.'
          +     '</div>'
          +     '<div class="cwt-sidebar__fields" id="cwt-fields" style="display:none">'
          +         '<div class="cwt-sidebar__field">'
          +             '<label class="cwt-sidebar__label" id="cwt-from-label">' + fromLabel + ' Originaltext</label>'
          +             '<textarea class="cwt-sidebar__textarea cwt-sidebar__textarea--readonly" id="cwt-sidebar-de" readonly rows="4"></textarea>'
          +             '<span class="cwt-sidebar__label" style="font-size:10px;margin-top:2px">Text</span>'
          +         '</div>'
          +         '<div class="cwt-sidebar__field">'
          +             '<label class="cwt-sidebar__label" id="cwt-to-label">🇬🇧 English</label>'
          +             '<textarea class="cwt-sidebar__textarea" id="cwt-sidebar-trans" rows="4" placeholder="Übersetzung eingeben…"></textarea>'
          +             '<span class="cwt-sidebar__label" style="font-size:10px;margin-top:2px">Text</span>'
          +         '</div>'
          +         '<div class="cwt-sidebar__message" id="cwt-sidebar-msg"></div>'
          +     '</div>'
          + '</div>'
          + '<div class="cwt-sidebar__footer" id="cwt-sidebar-footer" style="display:none">'
          +     '<button class="cwt-sidebar__save-btn" id="cwt-sidebar-save" type="button">Speichern</button>'
          + '</div>';

        document.body.appendChild( sidebar );

        // Events
        document.getElementById( 'cwt-sidebar-close' ).addEventListener( 'click', function () {
            modeActive = false;
            sessionStorage.setItem( 'cwt_translate_mode', '0' );
            syncToggleLabel();
            deactivateMode();
        } );

        document.getElementById( 'cwt-target-lang' ).addEventListener( 'change', function () {
            targetLang = this.value;
            updateTargetLabel();
            // Neue Sprache → vorhandene Übersetzung laden falls Element gewählt
            if ( selectedText ) {
                fetchAndFill( selectedText );
            }
        } );

        document.getElementById( 'cwt-sidebar-save' ).addEventListener( 'click', doSave );
    }

    function removeSidebar() {
        const s = document.getElementById( 'cwt-sidebar' );
        if ( s ) s.remove();
    }

    function updateTargetLabel() {
        const meta  = LANG_META[ targetLang ] || { flag: '', label: targetLang.toUpperCase() };
        const label = document.getElementById( 'cwt-to-label' );
        if ( label ) label.textContent = meta.flag + ' ' + meta.label;
    }

    // -----------------------------------------------------------------------
    // Sidebar-Felder befüllen
    // -----------------------------------------------------------------------
    function showFields( show ) {
        const fields  = document.getElementById( 'cwt-fields' );
        const footer  = document.getElementById( 'cwt-sidebar-footer' );
        const hint    = document.getElementById( 'cwt-hint' );
        if ( fields ) fields.style.display  = show ? 'flex' : 'none';
        if ( footer ) footer.style.display  = show ? 'block' : 'none';
        if ( hint   ) hint.style.display    = show ? 'none' : 'block';
    }

    function setOriginalText( text ) {
        const ta = document.getElementById( 'cwt-sidebar-de' );
        if ( ta ) ta.value = text;
    }

    function setTranslationText( text ) {
        const ta = document.getElementById( 'cwt-sidebar-trans' );
        if ( ta ) ta.value = text;
    }

    function clearMsg() {
        const msg = document.getElementById( 'cwt-sidebar-msg' );
        if ( ! msg ) return;
        msg.textContent = '';
        msg.className   = 'cwt-sidebar__message';
    }

    function showMsg( text, type ) {
        const msg = document.getElementById( 'cwt-sidebar-msg' );
        if ( ! msg ) return;
        msg.textContent = text;
        msg.className   = 'cwt-sidebar__message cwt-sidebar__message--' + type + ' cwt-sidebar__message--visible';
    }

    // -----------------------------------------------------------------------
    // Stift-Icons
    // -----------------------------------------------------------------------
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
            if ( el.querySelector( '.cwt-pencil-btn' ) ) return;

            el.classList.add( 'cwt-has-pencil' );

            const btn = document.createElement( 'button' );
            btn.className = 'cwt-pencil-btn';
            btn.type      = 'button';
            btn.innerHTML = '&#9998;';
            btn.title     = 'Übersetzen';
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
        document.querySelectorAll( '.cwt-pencil-btn' ).forEach( function ( b ) { b.remove(); } );
        document.querySelectorAll( '.cwt-has-pencil' ).forEach( function ( el ) {
            el.classList.remove( 'cwt-has-pencil' );
        } );
    }

    function shouldSkip( el ) {
        if ( el.closest( '#wpadminbar' ) )        return true;
        if ( el.closest( '#cwt-toolbar' ) )        return true;
        if ( el.closest( '#cwt-sidebar' ) )        return true;
        if ( el.closest( '.cwt-switcher' ) )       return true;
        if ( el.getAttribute( 'translate' ) === 'no' )   return true;
        if ( el.closest( '[translate="no"]' ) )           return true;
        if ( SKIP_TAGS.has( el.tagName.toLowerCase() ) )  return true;
        if ( el.parentElement && el.parentElement.closest( '.cwt-has-pencil' ) ) return true;
        return false;
    }

    function getCleanText( el ) {
        const clone = el.cloneNode( true );
        clone.querySelectorAll( '.cwt-pencil-btn' ).forEach( function ( b ) { b.remove(); } );
        return ( clone.innerText || clone.textContent || '' ).trim().replace( /\s+/g, ' ' );
    }

    // -----------------------------------------------------------------------
    // Element auswählen
    // -----------------------------------------------------------------------
    function selectElement( el, text ) {
        // Altes Element abwählen
        clearSelectedElement();

        selectedElement = el;
        selectedText    = text;

        el.classList.add( 'cwt-element-selected' );

        updateTargetLabel();
        setOriginalText( text );
        setTranslationText( '' );
        clearMsg();
        showFields( true );

        // Vorhandene Übersetzung laden
        fetchAndFill( text );

        // Sidebar-Textarea fokussieren
        setTimeout( function () {
            const ta = document.getElementById( 'cwt-sidebar-trans' );
            if ( ta ) ta.focus();
        }, 80 );

        // Sidebar ins Sichtfeld scrollen (mobile)
        const sidebar = document.getElementById( 'cwt-sidebar' );
        if ( sidebar ) sidebar.scrollTop = 0;
    }

    function clearSelectedElement() {
        if ( selectedElement ) {
            selectedElement.classList.remove( 'cwt-element-selected' );
            selectedElement = null;
            selectedText    = '';
        }
    }

    // -----------------------------------------------------------------------
    // AJAX: vorhandene Übersetzung laden
    // -----------------------------------------------------------------------
    function fetchAndFill( originalText ) {
        const ta = document.getElementById( 'cwt-sidebar-trans' );
        if ( ta ) ta.placeholder = 'Lädt…';

        const fd = new FormData();
        fd.append( 'action',   'cwt_get_translation' );
        fd.append( 'nonce',    cfg.nonce );
        fd.append( 'original', originalText );

        fetch( cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } )
            .then( function ( r ) { return r.json(); } )
            .then( function ( res ) {
                if ( ta ) ta.placeholder = 'Übersetzung eingeben…';
                if ( ! res.success ) return;
                const translations = res.data.translations || {};
                setTranslationText( translations[ targetLang ] || '' );
            } )
            .catch( function () {
                if ( ta ) ta.placeholder = 'Übersetzung eingeben…';
            } );
    }

    // -----------------------------------------------------------------------
    // AJAX: Übersetzung speichern
    // -----------------------------------------------------------------------
    function doSave() {
        const translated = ( document.getElementById( 'cwt-sidebar-trans' )?.value || '' ).trim();
        const saveBtn    = document.getElementById( 'cwt-sidebar-save' );

        if ( ! selectedText ) {
            showMsg( 'Kein Text ausgewählt.', 'error' );
            return;
        }

        if ( ! translated ) {
            showMsg( 'Bitte eine Übersetzung eingeben.', 'error' );
            return;
        }

        if ( saveBtn ) {
            saveBtn.disabled    = true;
            saveBtn.textContent = '…';
        }

        const fd = new FormData();
        fd.append( 'action',     'cwt_save_translation' );
        fd.append( 'nonce',      cfg.nonce );
        fd.append( 'original',   selectedText );
        fd.append( 'lang',       targetLang );
        fd.append( 'translated', translated );
        fd.append( 'status',     'active' );

        fetch( cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } )
            .then( function ( r ) { return r.json(); } )
            .then( function ( res ) {
                if ( res.success ) {
                    showMsg( '✓ Gespeichert!', 'success' );
                } else {
                    showMsg( res.data?.message || 'Fehler beim Speichern.', 'error' );
                }
            } )
            .catch( function () {
                showMsg( 'Netzwerkfehler. Bitte erneut versuchen.', 'error' );
            } )
            .finally( function () {
                if ( saveBtn ) {
                    saveBtn.disabled    = false;
                    saveBtn.textContent = 'Speichern';
                }
            } );
    }

} )();

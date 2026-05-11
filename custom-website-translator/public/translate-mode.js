/* global CWT_Translate */
( function () {
    'use strict';

    // Plugin-Daten fehlen → kein Admin oder nicht korrekt eingebunden
    if ( typeof CWT_Translate === 'undefined' ) return;

    const cfg = CWT_Translate;

    // Tags die komplett übersprungen werden
    const SKIP_TAGS = new Set( [
        'script', 'style', 'noscript', 'code', 'pre',
        'textarea', 'iframe', 'svg', 'path', 'input',
        'select', 'option', 'meta', 'link', 'head',
    ] );

    // Block-Elemente, die als Übersetzungseinheit gelten
    const BLOCK_SEL = 'p, h1, h2, h3, h4, h5, h6, li, td, th, dt, dd, figcaption, blockquote';
    // Leaf-Elemente mit eigenem Text
    const LEAF_SEL  = 'a, button, label';

    // Modus-Status aus sessionStorage wiederherstellen
    let modeActive     = sessionStorage.getItem( 'cwt_translate_mode' ) === '1';
    let currentText    = '';
    let currentElement = null;

    // -----------------------------------------------------------------------
    // Boot: nach DOM-Ready
    // -----------------------------------------------------------------------
    document.addEventListener( 'DOMContentLoaded', function () {
        buildToolbar();
        if ( modeActive ) activateMode();
    } );

    // -----------------------------------------------------------------------
    // Toolbar
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
            btn.setAttribute( 'aria-pressed', 'true' );
        } else {
            btn.textContent = '✎ Seite übersetzen';
            btn.classList.remove( 'cwt-mode-btn--active' );
            btn.setAttribute( 'aria-pressed', 'false' );
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
        addPencilIcons();
    }

    function deactivateMode() {
        document.body.classList.remove( 'cwt-translate-active' );
        removePencilIcons();
        closeModal();
    }

    // -----------------------------------------------------------------------
    // Stift-Icons hinzufügen
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
            // Nur Elemente mit Buchstaben (keine reinen Zahlen / Symbole)
            if ( ! /\p{L}/u.test( text ) ) return;
            // Kein doppeltes Icon
            if ( el.querySelector( '.cwt-pencil-btn' ) ) return;

            el.classList.add( 'cwt-has-pencil' );

            const btn = document.createElement( 'button' );
            btn.className = 'cwt-pencil-btn';
            btn.type      = 'button';
            btn.innerHTML = '&#9998;'; // ✎
            btn.title     = 'Text übersetzen';
            btn.setAttribute( 'translate', 'no' );
            btn.setAttribute( 'aria-label', 'Text übersetzen' );

            // Text jetzt erfassen (vor Append), damit Pencil-Text nicht mitläuft
            const capturedText = text;
            btn.addEventListener( 'click', function ( e ) {
                e.preventDefault();
                e.stopPropagation();
                openModal( capturedText, el );
            } );

            el.appendChild( btn );
        } );
    }

    function removePencilIcons() {
        document.querySelectorAll( '.cwt-pencil-btn' ).forEach( b => b.remove() );
        document.querySelectorAll( '.cwt-has-pencil' ).forEach( el =>
            el.classList.remove( 'cwt-has-pencil' )
        );
    }

    function shouldSkip( el ) {
        // Eigene Plugin-UI überspringen
        if ( el.closest( '#wpadminbar' ) )          return true;
        if ( el.closest( '#cwt-toolbar' ) )          return true;
        if ( el.closest( '#cwt-modal-backdrop' ) )   return true;
        if ( el.closest( '.cwt-switcher' ) )         return true;
        // translate="no" respektieren
        if ( el.getAttribute( 'translate' ) === 'no' )       return true;
        if ( el.closest( '[translate="no"]' ) )               return true;
        // Tag überspringen
        if ( SKIP_TAGS.has( el.tagName.toLowerCase() ) )      return true;
        // Kein verschachteltes Stift-Icon
        if ( el.parentElement && el.parentElement.closest( '.cwt-has-pencil' ) ) return true;

        return false;
    }

    /**
     * Sauberen Text eines Elements holen, ohne Stift-Button-Text.
     */
    function getCleanText( el ) {
        const clone = el.cloneNode( true );
        clone.querySelectorAll( '.cwt-pencil-btn' ).forEach( b => b.remove() );
        const raw = clone.innerText || clone.textContent || '';
        return raw.trim().replace( /\s+/g, ' ' );
    }

    // -----------------------------------------------------------------------
    // Modal öffnen
    // -----------------------------------------------------------------------
    function openModal( originalText, el ) {
        currentText    = originalText;
        currentElement = el;
        closeModal(); // Vorheriges Modal entfernen

        const backdrop = document.createElement( 'div' );
        backdrop.id = 'cwt-modal-backdrop';
        backdrop.addEventListener( 'click', function ( e ) {
            if ( e.target === backdrop ) closeModal();
        } );

        // Template
        const modal = document.createElement( 'div' );
        modal.id = 'cwt-modal';
        modal.setAttribute( 'role',       'dialog' );
        modal.setAttribute( 'aria-modal', 'true' );
        modal.setAttribute( 'aria-label', 'Text übersetzen' );
        modal.setAttribute( 'translate',  'no' );

        modal.innerHTML =
            '<div class="cwt-modal__header">' +
                '<h2 class="cwt-modal__title">✎ Text übersetzen</h2>' +
                '<button class="cwt-modal__close" id="cwt-modal-close" type="button" aria-label="Schließen">&times;</button>' +
            '</div>' +
            '<div class="cwt-modal__body">' +
                '<div class="cwt-modal__field">' +
                    '<label class="cwt-modal__label">🇩🇪 Originaltext (Deutsch)</label>' +
                    '<textarea class="cwt-modal__textarea cwt-modal__textarea--readonly" id="cwt-modal-de" readonly rows="3">' + escHtml( originalText ) + '</textarea>' +
                '</div>' +
                '<div class="cwt-modal__field">' +
                    '<label class="cwt-modal__label" for="cwt-modal-en">🇬🇧 Englisch</label>' +
                    '<textarea class="cwt-modal__textarea" id="cwt-modal-en" rows="3" placeholder="Englische Übersetzung…"></textarea>' +
                '</div>' +
                '<div class="cwt-modal__field">' +
                    '<label class="cwt-modal__label" for="cwt-modal-uk">🇺🇦 Ukrainisch</label>' +
                    '<textarea class="cwt-modal__textarea" id="cwt-modal-uk" rows="3" placeholder="Ukrainische Übersetzung…"></textarea>' +
                '</div>' +
                '<div class="cwt-modal__message" id="cwt-modal-msg"></div>' +
            '</div>' +
            '<div class="cwt-modal__footer">' +
                '<button class="cwt-modal__btn cwt-modal__btn--cancel" id="cwt-modal-cancel" type="button">Abbrechen</button>' +
                '<button class="cwt-modal__btn cwt-modal__btn--save"   id="cwt-modal-save"   type="button">Speichern</button>' +
            '</div>';

        backdrop.appendChild( modal );
        document.body.appendChild( backdrop );

        // Events
        modal.querySelector( '#cwt-modal-close' ).addEventListener( 'click', closeModal );
        modal.querySelector( '#cwt-modal-cancel' ).addEventListener( 'click', closeModal );
        modal.querySelector( '#cwt-modal-save' ).addEventListener( 'click', doSave );
        document.addEventListener( 'keydown', onEscapeKey );

        // Vorhandene Übersetzungen laden
        fetchExisting( originalText );

        // Fokus auf EN-Feld
        setTimeout( function () {
            const f = document.getElementById( 'cwt-modal-en' );
            if ( f ) f.focus();
        }, 80 );
    }

    function closeModal() {
        const bd = document.getElementById( 'cwt-modal-backdrop' );
        if ( bd ) bd.remove();
        document.removeEventListener( 'keydown', onEscapeKey );
        currentText    = '';
        currentElement = null;
    }

    function onEscapeKey( e ) {
        if ( e.key === 'Escape' ) closeModal();
    }

    function showMsg( text, type ) {
        const el = document.getElementById( 'cwt-modal-msg' );
        if ( ! el ) return;
        el.textContent = text;
        el.className   = 'cwt-modal__message cwt-modal__message--' + type + ' cwt-modal__message--visible';
    }

    function escHtml( str ) {
        const d = document.createElement( 'div' );
        d.textContent = str;
        return d.innerHTML;
    }

    // -----------------------------------------------------------------------
    // AJAX: vorhandene Übersetzungen laden
    // -----------------------------------------------------------------------
    function fetchExisting( originalText ) {
        const fd = new FormData();
        fd.append( 'action',   'cwt_get_translation' );
        fd.append( 'nonce',    cfg.nonce );
        fd.append( 'original', originalText );

        fetch( cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } )
            .then( function ( r ) { return r.json(); } )
            .then( function ( res ) {
                if ( ! res.success ) return;
                const t  = res.data.translations || {};
                const en = document.getElementById( 'cwt-modal-en' );
                const uk = document.getElementById( 'cwt-modal-uk' );
                if ( en && t.en ) en.value = t.en;
                if ( uk && t.uk ) uk.value = t.uk;
            } )
            .catch( function () {} ); // Stille Fehlerbehandlung
    }

    // -----------------------------------------------------------------------
    // AJAX: Übersetzungen speichern
    // -----------------------------------------------------------------------
    function doSave() {
        const enVal   = ( document.getElementById( 'cwt-modal-en' )?.value || '' ).trim();
        const ukVal   = ( document.getElementById( 'cwt-modal-uk' )?.value || '' ).trim();
        const saveBtn = document.getElementById( 'cwt-modal-save' );

        if ( ! enVal && ! ukVal ) {
            showMsg( 'Bitte mindestens eine Übersetzung eingeben.', 'error' );
            return;
        }

        if ( saveBtn ) {
            saveBtn.disabled    = true;
            saveBtn.textContent = '…';
        }

        const tasks = [];
        if ( enVal ) tasks.push( postTranslation( 'en', enVal ) );
        if ( ukVal ) tasks.push( postTranslation( 'uk', ukVal ) );

        Promise.all( tasks )
            .then( function ( results ) {
                const allOk = results.every( function ( r ) { return r && r.success; } );

                if ( allOk ) {
                    showMsg( '✓ Erfolgreich gespeichert!', 'success' );
                    setTimeout( closeModal, 1600 );
                } else {
                    showMsg( 'Fehler beim Speichern. Bitte erneut versuchen.', 'error' );
                    if ( saveBtn ) {
                        saveBtn.disabled    = false;
                        saveBtn.textContent = 'Speichern';
                    }
                }
            } )
            .catch( function () {
                showMsg( 'Netzwerkfehler. Bitte Seite neu laden.', 'error' );
                if ( saveBtn ) {
                    saveBtn.disabled    = false;
                    saveBtn.textContent = 'Speichern';
                }
            } );
    }

    function postTranslation( lang, translated ) {
        const fd = new FormData();
        fd.append( 'action',     'cwt_save_translation' );
        fd.append( 'nonce',      cfg.nonce );
        fd.append( 'original',   currentText );
        fd.append( 'lang',       lang );
        fd.append( 'translated', translated );
        fd.append( 'status',     'active' );

        return fetch( cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } )
            .then( function ( r ) { return r.json(); } );
    }

} )();

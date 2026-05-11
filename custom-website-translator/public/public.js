/* global CWT */
( function () {
    'use strict';

    // -------------------------------------------------------------------------
    // Dropdown-Interaktion
    // -------------------------------------------------------------------------

    document.addEventListener( 'DOMContentLoaded', function () {
        initSwitchers();
    } );

    function initSwitchers() {
        const switchers = document.querySelectorAll( '.cwt-switcher--dropdown' );

        switchers.forEach( function ( switcher ) {
            const trigger = switcher.querySelector( '.cwt-switcher__current' );
            if ( ! trigger ) return;

            // Toggle beim Klick auf den Trigger
            trigger.addEventListener( 'click', function ( e ) {
                e.stopPropagation();
                const isOpen = switcher.classList.contains( 'cwt-switcher--open' );

                // Alle anderen schließen
                closeAll();

                if ( ! isOpen ) {
                    openSwitcher( switcher );
                }
            } );

            // Tastatur-Navigation
            trigger.addEventListener( 'keydown', function ( e ) {
                if ( e.key === 'Enter' || e.key === ' ' ) {
                    e.preventDefault();
                    trigger.click();
                }
                if ( e.key === 'Escape' ) {
                    closeSwitcher( switcher );
                    trigger.focus();
                }
            } );
        } );

        // Klick außerhalb schließt alle
        document.addEventListener( 'click', closeAll );

        // Escape schließt alle
        document.addEventListener( 'keydown', function ( e ) {
            if ( e.key === 'Escape' ) closeAll();
        } );

        // Links: Sprache per AJAX setzen und dann weiterleiten
        document.querySelectorAll( '.cwt-switcher__link' ).forEach( function ( link ) {
            link.addEventListener( 'click', function ( e ) {
                const lang = link.getAttribute( 'data-lang' );
                if ( ! lang || ! window.CWT ) return;

                // Wenn gleiche Sprache: nichts tun
                if ( lang === CWT.currentLang ) {
                    e.preventDefault();
                    closeAll();
                    return;
                }

                // Cookie per AJAX setzen (non-blocking)
                const formData = new FormData();
                formData.append( 'action', 'cwt_switch_lang' );
                formData.append( 'nonce',  CWT.nonce );
                formData.append( 'lang',   lang );

                // Fire-and-forget – Seite lädt sowieso neu über href
                navigator.sendBeacon( CWT.ajaxUrl, formData );
            } );
        } );
    }

    function openSwitcher( switcher ) {
        switcher.classList.add( 'cwt-switcher--open' );
        switcher.setAttribute( 'aria-expanded', 'true' );

        const list = switcher.querySelector( '.cwt-switcher__list' );
        if ( list ) {
            list.style.display = 'block';
            // Erstes nicht-aktives Element fokussieren
            const firstLink = list.querySelector( '.cwt-switcher__link:not(.cwt-switcher__item--active .cwt-switcher__link)' );
            if ( firstLink ) firstLink.focus();
        }
    }

    function closeSwitcher( switcher ) {
        switcher.classList.remove( 'cwt-switcher--open' );
        switcher.removeAttribute( 'aria-expanded' );
    }

    function closeAll() {
        document.querySelectorAll( '.cwt-switcher--open' ).forEach( closeSwitcher );
    }

    // -------------------------------------------------------------------------
    // Sprach-Cookie auch clientseitig setzen (Fallback, ohne AJAX-Abhängigkeit)
    // -------------------------------------------------------------------------
    function setLangCookie( lang ) {
        const expires = new Date();
        expires.setDate( expires.getDate() + 30 );
        document.cookie = 'cwt_language=' + encodeURIComponent( lang )
            + '; expires=' + expires.toUTCString()
            + '; path=/'
            + ( location.protocol === 'https:' ? '; Secure' : '' )
            + '; SameSite=Lax';
    }

    // Bei direktem Link mit ?cwt_lang=… auch Cookie setzen
    ( function () {
        const params = new URLSearchParams( window.location.search );
        const lang   = params.get( 'cwt_lang' );
        if ( lang ) {
            setLangCookie( lang );
        }
    } )();

} )();

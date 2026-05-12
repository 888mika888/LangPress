/* global LP */
( function () {
    'use strict';

    // -------------------------------------------------------------------------
    // Dropdown-Interaktion
    // -------------------------------------------------------------------------

    document.addEventListener( 'DOMContentLoaded', function () {
        initSwitchers();
    } );

    function initSwitchers() {
        const switchers = document.querySelectorAll( '.lp-switcher--dropdown' );

        switchers.forEach( function ( switcher ) {
            const trigger = switcher.querySelector( '.lp-switcher__current' );
            if ( ! trigger ) return;

            // Toggle beim Klick auf den Trigger
            trigger.addEventListener( 'click', function ( e ) {
                e.stopPropagation();
                const isOpen = switcher.classList.contains( 'lp-switcher--open' );

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
        document.querySelectorAll( '.lp-switcher__link' ).forEach( function ( link ) {
            link.addEventListener( 'click', function ( e ) {
                const lang = link.getAttribute( 'data-lang' );
                if ( ! lang || ! window.LP ) return;

                // Wenn gleiche Sprache: nichts tun
                if ( lang === LP.currentLang ) {
                    e.preventDefault();
                    closeAll();
                    return;
                }

                // Cookie per AJAX setzen (non-blocking)
                const formData = new FormData();
                formData.append( 'action', 'lp_switch_lang' );
                formData.append( 'nonce',  LP.nonce );
                formData.append( 'lang',   lang );

                // Fire-and-forget – Seite lädt sowieso neu über href
                navigator.sendBeacon( LP.ajaxUrl, formData );
            } );
        } );
    }

    function openSwitcher( switcher ) {
        switcher.classList.add( 'lp-switcher--open' );
        switcher.setAttribute( 'aria-expanded', 'true' );

        const list = switcher.querySelector( '.lp-switcher__list' );
        if ( list ) {
            list.style.display = 'block';
            // Erstes nicht-aktives Element fokussieren
            const firstLink = list.querySelector( '.lp-switcher__link:not(.lp-switcher__item--active .lp-switcher__link)' );
            if ( firstLink ) firstLink.focus();
        }
    }

    function closeSwitcher( switcher ) {
        switcher.classList.remove( 'lp-switcher--open' );
        switcher.removeAttribute( 'aria-expanded' );
    }

    function closeAll() {
        document.querySelectorAll( '.lp-switcher--open' ).forEach( closeSwitcher );
    }

    // -------------------------------------------------------------------------
    // Sprach-Cookie auch clientseitig setzen (Fallback, ohne AJAX-Abhängigkeit)
    // -------------------------------------------------------------------------
    function setLangCookie( lang ) {
        const expires = new Date();
        expires.setDate( expires.getDate() + 30 );
        document.cookie = 'lp_language=' + encodeURIComponent( lang )
            + '; expires=' + expires.toUTCString()
            + '; path=/'
            + ( location.protocol === 'https:' ? '; Secure' : '' )
            + '; SameSite=Lax';
    }

    // Bei direktem Link mit ?lp_lang=… auch Cookie setzen
    ( function () {
        const params = new URLSearchParams( window.location.search );
        const lang   = params.get( 'lp_lang' );
        if ( lang ) {
            setLangCookie( lang );
        }
    } )();

} )();

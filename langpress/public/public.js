/* global LP */
( function () {
    'use strict';

    document.addEventListener( 'DOMContentLoaded', function () {
        initSwitchers();
    } );

    function initSwitchers() {
        const switchers = document.querySelectorAll( '.lp-switcher--dropdown' );

        switchers.forEach( function ( switcher ) {
            const trigger = switcher.querySelector( '.lp-switcher__current' );
            if ( ! trigger ) return;

            trigger.addEventListener( 'click', function ( e ) {
                e.stopPropagation();
                const isOpen = switcher.classList.contains( 'lp-switcher--open' );
                closeAll();
                if ( ! isOpen ) openSwitcher( switcher );
            } );

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

        document.addEventListener( 'click', closeAll );
        document.addEventListener( 'keydown', function ( e ) {
            if ( e.key === 'Escape' ) closeAll();
        } );

        document.querySelectorAll( '.lp-switcher__link' ).forEach( function ( link ) {
            link.addEventListener( 'click', function ( e ) {
                const lang = link.getAttribute( 'data-lang' );
                if ( ! lang || ! window.LP ) return;

                if ( lang === LP.currentLang ) {
                    e.preventDefault();
                    closeAll();
                    return;
                }

                // Other languages link to ?lp_lang=xx, and the server sets the
                // language cookie while serving that page, so nothing to do here.
                if ( lang !== LP.defaultLang ) return;

                // The default language uses a clean URL, so the stored cookie has to
                // be reset first or the next page renders in the previous language.
                // A fire-and-forget beacon raced the navigation, so wait for it.
                const formData = new FormData();
                formData.append( 'action', 'lp_switch_lang' );
                formData.append( 'lang',   lang );

                if ( e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey ) {
                    navigator.sendBeacon( LP.ajaxUrl, formData ); // new tab/window: best effort
                    return;
                }

                e.preventDefault();
                let navigated = false;
                const go = function () {
                    if ( navigated ) return;
                    navigated = true;
                    window.location.href = link.href;
                };
                fetch( LP.ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin', keepalive: true } )
                    .then( go, go );
                setTimeout( go, 1500 ); // never strand the visitor on a slow request
            } );
        } );
    }

    function openSwitcher( switcher ) {
        switcher.classList.add( 'lp-switcher--open' );
        switcher.setAttribute( 'aria-expanded', 'true' );

        const list = switcher.querySelector( '.lp-switcher__list' );
        if ( list ) {
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

    // The language cookie is owned by the server (HttpOnly, Secure on HTTPS,
    // COOKIEPATH-scoped). The former client-side fallback only existed because a
    // stray BOM sent output before headers; it wrote an unvalidated value under
    // path=/ and, on sites in a subdirectory, created a second competing cookie.

} )();

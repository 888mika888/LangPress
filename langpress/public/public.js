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

                const formData = new FormData();
                formData.append( 'action', 'lp_switch_lang' );
                formData.append( 'nonce',  LP.nonce );
                formData.append( 'lang',   lang );
                navigator.sendBeacon( LP.ajaxUrl, formData );
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

    // Set the language cookie client-side as a fallback when ?lp_lang= is in the URL.
    ( function () {
        const lang = new URLSearchParams( window.location.search ).get( 'lp_lang' );
        if ( lang ) {
            const expires = new Date();
            expires.setDate( expires.getDate() + 30 );
            document.cookie = 'lp_language=' + encodeURIComponent( lang )
                + '; expires=' + expires.toUTCString()
                + '; path=/'
                + ( location.protocol === 'https:' ? '; Secure' : '' )
                + '; SameSite=Lax';
        }
    } )();

} )();

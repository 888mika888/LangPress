/* global LP_Translate */
( function () {
    'use strict';

    if ( typeof LP_Translate === 'undefined' ) return;

    document.addEventListener( 'DOMContentLoaded', buildFAB );

    function buildFAB() {
        const url = new URL( window.location.href );
        url.searchParams.set( 'lp_translation_editor', '1' );

        const fab = document.createElement( 'a' );
        fab.id   = 'lp-fab';
        fab.href = url.toString();
        fab.setAttribute( 'translate', 'no' );
        fab.setAttribute( 'aria-label', 'Open Translation Editor' );

        const icon = document.createElement( 'span' );
        icon.className = 'lp-fab__icon';
        icon.innerHTML = '&#9998;';
        icon.setAttribute( 'aria-hidden', 'true' );

        const label = document.createElement( 'span' );
        label.className   = 'lp-fab__label';
        label.textContent = 'Translate Page';

        fab.appendChild( icon );
        fab.appendChild( label );
        document.body.appendChild( fab );
    }

} )();

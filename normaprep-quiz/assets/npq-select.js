/**
 * NPQ — Select stylisé (dropdown accessible)
 * ----------------------------------------------------------------------------
 * Améliore progressivement les <select> : le natif reste dans le DOM (donc
 * soumis avec le formulaire, et lu par WordPress comme d'habitude), mais il est
 * masqué visuellement et piloté par un dropdown habillé.
 *
 * Principe « progressive enhancement » : si le JavaScript ne s'exécute pas, le
 * <select> natif reste pleinement fonctionnel. Rien n'est jamais cassé.
 *
 * Synchronisation à double sens :
 *   - clic sur une option  -> met à jour le <select> natif + déclenche 'change'
 *   - 'change' sur le natif -> met à jour l'affichage du dropdown
 * Ainsi, tout code existant qui écoute l'événement 'change' du <select>
 * (par ex. le filtrage des domaines) continue de fonctionner sans modification.
 *
 * Cible : les <select> portant l'attribut data-npq-select, ou tous ceux à
 * l'intérieur d'un conteneur .npq-app (front candidat). On évite volontairement
 * les <select multiple> et ceux marqués data-npq-select="off".
 */
( function () {
    'use strict';

    var SVG_CHEV  = '<svg class="npq-select__chev" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"></path></svg>';
    var SVG_CHECK = '<svg class="npq-select__check" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6L9 17l-5-5"></path></svg>';

    /** Améliore un <select> donné. Sans effet s'il l'est déjà. */
    function ameliorer( selectNatif ) {
        if ( ! selectNatif || selectNatif.dataset.npqEnhanced === '1' ) {
            return;
        }
        // On ne touche pas aux select multiples ni à ceux explicitement exclus.
        if ( selectNatif.multiple || selectNatif.getAttribute( 'data-npq-select' ) === 'off' ) {
            return;
        }

        selectNatif.dataset.npqEnhanced = '1';
        selectNatif.classList.add( 'npq-select-natif--enhanced' );

        // Conteneur du composant, inséré juste après le select natif.
        var wrap = document.createElement( 'div' );
        wrap.className = 'npq-select';

        var trigger = document.createElement( 'button' );
        trigger.type = 'button';
        trigger.className = 'npq-select__trigger';
        trigger.setAttribute( 'aria-haspopup', 'listbox' );
        trigger.setAttribute( 'aria-expanded', 'false' );

        var valeur = document.createElement( 'span' );
        valeur.className = 'npq-select__value';
        trigger.appendChild( valeur );
        trigger.insertAdjacentHTML( 'beforeend', SVG_CHEV );

        var panel = document.createElement( 'div' );
        panel.className = 'npq-select__panel';
        panel.setAttribute( 'role', 'listbox' );

        // Construit une option de dropdown par <option> du select natif.
        Array.prototype.forEach.call( selectNatif.options, function ( optionNative ) {
            var opt = document.createElement( 'div' );
            opt.className = 'npq-select__option';
            opt.setAttribute( 'role', 'option' );
            opt.setAttribute( 'data-value', optionNative.value );
            opt.textContent = optionNative.textContent;
            opt.insertAdjacentHTML( 'beforeend', SVG_CHECK );

            opt.addEventListener( 'click', function () {
                choisir( optionNative.value );
                fermer();
            } );

            panel.appendChild( opt );
        } );

        wrap.appendChild( trigger );
        wrap.appendChild( panel );
        selectNatif.parentNode.insertBefore( wrap, selectNatif.nextSibling );

        /** Applique une valeur au select natif et répercute partout. */
        function choisir( value ) {
            if ( selectNatif.value !== value ) {
                selectNatif.value = value;
                // Déclenche les écouteurs existants (filtrages, etc.).
                selectNatif.dispatchEvent( new Event( 'change', { bubbles: true } ) );
            }
            rafraichir();
        }

        /** Met à jour l'affichage (libellé + coche) depuis le select natif. */
        function rafraichir() {
            var courant = selectNatif.value;
            var libelle = '';

            panel.querySelectorAll( '.npq-select__option' ).forEach( function ( opt ) {
                var actif = ( opt.getAttribute( 'data-value' ) === courant );
                opt.classList.toggle( 'is-selected', actif );
                opt.setAttribute( 'aria-selected', actif ? 'true' : 'false' );
                if ( actif ) {
                    libelle = opt.childNodes[0] ? opt.childNodes[0].nodeValue : opt.textContent;
                }
            } );

            valeur.textContent = libelle;
        }

        function ouvrir() {
            fermerTous();
            wrap.classList.add( 'is-open' );
            trigger.setAttribute( 'aria-expanded', 'true' );
        }
        function fermer() {
            wrap.classList.remove( 'is-open' );
            trigger.setAttribute( 'aria-expanded', 'false' );
        }

        trigger.addEventListener( 'click', function ( e ) {
            e.stopPropagation();
            if ( wrap.classList.contains( 'is-open' ) ) {
                fermer();
            } else {
                ouvrir();
            }
        } );

        // Si un autre code change le select natif, on suit.
        selectNatif.addEventListener( 'change', rafraichir );

        // Clavier minimal : Échap ferme, flèches non gérées (le natif s'en
        // charge si on lui rend le focus — ici on reste simple et robuste).
        trigger.addEventListener( 'keydown', function ( e ) {
            if ( e.key === 'Escape' ) { fermer(); }
        } );

        rafraichir();
    }

    /** Ferme tous les dropdowns ouverts. */
    function fermerTous() {
        document.querySelectorAll( '.npq-select.is-open' ).forEach( function ( w ) {
            w.classList.remove( 'is-open' );
            var t = w.querySelector( '.npq-select__trigger' );
            if ( t ) { t.setAttribute( 'aria-expanded', 'false' ); }
        } );
    }

    // Un clic hors d'un dropdown les ferme tous.
    document.addEventListener( 'click', fermerTous );

    /** Cible : les select data-npq-select, ou ceux dans .npq-app. */
    function ameliorerTous( racine ) {
        var scope = racine || document;

        scope.querySelectorAll( 'select[data-npq-select]' ).forEach( ameliorer );

        scope.querySelectorAll( '.npq-app select, .npq-espace select' ).forEach( function ( sel ) {
            ameliorer( sel );
        } );

        // Le tri du catalogue WooCommerce. Cible par son conteneur, et non
        // par un attribut : WooCommerce genere ce <select> lui-meme, sans
        // offrir de filtre sur ses attributs. Le marquer obligeait a
        // reecrire sa sortie au moment du rendu — une dependance de plus,
        // qui casse en silence le jour ou il change son gabarit.
        scope.querySelectorAll( '.woocommerce-ordering select' ).forEach( function ( sel ) {
            ameliorer( sel );
        } );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', function () { ameliorerTous(); } );
    } else {
        ameliorerTous();
    }

    // Exposé pour ré-améliorer après une injection dynamique de contenu.
    window.NPQSelect = { ameliorer: ameliorer, ameliorerTous: ameliorerTous };
} )();

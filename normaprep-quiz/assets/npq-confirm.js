/**
 * NPQ — Modale de confirmation
 * ----------------------------------------------------------------------------
 * Remplace les confirm() natifs du navigateur par une modale stylisée.
 *
 * Deux usages :
 *
 *  1. AUTOMATIQUE (recommandé) — sur tout lien ou bouton portant l'attribut
 *     data-npq-confirm="message". Au clic, l'action par défaut est suspendue,
 *     la modale s'ouvre, et l'action n'est reprise (suivre le lien, soumettre le
 *     formulaire) que si l'utilisateur confirme.
 *
 *       <a href="…?action=supprimer" data-npq-confirm="Supprimer ce domaine ?">…</a>
 *       <button type="submit" data-npq-confirm="Lancer l'examen ?">…</button>
 *
 *     Attributs optionnels :
 *       data-npq-confirm-title  : titre de la modale (défaut : « Confirmation »)
 *       data-npq-confirm-ok     : libellé du bouton de confirmation (défaut : « Confirmer »)
 *       data-npq-confirm-danger : présent = variante orange (action destructrice)
 *
 *  2. PROGRAMMATIQUE — pour un cas sur-mesure :
 *
 *       window.NPQConfirm.demander({
 *         message: 'Vraiment ?', danger: true,
 *         onConfirm: function () { … }
 *       });
 *
 * Le composant est autonome : une seule modale est créée à la volée et
 * réutilisée. Si le JavaScript ne s'exécute pas, les liens/boutons restent
 * fonctionnels (l'action se fait sans confirmation) — rien n'est bloqué.
 */
( function () {
    'use strict';

    var SVG_ALERTE = '<svg viewBox="0 0 24 24" aria-hidden="true">'
        + '<path d="M12 9v4m0 4h.01M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"></path>'
        + '</svg>';

    var overlay = null;   // l'élément overlay, créé une seule fois
    var boite, icone, titre, corps, btnOk, btnAnnuler;
    var actionEnCours = null; // callback à exécuter si l'utilisateur confirme

    /** Construit la modale (une fois) et la garde en mémoire. */
    function construire() {
        if ( overlay ) {
            return;
        }

        overlay = document.createElement( 'div' );
        overlay.className = 'npq-modal-overlay';
        overlay.setAttribute( 'role', 'dialog' );
        overlay.setAttribute( 'aria-modal', 'true' );

        boite = document.createElement( 'div' );
        boite.className = 'npq-modal-box';

        var divIcone = document.createElement( 'div' );
        divIcone.className = 'npq-modal-icon';
        divIcone.innerHTML = SVG_ALERTE;
        icone = divIcone;

        titre = document.createElement( 'div' );
        titre.className = 'npq-modal-title';

        corps = document.createElement( 'div' );
        corps.className = 'npq-modal-body';

        var actions = document.createElement( 'div' );
        actions.className = 'npq-modal-actions';

        btnAnnuler = document.createElement( 'button' );
        btnAnnuler.type = 'button';
        btnAnnuler.className = 'npq-modal-btn npq-modal-btn--ghost';
        btnAnnuler.textContent = 'Annuler';

        btnOk = document.createElement( 'button' );
        btnOk.type = 'button';
        btnOk.className = 'npq-modal-btn npq-modal-btn--confirm';
        btnOk.textContent = 'Confirmer';

        actions.appendChild( btnAnnuler );
        actions.appendChild( btnOk );

        boite.appendChild( divIcone );
        boite.appendChild( titre );
        boite.appendChild( corps );
        boite.appendChild( actions );
        overlay.appendChild( boite );
        document.body.appendChild( overlay );

        // Fermeture : Annuler, clic sur le fond, ou touche Échap.
        btnAnnuler.addEventListener( 'click', fermer );
        overlay.addEventListener( 'click', function ( e ) {
            if ( e.target === overlay ) { fermer(); }
        } );
        document.addEventListener( 'keydown', function ( e ) {
            if ( e.key === 'Escape' && overlay.classList.contains( 'is-open' ) ) {
                fermer();
            }
        } );

        // Confirmation : on ferme puis on exécute l'action mémorisée.
        btnOk.addEventListener( 'click', function () {
            var action = actionEnCours;
            fermer();
            if ( typeof action === 'function' ) {
                action();
            }
        } );
    }

    function fermer() {
        actionEnCours = null;
        if ( overlay ) {
            overlay.classList.remove( 'is-open' );
        }
    }

    /**
     * Ouvre la modale.
     * @param {Object} opts  message, title, ok, danger, onConfirm
     */
    function demander( opts ) {
        opts = opts || {};
        construire();

        titre.textContent = opts.title || 'Confirmation';
        corps.textContent = opts.message || 'Confirmer cette action ?';
        btnOk.textContent = opts.ok || 'Confirmer';

        boite.classList.toggle( 'is-danger', !! opts.danger );

        actionEnCours = ( typeof opts.onConfirm === 'function' ) ? opts.onConfirm : null;

        // Ouverture au prochain frame, pour laisser la transition jouer.
        requestAnimationFrame( function () {
            overlay.classList.add( 'is-open' );
            btnOk.focus();
        } );
    }

    /** Interception automatique des éléments data-npq-confirm. */
    document.addEventListener( 'click', function ( e ) {
        var el = e.target.closest ? e.target.closest( '[data-npq-confirm]' ) : null;
        if ( ! el ) {
            return;
        }

        // On suspend l'action le temps de la confirmation.
        e.preventDefault();
        e.stopPropagation();

        var danger = el.hasAttribute( 'data-npq-confirm-danger' );

        demander( {
            message: el.getAttribute( 'data-npq-confirm' ) || 'Confirmer cette action ?',
            title:   el.getAttribute( 'data-npq-confirm-title' ) || ( danger ? 'Attention' : 'Confirmation' ),
            ok:      el.getAttribute( 'data-npq-confirm-ok' ) || 'Confirmer',
            danger:  danger,
            onConfirm: function () {
                reprendre( el );
            }
        } );
    }, true ); // capture : on intercepte avant les autres écouteurs

    /**
     * Reprend l'action initiale de l'élément une fois la confirmation obtenue :
     *  - un lien  -> on suit son href ;
     *  - un bouton/entrée de formulaire -> on soumet le formulaire ;
     *  - sinon    -> on rejoue un clic « propre » (sans ré-intercepter).
     */
    function reprendre( el ) {
        // Lien.
        if ( el.tagName === 'A' && el.href ) {
            window.location.href = el.href;
            return;
        }

        // Élément appartenant à un formulaire.
        var form = el.form || ( el.closest ? el.closest( 'form' ) : null );
        if ( form ) {
            // Si l'élément est un bouton nommé, WordPress peut attendre sa valeur.
            if ( el.name ) {
                var cache = document.createElement( 'input' );
                cache.type = 'hidden';
                cache.name = el.name;
                cache.value = el.value || '';
                form.appendChild( cache );
            }
            if ( typeof form.requestSubmit === 'function' ) {
                form.requestSubmit();
            } else {
                form.submit();
            }
            return;
        }

        // Cas générique : rejouer un clic en retirant temporairement l'attribut
        // pour ne pas re-déclencher la modale.
        var attr = el.getAttribute( 'data-npq-confirm' );
        el.removeAttribute( 'data-npq-confirm' );
        el.click();
        el.setAttribute( 'data-npq-confirm', attr );
    }

    // API publique.
    window.NPQConfirm = { demander: demander };
} )();

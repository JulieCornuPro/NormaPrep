/**
 * Repli de la barre latérale de l'espace membre NormaPrep.
 *
 * Le bouton « Réduire le menu » bascule la barre en mode icônes seules.
 * Le choix est mémorisé localement (préférence d'affichage), pour que la barre
 * reste dans l'état voulu en naviguant d'une page à l'autre de l'espace.
 */
(function () {
    'use strict';

    var CLE = 'npq_sidebar_repliee';
    var shell = document.querySelector('.npq-app .shell');
    var bouton = document.getElementById('npqCollapseToggle');

    if (!shell) {
        return;
    }

    // Restaure l'état mémorisé au chargement.
    try {
        if (localStorage.getItem(CLE) === '1') {
            shell.classList.add('collapsed');
            majLibelle(true);
        }
    } catch (e) {
        // localStorage indisponible (navigation privée stricte) : on ignore.
    }

    if (bouton) {
        bouton.addEventListener('click', function () {
            var replie = shell.classList.toggle('collapsed');
            majLibelle(replie);
            try {
                localStorage.setItem(CLE, replie ? '1' : '0');
            } catch (e) {
                // Mémorisation impossible : le repli fonctionne quand même.
            }
        });
    }

    // Adapte le texte du bouton selon l'état.
    function majLibelle(replie) {
        if (!bouton) { return; }
        var lbl = bouton.querySelector('.lbl');
        if (lbl) {
            lbl.textContent = replie ? 'Déplier le menu' : 'Réduire le menu';
        }
        bouton.setAttribute('aria-expanded', replie ? 'false' : 'true');
    }
})();

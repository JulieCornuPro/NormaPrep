/**
 * Page Activité NormaPrep : alimente les composants dynamiques du thème
 * (bibliothèque Carto) avec les vraies données du candidat.
 *
 * On ne réinvente pas de graphiques : le thème fournit déjà sparkline, barChart,
 * gauge… avec leurs animations. On se contente de leur passer les données.
 */
(function () {
    'use strict';

    // La bibliothèque du thème doit être chargée.
    if (typeof Carto === 'undefined') {
        return;
    }

    dessinerProgression();

    /**
     * Courbe de progression : les scores des derniers examens.
     * Utilise le composant « sparkline » du thème (tracé animé, zone remplie).
     */
    function dessinerProgression() {
        var el = document.getElementById('npq-courbe-progression');
        if (!el) {
            return;
        }

        var scores = lireDonnees(el, 'scores');
        if (!scores || scores.length === 0) {
            return;
        }

        // Une seule valeur : le sparkline a besoin d'au moins deux points pour
        // tracer une ligne. On duplique le point pour afficher un trait plat.
        if (scores.length === 1) {
            scores = [scores[0], scores[0]];
        }

        Carto.sparkline(el, {
            points: scores,
            width: 720,
            // Hauteur volontairement contenue : sur une page de KPI, on veut
            // embrasser plusieurs indicateurs d'un coup d'œil, pas scroller.
            height: 120,
            color: Carto.colors.TEAL
        });
    }

    /** Lit un tableau JSON depuis un attribut data- de l'élément. */
    function lireDonnees(el, nom) {
        var brut = el.getAttribute('data-' + nom);
        if (!brut) {
            return null;
        }
        try {
            return JSON.parse(brut);
        } catch (e) {
            return null;
        }
    }
})();

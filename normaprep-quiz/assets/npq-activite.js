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
    dessinerPointsFaibles();
    dessinerVolume();

    /**
     * Volume de travail : compteurs qui s'incrémentent à l'apparition.
     * Utilise le composant « counter » du thème.
     */
    function dessinerVolume() {
        var compteurs = document.querySelectorAll('.npq-compteur');
        if (!compteurs.length) {
            return;
        }

        compteurs.forEach(function (el) {
            var valeur = parseInt(el.getAttribute('data-valeur'), 10);
            if (isNaN(valeur)) {
                return;
            }

            Carto.counter(el, {
                value: valeur,
                suffix: el.getAttribute('data-suffixe') || '',
                label: el.getAttribute('data-libelle') || '',
                color: Carto.colors.TEAL
            });
        });
    }

    /**
     * Points faibles : taux de réussite par domaine, en barres.
     * Utilise le composant « barChart » du thème (barres montantes, valeurs animées).
     * Les domaines sous le seuil sont en orange, les autres en teal.
     */
    function dessinerPointsFaibles() {
        var el = document.getElementById('npq-barres-domaines');
        if (!el) {
            return;
        }

        var domaines = lireDonnees(el, 'domaines');
        if (!domaines || domaines.length === 0) {
            return;
        }

        var data = domaines.map(function (d) {
            return {
                label: d.label,
                value: d.value,
                color: d.faible ? Carto.colors.ORANGE : Carto.colors.TEAL
            };
        });

        Carto.barChart(el, {
            data: data,
            max: 100,      // un taux va de 0 à 100 %
            unit: '%',
            height: 220,
            gap: 20
        });
    }

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

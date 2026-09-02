/**
 * Page Activité NormaPrep : alimente les composants dynamiques du thème
 * (bibliothèque Carto) avec les vraies données du candidat.
 *
 * Les barres des points faibles viennent du thème : le composant fait
 * exactement ce qu'on lui demande. La courbe de progression et le calage du
 * calendrier, eux, sont traités ici — voir leurs commentaires respectifs.
 */
(function () {
    'use strict';

    var EASE = 'cubic-bezier(0.16,1,0.3,1)';
    var NS = 'http://www.w3.org/2000/svg';

    // Les couleurs sont lues sur la coquille de l'espace membre plutôt que
    // recopiées : elles y sont déjà définies en jetons, et deux listes de
    // couleurs finissent toujours par diverger.
    var COUL = lireCouleurs();

    // La courbe ne dépend d'aucune bibliothèque : elle est tracée même si le
    // thème n'a pas chargé la sienne.
    dessinerProgression();
    calerCalendrier();

    if (typeof Carto !== 'undefined') {
        dessinerPointsFaibles();
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
     *
     * Tracée ici plutôt que par Carto.sparkline. Un sparkline est fait pour
     * suggérer une tendance dans un espace minuscule : il n'a ni échelle, ni
     * valeurs, ni repère. Sur cette page il mentait deux fois.
     *
     * D'abord il cadrait l'axe vertical sur le minimum et le maximum des
     * scores, jamais sur 0–100. Le plus bas touchait donc toujours le sol et le
     * plus haut toujours le plafond : 68, 70, 72 dessinait la même envolée que
     * 20, 50, 90. Ensuite il ne montrait pas le seuil de réussite, alors que
     * c'est la seule ligne qui décide de quoi que ce soit pour un candidat.
     *
     * L'échelle est donc fixée à 0–100, le seuil est tracé, et chaque score est
     * écrit à côté de son point.
     */
    function dessinerProgression() {
        var el = document.getElementById('npq-courbe-progression');
        if (!el) {
            return;
        }

        var points = lireDonnees(el, 'points');
        if (!points || points.length === 0) {
            return;
        }

        var seuil = parseInt(el.getAttribute('data-seuil'), 10);
        if (isNaN(seuil)) {
            seuil = 70;
        }

        // Largeur du dernier tracé, pour ne redessiner que si elle a bougé.
        var largeurRendue = 0;
        // L'animation d'entrée ne se joue qu'une fois : un redimensionnement
        // n'est pas une arrivée à l'écran, et rejouer le tracé à chaque cran de
        // la souris donne un graphique qui clignote.
        var dejaAnime = false;
        // Un redessin est déjà programmé pour la prochaine image.
        var redessinPrevu = false;

        tracer();
        surveillerLargeur();

        /**
         * Le graphique est le seul composant de la page dont la largeur est
         * mesurée en pixels à un instant donné : les barres et le calendrier
         * sont en flex et en grille, ils suivent la mise en page d'eux-mêmes.
         * C'est ce qui le rendait seul vulnérable au défaut suivant.
         *
         * L'événement « resize » de la fenêtre est tiré dès que le viewport a
         * fini de changer — mais la barre latérale de l'espace, elle, met
         * encore 300 ms à retrouver sa largeur (transition CSS sur .sidebar).
         * En repassant du format mobile au format bureau, la mesure tombait
         * donc pendant la transition : le conteneur mesurait alors 0, la
         * courbe se redessinait à une largeur fausse, et plus aucun événement
         * ne venait la corriger. Elle restait rétrécie jusqu'au rechargement.
         *
         * ResizeObserver observe la BOÎTE de l'élément et non la fenêtre : il
         * se déclenche à chaque étape de la transition, donc aussi à la
         * dernière. La mesure finale est toujours vue.
         */
        function surveillerLargeur() {
            if (typeof ResizeObserver !== 'undefined') {
                new ResizeObserver(redessinerSiBesoin).observe(el);
                return;
            }
            // Repli pour les navigateurs sans ResizeObserver : l'événement de
            // fenêtre, avec son défaut connu. Mieux vaut une courbe parfois
            // mal dimensionnée qu'une courbe qui ne suit jamais l'écran.
            window.addEventListener('resize', redessinerSiBesoin);
        }

        function redessinerSiBesoin() {
            if (!aRedessiner() || redessinPrevu) {
                return;
            }
            // Une transition CSS déclenche l'observateur à chaque image : on
            // n'en retient qu'un redessin par image, et on remesure au dernier
            // moment. Sans cela, revenir du format mobile au format bureau
            // redessinerait la courbe une soixantaine de fois pour rien.
            //
            // Aucun seuil de tolérance : un premier essai en ignorait les
            // écarts de moins de huit pixels, et c'est le tout dernier
            // ajustement de la barre latérale — six pixels — qui passait alors
            // à la trappe, laissant la courbe légèrement en retrait pour de
            // bon. Ce qu'il fallait écarter, ce n'était pas les petits écarts,
            // c'était la rafale.
            redessinPrevu = true;
            requestAnimationFrame(function () {
                redessinPrevu = false;
                if (aRedessiner()) {
                    tracer();
                }
            });
        }

        /**
         * Une largeur nulle n'est pas une largeur : l'élément est masqué, ou la
         * mise en page n'est pas encore posée. Redessiner là-dessus, c'est ce
         * qui gravait le défaut décrit plus haut.
         */
        function aRedessiner() {
            var largeur = mesurerLargeur();
            return largeur > 0 && largeur !== largeurRendue;
        }

        function mesurerLargeur() {
            return Math.round(el.clientWidth);
        }

        function tracer() {
            // Le viewBox est calé sur la largeur réelle du conteneur pour que
            // ses unités valent des pixels : un dessin de 720 de large réduit à
            // 340 sur un téléphone afficherait des libellés à moitié de leur
            // taille, donc illisibles.
            // On retient la largeur MESURÉE, pas la largeur dessinée : sur un
            // conteneur plus étroit que le minimum de 300, les comparer
            // reviendrait à trouver un écart à chaque fois et à redessiner sans
            // fin. Le repli à 720 ne sert qu'au tout premier tracé, si la page
            // n'est pas encore disposée ; ResizeObserver corrige aussitôt.
            largeurRendue = mesurerLargeur() || 720;
            var W = Math.max(300, largeurRendue);

            var H = 240;
            // Marges : à gauche les graduations (« 100 % »), en bas les dates,
            // en haut la place d'écrire un score au-dessus de son point.
            var mG = 46, mD = 14, mH = 22, mB = 34;
            var x0 = mG, x1 = W - mD, y0 = mH, y1 = H - mB;

            // Échelle FIXE de 0 à 100. C'est elle qui rend deux visites
            // comparables, et un gain de 2 points visiblement petit.
            function y(v) {
                return y1 - (Math.max(0, Math.min(100, v)) / 100) * (y1 - y0);
            }
            function x(i) {
                return points.length === 1
                    ? (x0 + x1) / 2
                    : x0 + i * (x1 - x0) / (points.length - 1);
            }

            var s = creer('svg', {
                viewBox: '0 0 ' + W + ' ' + H,
                width: '100%',
                role: 'img',
                'aria-label': resumeAccessible(points, seuil),
                style: 'display:block'
            });

            // --- Graduations : une ligne tous les 25 points ---
            [0, 25, 50, 75, 100].forEach(function (v) {
                s.appendChild(creer('line', {
                    x1: x0, y1: y(v), x2: x1, y2: y(v),
                    stroke: COUL.border, 'stroke-width': 1
                }));
                // Le seuil écrit dans la même colonne : une graduation trop
                // proche se superposerait à lui. C'est le seuil qui prime.
                if (Math.abs(y(v) - y(seuil)) < 13) {
                    return;
                }
                s.appendChild(texte(x0 - 8, y(v) + 4, v + ' %', {
                    fill: COUL.muted, 'text-anchor': 'end', 'font-size': '11'
                }));
            });

            // --- Ligne de seuil : la seule qui décide de la réussite ---
            s.appendChild(creer('line', {
                x1: x0, y1: y(seuil), x2: x1, y2: y(seuil),
                stroke: COUL.amber, 'stroke-width': 1.5, 'stroke-dasharray': '5 4'
            }));
            // Le seuil est chiffré dans la colonne des graduations, pas posé sur
            // le tracé : partout ailleurs il finissait par recouvrir un score.
            // Et c'est bien sa place — le seuil EST une graduation, celle qui
            // décide. La légende sous le titre dit ce que l'ambre signifie.
            s.appendChild(texte(x0 - 8, y(seuil) + 4, seuil + ' %', {
                fill: COUL.amber, 'text-anchor': 'end', 'font-size': '11',
                'font-weight': '700'
            }));

            // --- Aire et ligne : il faut deux points pour tracer un trait ---
            var aire = null, ligne = null;
            if (points.length > 1) {
                var trace = points.map(function (p, i) {
                    return (i ? 'L' : 'M') + x(i) + ',' + y(p.score);
                }).join(' ');

                aire = creer('path', {
                    d: trace + ' L' + x(points.length - 1) + ',' + y1 + ' L' + x(0) + ',' + y1 + ' Z',
                    fill: COUL.teal, 'fill-opacity': 0,
                    style: 'transition:fill-opacity 1s ' + EASE
                });
                s.appendChild(aire);

                ligne = creer('path', {
                    d: trace, fill: 'none', stroke: COUL.teal, 'stroke-width': 2.5,
                    'stroke-linecap': 'round', 'stroke-linejoin': 'round'
                });
                s.appendChild(ligne);
            }

            // --- Points et scores ---
            // Un score « 78 » tient dans une trentaine de pixels, une date
            // « 31/08 » dans une soixantaine : les deux séries de libellés ne
            // s'espacent donc pas au même rythme.
            var pasScores = pasAffichage(points.length, x1 - x0, 34);
            var pasDates  = pasAffichage(points.length, x1 - x0, 62);

            points.forEach(function (p, i) {
                // Teal au-dessus du seuil, orange en dessous : la couleur dit
                // l'état de CHAQUE examen, ce que la ligne seule ne peut pas.
                var coul = p.score >= seuil ? COUL.teal : COUL.orange;

                s.appendChild(creer('circle', {
                    cx: x(i), cy: y(p.score), r: 4,
                    fill: COUL.surface, stroke: coul, 'stroke-width': 2
                }));

                // Le score passe sous son point quand celui-ci frôle le haut du
                // cadre, sinon il sortirait du dessin. Il s'écrit sans « % » :
                // l'unité est déjà portée par les graduations, et la répéter
                // dix fois double la largeur de chaque libellé — c'est ce qui
                // les faisait se chevaucher sur un téléphone.
                if (garder(i, points.length, pasScores)) {
                    s.appendChild(texte(
                        x(i), y(p.score) + (p.score > 92 ? 18 : -11),
                        String(p.score),
                        { fill: coul, 'text-anchor': ancrage(i, points.length),
                          'font-size': '12', 'font-weight': '700' }
                    ));
                }

                if (garder(i, points.length, pasDates)) {
                    s.appendChild(texte(x(i), H - 12, p.date, {
                        fill: COUL.muted, 'text-anchor': ancrage(i, points.length),
                        'font-size': '11'
                    }));
                }
            });

            el.innerHTML = '';
            el.appendChild(s);

            // L'animation ne peut être réglée qu'une fois le tracé dans le
            // document : getTotalLength() ne répond pas sur un noeud détaché.
            // On mesure la longueur réelle plutôt que d'en deviner une : un
            // chemin plus long que la valeur supposée resterait tronqué.
            if (ligne) {
                var longueur = ligne.getTotalLength();
                ligne.setAttribute('stroke-dasharray', longueur);
                ligne.setAttribute('stroke-dashoffset', longueur);
                ligne.style.transition = 'stroke-dashoffset 1.4s ' + EASE;
            }
            function afficherTrace() {
                if (ligne) {
                    ligne.setAttribute('stroke-dashoffset', 0);
                }
                if (aire) {
                    aire.style.fillOpacity = '0.12';
                }
            }

            if (dejaAnime) {
                // Redessin après changement de taille : le tracé était déjà
                // visible, il doit le rester tout de suite.
                afficherTrace();
            } else {
                auVue(el, function () {
                    dejaAnime = true;
                    afficherTrace();
                });
            }
        }
    }

    /**
     * Le calendrier d'assiduité est rendu par PHP ; il ne lui manque que ceci.
     *
     * Sur un écran étroit, six mois de colonnes débordent et la zone défile.
     * Elle s'ouvre alors sur les semaines les plus anciennes — c'est-à-dire sur
     * ce dont le candidat n'a que faire. On la cale d'emblée sur la semaine en
     * cours, quitte à ce qu'il remonte le temps s'il le souhaite.
     *
     * Et on refait ce calage si la grille se met à déborder plus tard : en
     * passant du format bureau au format mobile, elle n'était calée que par le
     * chargement de la page, donc restée sur mars.
     */
    function calerCalendrier() {
        var el = document.querySelector('.npq-cal-defile');
        if (!el) {
            return;
        }

        var debordait = false;

        caler();

        // Même observateur que la courbe, pour la même raison : la barre
        // latérale met 300 ms à retrouver sa largeur, et un événement de
        // fenêtre serait tiré trop tôt.
        if (typeof ResizeObserver !== 'undefined') {
            new ResizeObserver(caler).observe(el);
        }

        function caler() {
            var deborde = el.scrollWidth > el.clientWidth + 1;

            // On ne recale QUE lorsque la grille se met à déborder. Si elle
            // débordait déjà, le candidat a pu la faire défiler lui-même pour
            // regarder un mois passé : le ramener de force sur aujourd'hui
            // parce qu'il a tourné son téléphone lui reprendrait ce qu'il
            // vient de chercher.
            if (deborde && !debordait) {
                el.scrollLeft = el.scrollWidth;
            }
            debordait = deborde;
        }
    }

    /* ==================================================================
     * Petits outils de tracé
     * ================================================================== */

    function creer(balise, attributs) {
        var e = document.createElementNS(NS, balise);
        for (var k in attributs) {
            if (Object.prototype.hasOwnProperty.call(attributs, k)) {
                e.setAttribute(k, attributs[k]);
            }
        }
        return e;
    }

    /** Un libellé du graphique : toujours en monospace, comme le reste du site. */
    function texte(x, y, contenu, attributs) {
        var e = creer('text', attributs || {});
        e.setAttribute('x', x);
        e.setAttribute('y', y);
        e.setAttribute('font-family', 'Inconsolata, monospace');
        e.textContent = contenu;
        return e;
    }

    /**
     * Déclenche à l'entrée dans l'écran, comme les composants du thème. Sans
     * IntersectionObserver, on joue tout de suite : mieux vaut une courbe sans
     * animation qu'une courbe invisible.
     */
    function auVue(el, cb) {
        if (typeof IntersectionObserver === 'undefined') {
            cb();
            return;
        }
        var io = new IntersectionObserver(function (entrees) {
            entrees.forEach(function (e) {
                if (e.isIntersecting) {
                    cb();
                    io.unobserve(el);
                }
            });
        }, { threshold: 0.25 });
        io.observe(el);
    }

    /**
     * Les jetons de couleur de la coquille (.npq-app). Les valeurs de repli ne
     * servent que si la feuille de style n'a pas encore été appliquée.
     */
    function lireCouleurs() {
        var cible = document.querySelector('.npq-app') || document.documentElement;
        var style = window.getComputedStyle(cible);

        function jeton(nom, repli) {
            var v = style.getPropertyValue(nom);
            return v ? v.trim() || repli : repli;
        }

        return {
            teal:    jeton('--teal', '#00CFCF'),
            orange:  jeton('--orange', '#FF7A50'),
            amber:   jeton('--amber', '#E8B84B'),
            border:  jeton('--border', '#1F2A3D'),
            muted:   jeton('--muted', '#4B5875'),
            surface: jeton('--surface', '#111827')
        };
    }

    /**
     * Combien de points sauter entre deux libellés pour qu'ils ne se
     * chevauchent pas, sachant la largeur disponible et celle d'un libellé.
     */
    function pasAffichage(nb, largeur, largeurLibelle) {
        if (nb <= 1) {
            return 1;
        }
        var tenables = Math.max(1, Math.floor(largeur / largeurLibelle));
        return Math.max(1, Math.ceil(nb / tenables));
    }

    /**
     * Les points extrêmes sont posés pile sur les bords du tracé : un libellé
     * centré y déborderait, à gauche dans la colonne des graduations, à droite
     * hors du dessin. On les aligne donc vers l'intérieur.
     */
    function ancrage(i, nb) {
        if (nb <= 1) {
            return 'middle';
        }
        if (i === 0) {
            return 'start';
        }
        return i === nb - 1 ? 'end' : 'middle';
    }

    /** Ce point porte-t-il un libellé, au rythme donné ? */
    function garder(i, nb, pas) {
        if (nb <= 1 || pas === 1) {
            return true;
        }
        // Le dernier prime sur le rythme : c'est l'examen le plus récent, celui
        // que le candidat vient chercher. Un libellé qui tomberait juste avant
        // lui est donc abandonné plutôt que de s'y coller.
        if (i === nb - 1) {
            return true;
        }
        if (i % pas !== 0) {
            return false;
        }
        return (nb - 1 - i) >= pas;
    }

    /** Résumé lu par les lecteurs d'écran : un SVG ne se raconte pas tout seul. */
    function resumeAccessible(points, seuil) {
        var scores = points.map(function (p) { return p.score + ' %'; }).join(', ');
        return 'Scores des examens, du plus ancien au plus récent : ' + scores
            + '. Seuil de réussite : ' + seuil + ' %.';
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

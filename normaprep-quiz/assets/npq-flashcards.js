/**
 * Flashcards NormaPrep — déroulé côté navigateur.
 *
 * Le paquet est chargé d'un coup : le retournement est instantané, sans aller-retour
 * serveur. Contrairement à un examen, il n'y a rien à protéger — la réponse EST le
 * contenu, le candidat vient précisément pour la voir.
 *
 * Parcours : recto → clic pour retourner → verso → « Je savais » / « À revoir » →
 * carte suivante. À la fin, on propose de rejouer les cartes ratées.
 */
(function () {
    'use strict';

    var conteneur = document.getElementById('npq-flashcards');
    if (!conteneur) {
        return;
    }

    var composer = document.getElementById('npq-fc-composer');
    var session  = document.getElementById('npq-fc-session');
    var form     = document.getElementById('npq-fc-form');
    var donnees  = document.getElementById('npq-fc-donnees');

    if (!composer || !session || !form || !donnees) {
        return;
    }

    // Toutes les cartes disponibles.
    var toutesLesCartes = [];
    try {
        toutesLesCartes = JSON.parse(donnees.textContent) || [];
    } catch (e) {
        return;
    }

    // État de la session en cours.
    var paquet    = [];   // les cartes tirées
    var position  = 0;    // où on en est
    var retournee = false;
    var vues      = [];   // ids des cartes dont on a vu la réponse
    var ratees    = [];   // ids des cartes marquées « à revoir »

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        lancerSession();
    });

    /** Tire les cartes selon les domaines cochés, puis démarre. */
    function lancerSession() {
        var domaines = [];
        form.querySelectorAll('[name="npq_domaines[]"]:checked').forEach(function (c) {
            domaines.push(c.value);
        });

        var champNombre = form.querySelector('[name="npq_nombre"]:checked');
        var nombre = champNombre ? parseInt(champNombre.value, 10) : 10;

        // Filtre par domaine (aucun coché = tout le programme).
        var candidates = toutesLesCartes.filter(function (c) {
            return domaines.length === 0 || domaines.indexOf(c.domaine) !== -1;
        });

        if (candidates.length === 0) {
            return;
        }

        paquet = melanger(candidates).slice(0, nombre);
        demarrer();
    }

    function demarrer() {
        position  = 0;
        retournee = false;
        vues      = [];
        ratees    = [];

        composer.style.display = 'none';
        session.style.display  = '';

        afficherCarte();
    }

    /** Affiche la carte courante et le panneau de suivi. */
    function afficherCarte() {
        var carte = paquet[position];
        if (!carte) {
            afficherFin();
            return;
        }

        retournee = false;

        // Les DEUX faces sont posées dès le départ : le recto visible, le verso
        // retourné derrière. Le clic fait pivoter la carte.
        session.innerHTML =
            '<div class="npq-fc-deux-col">' +

                // --- Colonne principale : la carte ---
                '<div class="npq-fc-principale">' +
                    '<div class="npq-fc-entete">' +
                        '<span class="npq-fc-domaine">' +
                            echapper(carte.domaine) +
                            (carte.libelle ? ' — ' + echapper(carte.libelle) : '') +
                        '</span>' +
                    '</div>' +

                    '<div class="npq-fc-scene" id="npq-fc-scene">' +
                        '<div class="npq-fc-carte" id="npq-fc-carte">' +
                            '<div class="npq-fc-face npq-fc-recto">' +
                                '<div class="npq-fc-label">Question</div>' +
                                '<div class="npq-fc-texte">' + echapper(carte.recto) + '</div>' +
                                '<div class="npq-fc-indice">Cliquez pour retourner</div>' +
                            '</div>' +
                            '<div class="npq-fc-face npq-fc-verso">' +
                                '<div class="npq-fc-label">Réponse</div>' +
                                '<div class="npq-fc-texte">' + echapper(carte.verso) + '</div>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +

                    // Marquage : n'apparaît qu'une fois la réponse vue.
                    '<div class="npq-fc-marquage" id="npq-fc-marquage"></div>' +

                    // Navigation : toujours disponible.
                    '<div class="npq-fc-nav">' +
                        (position > 0
                            ? '<button type="button" class="npq-btn npq-btn-ghost" id="npq-fc-prec">Précédente</button>'
                            : '') +
                        (position < paquet.length - 1
                            ? '<button type="button" class="npq-btn" id="npq-fc-suiv">Suivante</button>'
                            : '') +
                    '</div>' +
                '</div>' +

                // --- Colonne de suivi ---
                '<aside class="npq-fc-suivi">' +
                    panneauSuivi() +
                '</aside>' +

            '</div>';

        var scene = document.getElementById('npq-fc-scene');
        if (scene) {
            scene.addEventListener('click', retourner);
        }

        brancherNavigation();

        // Si on revient sur une carte déjà vue, on montre directement sa réponse.
        if (vues.indexOf(carte.id) !== -1) {
            retourner();
        }
    }

    /** Le panneau de droite : progression, marquées, pastilles, terminer. */
    function panneauSuivi() {
        var pastilles = '';
        paquet.forEach(function (c, i) {
            var classes = 'npq-pastille';
            if (i === position) { classes += ' courante'; }
            if (vues.indexOf(c.id) !== -1) { classes += ' repondue'; }
            if (ratees.indexOf(c.id) !== -1) { classes += ' marquee'; }

            pastilles +=
                '<button type="button" class="' + classes + '" data-pos="' + i + '" ' +
                'title="Carte ' + (i + 1) + (ratees.indexOf(c.id) !== -1 ? ' (à revoir)' : '') + '">' +
                    (i + 1) +
                '</button>';
        });

        var pct = paquet.length > 0
            ? Math.round(vues.length * 100 / paquet.length)
            : 0;

        return '' +
            '<div class="npq-suivi-box">' +
                '<div class="npq-suivi-titre">Progression</div>' +
                '<div class="npq-suivi-ligne">' +
                    '<span class="npq-suivi-lbl">Vues</span>' +
                    '<span class="npq-suivi-val">' + vues.length + ' / ' + paquet.length + '</span>' +
                '</div>' +
                '<div class="npq-suivi-ligne">' +
                    '<span class="npq-suivi-lbl">À revoir</span>' +
                    '<span class="npq-suivi-val marquee">' + ratees.length + '</span>' +
                '</div>' +
                '<div class="npq-barre-progression">' +
                    '<div class="npq-barre-remplie" style="width:' + pct + '%"></div>' +
                '</div>' +
            '</div>' +

            '<div class="npq-suivi-box">' +
                '<div class="npq-suivi-titre">Cartes</div>' +
                '<div class="npq-apercu" id="npq-fc-apercu">' + pastilles + '</div>' +
            '</div>' +

            '<button type="button" class="npq-btn npq-btn-terminer" id="npq-fc-terminer">' +
                'Terminer la session' +
            '</button>';
    }

    /** Branche les boutons de navigation et les pastilles. */
    function brancherNavigation() {
        var prec = document.getElementById('npq-fc-prec');
        if (prec) {
            prec.addEventListener('click', function () {
                if (position > 0) {
                    position--;
                    afficherCarte();
                }
            });
        }

        var suiv = document.getElementById('npq-fc-suiv');
        if (suiv) {
            suiv.addEventListener('click', function () {
                if (position < paquet.length - 1) {
                    position++;
                    afficherCarte();
                }
            });
        }

        var apercu = document.getElementById('npq-fc-apercu');
        if (apercu) {
            apercu.querySelectorAll('.npq-pastille').forEach(function (p) {
                p.addEventListener('click', function () {
                    position = parseInt(p.getAttribute('data-pos'), 10);
                    afficherCarte();
                });
            });
        }

        var terminer = document.getElementById('npq-fc-terminer');
        if (terminer) {
            terminer.addEventListener('click', afficherFin);
        }
    }

    /**
     * Retourne la carte. Fonctionne dans les DEUX SENS : on peut revenir à la
     * question après avoir vu la réponse, autant de fois qu'on veut. Une vraie
     * carte se retourne librement.
     */
    function retourner() {
        var carte = paquet[position];
        var elCarte = document.getElementById('npq-fc-carte');
        if (!elCarte || !carte) {
            return;
        }

        // Bascule : c'est le CSS qui fait pivoter la carte.
        retournee = elCarte.classList.toggle('retournee');

        // Dès qu'elle a été vue une fois, elle compte comme vue — même si on
        // la retourne à nouveau côté question.
        if (vues.indexOf(carte.id) === -1) {
            vues.push(carte.id);
        }

        // Le marquage apparaît dès que la réponse a été vue une fois, et RESTE
        // affiché même si on retourne la carte côté question. Il MARQUE seulement :
        // la navigation reste libre (précédent, suivant, pastilles).
        var marquage = document.getElementById('npq-fc-marquage');
        if (marquage) {
            var estRatee = ratees.indexOf(carte.id) !== -1;

            marquage.innerHTML =
                '<label class="npq-fc-marquer">' +
                    '<input type="checkbox" id="npq-fc-revoir"' + (estRatee ? ' checked' : '') + '>' +
                    ' Marquer cette carte comme « à revoir »' +
                '</label>';

            var caseRevoir = document.getElementById('npq-fc-revoir');
            if (caseRevoir) {
                caseRevoir.addEventListener('change', function () {
                    var i = ratees.indexOf(carte.id);
                    if (caseRevoir.checked && i === -1) {
                        ratees.push(carte.id);
                    } else if (!caseRevoir.checked && i !== -1) {
                        ratees.splice(i, 1);
                    }
                    // Le panneau de suivi se met à jour.
                    rafraichirSuivi();
                });
            }
        }

        rafraichirSuivi();
    }

    /** Met à jour le panneau de droite sans reconstruire la carte. */
    function rafraichirSuivi() {
        var suivi = document.querySelector('.npq-fc-suivi');
        if (!suivi) {
            return;
        }
        suivi.innerHTML = panneauSuivi();
        brancherNavigation();
    }

    /** Fin de session : bilan, et proposition de rejouer les cartes ratées. */
    function afficherFin() {
        var sues = vues.length - ratees.length;
        if (sues < 0) { sues = 0; }

        var html =
            '<div class="npq-fc-fin">' +
                '<h2>Session terminée</h2>' +
                '<div class="npq-fc-bilan">' +
                    '<div class="npq-fc-stat">' +
                        '<span class="npq-fc-stat-val">' + sues + '</span>' +
                        '<span class="npq-fc-stat-lbl">Sues</span>' +
                    '</div>' +
                    '<div class="npq-fc-stat">' +
                        '<span class="npq-fc-stat-val orange">' + ratees.length + '</span>' +
                        '<span class="npq-fc-stat-lbl">À revoir</span>' +
                    '</div>' +
                '</div>';

        if (ratees.length > 0) {
            html +=
                '<p class="npq-fc-conseil">' +
                    'Rejouez les cartes que vous n\'avez pas sues : c\'est en revenant ' +
                    'dessus qu\'on les retient.' +
                '</p>' +
                '<div class="npq-fc-actions">' +
                    '<button type="button" class="npq-btn" id="npq-fc-rejouer">' +
                        'Revoir les ' + ratees.length + ' carte(s) à revoir' +
                    '</button>' +
                    '<button type="button" class="npq-btn npq-btn-ghost" id="npq-fc-retour">' +
                        'Nouvelle session' +
                    '</button>' +
                '</div>';
        } else {
            html +=
                '<p class="npq-fc-conseil">Vous avez su toutes les cartes. Bien joué.</p>' +
                '<div class="npq-fc-actions">' +
                    '<button type="button" class="npq-btn" id="npq-fc-retour">' +
                        'Nouvelle session' +
                    '</button>' +
                '</div>';
        }

        html += '</div>';
        session.innerHTML = html;

        var btnRejouer = document.getElementById('npq-fc-rejouer');
        if (btnRejouer) {
            btnRejouer.addEventListener('click', function () {
                // On ne rejoue QUE les cartes marquées.
                var aRevoir = paquet.filter(function (c) {
                    return ratees.indexOf(c.id) !== -1;
                });
                paquet = melanger(aRevoir);
                demarrer();
            });
        }

        var btnRetour = document.getElementById('npq-fc-retour');
        if (btnRetour) {
            btnRetour.addEventListener('click', function () {
                session.style.display  = 'none';
                composer.style.display = '';
            });
        }
    }

    /* ---- Outils ---- */

    /** Mélange un tableau (Fisher-Yates). */
    function melanger(tableau) {
        var copie = tableau.slice();
        for (var i = copie.length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1));
            var tmp = copie[i];
            copie[i] = copie[j];
            copie[j] = tmp;
        }
        return copie;
    }

    function echapper(texte) {
        var div = document.createElement('div');
        div.textContent = texte;
        return div.innerHTML;
    }
})();

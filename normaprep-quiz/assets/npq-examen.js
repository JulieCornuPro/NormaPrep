/**
 * Navigation de l'examen NormaPrep (AJAX).
 *
 * Le serveur reste maître : à chaque déplacement, on lui envoie la réponse et le
 * marquage de la question quittée, et il renvoie la question demandée (sans jamais
 * les bonnes réponses). Le script affiche, avec une transition douce, sans recharger.
 *
 * Fonctionnalités :
 *   - Navigation libre (précédent, suivant, saut direct via la vue d'ensemble).
 *   - Marquage « à revoir » d'une question.
 *   - Vue d'ensemble tenue à jour (répondue / marquée / courante).
 *
 * Repli : si le JavaScript échoue, les boutons du formulaire fonctionnent toujours
 * par rechargement classique (la logique serveur est identique).
 */
(function () {
    'use strict';

    var zone = document.getElementById('npq-examen-zone');
    if (!zone || typeof NPQ_EXAMEN === 'undefined') {
        return;
    }

    // Quels scénarios le candidat a-t-il dépliés ? On s'en souvient pour ne pas
    // le forcer à replier à chaque question du même scénario.
    var scenariosOuverts = {};
    var scenarioCourant = null;

    // On mémorise l'identifiant de la tentative une fois pour toutes.
    // En mode révision, l'écran de correction remplace le formulaire : il ne faut
    // donc pas dépendre de sa présence pour retrouver cette valeur.
    var tentativeId = '';
    var formInitial = document.getElementById('npq-examen-form');
    if (formInitial) {
        var champ = formInitial.querySelector('[name="npq_tentative"]');
        if (champ) {
            tentativeId = champ.value;
        }
    }

    brancher();

    // Chronomètre : valeur initiale fournie par le serveur dans l'attribut data.
    var boxChrono = document.getElementById('npq-chrono-box');
    if (boxChrono && boxChrono.hasAttribute('data-restant')) {
        demarrerChrono(boxChrono.getAttribute('data-restant'));
    }

    /** (Re)branche les écouteurs sur le contenu courant. */
    function brancher() {
        // Boutons de navigation (précédent / suivant / terminer).
        var boutons = zone.querySelectorAll('[data-dest]');
        boutons.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                aller(btn.getAttribute('data-dest'));
            });
        });

        // Pastilles de la vue d'ensemble (saut direct).
        var pastilles = zone.querySelectorAll('.npq-pastille');
        pastilles.forEach(function (p) {
            p.addEventListener('click', function (e) {
                e.preventDefault();
                aller(p.getAttribute('data-pos'));
            });
        });

        brancherScenario();
    }

    /**
     * Zone scénario repliable : le candidat la déplie pour lire le contexte,
     * la replie une fois lu. L'état est mémorisé par scénario, pour ne pas
     * avoir à replier à chaque question du même scénario.
     */
    function brancherScenario() {
        var bascule = document.getElementById('npq-scen-bascule');
        var corps   = document.getElementById('npq-scen-corps');
        if (!bascule || !corps) {
            return;
        }
        bascule.addEventListener('click', function () {
            var ouvert = corps.classList.toggle('ouvert');
            bascule.textContent = ouvert ? '[ − Replier ]' : '[ + Lire le scénario ]';
            if (scenarioCourant) {
                scenariosOuverts[scenarioCourant] = ouvert;
            }
        });
    }

    /** Envoie la réponse courante au serveur et va à la destination demandée. */
    function aller(destination) {
        var form = document.getElementById('npq-examen-form');
        if (!form) { return; }

        var champPos = form.querySelector('[name="npq_position"]');
        var position = champPos ? champPos.value : '0';
        var marquee  = form.querySelector('#npq-marquee');

        var cochees = [];
        form.querySelectorAll('[name="npq_options[]"]:checked').forEach(function (input) {
            cochees.push(input.value);
        });

        var data = new FormData();
        data.append('action', 'npq_examen_etape');
        data.append('nonce', NPQ_EXAMEN.nonce);
        data.append('tentative', tentativeId);
        data.append('position', position);
        data.append('destination', destination);
        if (marquee && marquee.checked) {
            data.append('marquee', '1');
        }
        cochees.forEach(function (v) { data.append('options[]', v); });

        // Transition : on estompe pendant le chargement.
        zone.style.opacity = '0.4';
        zone.style.pointerEvents = 'none';

        fetch(NPQ_EXAMEN.ajax_url, { method: 'POST', body: data, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (rep) {
                if (!rep || !rep.success) {
                    // Erreur : on laisse le rechargement classique reprendre la main.
                    form.submit();
                    return;
                }
                if (rep.data.termine) {
                    window.__npqChronoFini = true; // plus d'avertissement de fermeture
                    if (window.__npqChronoTimer) {
                        clearInterval(window.__npqChronoTimer);
                        window.__npqChronoTimer = null;
                    }
                    window.location.href = rep.data.url_resultat;
                    return;
                }
                // Mode révision : on montre d'abord la correction de la question
                // qu'on vient de quitter, puis on passe à la suivante.
                if (rep.data.correction) {
                    montrerCorrection(rep.data.correction, function () {
                        afficher(rep.data);
                    });
                    return;
                }
                afficher(rep.data);
            })
            .catch(function () {
                form.submit();
            });
    }

    /**
     * Mode révision : affiche la correction de la question qu'on vient de quitter,
     * avec un bouton pour continuer. Le retour immédiat aide à mémoriser.
     */
    function montrerCorrection(c, continuer) {
        var optionsHtml = '';
        c.options.forEach(function (o) {
            var classes = 'npq-corr-option';
            if (o.correcte) { classes += ' bonne'; }
            if (o.choisie && !o.correcte) { classes += ' erreur'; }
            if (o.choisie) { classes += ' choisie'; }

            var marque = '';
            if (o.correcte && o.choisie)  { marque = ' ✓ (votre réponse)'; }
            else if (o.correcte)          { marque = ' ✓'; }
            else if (o.choisie)           { marque = ' ✗ (votre réponse)'; }

            optionsHtml += '<div class="' + classes + '">' + echapper(o.texte) + marque + '</div>';
        });

        var verdict = c.correcte
            ? '<div class="npq-corr-verdict ok">Correct</div>'
            : '<div class="npq-corr-verdict ko">Incorrect</div>';

        var explication = c.explication
            ? '<div class="npq-corr-explication"><strong>Explication</strong>' + echapper(c.explication) + '</div>'
            : '';

        var html =
            '<div class="npq-correction">' +
                verdict +
                optionsHtml +
                explication +
                '<div class="npq-corr-suite">' +
                    '<button type="button" class="npq-btn" id="npq-continuer">Continuer</button>' +
                '</div>' +
            '</div>';

        var conteneur = document.getElementById('npq-question-contenu');
        if (!conteneur) { continuer(); return; }
        conteneur.innerHTML = html;

        zone.style.opacity = '1';
        zone.style.pointerEvents = 'auto';
        zone.scrollIntoView({ behavior: 'smooth', block: 'start' });

        var btn = document.getElementById('npq-continuer');
        if (btn) {
            btn.addEventListener('click', function () {
                zone.style.opacity = '0.4';
                continuer();
            });
        } else {
            continuer();
        }
    }

    /** Affiche la question reçue, son scénario, et met à jour le suivi. */
    function afficher(d) {
        var q = d.question;
        if (!q) { return; }

        var type = q.multi_reponses ? 'checkbox' : 'radio';
        var dernier = (d.position + 1 >= d.total);

        // --- Scénario de cette question ---
        majScenario(d.scenario);

        // --- Options ---
        var optionsHtml = '';
        q.options.forEach(function (opt) {
            var checked = q.deja.indexOf(opt.id) !== -1 ? 'checked' : '';
            optionsHtml +=
                '<label class="npq-option">' +
                    '<input type="' + type + '" name="npq_options[]" value="' + opt.id + '" ' + checked + '>' +
                    echapper(opt.texte) +
                '</label>';
        });

        // --- Navigation ---
        var navHtml = '';
        if (d.position > 0) {
            navHtml += '<button type="submit" class="npq-btn npq-btn-ghost" data-dest="' + (d.position - 1) + '">Précédente</button>';
        }
        if (!dernier) {
            navHtml += '<button type="submit" class="npq-btn" data-dest="' + (d.position + 1) + '">Suivante</button>';
        } else {
            // Dernière question : un bouton explicite, dans le flux de lecture.
            // Sans lui, le candidat doit deviner qu'il faut chercher « Terminer »
            // dans la colonne de droite — mauvaise accessibilité.
            navHtml += '<button type="submit" class="npq-btn npq-btn-fin" data-dest="terminer">Terminer et voir mon résultat</button>';
        }

        var html =
            '<p class="npq-progression">Question ' + (d.position + 1) + ' / ' + d.total + '</p>' +
            '<div class="npq-enonce">' + echapper(q.enonce) + '</div>' +
            '<form id="npq-examen-form" method="post">' +
                '<input type="hidden" name="npq_examen_action" value="repondre">' +
                '<input type="hidden" name="npq_tentative" value="' + tentativeId + '">' +
                '<input type="hidden" name="npq_position" value="' + d.position + '">' +
                '<input type="hidden" name="npq_destination" id="npq-destination" value="">' +
                optionsHtml +
                '<label class="npq-marquer">' +
                    '<input type="checkbox" name="npq_marquee" id="npq-marquee" value="1"' + (q.marquee ? ' checked' : '') + '>' +
                    ' Marquer cette question pour y revenir' +
                '</label>' +
                '<div class="npq-nav">' + navHtml + '</div>' +
            '</form>';

        var conteneur = document.getElementById('npq-question-contenu');
        if (conteneur) {
            conteneur.innerHTML = html;
        }

        majApercu(d.apercu, d.position);
        majProgression(d.apercu, d.total);

        // Resynchronise le chronomètre sur la valeur du serveur.
        if (d.restant !== null && d.restant !== undefined) {
            demarrerChrono(d.restant);
        }

        brancher();
        zone.style.opacity = '1';
        zone.style.pointerEvents = 'auto';

        // On remonte en haut de la zone d'examen SEULEMENT si elle n'est plus
        // visible. Un scrollIntoView systématique masquait le scénario.
        var haut = zone.getBoundingClientRect().top;
        if (haut < 0) {
            window.scrollBy({ top: haut - 20, behavior: 'smooth' });
        }
    }

    /**
     * Met à jour la zone scénario. Si le candidat avait déplié ce scénario,
     * il reste déplié — on ne le force pas à recliquer à chaque question.
     */
    function majScenario(sc) {
        var box = document.getElementById('npq-scenario-box');
        if (!box) { return; }

        if (!sc) {
            box.style.display = 'none';
            scenarioCourant = null;
            return;
        }

        box.style.display = '';
        scenarioCourant = sc.id;
        var ouvert = !!scenariosOuverts[sc.id];

        var titre = sc.resume ? sc.resume : sc.nom;
        box.innerHTML =
            '<div class="npq-scen-titre">\u2B21 ' + echapper(titre) + '</div>' +
            '<div class="npq-scen-corps' + (ouvert ? ' ouvert' : '') + '" id="npq-scen-corps">' +
                echapper(sc.contexte) +
            '</div>' +
            '<span class="npq-scen-bascule" id="npq-scen-bascule">' +
                (ouvert ? '[ \u2212 Replier ]' : '[ + Lire le scénario ]') +
            '</span>';
    }

    /** Met à jour le suivi de progression (répondues, marquées, barre). */
    function majProgression(apercu, total) {
        if (!apercu) { return; }

        var repondues = 0, marquees = 0;
        apercu.forEach(function (e) {
            if (e.repondue) { repondues++; }
            if (e.marquee)  { marquees++; }
        });

        var elRep = document.getElementById('npq-nb-repondues');
        var elMar = document.getElementById('npq-nb-marquees');
        var elBar = document.getElementById('npq-barre-remplie');

        if (elRep) { elRep.textContent = repondues + ' / ' + total; }
        if (elMar) { elMar.textContent = marquees; }
        if (elBar) { elBar.style.width = (total > 0 ? Math.round(repondues * 100 / total) : 0) + '%'; }
    }

    /** Met à jour les pastilles de la vue d'ensemble. */
    function majApercu(apercu, positionCourante) {
        var conteneur = document.getElementById('npq-apercu');
        if (!conteneur || !apercu) { return; }

        var html = '';
        apercu.forEach(function (etat, i) {
            var classes = 'npq-pastille';
            if (i === positionCourante) { classes += ' courante'; }
            if (etat.repondue) { classes += ' repondue'; }
            if (etat.marquee)  { classes += ' marquee'; }
            var titre = 'Question ' + (i + 1) + (etat.marquee ? ' (à revoir)' : '');
            html += '<button type="button" class="' + classes + '" data-pos="' + i + '" title="' + titre + '">' + (i + 1) + '</button>';
        });
        conteneur.innerHTML = html;
    }


    /* ================= CHRONOMÈTRE ================= */

    /**
     * Le chronomètre stocke son heure de fin DANS LE DOM, pas dans une variable
     * de module.
     *
     * Pourquoi : si le script se retrouve évalué en double (deux fermetures en
     * mémoire), chaque copie a ses propres variables. L'intervalle d'une copie
     * lirait alors une variable vide et n'afficherait rien — c'est exactement le
     * bug observé (« tick ignoré : chronoFinMs null », 25 fois).
     *
     * En stockant l'heure de fin sur l'élément lui-même, toutes les copies lisent
     * la même valeur au même endroit. Le problème devient structurellement
     * impossible, quelle que soit la façon dont le script est chargé.
     */

    /**
     * (Re)synchronise le chronomètre sur la valeur du serveur.
     *
     * NB : on écrit l'identifiant en dur plutôt que d'utiliser une constante de
     * module. Une variable déclarée avec `var` plus bas dans le fichier vaut
     * `undefined` au moment où l'initialisation (en haut) appelle cette fonction :
     * getElementById(undefined) renvoie null, et le chronomètre ne démarrait
     * jamais sur la première question. Piège classique du hoisting.
     *
     * @param {number} secondes Secondes restantes, selon le serveur.
     */
    function demarrerChrono(secondes) {
        var box = document.getElementById('npq-chrono-box');
        if (!box) {
            return; // pas de chronomètre sur cette page (révision)
        }

        if (secondes === null || secondes === undefined || secondes === '') {
            return;
        }

        var valeur = parseInt(secondes, 10);
        if (isNaN(valeur) || valeur <= 0) {
            return;
        }

        // L'heure de fin vit DANS LE DOM : partagée par toutes les copies du script.
        box.dataset.finMs = String(Date.now() + (valeur * 1000));

        // Un seul intervalle global, mémorisé lui aussi hors du module.
        if (window.__npqChronoTimer) {
            clearInterval(window.__npqChronoTimer);
        }

        tickChrono();
        window.__npqChronoTimer = setInterval(tickChrono, 1000);
    }

    /** Un battement : on recalcule le restant depuis l'heure de fin (lue dans le DOM). */
    function tickChrono() {
        var box = document.getElementById('npq-chrono-box');
        if (!box || !box.dataset.finMs) {
            return;
        }

        var finMs = parseInt(box.dataset.finMs, 10);
        if (isNaN(finMs)) {
            return;
        }

        var restant = Math.round((finMs - Date.now()) / 1000);
        if (restant < 0) {
            restant = 0;
        }

        afficherChrono(box, restant);

        if (restant <= 0) {
            if (window.__npqChronoTimer) {
                clearInterval(window.__npqChronoTimer);
                window.__npqChronoTimer = null;
            }
            expirer();
        }
    }

    /**
     * Affiche le temps restant.
     *
     * On REMPLACE le nœud plutôt que de modifier son textContent : dans certains
     * contextes de composition (colonnes collantes, couches GPU), une simple
     * écriture de texte ne déclenche pas toujours le repaint — le DOM est à jour
     * mais l'écran reste figé. Remplacer le nœud force le navigateur à repeindre.
     */
    function afficherChrono(box, restant) {
        var ancien = document.getElementById('npq-chrono-val');
        if (!ancien) { return; }

        var h = Math.floor(restant / 3600);
        var m = Math.floor((restant % 3600) / 60);
        var s = restant % 60;
        var texte = deuxChiffres(h) + ':' + deuxChiffres(m) + ':' + deuxChiffres(s);

        // Nœud neuf : le repaint est garanti.
        var nouveau = document.createElement('div');
        nouveau.className = 'npq-chrono-val';
        nouveau.id = 'npq-chrono-val';
        nouveau.textContent = texte;

        ancien.parentNode.replaceChild(nouveau, ancien);

        // Alerte : orange sous 15 minutes, rouge pulsant sous 5 minutes.
        box.classList.toggle('alerte', restant <= 900 && restant > 300);
        box.classList.toggle('critique', restant <= 300);
    }

    /** Temps écoulé : on remet la copie automatiquement. */
    function expirer() {
        // Le drapeau vit aussi hors du module : une seule soumission, quoi qu'il arrive.
        if (window.__npqChronoFini) { return; }
        window.__npqChronoFini = true;

        var box = document.getElementById('npq-chrono-box');
        if (box) {
            box.classList.add('critique');
        }

        afficherMessageFin();
        aller('terminer');
    }

    /** Message de fin, affiché dans la page (non bloquant). */
    function afficherMessageFin() {
        if (document.querySelector('.npq-banniere-fin')) { return; }
        var banniere = document.createElement('div');
        banniere.className = 'npq-banniere-fin';
        banniere.textContent = 'Le temps imparti est écoulé. Votre copie est remise automatiquement.';
        zone.insertBefore(banniere, zone.firstChild);
    }

    function deuxChiffres(n) {
        return (n < 10 ? '0' : '') + n;
    }


    /* ================= ABANDON ================= */

    /**
     * Un examen qu'on quitte est ABANDONNÉ : pas de score, pas d'échec.
     * (Une révision qu'on quitte n'a aucune conséquence : c'est un entraînement.)
     */

    var estExamen = document.getElementById('npq-chrono-box') !== null;

    brancherAbandon();

    function brancherAbandon() {
        // Bouton « Quitter », avec confirmation : on ne quitte pas par accident.
        var btn = document.getElementById('npq-quitter');
        if (btn) {
            btn.addEventListener('click', function () {
                var ok = confirm(
                    "Quitter l'examen ?\n\n" +
                    "Votre tentative sera ABANDONNÉE : elle n'aura pas de score et " +
                    "apparaîtra comme abandonnée dans votre historique.\n\n" +
                    "Cette action est définitive."
                );
                if (ok) {
                    quitter();
                }
            });
        }

        // Avertissement du navigateur avant fermeture de l'onglet.
        // (Le navigateur impose son propre message : on ne peut pas le choisir.)
        if (estExamen && !window.__npqAbandonEnCours) {
            window.addEventListener('beforeunload', avertirFermeture);
        }

        // Signal d'abandon quand la page est réellement fermée.
        window.addEventListener('pagehide', signalerAbandon);
    }

    function avertirFermeture(e) {
        // Ne pas avertir si l'examen vient d'être terminé ou quitté volontairement.
        if (window.__npqChronoFini || window.__npqQuitte) {
            return;
        }
        e.preventDefault();
        e.returnValue = ''; // requis par les navigateurs
        return '';
    }

    /** Quitter volontairement : on abandonne puis on part. */
    function quitter() {
        window.__npqQuitte = true;
        window.removeEventListener('beforeunload', avertirFermeture);

        envoyerAbandon(function () {
            // Retour à l'espace membre.
            window.location.href = NPQ_EXAMEN.url_espace || '/';
        });
    }

    /**
     * Signal envoyé quand la page se ferme réellement.
     *
     * On utilise sendBeacon : conçu pour ça, il part même pendant la fermeture,
     * là où une requête classique serait annulée par le navigateur.
     *
     * Ce signal est imparfait par nature (un plantage ou une coupure réseau ne
     * l'enverra pas) — d'où le filet côté serveur, qui ferme les examens dont le
     * chronomètre a expiré sans soumission.
     */
    function signalerAbandon() {
        if (!estExamen) { return; }
        if (window.__npqChronoFini || window.__npqQuitte) { return; } // déjà clos

        if (navigator.sendBeacon) {
            var data = new FormData();
            data.append('action', 'npq_examen_abandon');
            data.append('nonce', NPQ_EXAMEN.nonce);
            data.append('tentative', tentativeId);
            navigator.sendBeacon(NPQ_EXAMEN.ajax_url, data);
        }
    }

    /** Envoi de l'abandon (bouton Quitter : on peut attendre la réponse). */
    function envoyerAbandon(apres) {
        var data = new FormData();
        data.append('action', 'npq_examen_abandon');
        data.append('nonce', NPQ_EXAMEN.nonce);
        data.append('tentative', tentativeId);

        fetch(NPQ_EXAMEN.ajax_url, { method: 'POST', body: data, credentials: 'same-origin' })
            .then(function () { apres(); })
            .catch(function () { apres(); });
    }

    function echapper(texte) {
        var div = document.createElement('div');
        div.textContent = texte;
        return div.innerHTML;
    }
})();

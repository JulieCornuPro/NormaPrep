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

        brancher();
        zone.style.opacity = '1';
        zone.style.pointerEvents = 'auto';
        zone.scrollIntoView({ behavior: 'smooth', block: 'start' });
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

    function echapper(texte) {
        var div = document.createElement('div');
        div.textContent = texte;
        return div.innerHTML;
    }
})();

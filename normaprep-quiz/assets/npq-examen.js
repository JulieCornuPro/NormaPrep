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
    }

    /** Envoie la réponse courante au serveur et va à la destination demandée. */
    function aller(destination) {
        var form = document.getElementById('npq-examen-form');
        if (!form) { return; }

        var tentative = form.querySelector('[name="npq_tentative"]').value;
        var position  = form.querySelector('[name="npq_position"]').value;
        var marquee   = form.querySelector('#npq-marquee');

        var cochees = [];
        form.querySelectorAll('[name="npq_options[]"]:checked').forEach(function (input) {
            cochees.push(input.value);
        });

        var data = new FormData();
        data.append('action', 'npq_examen_etape');
        data.append('nonce', NPQ_EXAMEN.nonce);
        data.append('tentative', tentative);
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
                afficher(rep.data);
            })
            .catch(function () {
                form.submit();
            });
    }

    /** Affiche la question reçue et met à jour la vue d'ensemble. */
    function afficher(d) {
        var q = d.question;
        if (!q) { return; }

        var type = q.multi_reponses ? 'checkbox' : 'radio';
        var dernier = (d.position + 1 >= d.total);
        var tentative = document.getElementById('npq-examen-form')
            .querySelector('[name="npq_tentative"]').value;

        // Options.
        var optionsHtml = '';
        q.options.forEach(function (opt) {
            var checked = q.deja.indexOf(opt.id) !== -1 ? 'checked' : '';
            optionsHtml +=
                '<label class="npq-option">' +
                    '<input type="' + type + '" name="npq_options[]" value="' + opt.id + '" ' + checked + '>' +
                    echapper(opt.texte) +
                '</label>';
        });

        // Boutons de navigation.
        var navHtml = '';
        if (d.position > 0) {
            navHtml += '<button type="submit" class="npq-btn npq-btn-ghost" data-dest="' + (d.position - 1) + '">Question précédente</button>';
        }
        if (!dernier) {
            navHtml += '<button type="submit" class="npq-btn" data-dest="' + (d.position + 1) + '">Question suivante</button>';
        }
        navHtml += '<button type="submit" class="npq-btn npq-btn-terminer" data-dest="terminer">Terminer l\'examen</button>';

        var html =
            '<p class="npq-progression">Question ' + (d.position + 1) + ' / ' + d.total + '</p>' +
            '<div class="npq-enonce">' + echapper(q.enonce) + '</div>' +
            '<form id="npq-examen-form" method="post">' +
                '<input type="hidden" name="npq_examen_action" value="repondre">' +
                '<input type="hidden" name="npq_tentative" value="' + tentative + '">' +
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

        brancher();
        zone.style.opacity = '1';
        zone.style.pointerEvents = 'auto';
        zone.scrollIntoView({ behavior: 'smooth', block: 'start' });
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

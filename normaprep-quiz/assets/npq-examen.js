/**
 * Navigation fluide de l'examen NormaPrep (AJAX).
 *
 * Le serveur reste maître : à chaque validation, on lui envoie la réponse et il
 * renvoie la question suivante (sans jamais les bonnes réponses). Le script se
 * contente d'afficher, avec une transition douce, sans recharger la page.
 *
 * Repli : si le JavaScript échoue, le formulaire fonctionne toujours par
 * rechargement classique (la logique serveur est identique).
 */
(function () {
    'use strict';

    // Conteneur de l'examen (présent uniquement en cours d'examen).
    var zone = document.getElementById('npq-examen-zone');
    if (!zone || typeof NPQ_EXAMEN === 'undefined') {
        return; // pas sur la page d'examen, ou données absentes
    }

    var form = document.getElementById('npq-examen-form');
    if (!form) {
        return;
    }

    form.addEventListener('submit', function (e) {
        // On prend la main : pas de rechargement.
        e.preventDefault();
        soumettreEtape();
    });

    function soumettreEtape() {
        var tentative = form.querySelector('[name="npq_tentative"]').value;
        var position = form.querySelector('[name="npq_position"]').value;

        // Options cochées.
        var cochees = [];
        form.querySelectorAll('[name="npq_options[]"]:checked').forEach(function (input) {
            cochees.push(input.value);
        });

        // Prépare les données pour l'appel AJAX.
        var data = new FormData();
        data.append('action', 'npq_examen_etape');
        data.append('nonce', NPQ_EXAMEN.nonce);
        data.append('tentative', tentative);
        data.append('position', position);
        cochees.forEach(function (v) { data.append('options[]', v); });

        // Transition : on estompe pendant le chargement.
        zone.style.opacity = '0.4';
        zone.style.pointerEvents = 'none';

        fetch(NPQ_EXAMEN.ajax_url, { method: 'POST', body: data, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (rep) {
                if (!rep || !rep.success) {
                    // En cas d'erreur, on laisse le rechargement classique reprendre.
                    form.submit();
                    return;
                }
                if (rep.data.termine) {
                    // Fin de l'examen : redirection vers le résultat.
                    window.location.href = rep.data.url_resultat;
                    return;
                }
                afficherQuestion(rep.data);
            })
            .catch(function () {
                // Repli : rechargement classique.
                form.submit();
            });
    }

    function afficherQuestion(d) {
        var q = d.question;
        var type = q.multi_reponses ? 'checkbox' : 'radio';

        // Construit la liste d'options.
        var optionsHtml = '';
        q.options.forEach(function (opt) {
            var checked = d.question.deja.indexOf(opt.id) !== -1 ? 'checked' : '';
            optionsHtml +=
                '<label class="npq-option">' +
                    '<input type="' + type + '" name="npq_options[]" value="' + opt.id + '" ' + checked + '>' +
                    echapper(opt.texte) +
                '</label>';
        });

        var dernier = (d.position + 1 >= d.total);
        var libelleBouton = dernier ? "Terminer l'examen" : 'Question suivante';

        // Reconstruit le contenu de la zone question.
        var html =
            '<p class="npq-progression">Question ' + (d.position + 1) + ' / ' + d.total + '</p>' +
            '<div class="npq-enonce">' + echapper(q.enonce) + '</div>' +
            '<form id="npq-examen-form" method="post">' +
                '<input type="hidden" name="npq_examen_action" value="repondre">' +
                '<input type="hidden" name="npq_tentative" value="' + form.querySelector('[name="npq_tentative"]').value + '">' +
                '<input type="hidden" name="npq_position" value="' + d.position + '">' +
                '<input type="hidden" name="npq_nonce_wp" value="">' +
                optionsHtml +
                '<p style="margin-top:20px"><button type="submit" class="npq-btn">' + libelleBouton + '</button></p>' +
            '</form>';

        // Remplace le contenu de la question (le contexte du scénario reste au-dessus).
        var conteneurQ = document.getElementById('npq-question-contenu');
        if (conteneurQ) {
            conteneurQ.innerHTML = html;
        }

        // Rebranche le nouveau formulaire et restaure l'affichage.
        rebrancher();
        zone.style.opacity = '1';
        zone.style.pointerEvents = 'auto';
        // Petit défilement vers le haut de la question pour le confort.
        zone.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function rebrancher() {
        form = document.getElementById('npq-examen-form');
        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                soumettreEtape();
            });
        }
    }

    // Échappement minimal pour éviter toute injection dans l'affichage.
    function echapper(texte) {
        var div = document.createElement('div');
        div.textContent = texte;
        return div.innerHTML;
    }
})();

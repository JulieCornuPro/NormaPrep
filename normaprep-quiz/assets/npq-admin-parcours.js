/**
 * Sélecteur de questions d'un parcours de révision (admin).
 *
 * Panneau à deux colonnes :
 *   - À GAUCHE : les questions disponibles, filtrables (domaine + recherche).
 *   - À DROITE : les questions du parcours, réordonnables au glisser-déposer.
 *
 * Un clic sur une question disponible l'ajoute à droite ; un clic sur la croix
 * d'une question choisie la renvoie à gauche. L'ordre de la colonne de droite
 * est la position enregistrée : on le sérialise dans un champ caché juste avant
 * l'envoi du formulaire.
 *
 * Les données des questions (id, énoncé, domaine) sont fournies par PHP via
 * wp_localize_script, dans l'objet global NPQ_PARCOURS.
 *
 * Dépendances : jQuery + jQuery UI Sortable (tous deux fournis par WordPress).
 */
( function ( $ ) {
	'use strict';

	// Rien à faire si le bloc n'est pas présent (autre mode, ou pas d'écran parcours).
	if ( typeof NPQ_PARCOURS === 'undefined' ) {
		return;
	}

	var $dispo    = $( '#npq-dispo-liste' );      // colonne gauche
	var $choisies = $( '#npq-choisies-liste' );   // colonne droite (triable)
	var $recherche = $( '#npq-recherche-q' );
	var $filtre    = $( '#npq-filtre-domaine' );
	var $filtreScen = $( '#npq-filtre-scenario' );
	var $champCache = $( '#npq-questions-serialise' );
	var $compteur   = $( '#npq-compteur' );

	if ( ! $dispo.length || ! $choisies.length ) {
		return;
	}

	// Index des questions par id, pour retrouver leurs données rapidement.
	var parId = {};
	NPQ_PARCOURS.questions.forEach( function ( q ) {
		parId[ q.id ] = q;
	} );

	// Ensemble des ids actuellement choisis (pour ne pas les proposer à gauche).
	var choisis = NPQ_PARCOURS.choisies.slice();

	/* ------------------------------------------------------------------ */
	/* Construction d'un élément de liste                                  */
	/* ------------------------------------------------------------------ */

	function texteCourt( enonce ) {
		if ( enonce.length > 120 ) {
			return enonce.slice( 0, 120 ) + '…';
		}
		return enonce;
	}

	// Élément de la colonne GAUCHE (disponible) : cliquable pour ajouter.
	function elementDispo( q ) {
		return $( '<li>', {
			'class': 'npq-q-dispo',
			'data-id': q.id,
			'data-domaine': q.domaine
		} ).append(
			$( '<span>', { 'class': 'npq-q-dom', text: q.domaine } ),
			$( '<span>', { 'class': 'npq-q-texte', text: texteCourt( q.enonce ) } ),
			$( '<button>', {
				type: 'button',
				'class': 'button-link npq-q-ajouter',
				text: 'Ajouter',
				'aria-label': 'Ajouter cette question'
			} )
		);
	}

	// Élément de la colonne DROITE (choisie) : déplaçable + croix pour retirer.
	function elementChoisi( q ) {
		return $( '<li>', {
			'class': 'npq-q-choisie',
			'data-id': q.id
		} ).append(
			$( '<span>', { 'class': 'npq-q-poignee', text: '⠿', title: 'Glisser pour réordonner' } ),
			$( '<span>', { 'class': 'npq-q-dom', text: q.domaine } ),
			$( '<span>', { 'class': 'npq-q-texte', text: texteCourt( q.enonce ) } ),
			$( '<button>', {
				type: 'button',
				'class': 'button-link npq-q-retirer',
				text: '✕',
				'aria-label': 'Retirer cette question'
			} )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Rendu des deux colonnes                                             */
	/* ------------------------------------------------------------------ */

	// Remplit la colonne de droite dans l'ordre de « choisis ».
	function rendreChoisies() {
		$choisies.empty();
		choisis.forEach( function ( id ) {
			var q = parId[ id ];
			if ( q ) {
				$choisies.append( elementChoisi( q ) );
			}
		} );
		majCompteur();
		serialiser();
	}

	// Remplit la colonne de gauche : toutes les questions NON choisies qui
	// passent les filtres domaine + scénario + recherche.
	function rendreDispo() {
		var termeDom = $filtre.val();
		var termeScen = $filtreScen.length ? $filtreScen.val() : '';
		var termeTxt = ( $recherche.val() || '' ).toLowerCase();

		$dispo.empty();

		NPQ_PARCOURS.questions.forEach( function ( q ) {
			if ( choisis.indexOf( q.id ) !== -1 ) {
				return; // déjà à droite
			}
			if ( termeDom && q.domaine !== termeDom ) {
				return;
			}
			// Le scénario est comparé en chaîne : la valeur du <select> est une
			// chaîne, et q.scenario peut être 0 (question sans scénario).
			if ( termeScen && String( q.scenario ) !== termeScen ) {
				return;
			}
			if ( termeTxt && q.enonce.toLowerCase().indexOf( termeTxt ) === -1 ) {
				return;
			}
			$dispo.append( elementDispo( q ) );
		} );

		if ( ! $dispo.children().length ) {
			$dispo.append( $( '<li>', {
				'class': 'npq-q-vide',
				text: 'Aucune question ne correspond.'
			} ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Compteur + sérialisation de l'ordre                                 */
	/* ------------------------------------------------------------------ */

	function majCompteur() {
		if ( $compteur.length ) {
			$compteur.text( choisis.length );
		}
	}

	// Écrit l'ordre courant (ids séparés par des virgules) dans le champ caché.
	// C'est CE champ que PHP lit à l'enregistrement.
	function serialiser() {
		$champCache.val( choisis.join( ',' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Actions : ajouter / retirer                                         */
	/* ------------------------------------------------------------------ */

	// Ajout (clic sur une question de gauche).
	$dispo.on( 'click', '.npq-q-ajouter, .npq-q-dispo', function ( e ) {
		e.preventDefault();
		var id = parseInt( $( this ).closest( 'li' ).attr( 'data-id' ), 10 );
		if ( isNaN( id ) || choisis.indexOf( id ) !== -1 ) {
			return;
		}
		choisis.push( id ); // ajoutée en fin de liste
		rendreChoisies();
		rendreDispo();
	} );

	// Retrait (clic sur la croix à droite).
	$choisies.on( 'click', '.npq-q-retirer', function ( e ) {
		e.preventDefault();
		var id = parseInt( $( this ).closest( 'li' ).attr( 'data-id' ), 10 );
		var i = choisis.indexOf( id );
		if ( i !== -1 ) {
			choisis.splice( i, 1 );
		}
		rendreChoisies();
		rendreDispo();
	} );

	/* ------------------------------------------------------------------ */
	/* Glisser-déposer (jQuery UI Sortable) sur la colonne de droite        */
	/* ------------------------------------------------------------------ */

	$choisies.sortable( {
		handle: '.npq-q-poignee',
		axis: 'y',
		placeholder: 'npq-q-placeholder',
		forcePlaceholderSize: true,
		update: function () {
			// Reconstruit « choisis » dans le nouvel ordre du DOM.
			choisis = $choisies.children( 'li' ).map( function () {
				return parseInt( $( this ).attr( 'data-id' ), 10 );
			} ).get();
			serialiser();
		}
	} );

	/* ------------------------------------------------------------------ */
	/* Filtres de la colonne de gauche                                     */
	/* ------------------------------------------------------------------ */

	$filtre.on( 'change', rendreDispo );
	$filtreScen.on( 'change', rendreDispo );
	$recherche.on( 'input', rendreDispo );

	/* ------------------------------------------------------------------ */
	/* Sécurité : sérialiser une dernière fois juste avant l'envoi          */
	/* ------------------------------------------------------------------ */

	$( '#npq-parcours-form' ).on( 'submit', serialiser );

	// Premier rendu.
	rendreChoisies();
	rendreDispo();

} )( jQuery );

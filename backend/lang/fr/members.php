<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dossiers des adhérents (M04)
    |--------------------------------------------------------------------------
    */

    'regime_change_required' => 'Le régime d\'un dossier validé change par « Changer de régime » (date d\'effet, motif).',
    'justification_required' => 'Joignez une pièce justificative pour modifier : :fields.',
    'regime_unchanged' => 'Choisissez un régime différent du régime actuel.',
    'regime_effective_date_before' => 'La date d\'effet ne peut pas précéder celle du régime actuel (:date).',
    'initial_regime' => 'Régime à l\'inscription',

    'alerts' => [
        'radiation_required' => 'Radiation requise — reclassement au régime réel',
        'fees_overdue' => 'Honoraires impayés (:periods période(s))',
    ],

    'move' => [
        'regime_not_cga' => 'Régime :regime : seul un dossier au RME ou au TEE entre au portefeuille CGA.',
        'default_reason' => 'Signature du contrat CGA',
    ],

    'transfer' => [
        'invalid' => 'Ce réimport est inconnu ou a déjà servi à une inscription.',
        'ncc_mismatch' => 'Le NCC saisi n\'est pas celui du dossier réimporté.',
    ],

    'radiation' => [
        'reason_not_allowed' => 'Ce motif n\'est pas prévu pour une radiation à l\'initiative de : :initiator.',
        'proof_required' => 'Joignez la pièce exigée pour ce motif : :proof.',
        'sp_request_reference_cga_only' => 'La référence de la demande au SP-CGA ne se saisit que pour une radiation à l\'initiative du CGA.',
        'effective_date_before_adhesion' => 'La date d\'effet ne peut pas précéder la date d\'adhésion (:date).',
        'immediate_not_allowed' => 'Seule une radiation à l\'initiative du contribuable ou consécutive à un décès se valide dans la même opération.',
        'proofs' => [
            'transfer_proof' => 'justificatif de transfert',
            'commission_minutes' => 'procès-verbal de la commission',
            'termination_letter' => 'lettre de résiliation',
            'withdrawal_decision' => 'décision de retrait d\'agrément',
        ],
    ],

    'fields' => [
        'company_name' => 'raison sociale',
        'ncc' => 'NCC',
        'regime' => 'régime',
    ],

    // Onglet « Historique » du dossier (F-ADH-03) : éléments, événements, champs et sources.
    'history' => [
        'subjects' => [
            'member' => 'Dossier',
            'revenue' => 'Chiffre d\'affaires',
            'regime_change' => 'Régime',
            'radiation' => 'Radiation',
            'portfolio_movement' => 'Portefeuille',
            'attestation_block' => 'Attestation',
            'piece' => 'Pièce',
        ],
        'events' => [
            'created' => 'Création',
            'updated' => 'Modification',
            'deleted' => 'Suppression',
            'membership_validated' => 'Adhésion validée',
            'radiation_requested' => 'Radiation demandée',
            'radiation_validated' => 'Radiation validée',
            'radiation_cancelled' => 'Radiation annulée',
            'portfolio_moved' => 'Bascule de portefeuille',
            'other' => 'Événement',
        ],
        'fields' => [
            'portfolio' => 'Portefeuille',
            'status' => 'Statut',
            'membership_number' => 'N° d\'adhésion',
            'client_number' => 'N° client',
            'legacy_number' => 'Ancien n°',
            'referrer_id' => 'Apporteur',
            'entity_type' => 'Type',
            'company_name' => 'Raison sociale',
            'sigle' => 'Sigle',
            'legal_form' => 'Forme juridique',
            'share_capital' => 'Capital social',
            'owner_last_name' => 'Nom du dirigeant',
            'owner_first_names' => 'Prénoms du dirigeant',
            'owner_nationality' => 'Nationalité du dirigeant',
            'owner_id_document_type' => 'Type de pièce d\'identité',
            'owner_id_document_number' => 'N° de la pièce d\'identité',
            'ncc' => 'NCC',
            'rccm' => 'RCCM',
            'idu' => 'IDU',
            'regime' => 'Régime',
            'regime_since' => 'Régime depuis le',
            'rsi_since' => 'Au régime réel depuis le',
            'tax_office_id' => 'CDI',
            'activity_code_id' => 'Code activité',
            'activity_code_text' => 'Code activité',
            'sector_id' => 'Secteur',
            'main_activity' => 'Activité principale',
            'address' => 'Siège / localisation',
            'commune_id' => 'Commune',
            'land_section' => 'N° de section',
            'land_parcel' => 'N° de parcelle',
            'registration_date' => 'Date d\'immatriculation',
            'phone' => 'Téléphone',
            'whatsapp' => 'WhatsApp',
            'email' => 'E-mail',
            'signatory_name' => 'Signataire du contrat',
            'signatory_title' => 'Qualité du signataire',
            'employees_count' => 'Effectif salarié',
            'cga_contract_signed_on' => 'Signature du contrat CGA',
            'cga_contract_ended_on' => 'Fin du contrat CGA',
            'adhesion_date' => 'Date d\'adhésion',
            'validated_at' => 'Validé le',
            'entry_fee_amount' => 'Droit d\'adhésion',
            'fees_amount' => 'Honoraires',
            'fees_periodicity' => 'Périodicité des honoraires',
            'pieces_status' => 'Pièces',
            'attestation_blocked' => 'Attestation bloquée',
            'year' => 'Année',
            'amount' => 'Montant',
            'kind' => 'Type',
            'source' => 'Source',
            'effective_date' => 'Date d\'effet',
            'reason' => 'Motif',
            'initiator' => 'Initiateur',
            'reason_other' => 'Précision',
            'observation' => 'Observation',
            'outcome' => 'Issue',
            'validation_reference' => 'Référence de validation',
            'sp_request_reference' => 'Référence de la demande au SP-CGA',
            'piece_type' => 'Pièce',
            'received_at' => 'Reçue le',
            'expires_at' => 'Expire le',
            'trigger' => 'Motif du blocage',
            'blocked_at' => 'Bloquée le',
            'unblocked_at' => 'Débloquée le',
            'direction' => 'Sens',
        ],
        'sealed' => 'Donnée chiffrée : consultable sur la fiche',
        'sources' => [
            'entry' => 'Saisie',
            'import' => 'Import',
            'report' => 'Rapport',
            'compta_flow' => 'Compta Flow',
        ],
    ],

];

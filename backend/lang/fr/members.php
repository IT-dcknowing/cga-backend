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

];

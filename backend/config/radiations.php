<?php

/*
|--------------------------------------------------------------------------
| Radiations du portefeuille CGA (01-PRD F-ADH-06, CR-06)
|--------------------------------------------------------------------------
|
| Motifs permis pour chaque initiateur. Pour chaque motif :
|  - « proof » : pièce justificative obligatoire (code, libellé dans lang/fr/members.php), ou absente ;
|  - « immediate » : la radiation peut être validée dans la même opération que la demande
|    (RG-ADH-17 : radiation initiée par le contribuable ou consécutive à un décès).
| « sp_request_reference » : la référence de la demande au SP-CGA se saisit (radiation initiée par le CGA).
|
*/

return [

    'initiators' => [

        'cga' => [
            'sp_request_reference' => true,
            'reasons' => [
                'zone_change' => ['proof' => 'transfer_proof'],
                'follow_up_impossible' => ['proof' => 'transfer_proof'],
                'serious_breach' => ['proof' => 'commission_minutes'],
                'reclassified_real_regime' => [],
                'cessation' => [],
                'business_sale' => [],
                'death' => ['immediate' => true],
                'other' => [],
            ],
        ],

        'member' => [
            'sp_request_reference' => false,
            'reasons' => [
                'dissatisfaction' => ['proof' => 'termination_letter', 'immediate' => true],
                'firm_change' => ['proof' => 'termination_letter', 'immediate' => true],
                'mutual_termination' => ['proof' => 'termination_letter', 'immediate' => true],
                'other' => ['proof' => 'termination_letter', 'immediate' => true],
            ],
        ],

        'sp_cga' => [
            'sp_request_reference' => false,
            'reasons' => [
                'agreement_loss' => ['proof' => 'withdrawal_decision'],
            ],
        ],

    ],

];

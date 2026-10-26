<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Journal d'activité (02-TRD §6.5, 05-BACKEND-SCHEMA §16)
    |--------------------------------------------------------------------------
    |
    | Chaque requête, Job, tâche planifiée et commande laisse une ligne dans
    | activity_logs. Si l'écriture échoue, la ligne part dans le canal de
    | secours (storage/logs/activity-fallback.log) : l'action de l'utilisateur
    | n'échoue jamais à cause du journal. La liste des champs masqués est le
    | paramètre logs.redacted_fields.
    |
    */

    'fallback_channel' => 'activity_fallback',

    // Taille maximale des paramètres journalisés d'une requête, et d'un texte dans ces paramètres.
    'params_max_bytes' => (int) env('ACTIVITY_PARAMS_MAX_BYTES', 10000),
    'string_max_length' => (int) env('ACTIVITY_STRING_MAX_LENGTH', 500),

    // Boucles d'infrastructure, dont les Jobs et tâches sont journalisés un par un.
    'ignored_commands' => ['schedule:run', 'schedule:work', 'schedule:finish', 'queue:work', 'queue:listen', 'horizon', 'horizon:work'],

    // Durée de conservation (heures) des compteurs d'alertes du jour (refus 403…), lus par la supervision.
    'alerts_ttl_hours' => (int) env('ACTIVITY_ALERTS_TTL_HOURS', 48),

    // Identifiant de corrélation accepté du client (X-Request-Id), sinon un ULID est généré.
    'request_id_pattern' => '/^[A-Za-z0-9._-]{8,40}$/',

];

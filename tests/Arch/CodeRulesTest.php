<?php

/*
 * Règles vérifiées sur tout le code (02-TRD §6.7, §7.6, §13) : variabilisation, sécurité, propreté.
 */

arch('env() n\'est lu que dans config/')
    ->expect('env')
    ->not->toBeUsed();

arch('aucune désérialisation de données (A08)')
    ->expect('unserialize')
    ->not->toBeUsed();

arch('aucune commande système construite à la main (A05)')
    ->expect(['exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'eval'])
    ->not->toBeUsed();

arch('aucun outil de débogage oublié')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die'])
    ->not->toBeUsed();

<?php

/*
 * Le backend est une API seule (02-TRD §3) : pas de page web, erreurs toujours en JSON, aucun
 * fichier du stockage servi directement (02-TRD §6.4).
 */

it('n\'a pas de page web à la racine', function () {
    $this->get('/')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/json');
});

it('répond en JSON à une adresse inconnue, même pour un navigateur', function () {
    $this->get('/page-inconnue', ['Accept' => 'text/html'])
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonStructure(['message']);
});

it('ne sert jamais directement un fichier du stockage', function () {
    $this->get('/storage/orgs/fichier.pdf')->assertNotFound();
});

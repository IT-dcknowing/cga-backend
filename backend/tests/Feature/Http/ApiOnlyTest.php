<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

/**
 * Le backend est une API seule (02-TRD §3) : pas de page web, erreurs toujours en JSON, aucun
 * fichier du stockage servi directement (02-TRD §6.4).
 */
class ApiOnlyTest extends TestCase
{
    public function test_the_root_address_has_no_web_page(): void
    {
        $this->get('/')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_an_unknown_address_answers_json_even_for_a_browser(): void
    {
        $this->get('/page-inconnue', ['Accept' => 'text/html'])
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message']);
    }

    public function test_storage_files_are_never_served_directly(): void
    {
        $this->get('/storage/orgs/fichier.pdf')->assertNotFound();
    }
}

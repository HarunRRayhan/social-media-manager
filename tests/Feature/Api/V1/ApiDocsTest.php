<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the api docs spec is public and documents api v1 with bearer auth', function () {
    $this->getJson('/docs/api.json')
        ->assertOk()
        ->assertJsonPath('info.title', 'Social Media Manager API')
        ->assertJsonStructure(['openapi', 'paths' => ['/posts', '/me', '/social-sets']])
        ->assertJsonPath('components.securitySchemes.bearerAuth.type', 'http');
});

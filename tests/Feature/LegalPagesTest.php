<?php

use Inertia\Testing\AssertableInertia as Assert;

test('the pages Meta links to are public', function (string $page) {
    $this->get("/{$page}")
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('legal')->where('page', $page));
})->with(['privacy', 'terms', 'data-deletion']);

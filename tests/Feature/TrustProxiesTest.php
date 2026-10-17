<?php

test('redirects keep https behind the proxy', function () {
    $this->get('/dashboard', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'sm.harun.dev'])
        ->assertRedirect('https://sm.harun.dev/login');
});

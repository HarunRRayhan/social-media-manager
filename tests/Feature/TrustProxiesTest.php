<?php

test('redirects keep https behind the proxy', function () {
    $this->get('/dashboard', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'sm.harun.dev'])
        ->assertRedirect('https://sm.harun.dev/login');
});

test('a forwarded client ip is not trusted', function () {
    $this->get('/version', ['X-Forwarded-For' => '6.6.6.6']);

    expect(request()->ip())->not->toBe('6.6.6.6');
});

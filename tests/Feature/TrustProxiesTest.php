<?php

test('redirects keep https behind the proxy', function () {
    $this->get('http://sm.harun.dev/dashboard', ['X-Forwarded-Proto' => 'https'])
        ->assertRedirect('https://sm.harun.dev/login');
});

test('a forwarded client ip is not trusted', function () {
    $this->get('/version', ['X-Forwarded-For' => '6.6.6.6']);

    expect(request()->ip())->not->toBe('6.6.6.6');
});

test('a forwarded host is not trusted', function () {
    $this->get('http://sm.harun.dev/dashboard', ['X-Forwarded-Host' => 'evil.example'])
        ->assertRedirect('http://sm.harun.dev/login');
});

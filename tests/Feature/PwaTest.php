<?php

it('RNF-003 links the web app manifest on every page', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false);
});

it('RNF-002 colors the browser bar with the appearance chosen by the user', function (string $aparencia, string $cor) {
    $this->withUnencryptedCookie('appearance', $aparencia)
        ->get(route('login'))
        ->assertSee('<meta name="theme-color" content="'.$cor.'">', false);
})->with([
    'dark' => ['dark', '#0a0a0a'],
    'light' => ['light', '#ffffff'],
]);

it('RNF-002 turns the browser bar dark before the app loads when the system appearance is dark', function () {
    $this->withUnencryptedCookie('appearance', 'system')
        ->get(route('login'))
        ->assertSeeInOrder([
            '<meta name="theme-color" content="#ffffff">',
            'if (prefersDark) {',
            "document.querySelector('meta[name=\"theme-color\"]')?.setAttribute('content', '#0a0a0a');",
        ], false);
});

it('RNF-003 ships an installable manifest with a service worker and no offline cache', function () {
    $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest)
        ->name->toBe('Meu Money')
        ->start_url->toBe('/')
        ->display->toBe('standalone')
        ->and(array_column($manifest['icons'], 'sizes'))->toContain('192x192', '512x512')
        ->and(array_column($manifest['icons'], 'purpose'))->toContain('maskable');

    foreach ($manifest['icons'] as $icone) {
        expect(public_path(ltrim($icone['src'], '/')))->toBeFile();
    }

    expect(file_get_contents(public_path('sw.js')))
        ->not->toContain("addEventListener('fetch'")
        ->not->toContain('caches.');
});

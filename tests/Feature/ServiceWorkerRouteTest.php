<?php

declare(strict_types=1);

test('the service worker is served from a file that ships with the composer package', function () {
    $response = $this->get('/duo-sw.js');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/javascript');
    $response->assertHeader('Service-Worker-Allowed', '/');

    expect($response->getFile()->getPathname())
        ->toEndWith('resources/js/duo/service-worker.js');
});

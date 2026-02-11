<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use JoshCirre\Duo\Http\Middleware\DuoDebugMiddleware;

test('middleware sets transformations enabled by default', function () {
    app()->detectEnvironment(fn () => 'local');
    $middleware = new DuoDebugMiddleware;
    $request = Request::create('/test');

    $middleware->handle($request, function ($req) {
        expect(app()->bound('duo.transformations.enabled'))->toBeTrue();
        expect(app()->make('duo.transformations.enabled'))->toBeTrue();

        return response('ok');
    });
});

test('middleware disables transformations with duo=off query parameter', function () {
    app()->detectEnvironment(fn () => 'local');
    $middleware = new DuoDebugMiddleware;
    $request = Request::create('/test?duo=off');

    $middleware->handle($request, function ($req) {
        expect(app()->bound('duo.transformations.enabled'))->toBeTrue();
        expect(app()->make('duo.transformations.enabled'))->toBeFalse();

        return response('ok');
    });
});

test('middleware enables transformations with duo=on query parameter', function () {
    app()->detectEnvironment(fn () => 'local');
    $middleware = new DuoDebugMiddleware;
    $request = Request::create('/test?duo=on');

    $middleware->handle($request, function ($req) {
        expect(app()->bound('duo.transformations.enabled'))->toBeTrue();
        expect(app()->make('duo.transformations.enabled'))->toBeTrue();

        return response('ok');
    });
});

test('middleware only runs in local environment', function () {
    app()->detectEnvironment(fn () => 'production');

    $middleware = new DuoDebugMiddleware;
    $request = Request::create('/test?duo=off');

    $middleware->handle($request, function ($req) {
        expect(app()->bound('duo.transformations.enabled'))->toBeFalse();

        return response('ok');
    });
});

test('middleware treats any value other than off as enabled', function () {
    app()->detectEnvironment(fn () => 'local');
    $middleware = new DuoDebugMiddleware;
    $request = Request::create('/test?duo=somethingelse');

    $middleware->handle($request, function ($req) {
        expect(app()->bound('duo.transformations.enabled'))->toBeTrue();
        expect(app()->make('duo.transformations.enabled'))->toBeTrue();

        return response('ok');
    });
});

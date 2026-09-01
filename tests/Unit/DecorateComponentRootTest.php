<?php

declare(strict_types=1);

use JoshCirre\Duo\DuoServiceProvider;

beforeEach(function () {
    $this->provider = app(DuoServiceProvider::class);
    $this->meta = ['enabled' => true, 'component' => 'demo', 'models' => [], 'methods' => []];
});

it('adds duo attributes and x-data to a compiled component root', function () {
    $html = '<div class="wrap"><form x-on:submit.prevent="duo.submit(\'save\')"></form></div>';

    $result = $this->provider->decorateComponentRoot($html, $this->meta);

    expect($result)
        ->toStartWith('<div data-duo-enabled="true" data-duo-meta="')
        ->toContain(' x-data="duo"')
        ->toContain('class="wrap"');
});

it('does not add x-data when the template was not compiled by Duo', function () {
    $html = '<div><button wire:click="save">Save</button></div>';

    $result = $this->provider->decorateComponentRoot($html, $this->meta);

    expect($result)
        ->toContain('data-duo-enabled="true"')
        ->not->toContain('x-data');
});

it('wraps an existing x-data so both scopes are available', function () {
    $html = '<div x-data="{ open: false }"><input x-model="duo.form.title"></div>';

    $result = $this->provider->decorateComponentRoot($html, $this->meta);

    expect($result)
        ->toContain('x-data="duo({ open: false })"')
        ->not->toContain('x-data="{ open: false }"');
});

it('treats an empty existing x-data as absent', function () {
    $html = '<div x-data="{}"><input x-model="duo.form.title"></div>';

    $result = $this->provider->decorateComponentRoot($html, $this->meta);

    expect($result)->toContain('x-data="duo"');
});

it('handles a ">" inside an attribute value on the root', function () {
    $html = '<div x-data="{ fn: () => 1 }" title="a > b"><input x-model="duo.form.title"></div>';

    $result = $this->provider->decorateComponentRoot($html, $this->meta);

    expect($result)
        ->toContain('x-data="duo({ fn: () => 1 })"')
        ->toContain('title="a > b"')
        ->toContain('data-duo-enabled="true"');

    // Attributes must land inside the root's open tag, not after it.
    $openTagEnd = strpos($result, '><input');
    expect(strpos($result, 'data-duo-enabled'))->toBeLessThan($openTagEnd);
});

it('escapes the meta JSON for use in an attribute', function () {
    $meta = array_merge($this->meta, ['component' => 'x"y']);

    $result = $this->provider->decorateComponentRoot('<div></div>', $meta);

    // json_encode escapes the quote, htmlspecialchars escapes the delimiters
    expect($result)->toContain('&quot;x\&quot;y&quot;')->not->toContain('x"y');
});

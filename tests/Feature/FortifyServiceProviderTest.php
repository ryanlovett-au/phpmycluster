<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

it('registers a two-factor rate limiter', function () {
    $limiter = RateLimiter::limiter('two-factor');
    expect($limiter)->not->toBeNull();

    // Create a fake request with a session
    $request = Request::create('/two-factor-challenge', 'POST');
    $request->setLaravelSession(app('session.store'));
    $request->session()->put('login.id', 123);

    $limit = $limiter($request);
    expect($limit)->toBeInstanceOf(Limit::class);
});

it('registers a login rate limiter', function () {
    $limiter = RateLimiter::limiter('login');
    expect($limiter)->not->toBeNull();

    // Create a fake request with email and IP
    $request = Request::create('/login', 'POST', ['email' => 'Test@Example.com']);
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $limit = $limiter($request);
    expect($limit)->toBeInstanceOf(Limit::class);
});

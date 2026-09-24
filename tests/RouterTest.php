<?php
declare(strict_types=1);

use App\Core\HttpException;
use App\Core\Router;

$routes = [
    ['GET',  'auth/login', ['X', 'show'],  Router::GUEST],
    ['POST', 'auth/login', ['X', 'login'], Router::GUEST],
    ['GET',  'users',      ['X', 'index'], 'user.manage'],
];

return [
    'matches method and path, returning handler and access' => function () use ($routes): void {
        $match = (new Router($routes))->match('GET', 'users');
        assert_same(['X', 'index'], $match['handler']);
        assert_same('user.manage', $match['access']);
    },

    'the same path with another method picks the other route' => function () use ($routes): void {
        $match = (new Router($routes))->match('POST', 'auth/login');
        assert_same(['X', 'login'], $match['handler']);
        assert_same(Router::GUEST, $match['access']);
    },

    'an unknown path is a 404' => function () use ($routes): void {
        $e = assert_throws(HttpException::class, fn () => (new Router($routes))->match('GET', 'nope'));
        assert_same(404, $e->status);
    },

    'a known path with the wrong method is a 405' => function () use ($routes): void {
        $e = assert_throws(HttpException::class, fn () => (new Router($routes))->match('POST', 'users'));
        assert_same(405, $e->status);
    },

    'malformed paths are a 404' => function () use ($routes): void {
        foreach (['', '../etc', 'Users', 'a/b/c', 'users/', 'users?x'] as $path) {
            $e = assert_throws(HttpException::class, fn () => (new Router($routes))->match('GET', $path));
            assert_same(404, $e->status, "path '{$path}'");
        }
    },
];

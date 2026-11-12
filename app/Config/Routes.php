<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

$routes->get('users', 'Users::index');
$routes->get('users/(:num)', 'Users::show/$1');
$routes->post('users', 'Users::create');
$routes->put('users/(:num)', 'Users::update/$1');
$routes->delete('users/(:num)', 'Users::delete/$1');

// CORS preflight: the `cors` filter answers it before this handler runs; the
// routes must exist so the filter is reached instead of a 404.
$noContent = static fn () => service('response')->setStatusCode(204);
$routes->options('users', $noContent);
$routes->options('users/(:num)', $noContent);

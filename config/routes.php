<?php

declare(strict_types=1);

use Horde\Core\Middleware\DefaultStack;
use Horde\Core\Middleware\ErrorFilter;
use Horde\Satisfiend\Controller\EventDetailController;
use Horde\Satisfiend\Controller\EventListController;
use Horde\Satisfiend\Controller\IndexController;
use Horde\Satisfiend\Controller\WebhookHandler;
use Horde\Satisfiend\Middleware\SatisfiendBootstrap;

$mapper->buildRoute(uri: '/', name: 'SatisfiendIndex')
    ->withController(IndexController::class)
    ->withDefaults(['HordeAuthType' => 'authenticate'])
    ->withMiddleware(DefaultStack::get())
    ->add();

$mapper->buildRoute(uri: '/events', name: 'SatisfiendEventList')
    ->withController(EventListController::class)
    ->withDefaults(['HordeAuthType' => 'authenticate'])
    ->withMiddleware(DefaultStack::get())
    ->add();

$mapper->buildRoute(uri: '/events/:delivery_id', name: 'SatisfiendEventDetail')
    ->withController(EventDetailController::class)
    ->withDefaults(['HordeAuthType' => 'authenticate'])
    ->requires('delivery_id', '[A-Za-z0-9\-]+')
    ->withMiddleware(DefaultStack::get())
    ->add();

// Webhook endpoint: authenticated by HMAC signature, not by Horde session,
// so it does not need the legacy HordeCore shim or the auth middlewares.
// SatisfiendBootstrap installs just the satisfiend-specific DI bindings
// (EndpointLookupInterface + PersistEventListener subscription) that the
// controller needs; ErrorFilter keeps stack traces out of error responses.
$mapper->buildRoute(uri: '/webhook/:slug', name: 'SatisfiendWebhook')
    ->withController(WebhookHandler::class)
    ->withDefaults(['HordeAuthType' => 'NONE'])
//    ->requires('slug', '[a-zA-Z0-9\-_]+')
    ->withMiddleware([SatisfiendBootstrap::class, ErrorFilter::class])
    ->add();

<?php

declare(strict_types=1);

use Horde\Core\Middleware\DefaultStack;
use Horde\Core\Middleware\ErrorFilter;
use Horde\Satisfiend\Controller\Api\EventDetailController as ApiEventDetailController;
use Horde\Satisfiend\Controller\Api\EventListController as ApiEventListController;
use Horde\Satisfiend\Controller\Api\EventPayloadController as ApiEventPayloadController;
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

// JSON API. Same auth as the HTML surface (session cookie); token
// auth is a later plan. Routes deliberately keep the same
// delivery-id shape as the HTML views so consumers can navigate
// between representations by string-swapping the path prefix.
$mapper->buildRoute(uri: '/api/events', name: 'SatisfiendApiEventList')
    ->withController(ApiEventListController::class)
    ->withDefaults(['HordeAuthType' => 'authenticate'])
    ->withMiddleware(DefaultStack::get())
    ->add();

$mapper->buildRoute(uri: '/api/events/:delivery_id', name: 'SatisfiendApiEventDetail')
    ->withController(ApiEventDetailController::class)
    ->withDefaults(['HordeAuthType' => 'authenticate'])
    ->requires('delivery_id', '[A-Za-z0-9\-]+')
    ->withMiddleware(DefaultStack::get())
    ->add();

$mapper->buildRoute(uri: '/api/events/:delivery_id/payload', name: 'SatisfiendApiEventPayload')
    ->withController(ApiEventPayloadController::class)
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

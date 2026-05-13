<?php

declare(strict_types=1);

use Horde\Core\Middleware\DefaultStack;
use Horde\Satisfiend\Controller\IndexController;
use Horde\Satisfiend\WebhookHandler;

$mapper->buildRoute(uri: '/', name: 'SatisfiendIndex')
    ->withController(IndexController::class)
    ->withDefaults(['HordeAuthType' => 'authenticate'])
    ->withMiddleware(DefaultStack::get())
    ->add();

$mapper->buildRoute(uri: '/webhook/:slug', name: 'SatisfiendWebhook')
    ->withController(WebhookHandler::class)
    ->withDefaults(['HordeAuthType' => 'NONE'])
    ->requires('slug', '[a-zA-Z0-9\-_]+')
    ->noMiddleware()
    ->add();

<?php

declare(strict_types=1);

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @license http://www.horde.org/licenses/lgpl21 LGPL
 */

namespace Horde\Satisfiend\Controller\Api;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Small helper that keeps the three JSON API controllers consistent:
 * one place that decides on the encoding flags, the content type and
 * how error payloads look.
 */
final class ApiResponse
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {}

    /**
     * Encode $data as JSON and return a PSR-7 response with the right
     * content type. Uses UNESCAPED_SLASHES/UNICODE for legibility;
     * pretty-print is off (clients that want it can pipe through jq).
     *
     * @param mixed $data
     */
    public function json(mixed $data, int $status = 200): ResponseInterface
    {
        $encoded = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($encoded === false) {
            // Fall back to a JSON error object rather than raw text so
            // clients still get parseable output.
            $encoded = json_encode(['error' => 'encoding_failed']);
            $status = 500;
        }

        return $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withBody($this->streamFactory->createStream($encoded));
    }

    /**
     * Convenience: JSON error response with a stable shape.
     */
    public function error(int $status, string $code, string $message = ''): ResponseInterface
    {
        return $this->json(
            [
                'error' => [
                    'code' => $code,
                    'message' => $message,
                ],
            ],
            $status,
        );
    }
}

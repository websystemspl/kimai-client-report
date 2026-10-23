<?php

namespace KimaiPlugin\ClientReportBundle\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * JSON responses for the plugin's API routes. Errors use the same shape as
 * Kimai's own API: {"code": 400, "message": "..."}.
 */
final class ApiJson
{
    private const FLAGS = JsonResponse::DEFAULT_ENCODING_OPTIONS | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION;

    public static function ok(mixed $data, int $status = 200): JsonResponse
    {
        $response = new JsonResponse(null, $status);
        $response->setEncodingOptions(self::FLAGS);
        $response->setData($data);

        return $response;
    }

    public static function error(string $message, int $status = 400): JsonResponse
    {
        return self::ok(['code' => $status, 'message' => $message], $status);
    }
}

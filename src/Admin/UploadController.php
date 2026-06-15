<?php

declare(strict_types=1);

namespace App\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Single image-upload endpoint used by the admin dropzone (POST {base}/upload).
 * The target /media subfolder is derived from a whitelisted `context` so the
 * client can never write outside the known media folders.
 */
final class UploadController extends BaseController
{
    /** image type -> /media/{brand}/{folder} (mirrors Catalog\Repository::FOLDER) */
    private const PRODUCT_FOLDER = ['cover' => 'cover', 'color' => 'culori', 'gallery' => 'motociclete', 'detail' => 'detalii'];

    /** POST {base}/upload  (multipart: files[] + context + _csrf) */
    public function upload(Request $request, Response $response): Response
    {
        if ($denied = $this->requireAuth($response)) {
            return $this->json($response, ['ok' => [], 'errors' => ['Neautentificat.']], 401);
        }
        $body = $this->body($request);
        if (!$this->csrfOk($body)) {
            return $this->json($response, ['ok' => [], 'errors' => ['Token invalid.']], 403);
        }

        $subdir = $this->resolveSubdir(
            (string) ($body['context'] ?? ''),
            (string) ($body['brand'] ?? ''),
            (string) ($body['type'] ?? '')
        );
        if ($subdir === null) {
            return $this->json($response, ['ok' => [], 'errors' => ['Context invalid.']], 422);
        }

        $files = $request->getUploadedFiles();
        $list = $files['files'] ?? ($files['file'] ?? []);
        if (!is_array($list)) {
            $list = [$list];
        }

        $root = dirname(__DIR__, 2); // project root
        $up = new Upload($root . '/media');
        $result = $up->store($list, $subdir);

        return $this->json($response, $result);
    }

    /** Map a whitelisted context to a /media subfolder, or null if invalid. */
    private function resolveSubdir(string $context, string $brand, string $type): ?string
    {
        switch ($context) {
            case 'hero':
                return 'hero';
            case 'news':
                return 'noutati-moto';
            case 'event':
                return 'evenimente';
            case 'product':
                if (!in_array($brand, ['yamaha', 'cfmoto'], true)) {
                    return null;
                }
                $folder = self::PRODUCT_FOLDER[$type] ?? null;
                return $folder ? $brand . '/' . $folder : null;
            default:
                return null;
        }
    }

    /** @param array<string,mixed> $payload */
    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}

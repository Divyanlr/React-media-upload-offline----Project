<?php
namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Service\ChunkHandler;

/**
 * UploadController
 *
 * Endpoints:
 * POST /api/upload/initiate
 * POST /api/upload/chunk
 * POST /api/upload/finalize
 * GET  /api/upload/status?uploadId=...
 */
class UploadController extends AbstractController
{
    private ChunkHandler $chunkHandler;

    public function __construct(ChunkHandler $chunkHandler)
    {
        $this->chunkHandler = $chunkHandler;
    }

    // POST /api/upload/initiate
    public function initiate(Request $request)
    {
        $data = json_decode($request->getContent(), true) ?? $request->request->all();

        $uploadId = $data['uploadId'] ?? null;
        $fileName = $data['fileName'] ?? null;
        $size = isset($data['size']) ? (int)$data['size'] : null;
        $type = $data['type'] ?? null;
        $totalChunks = isset($data['totalChunks']) ? (int)$data['totalChunks'] : null;

        if (!$uploadId || !$fileName || !$size) {
            return new JsonResponse(['error' => 'missing_parameters'], 400);
        }

        try {
            $this->chunkHandler->initSession($uploadId, $fileName, $size, $type, $totalChunks);
            return new JsonResponse(['ok' => true, 'uploadId' => $uploadId]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    // POST /api/upload/chunk
    // Expects multipart/form-data:
    // - uploadId (form field)
    // - index (0-based chunk index)
    // - chunk (file field)
    public function chunk(Request $request)
    {
        $uploadId = $request->request->get('uploadId');
        $index = $request->request->get('index');
        $file = $request->files->get('chunk');

        if (!$uploadId || $index === null || !$file) {
            return new JsonResponse(['error' => 'missing_parameters'], 400);
        }

        try {
            $this->chunkHandler->storeChunk($uploadId, (int)$index, $file->getPathname());
            return new JsonResponse(['ok' => true, 'index' => (int)$index]);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    // POST /api/upload/finalize
    // Body JSON: { uploadId, fileName }
    public function finalize(Request $request)
    {
        $data = json_decode($request->getContent(), true) ?? $request->request->all();
        $uploadId = $data['uploadId'] ?? null;
        $fileName = $data['fileName'] ?? null;

        if (!$uploadId || !$fileName) {
            return new JsonResponse(['error' => 'missing_parameters'], 400);
        }

        try {
            $result = $this->chunkHandler->assembleChunks($uploadId, $fileName);
            return new JsonResponse(['ok' => true, 'file' => $result]);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    // GET /api/upload/status?uploadId=...
    public function status(Request $request)
    {
        $uploadId = $request->query->get('uploadId');
        if (!$uploadId) {
            return new JsonResponse(['error' => 'missing_id'], 400);
        }
        try {
            $status = $this->chunkHandler->getStatus($uploadId);
            return new JsonResponse(['status' => $status]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }
}

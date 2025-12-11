<?php
namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Service\ChunkHandler;

/*
  UploadController:
  - handle initiate, chunk upload, finalize and status
  - uses ChunkHandler service to manage chunk storage and reassembly
*/

class UploadController extends AbstractController
{
    private $chunkHandler;

    public function __construct(ChunkHandler $chunkHandler)
    {
        $this->chunkHandler = $chunkHandler;
    }

    public function initiate(Request $request)
    {
        $data = json_decode($request->getContent(), true);
        if(!$data || !isset($data['uploadId'])) {
            return new JsonResponse(['error'=>'invalid_payload'], 400);
        }
        // create upload session folder
        $this->chunkHandler->initSession($data['uploadId'], $data);
        return new JsonResponse(['ok'=>true]);
    }

    public function chunk(Request $request)
    {
        $uploadId = $request->request->get('uploadId');
        $chunkIndex = $request->request->get('chunkIndex');

        $file = $request->files->get('chunk');
        if(!$uploadId || !$file || $chunkIndex === null) {
            return new JsonResponse(['error'=>'invalid_chunk_request'], 400);
        }

        try {
            $this->chunkHandler->storeChunk($uploadId, intval($chunkIndex), $file);
            return new JsonResponse(['ok'=>true]);
        } catch(\Exception $e){
            return new JsonResponse(['error'=>$e->getMessage()], 500);
        }
    }

    public function finalize(Request $request)
    {
        $data = json_decode($request->getContent(), true);
        $uploadId = $data['uploadId'] ?? null;
        $fileName = $data['fileName'] ?? null;
        if(!$uploadId || !$fileName) return new JsonResponse(['error'=>'invalid_finalize'],400);

        try {
            $result = $this->chunkHandler->assembleChunks($uploadId, $fileName);
            return new JsonResponse(['ok'=>true, 'file'=>$result]);
        } catch(\Exception $e){
            return new JsonResponse(['error'=>$e->getMessage()], 500);
        }
    }

    public function status(Request $request)
    {
        $uploadId = $request->query->get('uploadId');
        if(!$uploadId) return new JsonResponse(['error'=>'missing_id'],400);
        $status = $this->chunkHandler->getStatus($uploadId);
        return new JsonResponse(['status'=>$status]);
    }
}
    
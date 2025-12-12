<?php
namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;

class HealthController extends AbstractController
{
    #[Route('/', name: 'app_health', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse([
            'ok' => true,
            'message' => 'Upload API running. Use /api/upload/* endpoints.',
        ]);
    }
}
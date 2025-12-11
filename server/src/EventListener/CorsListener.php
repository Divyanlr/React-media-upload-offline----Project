<?php
namespace App\EventListener;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

class CorsListener
{
    // Called very early for requests
    public function onKernelRequest(RequestEvent $event)
    {
        $request = $event->getRequest();

        // Only act on API routes (adjust prefix if your API lives elsewhere)
        if (strpos($request->getPathInfo(), '/api/') !== 0) {
            return;
        }

        // If it's a preflight request, respond immediately with correct CORS headers
        if ($request->getMethod() === 'OPTIONS') {
            $response = new Response();
            $response->setStatusCode(Response::HTTP_OK);

            $response->headers->set('Access-Control-Allow-Origin', 'http://localhost:5173');
            $response->headers->set('Access-Control-Allow-Methods', 'GET,POST,PUT,DELETE,OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Access-Control-Max-Age', '3600');

            $event->setResponse($response);
        }
    }

    // Ensure every API response contains CORS headers so browser accepts responses
    public function onKernelResponse(ResponseEvent $event)
    {
        $request = $event->getRequest();

        if (strpos($request->getPathInfo(), '/api/') !== 0) {
            return;
        }

        $response = $event->getResponse();

        // Allow local dev host. If you want to allow any origin for testing, use '*'
        $response->headers->set('Access-Control-Allow-Origin', 'http://localhost:5173');
        $response->headers->set('Access-Control-Allow-Methods', 'GET,POST,PUT,DELETE,OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
    }
}

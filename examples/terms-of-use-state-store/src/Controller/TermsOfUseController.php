<?php

declare(strict_types=1);

namespace App\Controller;

use App\TermsOfUse\Query\GetTermsOfUseAcceptanceStatus;
use App\TermsOfUse\Write\AcceptCurrentTermsOfUse;
use App\TermsOfUse\Write\DefineTermsOfUse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Ultra\MessageHandling\MediatorInterface;

final class TermsOfUseController extends AbstractController
{
    public function __construct(
        private readonly MediatorInterface $mediator,
    ) {}

    #[Route('/', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return $this->json([
            'name' => 'Ultra Terms of Use State Store Example',
            'routes' => [
                'PUT /terms-of-use/current',
                'POST /users/{userId}/terms-of-use/acceptance',
                'GET /users/{userId}/terms-of-use/status',
            ],
        ]);
    }

    #[Route('/terms-of-use/current', methods: ['PUT'])]
    public function define(Request $request): JsonResponse
    {
        $payload = $request->toArray();
        $this->mediator->send(new DefineTermsOfUse((string) $payload['id'], (string) $payload['url']));

        return $this->json(['defined' => true]);
    }

    #[Route('/users/{userId}/terms-of-use/acceptance', methods: ['POST'])]
    public function accept(string $userId): JsonResponse
    {
        $this->mediator->send(new AcceptCurrentTermsOfUse($userId));

        return $this->json($this->mediator->send(new GetTermsOfUseAcceptanceStatus($userId)));
    }

    #[Route('/users/{userId}/terms-of-use/status', methods: ['GET'])]
    public function status(string $userId): JsonResponse
    {
        return $this->json($this->mediator->send(new GetTermsOfUseAcceptanceStatus($userId)));
    }
}

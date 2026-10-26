<?php

namespace App\Domain\Documents\Http\Controllers;

use App\Domain\Documents\Actions\VerifyDocument;
use App\Domain\Documents\Http\Resources\VerificationResource;
use App\Support\Http\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/public/verify/{code} : page publique de vérification d'un document par son QR code
 * (F-DOC-04, RG-DOC-09), sans compte. Le code ne figure jamais dans le journal (paramètre de route).
 */
class VerificationController extends BaseApiController
{
    public function show(Request $request, string $verificationCode, VerifyDocument $verify): VerificationResource|JsonResponse
    {
        return $this->handle(function () use ($request, $verificationCode, $verify): VerificationResource {
            $this->logActor('public:verification');
            $document = $verify->execute($verificationCode, $request->ip(), $request->userAgent());
            $this->logSubject($document);

            return new VerificationResource($document);
        }, action: 'documents.verify');
    }
}

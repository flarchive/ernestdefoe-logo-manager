<?php

namespace Ernestdefoe\LogoManager\Api\Controller;

use Ernestdefoe\LogoManager\Transfer\Backup;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

/**
 * GET  /api/logo-manager/backup — the whole setup, files included, as JSON.
 * POST /api/logo-manager/backup — put one back.
 */
class BackupController implements RequestHandlerInterface
{
    public function __construct(
        protected Backup $backup,
        protected TranslatorInterface $translator,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        if ($request->getMethod() === 'GET') {
            return new JsonResponse($this->backup->export(), 200, [
                'Content-Disposition' => 'attachment; filename="logo-manager-'.gmdate('Y-m-d').'.json"',
            ]);
        }

        try {
            $count = $this->backup->import($request->getParsedBody());
        } catch (RuntimeException $e) {
            $key = $e->getMessage() === 'import_invalid' ? 'backup_invalid' : 'backup_file';

            throw new ValidationException(['backup' => $this->translator->trans("ernestdefoe-logo-manager.admin.$key")]);
        }

        return new JsonResponse(['files' => $count]);
    }
}

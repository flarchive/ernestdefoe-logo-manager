<?php

namespace Ernestdefoe\LogoManager\Api\Controller;

use Ernestdefoe\LogoManager\Report\HealthReport;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** GET /api/logo-manager/report — built on demand when the studio opens. */
class ReportController implements RequestHandlerInterface
{
    public function __construct(protected HealthReport $report)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        return new JsonResponse($this->report->build());
    }
}

<?php

declare(strict_types=1);

namespace OCA\FormVox\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCA\FormVox\AppInfo\Application;
use OCA\FormVox\Service\StatisticsService;
use OCA\FormVox\Service\TelemetryService;

class StatisticsController extends Controller
{
    private StatisticsService $statisticsService;
    private TelemetryService $telemetryService;

    public function __construct(
        IRequest $request,
        StatisticsService $statisticsService,
        TelemetryService $telemetryService
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->statisticsService = $statisticsService;
        $this->telemetryService = $telemetryService;
    }

    /**
     * Get statistics (admin only)
     */
    public function getStatistics(): DataResponse
    {
        return new DataResponse([
            'statistics' => $this->statisticsService->getStatistics(),
            'telemetry' => $this->telemetryService->getStatus(),
        ]);
    }

    /**
     * Get telemetry status (admin only)
     */
    public function getTelemetry(): DataResponse
    {
        return new DataResponse($this->telemetryService->getStatus());
    }

    /**
     * Toggle telemetry (admin only)
     */
    public function setTelemetry(bool $enabled): DataResponse
    {
        $this->telemetryService->setEnabled($enabled);
        return new DataResponse([
            'enabled' => $this->telemetryService->isEnabled(),
        ]);
    }

    /**
     * Manually send telemetry report (admin only)
     */
    public function sendTelemetry(): DataResponse
    {
        // Temporarily enable telemetry for this request if it's disabled
        $wasEnabled = $this->telemetryService->isEnabled();
        if (!$wasEnabled) {
            $this->telemetryService->setEnabled(true);
        }

        $success = $this->telemetryService->sendReport();

        // Restore original state
        if (!$wasEnabled) {
            $this->telemetryService->setEnabled(false);
        }

        return new DataResponse([
            'success' => $success,
            'lastReport' => $this->telemetryService->getLastReportTime(),
        ]);
    }
}

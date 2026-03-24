<?php

namespace Controllers;

use Symfony\Component\HttpFoundation\Response;

class PageController extends BaseController
{
    public function home(): Response
    {
        $html = file_get_contents(__DIR__ . '/../views/home.html');
        return $this->renderWithEnv($html);
    }

    public function commandBuilder(): Response
    {
        $html = file_get_contents(__DIR__ . '/../views/command-builder.html');
        return $this->renderWithEnv($html);
    }

    public function docs(): Response
    {
        $html = file_get_contents(__DIR__ . '/../views/api-docs.html');
        return $this->renderWithEnv($html);
    }

    public function apiSpec(): Response
    {
        $json = file_get_contents(__DIR__ . '/../views/openapi.json');
        return new Response($json, 200, ['Content-Type' => 'application/json']);
    }

    public function index(): Response
    {
        $html = file_get_contents(__DIR__ . '/../views/monitoring.html');
        return $this->renderWithEnv($html);
    }

    public function logs(): Response
    {
        $html = file_get_contents(__DIR__ . '/../views/logs.html');
        return $this->renderWithEnv($html);
    }

    public function facebookReports(): Response
    {
        $html = file_get_contents(__DIR__ . '/../views/facebook-reports.html');
        
        $channelsConfig = \Helpers\Helpers::getChannelsConfig();
        $fbConfig = $channelsConfig['facebook_marketing'] ?? [];
        
        $configData = [
            'strategy' => $fbConfig['metrics_strategy'] ?? 'default',
            'metrics_config' => $fbConfig['metrics_config'] ?? [],
            'metrics_level' => $this->deriveMetricsLevel($fbConfig)
        ];

        $appMode = \Helpers\Helpers::getAppMode();
        $isDemo = \Helpers\Helpers::isDemo();
        
        $autoAuthScript = $isDemo ? "<script>localStorage.setItem('apis_hub_admin_auth', JSON.stringify({token: 'DEMO_BYPASS', timestamp: Date.now()})); window.AUTH_BYPASS = true;</script>" : "";
        
        $html = str_replace(
            '<!-- FB_CONFIG_PLACEHOLDER -->',
            $autoAuthScript . '<script>window.FB_METRICS_CONFIG = ' . json_encode($configData) . ';</script>',
            $html
        );

        return $this->renderWithEnv($html);
    }

    private function deriveMetricsLevel(array $fbConfig): string
    {
        $t = $fbConfig['AD_ACCOUNT'] ?? [];
        if ($t['creative_metrics'] ?? false) return 'creative';
        if ($t['ad_metrics'] ?? false) return 'ad';
        if ($t['adset_metrics'] ?? false) return 'adset';
        if ($t['campaign_metrics'] ?? false) return 'campaign';
        return 'ad_account';
    }
}

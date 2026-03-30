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
        return $this->renderFacebookReport($html, 'facebook_marketing', '<!-- FB_CONFIG_PLACEHOLDER -->');
    }

    public function facebookOrganicReports(): Response
    {
        $html = file_get_contents(__DIR__ . '/../views/facebook-organic-reports.html');
        return $this->renderFacebookReport($html, 'facebook_organic', '<!-- FB_ORGANIC_CONFIG_PLACEHOLDER -->');
    }

    private function renderFacebookReport(string $html, string $channel, string $placeholder): Response
    {
        $channelsConfig = \Helpers\Helpers::getChannelsConfig();
        $config = $channelsConfig[$channel] ?? [];
        
        $configData = [
            'strategy' => $config['metrics_strategy'] ?? 'default',
            'metrics_config' => $config['metrics_config'] ?? [],
            'metrics_level' => $this->deriveMetricsLevel($config)
        ];

        $isDemo = \Helpers\Helpers::isDemo();
        $autoAuthScript = $isDemo ? "<script>localStorage.setItem('apis_hub_admin_auth', JSON.stringify({token: 'DEMO_BYPASS', timestamp: Date.now()})); window.AUTH_BYPASS = true;</script>" : "";
        
        $html = str_replace(
            $placeholder,
            $autoAuthScript . '<script>window.FB_METRICS_CONFIG = ' . json_encode($configData) . ';</script>',
            $html
        );

        return $this->renderWithEnv($html);
    }

    private function deriveMetricsLevel(array $config): string
    {
        // For organic, check for content levels
        if ($config['PAGES']['post_metrics'] ?? false) return 'post';
        
        // Fallback to marketing logic levels
        $t = $config['AD_ACCOUNT'] ?? [];
        if ($t['creative_metrics'] ?? false) return 'creative';
        if ($t['ad_metrics'] ?? false) return 'ad';
        if ($t['adset_metrics'] ?? false) return 'adset';
        if ($t['campaign_metrics'] ?? false) return 'campaign';
        return 'ad_account';
    }
}

<?php

namespace App\Support;

use App\Models\PublishingSetting;
use App\Services\News\AdSenseService;
use Illuminate\Http\Response;

class FrontendPage
{
    private const FAVICON_TAGS = '  <link rel="icon" type="image/png" href="/site/Image/logo_main.png">'."\n"
        .'  <link rel="apple-touch-icon" href="/site/Image/logo_main.png">';

    public function __construct(private readonly FrontendLinker $linker) {}

    /**
     * @param  array<string, string>  $replacements
     * @param  'public'|'admin'|'staff'|'parent'|'student'|'auth'  $area
     */
    public function html(string $relativePath, array $replacements = [], string $area = 'public'): string
    {
        abort_unless(preg_match('/^[A-Za-z0-9_\-]+(?:\/[A-Za-z0-9_\-]+)*\.html$/', $relativePath) === 1, 404);

        $path = resource_path('frontend/'.$relativePath);
        abort_unless(is_file($path), 404);

        $html = (string) file_get_contents($path);

        foreach ($replacements as $search => $replace) {
            $html = str_replace($search, $replace, $html);
        }

        $html = $this->linker->rewrite($this->withFavicon($html), $area);

        if ($area === 'public') {
            $html = $this->withPublicAnalytics($html);
        }

        return $html;
    }

    /**
     * @param  array<string, string>  $replacements
     * @param  'public'|'admin'|'staff'|'parent'|'student'|'auth'  $area
     */
    public function response(string $relativePath, array $replacements = [], string $area = 'public'): Response
    {
        return $this->htmlResponse($this->html($relativePath, $replacements, $area));
    }

    /**
     * @deprecated Use response() after HTML lives in resources/frontend.
     * @param  array<string, string>  $replacements
     */
    public function publicHtml(string $relativePath, array $replacements = []): string
    {
        $mapped = $this->mapLegacyPublicPath($relativePath);

        return $this->html($mapped['file'], $replacements, $mapped['area']);
    }

    /**
     * @param  array<string, string>  $replacements
     */
    public function publicResponse(string $relativePath, array $replacements = []): Response
    {
        $mapped = $this->mapLegacyPublicPath($relativePath);

        return $this->response($mapped['file'], $replacements, $mapped['area']);
    }

    /**
     * @return array{file: string, area: 'public'|'staff'|'parent'|'auth'}
     */
    private function mapLegacyPublicPath(string $relativePath): array
    {
        if (str_starts_with($relativePath, 'staff/')) {
            return ['file' => $relativePath, 'area' => 'staff'];
        }

        if (str_starts_with($relativePath, 'parent_student/')) {
            return ['file' => $relativePath, 'area' => 'parent'];
        }

        if (in_array($relativePath, ['staffLogin.html', 'Parent_studentlogin.html', 'parent_studentPage.html'], true)) {
            return ['file' => 'auth/'.$relativePath, 'area' => 'auth'];
        }

        return ['file' => 'public/'.$relativePath, 'area' => 'public'];
    }

    private function withPublicAnalytics(string $html): string
    {
        $html = $this->withConsentBanner($html);

        $settings = PublishingSetting::current();
        $id = $settings->analyticsMeasurementId();

        if (! $settings->analyticsReady() || $id === null) {
            return $html;
        }

        if (! app(AdSenseService::class)->hasAnalyticsConsent(request())) {
            return $html;
        }

        if (str_contains($html, 'googletagmanager.com/gtag/js')) {
            return $html;
        }

        $tag = '<script async src="https://www.googletagmanager.com/gtag/js?id='.e($id).'"></script>'."\n"
            .'<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("js",new Date());gtag("config",'.json_encode($id).',{anonymize_ip:true});</script>'."\n";

        if (str_contains($html, '</head>')) {
            $updated = preg_replace('/<\/head>/i', $tag.'</head>', $html, 1);

            return is_string($updated) ? $updated : $html;
        }

        return $html;
    }

    private function withConsentBanner(string $html): string
    {
        if (str_contains($html, 'data-consent-banner')) {
            return $html;
        }

        $banner = <<<'HTML'
<aside class="consent-banner" data-consent-banner hidden role="dialog" aria-labelledby="consent-title" aria-describedby="consent-copy">
  <div class="consent-inner">
    <div class="consent-copy">
      <p class="consent-kicker" id="consent-title">Cookie choices</p>
      <p id="consent-copy">Necessary cookies keep the site working. Analytics and advertising cookies are optional, and the desks never show ads. <a href="/privacy">Privacy Policy</a></p>
    </div>
    <form class="consent-actions" method="post" action="/privacy/consent">
      <button type="submit" class="consent-btn ghost">Necessary only</button>
      <button type="submit" class="consent-btn" name="analytics" value="1">Allow analytics</button>
      <button type="submit" class="consent-btn solid" data-consent-all>Accept optional</button>
    </form>
  </div>
</aside>
<script src="/site/JS/site-consent.js"></script>
HTML;

        if (str_contains($html, '</body>')) {
            $updated = preg_replace('/<\/body>/i', $banner."\n</body>", $html, 1);

            return is_string($updated) ? $updated : $html;
        }

        return $html;
    }

    private function withFavicon(string $html): string
    {
        if (str_contains($html, 'rel="icon"')) {
            return $html;
        }

        $updated = preg_replace('/<head([^>]*)>/i', '<head$1>'."\n".self::FAVICON_TAGS, $html, 1);

        return is_string($updated) ? $updated : $html;
    }

    private function htmlResponse(string $html): Response
    {
        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}

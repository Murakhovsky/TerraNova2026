<?php
declare(strict_types=1);

namespace App\Web\Phtml;

use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

final class PhtmlRenderer
{
    public readonly UrlHelper $url;
    public RequestQueryAdapter $request;

    /** @var list<array<string,mixed>> */
    private array $contextStack = [];
    private string $content = '';

    public function __construct(
        private readonly string $viewRoot,
        private readonly ViteAssetManifest $manifest,
    ) {
        $this->url = new UrlHelper();
    }

    /** @param array<string,mixed> $variables */
    public function render(Request $request, string $view, array $variables = []): string
    {
        $this->request = new RequestQueryAdapter($request);
        $this->content = $this->capture($view, $variables);

        return $this->capture('index', $variables);
    }

    /** @param array<string,mixed> $variables */
    public function partial(string $view, array $variables = []): void
    {
        $inherited = $this->contextStack !== [] ? $this->contextStack[array_key_last($this->contextStack)] : [];
        echo $this->capture($view, array_replace($inherited, $variables));
    }

    public function getContent(): string
    {
        return $this->content;
    }

    /** @param list<string> $entries @return array{scripts:list<string>,styles:list<string>} */
    public function assets(array $entries): array
    {
        return $this->manifest->assets($entries);
    }

    /** @param array<string,mixed> $variables */
    private function capture(string $view, array $variables): string
    {
        $relative = trim($view, '/');
        $path = rtrim($this->viewRoot, '/') . '/' . $relative . (str_ends_with($relative, '.phtml') ? '' : '.phtml');
        if (!is_file($path)) {
            throw new RuntimeException('PHTML view not found: ' . $relative);
        }

        $this->contextStack[] = $variables;
        ob_start();
        try {
            extract($variables, EXTR_SKIP);
            include $path;
            return (string) ob_get_clean();
        } catch (\Throwable $error) {
            ob_end_clean();
            throw $error;
        } finally {
            array_pop($this->contextStack);
        }
    }
}

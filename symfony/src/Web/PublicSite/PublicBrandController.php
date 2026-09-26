<?php

declare(strict_types=1);

namespace App\Web\PublicSite;

use App\Application\Content\Query\GetPublicBrandPageQuery;
use App\Application\Sales\Command\ReceivePublicLeadCommand;
use App\Application\Sales\Command\SalesMutationResult;
use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PagePresentationFactory;
use App\Web\PublicSite\ViewModel\PublicBrandViewModel;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Shared\Domain\OrganizationId;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

final readonly class PublicBrandController
{
    public function __construct(
        private Environment $twig,
        private QueryBusInterface $queries,
        private CommandBusInterface $commands,
        private PagePresentationFactory $pages,
        private PublicBrandPresenter $presenter,
        private string $organizationId,
    ) {
    }

    public function show(Request $request, string $slug): Response
    {
        try {
            $definition = $this->queries->ask(new GetPublicBrandPageQuery($slug));
        } catch (Throwable $error) {
            error_log('public.brand.read_failed ' . $error->getMessage());

            return new Response('Page is temporarily unavailable.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (!is_array($definition)) {
            return new Response('Page was not found.', Response::HTTP_NOT_FOUND);
        }

        $notice = $slug === 'contacts' ? $this->receiveLead($request) : null;
        $brand = $this->presenter->present(
            $definition,
            $slug === 'contacts' ? $request->request->all() : [],
            $notice,
        );

        return $this->render($request, $brand);
    }

    /** @return array{message:string,tone:string}|null */
    private function receiveLead(Request $request): ?array
    {
        if (!$request->isMethod('POST')) {
            return null;
        }

        try {
            $result = $this->commands->dispatch(new ReceivePublicLeadCommand(
                OrganizationId::fromString($this->organizationId),
                $request->request->all(),
                $request->getRequestUri(),
            ));

            if (!$result instanceof SalesMutationResult) {
                return ['message' => 'Заявку не вдалося зберегти.', 'tone' => 'danger'];
            }

            return $result->ok
                ? ['message' => 'Заявку прийнято. Менеджер звʼяжеться з вами.', 'tone' => 'success']
                : ['message' => $result->message ?? 'Заявку не вдалося зберегти.', 'tone' => 'warning'];
        } catch (Throwable $error) {
            error_log('public.brand.contact_failed ' . $error->getMessage());

            return ['message' => 'Заявку не вдалося зберегти.', 'tone' => 'danger'];
        }
    }

    private function render(Request $request, PublicBrandViewModel $brand): Response
    {
        return new Response(
            $this->twig->render('experience/public/brand_page.html.twig', [
                'page' => $this->pages->create(
                    PageArchetype::PublicDetailMarketing,
                    ['PageHeader', 'ActionBar'],
                    $brand->state(),
                ),
                'brand' => $brand,
            ]),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => $request->isMethod('GET')
                    ? 'public, max-age=60'
                    : 'no-store, private',
            ],
        );
    }
}

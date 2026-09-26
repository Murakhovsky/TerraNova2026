<?php

declare(strict_types=1);

namespace App\Web\Property;

use App\Application\Property\Query\GetPublicPropertySubmitFormQuery;
use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PagePresentationFactory;
use Kernel\Application\Bus\QueryBusInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

final readonly class PublicPropertySubmitController
{
    private const INTAKE_UNAVAILABLE = 'Публічна подача обʼєкта тимчасово переведена на новий canonical Property intake. Форма зберегла введені дані, але запис зараз не створено.';

    public function __construct(
        private Environment $twig,
        private QueryBusInterface $queries,
        private PagePresentationFactory $pages,
        private PublicPropertySubmitPresenter $presenter,
    ) {
    }

    public function index(Request $request): Response
    {
        $submissionStatus = $request->isMethod('POST')
            ? self::INTAKE_UNAVAILABLE
            : null;
        $status = $request->isMethod('POST')
            ? Response::HTTP_SERVICE_UNAVAILABLE
            : Response::HTTP_OK;

        try {
            $data = $this->queries->ask(new GetPublicPropertySubmitFormQuery());
            $submit = $this->presenter->present(
                is_array($data) ? $data : [],
                $request->request->all(),
                $submissionStatus,
            );

            return $this->render($submit, $status);
        } catch (Throwable $error) {
            error_log('property.public.submit_form_failed ' . $error->getMessage());
            $submit = $this->presenter->present(
                [],
                $request->request->all(),
                $submissionStatus,
                'Форма тимчасово недоступна.',
            );

            return $this->render($submit, Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    private function render(
        \App\Web\Property\ViewModel\PublicPropertySubmitViewModel $submit,
        int $status,
    ): Response {
        return new Response(
            $this->twig->render('experience/public/property_submit.html.twig', [
                'page' => $this->pages->create(
                    PageArchetype::FormEditor,
                    ['PageHeader', 'FormSection', 'StickyActions', 'ErrorState'],
                    $submit->state(),
                ),
                'submit' => $submit,
            ]),
            $status,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
            ],
        );
    }
}

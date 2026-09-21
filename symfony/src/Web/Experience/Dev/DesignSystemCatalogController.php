<?php

declare(strict_types=1);

namespace App\Web\Experience\Dev;

use App\Web\Experience\Form\Reference\FormsCatalogInput;
use App\Web\Experience\Form\Reference\FormsCatalogType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final readonly class DesignSystemCatalogController
{
    public function __construct(
        private Environment $twig,
        private FormFactoryInterface $forms,
        private DataGridCatalogDemo $dataGrid,
        private UiCatalogRegistry $uiCatalog,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $form = $this->forms->create(FormsCatalogType::class, new FormsCatalogInput());

        return new Response(
            $this->twig->render('experience/design_system_catalog.html.twig', [
                'formsDemo' => $form->createView(),
                'formValidationExample' => [
                    'Name: Enter a name.',
                    'Email: Enter a valid email address.',
                ],
                'dataGridDemo' => $this->dataGrid->build($request),
                'uiCatalog' => [
                    'groups' => $this->uiCatalog->grouped(),
                    'categories' => $this->uiCatalog->categories(),
                    'stats' => $this->uiCatalog->stats(),
                ],
            ]),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'X-Robots-Tag' => 'noindex, nofollow',
                'Cache-Control' => 'no-store, private',
            ],
        );
    }
}

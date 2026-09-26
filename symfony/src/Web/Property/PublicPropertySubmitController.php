<?php

declare(strict_types=1);

namespace App\Web\Property;

use App\Application\Property\Command\SubmitPublicPropertyCommand;
use App\Application\Property\Query\GetPublicPropertySubmitFormQuery;
use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PagePresentationFactory;
use App\Web\Property\ViewModel\PublicPropertySubmitViewModel;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Application\Bus\QueryBusInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

final readonly class PublicPropertySubmitController
{
    public function __construct(
        private Environment $twig,
        private QueryBusInterface $queries,
        private CommandBusInterface $commands,
        private PagePresentationFactory $pages,
        private PublicPropertySubmitPresenter $presenter,
    ) {
    }

    public function index(Request $request): Response
    {
        $formData = $request->request->all();
        $submissionStatus = null;
        $submissionOk = false;
        $status = Response::HTTP_OK;

        if ($request->isMethod('POST')) {
            try {
                $result = $this->commands->dispatch(new SubmitPublicPropertyCommand(
                    input: $formData,
                    sourcePage: $request->getRequestUri(),
                    files: $this->files($request),
                ));

                if (is_array($result)) {
                    $submissionOk = (bool) ($result['ok'] ?? false);
                    $submissionStatus = (string) ($result['message'] ?? '');
                    $status = $submissionOk
                        ? Response::HTTP_CREATED
                        : Response::HTTP_UNPROCESSABLE_ENTITY;

                    if ($submissionOk) {
                        $formData = [];
                    }
                } else {
                    $submissionStatus = 'Обʼєкт не вдалося зберегти.';
                    $status = Response::HTTP_SERVICE_UNAVAILABLE;
                }
            } catch (Throwable $error) {
                error_log('property.public.submit_failed ' . $error->getMessage());
                $submissionStatus = 'Обʼєкт не вдалося зберегти. Спробуйте ще раз.';
                $status = Response::HTTP_SERVICE_UNAVAILABLE;
            }
        }

        try {
            $data = $this->queries->ask(new GetPublicPropertySubmitFormQuery());
            $submit = $this->presenter->present(
                is_array($data) ? $data : [],
                $formData,
                $submissionStatus,
                null,
                $submissionOk,
            );

            return $this->render($submit, $status);
        } catch (Throwable $error) {
            error_log('property.public.submit_form_failed ' . $error->getMessage());
            $submit = $this->presenter->present(
                [],
                $formData,
                $submissionStatus,
                'Форма тимчасово недоступна.',
                $submissionOk,
            );

            return $this->render($submit, Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    /** @return array<string,mixed> */
    private function files(Request $request): array
    {
        $result = [];
        $main = $request->files->get('main_photo');
        if ($main instanceof UploadedFile) {
            $result['main_photo'] = $this->file($main);
        }

        $gallery = $request->files->get('gallery_photos', []);
        if ($gallery instanceof UploadedFile) {
            $gallery = [$gallery];
        }
        if (is_array($gallery)) {
            $normalized = array_values(array_filter($gallery, static fn (mixed $file): bool => $file instanceof UploadedFile));
            if ($normalized !== []) {
                $result['gallery_photos'] = [
                    'name' => array_map(static fn (UploadedFile $file): string => $file->getClientOriginalName(), $normalized),
                    'type' => array_map(static fn (UploadedFile $file): string => $file->getClientMimeType(), $normalized),
                    'tmp_name' => array_map(static fn (UploadedFile $file): string => $file->getPathname(), $normalized),
                    'error' => array_map(static fn (UploadedFile $file): int => $file->getError(), $normalized),
                    'size' => array_map(static fn (UploadedFile $file): int => $file->getSize() ?: 0, $normalized),
                ];
            }
        }

        return $result;
    }

    /** @return array{name:string,type:string,tmp_name:string,error:int,size:int} */
    private function file(UploadedFile $file): array
    {
        return [
            'name' => $file->getClientOriginalName(),
            'type' => $file->getClientMimeType(),
            'tmp_name' => $file->getPathname(),
            'error' => $file->getError(),
            'size' => $file->getSize() ?: 0,
        ];
    }

    private function render(PublicPropertySubmitViewModel $submit, int $status): Response
    {
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

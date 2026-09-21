<?php

declare(strict_types=1);

namespace App\Command;

use App\Web\Experience\Form\FormErrorSummary;
use App\Web\Experience\Form\Reference\FormsCatalogInput;
use App\Web\Experience\Form\Reference\FormsCatalogType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\UX\Dropzone\Form\DropzoneType;
use Twig\Environment;

#[AsCommand(
    name: 'cos:web:forms:smoke',
    description: 'Validate the canonical COS Forms Platform runtime.',
)]
final class FormsPlatformSmokeCommand extends Command
{
    public function __construct(
        private readonly FormFactoryInterface $forms,
        private readonly FormErrorSummary $errors,
        private readonly Environment $twig,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $form = $this->forms->create(
            FormsCatalogType::class,
            new FormsCatalogInput(),
            ['csrf_protection' => false],
        );

        $form->submit([
            'name' => '',
            'email' => 'not-an-email',
            'priority' => 'normal',
            'notes' => '',
        ]);

        if (!$form->isSubmitted() || $form->isValid()) {
            $output->writeln('<error>Forms Platform validation contract is unavailable.</error>');

            return Command::FAILURE;
        }

        $messages = $this->errors->messages($form);
        if (count($messages) < 2) {
            $output->writeln('<error>Forms Platform validation summary is incomplete.</error>');

            return Command::FAILURE;
        }

        $fresh = $this->forms->create(
            FormsCatalogType::class,
            new FormsCatalogInput(),
            ['csrf_protection' => false],
        );

        if ($fresh->get('priority')->getConfig()->getOption('autocomplete') !== true) {
            $output->writeln('<error>UX Autocomplete adapter is not enabled on the reference form.</error>');

            return Command::FAILURE;
        }

        $attachmentType = $fresh->get('attachment')->getConfig()->getType()->getInnerType();
        if (!$attachmentType instanceof DropzoneType) {
            $output->writeln('<error>UX Dropzone adapter is not enabled on the reference form.</error>');

            return Command::FAILURE;
        }

        $html = $this->twig->render('experience/design_system_catalog.html.twig', [
            'formsDemo' => $fresh->createView(),
            'formValidationExample' => $messages,
        ]);

        foreach ([
            'Form primitives / Forms Platform',
            'data-controller="form-state form-validation conditional-fields"',
            'data-form-state-draft-mode-value="manual"',
            'cos-validation-summary',
            'cos-form__actions',
            'catalog-form-modal',
            'Modal form content remains server-rendered.',
        ] as $marker) {
            if (!str_contains($html, $marker)) {
                $output->writeln(sprintf('<error>Missing Forms Platform marker: %s</error>', $marker));

                return Command::FAILURE;
            }
        }

        $output->writeln('COS Forms Platform runtime passed.');

        return Command::SUCCESS;
    }
}

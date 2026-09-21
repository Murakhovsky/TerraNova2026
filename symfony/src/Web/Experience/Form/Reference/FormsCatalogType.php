<?php

declare(strict_types=1);

namespace App\Web\Experience\Form\Reference;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Dropzone\Form\DropzoneType;

/** @extends AbstractType<FormsCatalogInput> */
final class FormsCatalogType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Name',
                'help' => 'Presentation DTO field. The Domain is not bound to this form.',
                'attr' => [
                    'autocomplete' => 'name',
                    'inputmode' => 'text',
                    'placeholder' => 'Alex Morgan',
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'help' => 'Browser hints improve input, Symfony Validator remains authoritative.',
                'attr' => [
                    'autocomplete' => 'email',
                    'inputmode' => 'email',
                    'placeholder' => 'alex@example.com',
                ],
            ])
            ->add('priority', ChoiceType::class, [
                'label' => 'Priority',
                'choices' => [
                    'Normal' => 'normal',
                    'High' => 'high',
                    'Urgent' => 'urgent',
                ],
                'autocomplete' => true,
                'attr' => [
                    'data-conditional-fields-target' => 'source',
                    'data-action' => 'change->conditional-fields#update',
                ],
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Escalation notes',
                'required' => false,
                'help' => 'Shown for high and urgent priority in this catalog example.',
                'attr' => [
                    'rows' => 4,
                    'maxlength' => 1200,
                ],
                'row_attr' => [
                    'data-conditional-fields-target' => 'section',
                    'data-conditional-fields-when-value' => 'high,urgent',
                ],
            ])
            ->add('attachment', DropzoneType::class, [
                'label' => 'Attachment',
                'mapped' => false,
                'required' => false,
                'help' => 'UX Dropzone enhances the native file input; upload security stays server-side.',
                'attr' => [
                    'accept' => 'image/*,.pdf',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => FormsCatalogInput::class,
            'method' => 'POST',
        ]);
    }
}

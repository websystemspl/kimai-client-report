<?php

namespace KimaiPlugin\ClientReportBundle\Form;

use App\Entity\Activity;
use App\Entity\Project;
use App\Entity\Tag;
use App\Entity\User;
use App\Form\Type\CustomerType;
use App\Form\Type\DatePickerType;
use App\Form\Type\ProjectType;
use Doctrine\ORM\EntityRepository;
use KimaiPlugin\ClientReportBundle\Entity\SharedReport;
use KimaiPlugin\ClientReportBundle\Report\Labels;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Kimai's own field types are used on purpose: its form theme decorates every date
 * input with a JS picker that expects the localised, non-HTML5 widget. A plain
 * Symfony DateType renders as <input type="date"> and the picker then wipes the
 * preselected value.
 */
final class SharedReportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('project', ProjectType::class, [
                'required' => false,
                'label' => 'form.project',
                'help' => 'form.project.help',
                // without this, projects without a running date range are hidden
                'ignore_date' => true,
                'join_customer' => true,
            ])
            ->add('customer', CustomerType::class, [
                'required' => false,
                'label' => 'form.customer',
            ])
            ->add('dateStart', DatePickerType::class, [
                'input' => 'datetime_immutable',
                'label' => 'form.date_start',
            ])
            ->add('dateEnd', DatePickerType::class, [
                'input' => 'datetime_immutable',
                'label' => 'form.date_end',
            ])
            ->add('excludedProjects', EntityType::class, [
                'class' => Project::class,
                'multiple' => true,
                'required' => false,
                'label' => 'form.excluded_projects',
                'help' => 'form.excluded_projects.help',
                'choice_label' => static fn (Project $project): string => $project->getCustomer()?->getName() . ' - ' . $project->getName(),
                'query_builder' => static fn (EntityRepository $repo) => $repo->createQueryBuilder('p')
                    ->join('p.customer', 'c')
                    ->orderBy('c.name', 'ASC')
                    ->addOrderBy('p.name', 'ASC'),
            ])
            ->add('users', EntityType::class, [
                'class' => User::class,
                'multiple' => true,
                'required' => false,
                'label' => 'form.users',
                'help' => 'form.users.help',
                'choice_label' => static fn (User $user): string => $user->getDisplayName(),
                'query_builder' => static fn (EntityRepository $repo) => $repo->createQueryBuilder('u')
                    ->where('u.enabled = :enabled')
                    ->setParameter('enabled', true)
                    ->orderBy('u.alias', 'ASC'),
            ])
            ->add('activities', EntityType::class, [
                'class' => Activity::class,
                'multiple' => true,
                'required' => false,
                'label' => 'form.activities',
                'help' => 'form.activities.help',
                'choice_label' => 'name',
                'query_builder' => static fn (EntityRepository $repo) => $repo->createQueryBuilder('a')
                    ->where('a.visible = :visible')
                    ->setParameter('visible', true)
                    ->orderBy('a.name', 'ASC'),
            ])
            ->add('tags', EntityType::class, [
                'class' => Tag::class,
                'multiple' => true,
                'required' => false,
                'label' => 'form.tags',
                'help' => 'form.tags.help',
                'choice_label' => 'name',
                'query_builder' => static fn (EntityRepository $repo) => $repo->createQueryBuilder('t')
                    ->orderBy('t.name', 'ASC'),
            ])
            ->add('locale', ChoiceType::class, [
                'choices' => Labels::available(),
                // language names stay in their own language, whatever the UI language is
                'choice_translation_domain' => false,
                'label' => 'form.locale',
            ])
            ->add('showNonBillable', CheckboxType::class, [
                'required' => false,
                'label' => 'form.show_non_billable',
                'help' => 'form.show_non_billable.help',
            ])
            ->add('title', TextType::class, [
                'required' => false,
                'label' => 'form.title',
                'help' => 'form.title.help',
            ])
            ->add('expiresAt', DatePickerType::class, [
                'input' => 'datetime_immutable',
                'required' => false,
                'label' => 'form.expires_at',
                'help' => 'form.expires_at.help',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SharedReport::class,
            'translation_domain' => 'client_report',
        ]);
    }
}

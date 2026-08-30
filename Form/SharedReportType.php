<?php

namespace KimaiPlugin\ClientReportBundle\Form;

use App\Entity\Activity;
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
                'label' => 'Projekt',
                'help' => 'Wybierz projekt albo klienta. Projekt wygrywa, jeśli podasz oba.',
                // without this, projects without a running date range are hidden
                'ignore_date' => true,
                'join_customer' => true,
            ])
            ->add('customer', CustomerType::class, [
                'required' => false,
                'label' => 'Klient (wszystkie projekty)',
            ])
            ->add('dateStart', DatePickerType::class, [
                'input' => 'datetime_immutable',
                'label' => 'Od',
            ])
            ->add('dateEnd', DatePickerType::class, [
                'input' => 'datetime_immutable',
                'label' => 'Do',
            ])
            ->add('users', EntityType::class, [
                'class' => User::class,
                'multiple' => true,
                'required' => false,
                'label' => 'Tylko te osoby (opcjonalnie)',
                'help' => 'Puste = wszyscy, którzy pracowali w tym okresie.',
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
                'label' => 'Tylko te rodzaje pracy (opcjonalnie)',
                'help' => 'Puste = wszystkie.',
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
                'label' => 'Tylko te tagi (opcjonalnie)',
                'help' => 'Puste = bez znaczenia, czy wpis ma tagi.',
                'choice_label' => 'name',
                'query_builder' => static fn (EntityRepository $repo) => $repo->createQueryBuilder('t')
                    ->orderBy('t.name', 'ASC'),
            ])
            ->add('locale', ChoiceType::class, [
                'choices' => Labels::available(),
                'label' => 'Język raportu',
            ])
            ->add('showNonBillable', CheckboxType::class, [
                'required' => false,
                'label' => 'Pokaż też wpisy nieodpłatne',
                'help' => 'Podsumowanie i tak liczy jedne i drugie, więc klient widzi "66 z 87 godzin płatnych". Odznacz, żeby w tabeli zostały same płatne.',
            ])
            ->add('title', TextType::class, [
                'required' => false,
                'label' => 'Tytuł (opcjonalnie)',
                'help' => 'Nagłówek strony. Puste = nazwa klienta i projektu.',
            ])
            ->add('expiresAt', DatePickerType::class, [
                'input' => 'datetime_immutable',
                'required' => false,
                'label' => 'Wygasa (opcjonalnie)',
                'help' => 'Po tym dniu link przestaje działać. Puste = bezterminowo.',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SharedReport::class,
        ]);
    }
}

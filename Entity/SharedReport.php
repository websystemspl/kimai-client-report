<?php

namespace KimaiPlugin\ClientReportBundle\Entity;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Tag;
use App\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use KimaiPlugin\ClientReportBundle\Repository\SharedReportRepository;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: SharedReportRepository::class)]
#[ORM\Table(name: 'kimai2_shared_reports')]
#[ORM\Index(columns: ['token'], name: 'IDX_shared_report_token')]
class SharedReport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 64, unique: true)]
    private string $token;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE', nullable: true)]
    private ?Customer $customer = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE', nullable: true)]
    private ?Project $project = null;

    #[ORM\Column(name: 'date_start', type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $dateStart = null;

    #[ORM\Column(name: 'date_end', type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $dateEnd = null;

    /**
     * Optional narrowing on top of the project or customer. Empty means "everyone",
     * "every type of work" and "every tag" - which is what a weekly client report
     * normally wants.
     *
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'kimai2_shared_reports_users')]
    private Collection $users;

    /** @var Collection<int, Activity> */
    #[ORM\ManyToMany(targetEntity: Activity::class)]
    #[ORM\JoinTable(name: 'kimai2_shared_reports_activities')]
    private Collection $activities;

    /** @var Collection<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'kimai2_shared_reports_tags')]
    private Collection $tags;

    /**
     * Whether unpaid entries are listed as well. They are always counted in the
     * summary, so the client can see "66 of 87 hours billed" instead of a shorter
     * list with no explanation of what is missing.
     */
    #[ORM\Column(name: 'show_non_billable', type: Types::BOOLEAN)]
    private bool $showNonBillable = true;

    #[ORM\Column(type: Types::STRING, length: 10)]
    private string $locale = 'en';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by', onDelete: 'SET NULL', nullable: true)]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'expires_at', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(name: 'revoked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $views = 0;

    #[ORM\Column(name: 'last_view_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastViewAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->token = bin2hex(random_bytes(16));
        $this->users = new ArrayCollection();
        $this->activities = new ArrayCollection();
        $this->tags = new ArrayCollection();
    }

    /** @return Collection<int, User> */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function addUser(User $user): void
    {
        if (!$this->users->contains($user)) {
            $this->users->add($user);
        }
    }

    public function removeUser(User $user): void
    {
        $this->users->removeElement($user);
    }

    /** @return Collection<int, Activity> */
    public function getActivities(): Collection
    {
        return $this->activities;
    }

    public function addActivity(Activity $activity): void
    {
        if (!$this->activities->contains($activity)) {
            $this->activities->add($activity);
        }
    }

    public function removeActivity(Activity $activity): void
    {
        $this->activities->removeElement($activity);
    }

    /** @return Collection<int, Tag> */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(Tag $tag): void
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }
    }

    public function removeTag(Tag $tag): void
    {
        $this->tags->removeElement($tag);
    }

    /**
     * True when the report is narrowed beyond the project or customer, which is
     * worth saying on the admin list so nobody wonders why hours are missing.
     */
    public function hasExtraFilters(): bool
    {
        return \count($this->users) > 0 || \count($this->activities) > 0 || \count($this->tags) > 0;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): void
    {
        $this->title = $title;
    }

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function setCustomer(?Customer $customer): void
    {
        $this->customer = $customer;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): void
    {
        $this->project = $project;
    }

    public function getDateStart(): ?\DateTimeImmutable
    {
        return $this->dateStart;
    }

    public function setDateStart(?\DateTimeImmutable $dateStart): void
    {
        $this->dateStart = $dateStart;
    }

    public function getDateEnd(): ?\DateTimeImmutable
    {
        return $this->dateEnd;
    }

    public function setDateEnd(?\DateTimeImmutable $dateEnd): void
    {
        $this->dateEnd = $dateEnd;
    }

    public function isShowNonBillable(): bool
    {
        return $this->showNonBillable;
    }

    public function setShowNonBillable(bool $showNonBillable): void
    {
        $this->showNonBillable = $showNonBillable;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): void
    {
        $this->createdBy = $createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(): void
    {
        $this->revokedAt = new \DateTimeImmutable();
    }

    public function restore(): void
    {
        $this->revokedAt = null;
    }

    public function getViews(): int
    {
        return $this->views;
    }

    public function getLastViewAt(): ?\DateTimeImmutable
    {
        return $this->lastViewAt;
    }

    public function registerView(): void
    {
        $this->views++;
        $this->lastViewAt = new \DateTimeImmutable();
    }

    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt < new \DateTimeImmutable('today');
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function isAccessible(): bool
    {
        return !$this->isRevoked() && !$this->isExpired();
    }

    /**
     * Name of whatever the report is scoped to, for headlines and the admin list.
     */
    public function getScopeName(): string
    {
        if ($this->project !== null) {
            return (string) $this->project->getName();
        }

        if ($this->customer !== null) {
            return (string) $this->customer->getName();
        }

        return '';
    }

    public function getCustomerName(): string
    {
        if ($this->project !== null && $this->project->getCustomer() !== null) {
            return (string) $this->project->getCustomer()->getName();
        }

        if ($this->customer !== null) {
            return (string) $this->customer->getName();
        }

        return '';
    }
}

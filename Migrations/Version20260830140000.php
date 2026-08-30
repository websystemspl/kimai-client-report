<?php

declare(strict_types=1);

namespace KimaiPlugin\ClientReportBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Optional filters on top of the project or customer: person, type of work, tag.
 */
final class Version20260830140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ClientReport: per-person, per-activity and per-tag filters on a shared report';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE kimai2_shared_reports_users (
            shared_report_id INT NOT NULL,
            user_id INT NOT NULL,
            INDEX IDX_shared_report_users_report (shared_report_id),
            INDEX IDX_shared_report_users_user (user_id),
            PRIMARY KEY(shared_report_id, user_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE kimai2_shared_reports_activities (
            shared_report_id INT NOT NULL,
            activity_id INT NOT NULL,
            INDEX IDX_shared_report_activities_report (shared_report_id),
            INDEX IDX_shared_report_activities_activity (activity_id),
            PRIMARY KEY(shared_report_id, activity_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE kimai2_shared_reports_tags (
            shared_report_id INT NOT NULL,
            tag_id INT NOT NULL,
            INDEX IDX_shared_report_tags_report (shared_report_id),
            INDEX IDX_shared_report_tags_tag (tag_id),
            PRIMARY KEY(shared_report_id, tag_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE kimai2_shared_reports_users ADD CONSTRAINT FK_srep_users_report FOREIGN KEY (shared_report_id) REFERENCES kimai2_shared_reports (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE kimai2_shared_reports_users ADD CONSTRAINT FK_srep_users_user FOREIGN KEY (user_id) REFERENCES kimai2_users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE kimai2_shared_reports_activities ADD CONSTRAINT FK_srep_act_report FOREIGN KEY (shared_report_id) REFERENCES kimai2_shared_reports (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE kimai2_shared_reports_activities ADD CONSTRAINT FK_srep_act_activity FOREIGN KEY (activity_id) REFERENCES kimai2_activities (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE kimai2_shared_reports_tags ADD CONSTRAINT FK_srep_tags_report FOREIGN KEY (shared_report_id) REFERENCES kimai2_shared_reports (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE kimai2_shared_reports_tags ADD CONSTRAINT FK_srep_tags_tag FOREIGN KEY (tag_id) REFERENCES kimai2_tags (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE kimai2_shared_reports_tags');
        $this->addSql('DROP TABLE kimai2_shared_reports_activities');
        $this->addSql('DROP TABLE kimai2_shared_reports_users');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}

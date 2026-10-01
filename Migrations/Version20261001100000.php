<?php

declare(strict_types=1);

namespace KimaiPlugin\ClientReportBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Projects left out of a customer-wide report (e.g. a fixed-price project).
 */
final class Version20261001100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ClientReport: exclude selected projects from a customer-wide shared report';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE kimai2_shared_reports_excluded_projects (
            shared_report_id INT NOT NULL,
            project_id INT NOT NULL,
            INDEX IDX_shared_report_exprj_report (shared_report_id),
            INDEX IDX_shared_report_exprj_project (project_id),
            PRIMARY KEY(shared_report_id, project_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE kimai2_shared_reports_excluded_projects ADD CONSTRAINT FK_srep_exprj_report FOREIGN KEY (shared_report_id) REFERENCES kimai2_shared_reports (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE kimai2_shared_reports_excluded_projects ADD CONSTRAINT FK_srep_exprj_project FOREIGN KEY (project_id) REFERENCES kimai2_projects (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE kimai2_shared_reports_excluded_projects');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}

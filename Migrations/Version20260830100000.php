<?php

declare(strict_types=1);

namespace KimaiPlugin\ClientReportBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260830100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ClientReport: table for reports shared with clients under a public link';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE kimai2_shared_reports (
            id INT AUTO_INCREMENT NOT NULL,
            token VARCHAR(64) NOT NULL,
            title VARCHAR(255) DEFAULT NULL,
            customer_id INT DEFAULT NULL,
            project_id INT DEFAULT NULL,
            date_start DATE NOT NULL,
            date_end DATE NOT NULL,
            show_non_billable TINYINT(1) NOT NULL,
            locale VARCHAR(10) NOT NULL,
            created_by INT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            expires_at DATE DEFAULT NULL,
            revoked_at DATETIME DEFAULT NULL,
            views INT NOT NULL,
            last_view_at DATETIME DEFAULT NULL,
            UNIQUE INDEX UNIQ_shared_report_token (token),
            INDEX IDX_shared_report_customer (customer_id),
            INDEX IDX_shared_report_project (project_id),
            INDEX IDX_shared_report_user (created_by),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE kimai2_shared_reports ADD CONSTRAINT FK_shared_report_customer FOREIGN KEY (customer_id) REFERENCES kimai2_customers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE kimai2_shared_reports ADD CONSTRAINT FK_shared_report_project FOREIGN KEY (project_id) REFERENCES kimai2_projects (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE kimai2_shared_reports ADD CONSTRAINT FK_shared_report_user FOREIGN KEY (created_by) REFERENCES kimai2_users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE kimai2_shared_reports');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}

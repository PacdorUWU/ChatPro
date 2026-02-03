<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260203163109 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mensajes ADD fecha_envio DATETIME NOT NULL');
        $this->addSql('ALTER TABLE usuario CHANGE roles roles JSON NOT NULL, CHANGE latitud latitud DOUBLE PRECISION DEFAULT NULL, CHANGE longitud longitud DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mensajes DROP fecha_envio');
        $this->addSql('ALTER TABLE usuario CHANGE roles roles LONGTEXT NOT NULL COLLATE `utf8mb4_bin`, CHANGE latitud latitud DOUBLE PRECISION DEFAULT \'NULL\', CHANGE longitud longitud DOUBLE PRECISION DEFAULT \'NULL\'');
    }
}

<?php

namespace Commands;

use Doctrine\ORM\EntityManagerInterface;
use Entities\Analytics\Page;
use Entities\Analytics\MetricConfig;
use Entities\Analytics\Post;
use Helpers\Helpers;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Exception;

#[AsCommand(
    name: 'app:migrate-pages-canonical',
    description: 'Generates canonical IDs for all pages and merges duplicates'
)]
class MigratePagesCanonicalCommand extends Command
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln("<info>Iniciando migración de identidades canónicas en 'pages'...</info>");
        $conn = $this->entityManager->getConnection();

        try {
            // Paso 1: Asegurar columna física
            $output->writeln("Paso 1: Verificando la columna 'canonical_id'...");
            try {
                $conn->executeStatement("ALTER TABLE pages ADD COLUMN canonical_id VARCHAR(255) NULL");
                $output->writeln("<comment>Columna creada.</comment>");
            } catch (Exception) {
                $output->writeln("Nota: La columna ya existe.");
            }

            // Paso 2: Generar IDs canónicos
            $output->writeln("Paso 2: Generando IDs canónicos para todos los registros...");
            $pages = $conn->fetchAllAssociative("SELECT id, url, platform_id, data FROM pages");
            foreach ($pages as $page) {
                $data = json_decode($page['data'] ?? '{}', true);
                $type = $data['source'] ?? null;
                $canonicalId = Helpers::getCanonicalPageId($page['url'], $page['platform_id'], $type);
                
                $conn->executeStatement("UPDATE pages SET canonical_id = ? WHERE id = ?", [$canonicalId, $page['id']]);
            }
            $output->writeln("IDs generados y persistidos.");

            // Paso 3: Fusionar duplicados
            $output->writeln("Paso 3: Buscando y fusionando duplicados por canonical_id...");
            $duplicatesSql = "SELECT canonical_id, COUNT(*) as count FROM pages GROUP BY canonical_id HAVING COUNT(*) > 1";
            $duplicates = $conn->fetchAllAssociative($duplicatesSql);

            foreach ($duplicates as $duplicate) {
                $cid = $duplicate['canonical_id'];
                $output->writeln("<comment>Fusionando duplicado: $cid</comment>");

                // Buscar candidatos ordenados por longitud de URL (para quedarnos con la más descriptiva)
                $candidates = $this->entityManager->getRepository(Page::class)->findBy(
                    ['canonicalId' => $cid],
                    ['url' => 'DESC']
                );

                if (count($candidates) < 2) continue;

                $survivor = array_shift($candidates);
                $survivorId = $survivor->getId();

                foreach ($candidates as $loser) {
                    $loserId = $loser->getId();
                    $output->writeln(" - Re-vinculando dependencias de #$loserId a #$survivorId...");

                    $conn->executeStatement("UPDATE metric_configs SET page_id = ? WHERE page_id = ?", [$survivorId, $loserId]);
                    $conn->executeStatement("UPDATE posts SET page_id = ? WHERE page_id = ?", [$survivorId, $loserId]);

                    $this->entityManager->remove($loser);
                }
            }
            $this->entityManager->flush();
            $output->writeln("Fusión completada.");

            // Paso 4: Ídice único (seguridad final)
            $output->writeln("Paso 4: Asegurando integridad con índice UNIQUE...");
            try {
                if (Helpers::isPostgres()) {
                    $conn->executeStatement("CREATE UNIQUE INDEX idx_pages_canonical_id ON pages (canonical_id)");
                } else {
                    $conn->executeStatement("ALTER TABLE pages ADD UNIQUE INDEX idx_pages_canonical_id (canonical_id)");
                }
                $output->writeln("<comment>Índice UNIQUE creado exitosamente.</comment>");
            } catch (Exception $e) {
                $output->writeln("Nota: El índice ya existe o no se pudo crear: " . $e->getMessage());
            }

            $output->writeln("<info>Migración finalizada con éxito.</info>");
            return Command::SUCCESS;
        } catch (Exception $e) {
            $output->writeln("<error>Error en la migración: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }
}

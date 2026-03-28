<?php

namespace Commands;

use Doctrine\ORM\EntityManagerInterface;
use Helpers\Helpers;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Exception;

#[AsCommand(
    name: 'app:migrate-pages-canonical',
    description: 'Generates canonical IDs (snake_case) for all pages and merges duplicates'
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
        $output->writeln("<info>Iniciando migración de identidades canónicas (SNAKE_CASE) en 'pages'...</info>");
        $conn = $this->entityManager->getConnection();
        $isPostgres = Helpers::isPostgres();

        try {
            // Paso 0: Limpieza de posibles columnas corruptas (legacy typo de camelCase)
            $output->writeln("Paso 0: Limpiando posibles columnas obsoletas (canonicalId)...");
            try {
                if ($isPostgres) {
                    $conn->executeStatement("ALTER TABLE pages DROP COLUMN IF EXISTS \"canonicalId\"");
                } else {
                    // MySQL compatibility check for DROP IF EXISTS is 8.0+, fallback to try/catch
                    $conn->executeStatement("ALTER TABLE pages DROP COLUMN canonicalId");
                }
                $output->writeln("<comment>Columna antigua eliminada.</comment>");
            } catch (Exception) {
                // Ignore if not present
            }

            // Paso 1: Asegurar columna física snake_case
            $output->writeln("Paso 1: Verificando la columna 'canonical_id'...");
            try {
                $conn->executeStatement("ALTER TABLE pages ADD COLUMN canonical_id VARCHAR(255) NULL");
                $output->writeln("<comment>Columna creada.</comment>");
            } catch (Exception) {
                $output->writeln("Nota: La columna already exists.");
            }

            // Paso 2: Generar IDs canónicos (SQL Nativo para evitar cache de metadatos)
            $output->writeln("Paso 2: Generando IDs canónicos para todos los registros...");
            // Now we FETCH hostname too
            $pages = $conn->fetchAllAssociative("SELECT id, url, platform_id, hostname, data FROM pages");
            foreach ($pages as $page) {
                $data = json_decode($page['data'] ?? '{}', true);
                $type = $data['source'] ?? null;
                // PASS HOSTNAME to helper for better social detection
                $canonicalId = Helpers::getCanonicalPageId($page['url'], $page['platform_id'], $type, $page['hostname']);
                
                $conn->executeStatement("UPDATE pages SET canonical_id = ? WHERE id = ?", [$canonicalId, $page['id']]);
            }
            $output->writeln("IDs generados y persistidos.");

            // Paso 3: Fusionar duplicados (SQL Nativo)
            $output->writeln("Paso 3: Buscando y fusionando duplicados por canonical_id...");
            $duplicates = $conn->fetchAllAssociative("SELECT canonical_id, COUNT(*) as count FROM pages GROUP BY canonical_id HAVING COUNT(*) > 1");

            foreach ($duplicates as $duplicate) {
                $cid = $duplicate['canonical_id'];
                if (!$cid) continue;
                
                $output->writeln("<comment>Fusionando duplicado: $cid</comment>");

                // Buscar candidatos ordenados por longitud de URL (para quedarnos con la más descriptiva)
                $candidates = $conn->fetchAllAssociative("SELECT id FROM pages WHERE canonical_id = ? ORDER BY LENGTH(url) DESC", [$cid]);

                if (count($candidates) < 2) continue;

                $survivorId = $candidates[0]['id'];
                array_shift($candidates);

                foreach ($candidates as $loser) {
                    $loserId = $loser['id'];
                    $output->writeln(" - Re-vinculando dependencias de #$loserId a #$survivorId...");

                    $conn->executeStatement("UPDATE metric_configs SET page_id = ? WHERE page_id = ?", [$survivorId, $loserId]);
                    $conn->executeStatement("UPDATE posts SET page_id = ? WHERE page_id = ?", [$survivorId, $loserId]);

                    $conn->executeStatement("DELETE FROM pages WHERE id = ?", [$loserId]);
                }
            }
            $output->writeln("Fusión completada.");

            // Paso 4: Ídice único (seguridad final)
            $output->writeln("Paso 4: Asegurando integridad con índice UNIQUE...");
            try {
                if ($isPostgres) {
                    $conn->executeStatement("CREATE UNIQUE INDEX idx_pages_canonical_id ON pages (canonical_id)");
                } else {
                    // Try to drop ANY previous index name first to avoid collision
                    try { $conn->executeStatement("ALTER TABLE pages DROP INDEX idx_pages_canonicalId"); } catch (Exception) {}
                    try { $conn->executeStatement("ALTER TABLE pages DROP INDEX idx_pages_canonical_id"); } catch (Exception) {}
                    
                    $conn->executeStatement("ALTER TABLE pages ADD UNIQUE INDEX idx_pages_canonical_id (canonical_id)");
                }
                $output->writeln("<comment>Índice UNIQUE creado exitosamente.</comment>");
            } catch (Exception $e) {
                $output->writeln("Nota: El índice ya existe o hubo un error: " . $e->getMessage());
            }

            $output->writeln("<info>Migración finalizada con éxito.</info>");
            return Command::SUCCESS;
        } catch (Exception $e) {
            $output->writeln("<error>Error en la migración: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }
}

<?php

namespace Commands;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use ReflectionUnionType;
use ReflectionNamedType;

#[AsCommand(
    name: 'generate:entities-config',
    description: 'Generates entities config with precise dynamic analysis'
)]
class GenerateEntitiesConfigCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $entities = [];
        $basePath = realpath(__DIR__.'/../../').'/';

        $entityDirs = [
            'general' => $basePath.'src/Entities',
            'channeled' => $basePath.'src/Entities/Analytics/Channeled'
        ];

        $channeledClassMap = [];

        foreach ($entityDirs as $type => $dir) {
            foreach ($this->findPhpFiles($dir) as $file) {
                if ($config = $this->processEntityFile($file, $type, $output)) {
                    $key = $this->generateEntityKey($config['class'], $type);

                    if ($type === 'channeled') {
                        $shortName = substr($config['class'], strrpos($config['class'], '\\') + 1);
                        $channeledClassMap[$shortName] = $config['class'];
                    }

                    $entities[$key] = $config;
                }
            }
        }

        if (empty($entities)) {
            $output->writeln("<error>No valid entities with analyzable repositories found</error>");
            return Command::FAILURE;
        }

        foreach ($entities as $key => &$config) {
            if (!str_starts_with($key, 'channeled_')) {
                $shortName = substr($config['class'], strrpos($config['class'], '\\') + 1);
                $channeledName = 'Channeled' . $shortName;

                if (isset($channeledClassMap[$channeledName])) {
                    $channeledClass = '\\' . $channeledClassMap[$channeledName];

                    // Rebuild the array maintaining desired order
                    $config = array_merge(
                        ['class' => $config['class']],
                        ['channeled_class' => $channeledClass],
                        array_diff_key($config, ['class' => 1])
                    );
                }
            }
        }

        $this->saveConfig($entities, $output);
        return Command::SUCCESS;
    }

    private function findPhpFiles(string $dir): \Generator
    {
        if (!is_dir($dir)) return;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }

    private function processEntityFile(
        string $file,
        string $type,
        OutputInterface $output
    ): ?array {
        $content = file_get_contents($file);
        if (!$content) {
            $output->writeln("<comment>Empty file: $file</comment>", OutputInterface::VERBOSITY_VERBOSE);
            return null;
        }

        $classInfo = $this->extractClassInfo($content);
        if (!$classInfo) {
            $output->writeln("<comment>Invalid entity in: $file</comment>", OutputInterface::VERBOSITY_VERBOSE);
            return null;
        }

        $repoShort = $this->extractRepositoryClass($content);
        if (!$repoShort) {
            return null;
        }

        $repoFQN = $this->resolveRepositoryClassFromUse($repoShort, $content) ?? $repoShort;
        $repositoryClass = $this->resolveRepositoryClass($repoFQN, $type);
        if (!$repositoryClass) {
            return null;
        }

        $methods = $this->analyzeRepositoryMethods($repositoryClass, $output);
        if (empty($methods)) {
            $output->writeln("<warning>No methods found in repository: $repositoryClass</warning>", OutputInterface::VERBOSITY_VERBOSE);
            return null;
        }

        $config = [
            'class' => $this->normalizeClassName($classInfo['fullName']),
            'crud_enabled' => true,
            'repository_class' => $this->normalizeClassName($repositoryClass),
            'repository_methods' => $methods
        ];

        // Only for general type: try to add channeled_class
        if ($type === 'general') {
            $channeledClass = 'Entities\\Analytics\\Channeled\\Channeled' . $classInfo['shortName'];
            if (class_exists($channeledClass)) {
                $config = ['class' => $config['class'], 'channeled_class' => $this->normalizeClassName($channeledClass)] + $config;
            }
        }

        return $config;
    }

    private function extractClassInfo(string $content): ?array
    {
        if (!preg_match('/namespace\s+(.+?);.*?class\s+(\w+)/s', $content, $matches)) {
            return null;
        }

        return [
            'fullName' => $matches[1].'\\'.$matches[2],
            'shortName' => $matches[2]
        ];
    }

    private function resolveRepositoryClass(string $repoClass, string $type): string
    {
        if (class_exists($repoClass)) {
            return $repoClass;
        }

        // Prefix if it's short (with no namespace)
        if (!str_contains($repoClass, '\\')) {
            $repoClass = ($type === 'channeled')
                ? 'Repositories\\Channeled\\' . $repoClass
                : 'Repositories\\' . $repoClass;
        }

        if (!class_exists($repoClass)) {
            throw new RuntimeException("Repository not found: $repoClass");
        }

        return $repoClass;
    }

    private function loadRepositoryClass(string $className, OutputInterface $output): bool
    {
        if (class_exists($className)) {
            return true;
        }

        $filePath = __DIR__.'/../../src/'.str_replace('\\', '/', $className).'.php';
        if (file_exists($filePath)) {
            include_once $filePath;
            if (class_exists($className)) {
                $output->writeln("<info>Dynamically loaded repository: $className</info>", OutputInterface::VERBOSITY_VERBOSE);
                return true;
            }
        }

        return false;
    }

    protected function analyzeRepositoryMethods(
        string $repositoryClass,
        OutputInterface $output
    ): array {
        try {
            $reflection = new ReflectionClass($repositoryClass);
            $methods = [];

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || $method->isConstructor()) {
                    continue;
                }

                $methodInfo = [
                    'parameters' => array_map(
                        fn($param) => $param->getName(),
                        $method->getParameters()
                    )
                ];

                if ($returnType = $method->getReturnType()) {
                    $methodInfo['return_type'] = $this->getTypeName($returnType);
                }

                $methods[$method->getName()] = $methodInfo;
            }

            return $methods;

        } catch (ReflectionException $e) {
            $output->writeln("<error>Reflection failed for $repositoryClass: {$e->getMessage()}</error>");
            return [];
        }
    }

    private function getTypeName($type): string
    {
        if ($type instanceof ReflectionNamedType) {
            return $type->getName();
        }

        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map(
                fn($t) => $t->getName(),
                $type->getTypes()
            ));
        }

        return 'mixed';
    }

    private function generateEntityKey(string $className, string $type): string
    {
        $shortName = substr($className, strrpos($className, '\\') + 1);

        // Delete duplicated "Channeled" for channeled entities
        if ($type === 'channeled' && str_starts_with($shortName, 'Channeled')) {
            $shortName = substr($shortName, strlen('Channeled'));
        }
        $key = strtolower(ltrim(preg_replace('/(?<!^)([A-Z])/', '_$1', $shortName), '_'));
        return ($type === 'channeled')
            ? 'channeled_'.ltrim($key, '_')
            : ltrim($key, '_');
    }

    private function saveConfig(array $entities, OutputInterface $output): void
    {
        $yaml = Yaml::dump($entities, 6, 2, Yaml::DUMP_OBJECT_AS_MAP);
        file_put_contents(__DIR__.'/../../config/yaml/entitiesconfig.yaml', $yaml);
        $output->writeln("<info>Successfully generated config for ".count($entities)." entities</info>");
    }

    private function extractRepositoryClass(string $content): ?string
    {
        if (
            preg_match(
                '/#\[\s*ORM\\\\Entity\s*\(\s*repositoryClass\s*:\s*([^\)\]]+)/',
                $content,
                $matches
            )
        ) {
            $raw = trim($matches[1]);

            // If it's of the "Class::class" type, convert it to a "Class"
            if (str_ends_with($raw, '::class')) {
                $raw = substr($raw, 0, -7);
            }

            // Delete possible remaining wrong characters
            return trim($raw, '\'"\\)');
        }

        return null;
    }

    private function resolveRepositoryClassFromUse(string $shortName, string $content): ?string
    {
        if (preg_match_all('/use\s+([^\s;]+)\\\\('.$shortName.');/', $content, $matches)) {
            return $matches[1][0].'\\'.$matches[2][0];
        }
        return null;
    }

    private function normalizeClassName(string $className): string
    {
        return ltrim($className, '\\');
    }
}
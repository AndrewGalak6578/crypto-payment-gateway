<?php
declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use ReflectionClass;
use ReflectionException;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;

class GenerateProjectDocs extends Command
{
    protected $signature = 'docs:generate-code {--path=docs/project}';

    protected $description = 'Generate Markdown documentation for application classes';

    public function handle(Filesystem $files): int
    {
        $targetPath = base_path((string) $this->option('path'));
        $classesPath = $targetPath.'/classes';

        $files->ensureDirectoryExists($classesPath);

        $classes = $this->collectClasses($files);
        $sections = [
            'Contracts' => 'App\\Contracts\\',
            'Data Objects' => 'App\\Data\\',
            'Exceptions' => 'App\\Exceptions\\',
            'HTTP Controllers' => 'App\\Http\\Controllers\\',
            'HTTP Middleware' => 'App\\Http\\Middleware\\',
            'HTTP Requests' => 'App\\Http\\Requests\\',
            'Jobs' => 'App\\Jobs\\',
            'Models' => 'App\\Models\\',
            'Providers' => 'App\\Providers\\',
            'Services' => 'App\\Services\\',
            'Support' => 'App\\Support\\',
        ];

        $index = [
            '# Project Code Documentation',
            '',
            'Generated from PHP reflection. Run `php artisan docs:generate-code` to refresh.',
            '',
            '## Sections',
            '',
        ];

        foreach ($sections as $title => $prefix) {
            $sectionClasses = array_values(array_filter(
                $classes,
                fn (ReflectionClass $class): bool => str_starts_with($class->getName(), $prefix)
            ));

            if ($sectionClasses === []) {
                continue;
            }

            $sectionFile = $this->sectionFilename($title);
            $index[] = "- [{$title}](./{$sectionFile}) ({$this->pluralize(count($sectionClasses), 'class', 'classes')})";

            $files->put($targetPath.'/'.$sectionFile, $this->renderSection($title, $sectionClasses));
        }

        $index[] = '';
        $index[] = '## All Classes';
        $index[] = '';

        foreach ($classes as $class) {
            $classFile = 'classes/'.$this->classFilename($class);
            $index[] = "- [{$class->getName()}](./{$classFile})";
            $files->put($targetPath.'/'.$classFile, $this->renderClass($class));
        }

        $files->put($targetPath.'/index.md', implode(PHP_EOL, $index).PHP_EOL);

        $this->info('Generated '.count($classes).' class documents in '.str_replace(base_path().'/', '', $targetPath).'.');

        return self::SUCCESS;
    }

    /**
     * @return ReflectionClass[]
     */
    private function collectClasses(Filesystem $files): array
    {
        $classes = [];

        foreach ($files->allFiles(app_path()) as $file) {
            $className = $this->classNameFromFile($file->getPathname());

            if ($className === null || ! class_exists($className) && ! interface_exists($className) && ! trait_exists($className) && ! enum_exists($className)) {
                continue;
            }

            try {
                $classes[] = new ReflectionClass($className);
            } catch (ReflectionException $exception) {
                $this->warn("Skipping {$className}: {$exception->getMessage()}");
            }
        }

        usort($classes, fn (ReflectionClass $a, ReflectionClass $b): int => $a->getName() <=> $b->getName());

        return $classes;
    }

    private function classNameFromFile(string $path): ?string
    {
        $tokens = token_get_all((string) file_get_contents($path));
        $namespace = '';

        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_NAMESPACE) {
                $namespace = $this->readNamespace($tokens, $i + 1);
                continue;
            }

            if (is_array($token) && in_array($token[0], $this->classLikeTokens(), true) && ! $this->isAnonymousClass($tokens, $i)) {
                $name = $this->readNextStringToken($tokens, $i + 1);

                return $name ? trim($namespace.'\\'.$name, '\\') : null;
            }
        }

        return null;
    }

    /**
     * @param array<int, mixed> $tokens
     */
    private function readNamespace(array $tokens, int $start): string
    {
        $namespace = '';

        for ($i = $start, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === ';' || $token === '{') {
                break;
            }

            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                $namespace .= $token[1];
            }
        }

        return $namespace;
    }

    /**
     * @param array<int, mixed> $tokens
     */
    private function readNextStringToken(array $tokens, int $start): ?string
    {
        for ($i = $start, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_STRING) {
                return $token[1];
            }
        }

        return null;
    }

    /**
     * @param array<int, mixed> $tokens
     */
    private function isAnonymousClass(array $tokens, int $position): bool
    {
        for ($i = $position - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token) && $token[0] === T_NEW;
        }

        return false;
    }

    /**
     * @return int[]
     */
    private function classLikeTokens(): array
    {
        $tokens = [T_CLASS, T_INTERFACE, T_TRAIT];

        if (defined('T_ENUM')) {
            $tokens[] = T_ENUM;
        }

        return $tokens;
    }

    /**
     * @param ReflectionClass[] $classes
     */
    private function renderSection(string $title, array $classes): string
    {
        $lines = [
            "# {$title}",
            '',
            "| Class | Type | Summary |",
            "| --- | --- | --- |",
        ];

        foreach ($classes as $class) {
            $summary = $this->summary($class->getDocComment() ?: '') ?: '-';
            $lines[] = '| ['.$class->getName().'](./classes/'.$this->classFilename($class).') | '.$this->classKind($class).' | '.$this->escapeTable($summary).' |';
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private function renderClass(ReflectionClass $class): string
    {
        $lines = [
            '# '.$class->getName(),
            '',
            '- Type: `'.$this->classKind($class).'`',
            '- File: `'.$this->relativePath((string) $class->getFileName()).'`',
        ];

        if ($class->getParentClass()) {
            $lines[] = '- Extends: `'.$class->getParentClass()->getName().'`';
        }

        if ($class->getInterfaceNames() !== []) {
            $lines[] = '- Implements: `'.implode('`, `', $class->getInterfaceNames()).'`';
        }

        if ($class->getTraitNames() !== []) {
            $lines[] = '- Uses traits: `'.implode('`, `', $class->getTraitNames()).'`';
        }

        $doc = $this->cleanDoc($class->getDocComment() ?: '');

        if ($doc !== '') {
            $lines[] = '';
            $lines[] = '## Description';
            $lines[] = '';
            $lines[] = $doc;
        }

        $properties = array_filter(
            $class->getProperties(ReflectionProperty::IS_PUBLIC),
            fn (ReflectionProperty $property): bool => $property->getDeclaringClass()->getName() === $class->getName()
        );

        if ($properties !== []) {
            $lines[] = '';
            $lines[] = '## Public Properties';
            $lines[] = '';

            foreach ($properties as $property) {
                $lines[] = '- `'.$this->typeName($property->getType()).' $'.$property->getName().'`';
            }
        }

        $methods = array_filter(
            $class->getMethods(ReflectionMethod::IS_PUBLIC),
            fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $class->getName()
        );

        if ($methods !== []) {
            $lines[] = '';
            $lines[] = '## Public Methods';
            $lines[] = '';

            foreach ($methods as $method) {
                $lines[] = '### `'.$this->methodSignature($method).'`';
                $summary = $this->summary($method->getDocComment() ?: '');

                if ($summary !== '') {
                    $lines[] = '';
                    $lines[] = $summary;
                }

                $lines[] = '';
            }
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private function methodSignature(ReflectionMethod $method): string
    {
        $parameters = array_map(
            fn (ReflectionParameter $parameter): string => $this->parameterSignature($parameter),
            $method->getParameters()
        );

        return $method->getName().'('.implode(', ', $parameters).'): '.$this->typeName($method->getReturnType());
    }

    private function parameterSignature(ReflectionParameter $parameter): string
    {
        $signature = $this->typeName($parameter->getType()).' ';

        if ($parameter->isPassedByReference()) {
            $signature .= '&';
        }

        if ($parameter->isVariadic()) {
            $signature .= '...';
        }

        $signature .= '$'.$parameter->getName();

        if ($parameter->isDefaultValueAvailable() && ! $parameter->isVariadic()) {
            $signature .= ' = '.$this->formatDefaultValue($parameter);
        }

        return $signature;
    }

    private function typeName(?ReflectionType $type): string
    {
        if ($type === null) {
            return 'mixed';
        }

        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();

            return ($type->allowsNull() && $name !== 'mixed' && $name !== 'null' ? '?' : '').$name;
        }

        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map(fn (ReflectionType $type): string => $this->typeName($type), $type->getTypes()));
        }

        if ($type instanceof ReflectionIntersectionType) {
            return implode('&', array_map(fn (ReflectionType $type): string => $this->typeName($type), $type->getTypes()));
        }

        return (string) $type;
    }

    private function formatDefaultValue(ReflectionParameter $parameter): string
    {
        if ($parameter->isDefaultValueConstant()) {
            return (string) $parameter->getDefaultValueConstantName();
        }

        return str_replace(PHP_EOL, '', var_export($parameter->getDefaultValue(), true));
    }

    private function classKind(ReflectionClass $class): string
    {
        return match (true) {
            $class->isInterface() => 'interface',
            $class->isTrait() => 'trait',
            $class->isEnum() => 'enum',
            $class->isAbstract() => 'abstract class',
            default => 'class',
        };
    }

    private function cleanDoc(string $doc): string
    {
        if ($doc === '') {
            return '';
        }

        $doc = preg_replace('/^\\s*\\/\\*\\*|\\*\\/\\s*$/', '', $doc) ?: '';
        $lines = array_map(
            fn (string $line): string => trim((string) preg_replace('/^\\s*\\* ?/', '', $line)),
            preg_split('/\\R/', $doc) ?: []
        );

        return trim(implode(PHP_EOL, array_filter($lines, fn (string $line): bool => $line !== '')));
    }

    private function summary(string $doc): string
    {
        $doc = $this->cleanDoc($doc);

        if ($doc === '') {
            return '';
        }

        return strtok($doc, PHP_EOL) ?: '';
    }

    private function sectionFilename(string $title): string
    {
        return strtolower(str_replace(' ', '-', $title)).'.md';
    }

    private function classFilename(ReflectionClass $class): string
    {
        return str_replace('\\', '__', $class->getName()).'.md';
    }

    private function relativePath(string $path): string
    {
        return str_replace(base_path().'/', '', $path);
    }

    private function pluralize(int $count, string $singular, string $plural): string
    {
        return $count.' '.($count === 1 ? $singular : $plural);
    }

    private function escapeTable(string $value): string
    {
        return str_replace('|', '\\|', $value);
    }
}

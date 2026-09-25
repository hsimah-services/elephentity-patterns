<?php

declare(strict_types=1);

use Eleph\Schema\SchemaCompiler;
use Eleph\Schema\SpecSource;

require $argv[1] ?? throw new RuntimeException('Pass the Elephentity vendor/autoload.php path.');

$catalog = json_decode(file_get_contents(__DIR__ . '/../catalog.json'), true, flags: JSON_THROW_ON_ERROR);
$directory = sys_get_temp_dir() . '/eleph-patterns-' . bin2hex(random_bytes(8));
mkdir($directory . '/entities', recursive: true);
mkdir($directory . '/patterns');

try {
    file_put_contents($directory . '/project.yml', "project: CatalogTest\nstorage:\n  driver: memory\n");
    foreach ($catalog['patterns'] as $name => $entry) {
        if ('ready' !== $entry['status']) {
            continue;
        }
        copy(__DIR__ . '/../patterns/' . $name . '/pattern.yml', $directory . '/patterns/' . $name . '.yml');
        file_put_contents($directory . '/entities/' . $name . 'Example.yml', "entity: {$name}Example\nuse: [{$name}]\nstorage:\n  table: " . strtolower($name) . "_example\n");
    }
    $result = (new SchemaCompiler())->compile(new SpecSource($directory));
    if (!$result->isSuccess()) {
        throw new RuntimeException(json_encode($result->errors, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
    foreach ($catalog['patterns'] as $name => $entry) {
        if ('ready' === $entry['status'] && null === $result->schema()->pattern($name)) {
            throw new RuntimeException('Missing compiled pattern: ' . $name);
        }
    }
    echo "All ready catalog patterns compile successfully.\n";
} finally {
    foreach (glob($directory . '/entities/*') as $file) {
        unlink($file);
    }
    foreach (glob($directory . '/patterns/*') as $file) {
        unlink($file);
    }
    unlink($directory . '/project.yml');
    rmdir($directory . '/entities');
    rmdir($directory . '/patterns');
    rmdir($directory);
}

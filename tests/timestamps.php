<?php

declare(strict_types=1);

use App\Patterns\HasTimestamps\HasTimestampsTrigger;
use Eleph\Gen\Php\PhpTarget;
use Eleph\Gen\Php\TargetRequest;
use Eleph\Gen\Php\Wire\IrCodec as PhpIrCodec;
use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Identity\PendingId;
use Eleph\Runtime\Mutation\Mutation;
use Eleph\Runtime\SideEffect\SideEffectPhase;
use Eleph\Runtime\Type\NullProcessorRegistry;
use Eleph\Runtime\UnitOfWork\SideEffectDispatcher;
use Eleph\Runtime\UnitOfWork\VerificationPipeline;
use Eleph\Schema\SchemaCompiler;
use Eleph\Schema\SpecSource;
use Eleph\Schema\Wire\IrCodec;

$workspace = $argv[1] ?? throw new RuntimeException('Pass the Elephentity workspace directory.');
require $workspace . '/elephentity/vendor/autoload.php';
require $workspace . '/elephentity-codegen-php/vendor/autoload.php';
require $workspace . '/elephentity-runtime/vendor/autoload.php';
require __DIR__ . '/../patterns/HasTimestamps/HasTimestampsTrigger.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$directory = sys_get_temp_dir() . '/eleph-timestamps-' . bin2hex(random_bytes(8));
mkdir($directory . '/spec/entities', recursive: true);
mkdir($directory . '/spec/patterns');

try {
    file_put_contents($directory . '/spec/project.yml', "project: TimestampTest\nstorage:\n  driver: memory\n");
    copy(__DIR__ . '/../patterns/HasTimestamps/pattern.yml', $directory . '/spec/patterns/HasTimestamps.yml');
    foreach (['Article', 'Comment'] as $name) {
        file_put_contents($directory . '/spec/entities/' . $name . '.yml', "entity: {$name}\nuse: [HasTimestamps]\nstorage:\n  table: " . strtolower($name) . "\n");
    }
    $compiled = (new SchemaCompiler())->compile(new SpecSource($directory . '/spec'));
    check($compiled->isSuccess(), 'Timestamp spec failed compilation: ' . json_encode($compiled->errors));
    $schema = PhpIrCodec::decode(IrCodec::encode($compiled->schema()));
    $generated = (new PhpTarget())->generate(TargetRequest::of($directory . '/generated', [
        'namespace' => 'TimestampTest',
        'typeNamespace' => 'TimestampTest\\Type',
    ]), $schema);
    check($generated->isSuccess(), 'Timestamp generation failed: ' . json_encode($generated->errors));
    foreach ($generated->files as $file) {
        $path = $directory . '/generated/' . $file->relativePath;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), recursive: true);
        }
        file_put_contents($path, "<?php\n\ndeclare(strict_types=1);\n\n" . $file->body);
    }
    $autoload = static function (string $class) use ($directory): void {
        if (str_starts_with($class, 'TimestampTest\\')) {
            $path = $directory . '/generated/' . str_replace('\\', '/', substr($class, strlen('TimestampTest\\'))) . '.php';
            if (is_file($path)) {
                require $path;
            }
        }
    };
    spl_autoload_register($autoload);

    $now = new DateTimeImmutable('2026-09-25T12:00:00Z');
    $calls = 0;
    $trigger = new class (static function () use (&$now, &$calls): DateTimeImmutable {
        ++$calls;
        return $now;
    }) extends HasTimestampsTrigger implements
        TimestampTest\Article\Contract\ArticleTimestampsSideEffect,
        TimestampTest\Comment\Contract\CommentTimestampsSideEffect
    {
    };
    $dispatcher = new SideEffectDispatcher([
        'Article' => new TimestampTest\Article\ArticleSideEffects($trigger),
        'Comment' => new TimestampTest\Comment\CommentSideEffects($trigger),
    ]);
    $verification = new VerificationPipeline([], [], new NullProcessorRegistry(), [
        'Article' => ['createdAt', 'updatedAt'],
        'Comment' => ['createdAt', 'updatedAt'],
    ]);

    foreach (['Article', 'Comment'] as $name) {
        foreach ($schema->entity($name)->fields as $field) {
            check(null === $field->managed, 'Runtime stamping must not overwrite custom trigger values.');
        }
        $create = new Mutation($name, new PendingId($name));
        $create->set('createdAt', new DateTimeImmutable('2000-01-01Z'));
        $create->set('updatedAt', new DateTimeImmutable('2000-01-01Z'));
        $before = $calls;
        $dispatcher->dispatch(SideEffectPhase::PreCommit, [$create]);
        check($calls === $before + 1, 'Creation must read the clock once.');
        check($create->pending('createdAt') === $now && $create->pending('updatedAt') === $now, 'Creation must stamp both fields with the same instant.');
        check([] === $verification->verify($create), 'Trigger must fill required timestamps before verification.');
        $createdAt = $now;
        $updatedAt = $now;

        for ($i = 0; $i < 2; ++$i) {
            $now = $now->modify('+1 hour');
            $update = new Mutation($name, EntityId::of(1), ['createdAt' => $createdAt, 'updatedAt' => $updatedAt]);
            if (0 === $i) {
                $update->set('updatedAt', new DateTimeImmutable('2000-01-01Z'));
            }
            $dispatcher->dispatch(SideEffectPhase::PreCommit, [$update]);
            check(!$update->isChanged('createdAt') && $update->pending('createdAt') === $createdAt, 'Update must leave creation time untouched.');
            check($update->pending('updatedAt') === $now, 'Every update, including an empty update, must refresh modification time.');
            $updatedAt = $now;
        }

        $deletion = new Mutation($name, EntityId::of(1), ['createdAt' => $createdAt, 'updatedAt' => $updatedAt]);
        $before = $calls;
        $dispatcher->dispatchDeletions(SideEffectPhase::PreCommit, [$deletion]);
        $dispatcher->dispatchDeletions(SideEffectPhase::PostCommit, [$deletion]);
        $dispatcher->dispatch(SideEffectPhase::PostCommit, [$create, $update]);
        check($calls === $before && [] === $deletion->changes(), 'Delete and post-commit dispatch must not invoke the trigger.');

        $invalid = new Mutation($name, EntityId::of(1), ['createdAt' => $createdAt]);
        $invalid->set('createdAt', $now);
        try {
            $dispatcher->dispatch(SideEffectPhase::PreCommit, [$invalid]);
            throw new RuntimeException('A write to createdAt on update must be rejected.');
        } catch (DomainException) {
            check(!$invalid->isChanged('updatedAt'), 'Rejected creation-time edits must not stamp modification time.');
        }
    }
    $defaultTrigger = new class extends HasTimestampsTrigger {};
    $mutation = new Mutation('Article', new PendingId('Article'));
    $before = new DateTimeImmutable();
    $defaultTrigger->handle($mutation);
    $timestamp = $mutation->pending('createdAt');
    check($timestamp instanceof DateTimeImmutable && $timestamp >= $before && $timestamp <= new DateTimeImmutable(), 'Default clock must use the current time.');
    check('UTC' === $timestamp->getTimezone()->getName(), 'Default clock must use UTC.');
    echo "Timestamp trigger passes generated create/update/delete dispatch for two entities.\n";
} finally {
    if (isset($autoload)) {
        spl_autoload_unregister($autoload);
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($directory);
}

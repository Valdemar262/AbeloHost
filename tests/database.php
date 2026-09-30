<?php

declare(strict_types=1);

use App\Database;
use App\Database\DatabaseLock;
use App\Database\MigrationRunner;
use App\Database\Seeder;
use App\Repository\CategoryRepository;
use App\Repository\PostRepository;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$config = require $root . '/config/app.php';

if ($config['database']['name'] !== 'abelohost_test') {
    throw new RuntimeException('Database checks require an empty, dedicated abelohost_test database.');
}

$pdo = Database::connect($config['database']);
$tableCount = $pdo->query(
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()',
)->fetchColumn();

if ((int) $tableCount !== 0) {
    throw new RuntimeException('Test database is not empty. Use the isolated compose.test.yaml environment.');
}

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }

    $checks++;
};
$expectException = static function (
    callable $operation,
    string $type,
    string $message,
) use ($check): void {
    try {
        $operation();
    } catch (Throwable $exception) {
        $check($exception instanceof $type, $message . ': ' . $exception->getMessage());
        return;
    }

    throw new RuntimeException($message);
};
$count = static fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();
$snapshot = static function () use ($pdo): array {
    return [
        'categories' => $pdo->query('SELECT * FROM categories ORDER BY id')->fetchAll(),
        'posts' => $pdo->query('SELECT * FROM posts ORDER BY id')->fetchAll(),
        'links' => $pdo->query('SELECT * FROM post_category ORDER BY post_id, category_id')->fetchAll(),
    ];
};
$startCommand = static function (string $filename) use ($root): array {
    $process = proc_open([PHP_BINARY, $root . '/bin/' . $filename], [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        throw new RuntimeException('Could not start CLI command.');
    }

    return [$process, $pipes];
};
$finishCommand = static function (array $command) use ($check): string {
    [$process, $pipes] = $command;
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $check(proc_close($process) === 0 && $errors === '', 'CLI command must succeed: ' . $errors);

    return $output;
};

$runner = new MigrationRunner($pdo, $root . '/database/migrations');
$check(count($runner->run()) === 3, 'All three migrations must run on an empty schema.');
$check($runner->run() === [], 'Applied migrations must not run twice.');
$check($count('SELECT COUNT(*) FROM schema_migrations WHERE applied_at IS NOT NULL') === 3, 'History must be complete.');
$check(
    str_contains($finishCommand($startCommand('migrate.php')), 'up to date'),
    'Migration CLI must report that no changes are needed.',
);

$pdo->beginTransaction();
$expectException(fn () => $runner->run(), LogicException::class, 'DDL must not commit a caller transaction.');
$pdo->rollBack();
$expectException(
    fn () => $pdo->exec('SELECT 1; SELECT 2'),
    PDOException::class,
    'The connection must reject multiple SQL statements.',
);

$seedCommands = [$startCommand('seed.php'), $startCommand('seed.php')];
$seedOutputs = array_map($finishCommand, $seedCommands);
$check(
    count(array_filter($seedOutputs, static fn (string $output): bool => str_contains($output, '5 categories and 31 posts')))
        === 1,
    'Only one concurrent seed command may create the dataset.',
);
$check(
    count(array_filter($seedOutputs, static fn (string $output): bool => str_contains($output, '0 categories and 0 posts')))
        === 1,
    'The other concurrent seed command must preserve the dataset.',
);

$data = require $root . '/database/seeds/blog.php';
$seeder = new Seeder($pdo, $root . '/public');
$categories = new CategoryRepository($pdo);
$posts = new PostRepository($pdo);
$check($count('SELECT COUNT(*) FROM categories') === 5, 'Five categories must be seeded.');
$check($count('SELECT COUNT(*) FROM posts') === 31, 'Thirty-one posts must be seeded.');
$check($count('SELECT COUNT(*) FROM posts WHERE published_at <= UTC_TIMESTAMP()') === 30, 'Thirty posts must be published.');
$check($count('SELECT COUNT(*) FROM posts WHERE published_at > UTC_TIMESTAMP()') === 1, 'One post must be scheduled.');
$check($count(<<<'SQL'
    SELECT COUNT(*) FROM posts p
    WHERE NOT EXISTS (SELECT 1 FROM post_category pc WHERE pc.post_id = p.id)
    SQL) === 0, 'Every post must have a category.');
$check($count(<<<'SQL'
    SELECT COUNT(*) FROM post_category pc
    JOIN categories c ON c.id = pc.category_id WHERE c.slug = 'notes'
    SQL) === 0, 'The notes category must be empty.');
$check($count(<<<'SQL'
    SELECT COUNT(*) FROM post_category pc
    JOIN categories c ON c.id = pc.category_id WHERE c.slug = 'frontend'
    SQL) === 2, 'Frontend must contain only two articles.');
$check($count(<<<'SQL'
    SELECT COUNT(*) FROM posts p
    JOIN post_category pc ON pc.post_id = p.id
    JOIN categories c ON c.id = pc.category_id
    WHERE c.slug = 'php' AND p.published_at <= UTC_TIMESTAMP()
    SQL) >= 12, 'PHP must have enough posts for pagination.');
$check($count(<<<'SQL'
    SELECT COUNT(*) FROM (SELECT post_id FROM post_category GROUP BY post_id HAVING COUNT(*) > 1) multiple_categories
    SQL) > 0, 'The dataset must contain posts in multiple categories.');
$check($count(<<<'SQL'
    SELECT COUNT(*) FROM (SELECT published_at, views FROM posts GROUP BY published_at, views HAVING COUNT(*) > 1) ties
    SQL) > 0, 'The dataset must contain tied dates and view counts.');

foreach ($data['posts'] as $post) {
    $check(is_file($root . '/public' . $post['image_path']), 'Seed images must exist locally.');
}

$before = $snapshot();
$check($seeder->run($data) === ['categories' => 0, 'posts' => 0], 'A repeated seed must not create records.');
$check($snapshot() === $before, 'A repeated seed must not change records or relationships.');
$check($categories->findBySlug("' OR 1=1 --") === null, 'Category lookup must bind its parameters.');
$check($posts->findBySlug("' OR 1=1 --") === null, 'Post lookup must bind its parameters.');
$check($posts->findBySlug('missing') === null, 'Unknown posts must return null.');

$phpId = (int) $categories->findBySlug('php')['id'];
$sample = $data['posts'][0];
$sample['slug'] = 'custom-article';
$sample['body'] = 'Собственная запись: привет, мир 🌍';
unset($sample['views']);
$customId = $posts->create($sample, [$phpId, $phpId]);
$check((int) $posts->findBySlug('custom-article')['views'] === 0, 'New posts must default to zero views.');
$check($posts->findBySlug('custom-article')['body'] === $sample['body'], 'utf8mb4 text must round-trip correctly.');
$check($count('SELECT COUNT(*) FROM post_category WHERE post_id = ' . $customId) === 1, 'Duplicate categories are deduplicated.');
$categories->create('custom-category', 'Своя категория', 'Не относится к демонстрационным данным.');
$pdo->exec("UPDATE posts SET views = 1234, title = 'Изменённая статья' WHERE slug = 'strict-types'");
$pdo->exec("UPDATE categories SET description = 'Свое описание' WHERE slug = 'php'");
$before = $snapshot();
$seeder->run($data);
$check($snapshot() === $before, 'Seeding must preserve edits, views and custom records.');

$expectException(fn () => $posts->create($sample, []), InvalidArgumentException::class, 'Category-less posts must fail.');
$expectException(fn () => $posts->create($sample, [0]), InvalidArgumentException::class, 'Invalid category IDs must fail.');
$sample['slug'] = 'invalid-category-post';
$expectException(fn () => $posts->create($sample, [PHP_INT_MAX]), PDOException::class, 'Unknown categories must fail.');
$check($posts->findBySlug($sample['slug']) === null, 'Failure to create relationships must roll back the post.');
$check(!$pdo->inTransaction(), 'Failed standalone writes must close their transaction.');
$expectException(
    fn () => $categories->create('php', 'Duplicate', 'Duplicate'),
    PDOException::class,
    'Duplicate category slugs must fail.',
);
$sample['slug'] = 'strict-types';
$expectException(fn () => $posts->create($sample, [$phpId]), PDOException::class, 'Duplicate post slugs must fail.');
$sample['slug'] = 'negative-views';
$sample['views'] = -1;
$expectException(fn () => $posts->create($sample, [$phpId]), PDOException::class, 'Negative views must fail.');
$expectException(
    fn () => $pdo->exec('INSERT INTO post_category VALUES (' . $customId . ', ' . $phpId . ')'),
    PDOException::class,
    'Duplicate relationships must fail.',
);
$expectException(
    fn () => $pdo->exec('DELETE FROM categories WHERE id = ' . $phpId),
    PDOException::class,
    'Deleting a populated category must fail.',
);
$pdo->exec('DELETE FROM posts WHERE id = ' . $customId);
$check($count('SELECT COUNT(*) FROM post_category WHERE post_id = ' . $customId) === 0, 'Post deletion must remove its links.');

$broken = [
    'categories' => [['slug' => 'rollback-category', 'name' => 'Rollback', 'description' => 'Rollback']],
    'posts' => [
        array_replace($data['posts'][0], ['slug' => 'rollback-first', 'categories' => ['rollback-category']]),
        array_replace($data['posts'][1], ['slug' => 'rollback-last', 'categories' => ['unknown-category']]),
    ],
];
$before = $snapshot();
$expectException(fn () => $seeder->run($broken), RuntimeException::class, 'A broken seed must fail.');
$check($snapshot() === $before, 'Seed failure must roll back all records, including earlier successful writes.');
$broken['posts'] = [array_replace($broken['posts'][0], ['image_path' => '/assets/images/missing.svg'])];
$expectException(fn () => $seeder->run($broken), RuntimeException::class, 'Missing seed images must fail.');
$check($snapshot() === $before, 'Missing images must not leave partial data.');

$otherConnection = Database::connect($config['database']);
$expectException(
    fn () => (new DatabaseLock($pdo))->run(static fn () => throw new RuntimeException('Expected failure.')),
    RuntimeException::class,
    'Lock callback errors must propagate.',
);
$check((new DatabaseLock($otherConnection))->run(static fn (): bool => true), 'Failed commands must release their lock.');

$plan = $pdo->query(<<<'SQL'
    EXPLAIN SELECT id FROM posts
    WHERE published_at <= UTC_TIMESTAMP() ORDER BY published_at DESC, id DESC LIMIT 3
    SQL)->fetch();
$check(str_contains($plan['possible_keys'] ?? '', 'posts_published_at_id_index'), 'Date queries must have a usable index.');
$plan = $pdo->query(<<<'SQL'
    EXPLAIN SELECT id FROM posts ORDER BY views DESC, published_at DESC, id DESC LIMIT 9
    SQL)->fetch();
$check($plan['key'] === 'posts_views_published_at_id_index', 'View sorting must have an ordered index.');
$plan = $pdo->query('EXPLAIN SELECT post_id FROM post_category WHERE category_id = ' . $phpId)->fetch();
$check($plan['key'] === 'post_category_category_post_index', 'Category lookup must use the reverse relationship index.');

$directory = sys_get_temp_dir() . '/abelohost-migrations-' . bin2hex(random_bytes(6));
mkdir($directory, 0775, true);

try {
    foreach (glob($root . '/database/migrations/*.sql') as $file) {
        copy($file, $directory . '/' . basename($file));
    }

    $testRunner = new MigrationRunner($pdo, $directory);
    $firstMigration = $directory . '/001_create_categories.sql';
    $originalSql = file_get_contents($firstMigration);
    file_put_contents($firstMigration, $originalSql . "\n");
    $expectException(fn () => $testRunner->run(), RuntimeException::class, 'Changed applied migrations must fail.');
    file_put_contents($firstMigration, $originalSql);
    file_put_contents($directory . '/000_earlier.sql', 'SELECT 1');
    $expectException(fn () => $testRunner->run(), RuntimeException::class, 'Out-of-order migrations must fail.');
    unlink($directory . '/000_earlier.sql');
    file_put_contents($directory . '/004_invalid.sql', 'CREATE TABL invalid_schema (id INT)');
    $expectException(fn () => $testRunner->run(), RuntimeException::class, 'Failed DDL must stop migration processing.');
    $check(
        $count("SELECT COUNT(*) FROM schema_migrations WHERE migration = '004_invalid.sql' AND applied_at IS NULL") === 1,
        'Failed DDL must leave an incomplete history entry.',
    );
    $expectException(fn () => $testRunner->run(), RuntimeException::class, 'Incomplete migrations must block retries.');

    [$process, $pipes] = $startCommand('migrate.php');
    stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $check(proc_close($process) === 1 && str_contains($errors, 'Incomplete migration'), 'Failed CLI must return exit code 1.');
} finally {
    foreach (glob($directory . '/*') as $file) {
        unlink($file);
    }

    rmdir($directory);
}

fwrite(STDOUT, "Database checks passed: {$checks}.\n");

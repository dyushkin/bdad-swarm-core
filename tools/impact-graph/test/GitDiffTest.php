<?php

declare(strict_types=1);

namespace ImpactGraph\Test;

use ImpactGraph\Diff\GitDiff;
use PHPUnit\Framework\TestCase;

/**
 * Covers changed-file detection across the root repository and nested (symlinked) repositories.
 */
final class GitDiffTest extends TestCase
{
    private const SOURCE = 'app/code/Vendor/Sample/Model/Thing.php';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/impact-graph-git-' . bin2hex(random_bytes(4));
        $nested = $this->root . '/nested-repo';
        $this->write($nested . '/' . self::SOURCE, "<?php\n// base\n");
        $this->git($nested, 'init -q');
        $this->git($nested, 'add -A');
        $this->git($nested, 'commit -q -m base');
        mkdir($this->root . '/app/code/Vendor', 0777, true);
        symlink($nested . '/app/code/Vendor/Sample', $this->root . '/app/code/Vendor/Sample');
        $this->write($this->root . '/README.md', 'root');
        $this->git($this->root, 'init -q');
        $this->git($this->root, 'add README.md');
        $this->git($this->root, 'commit -q -m base');
        file_put_contents($nested . '/' . self::SOURCE, "<?php\n// head\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /**
     * Scenario: a PHP file changes in a nested repository that is symlinked into app/code.
     * Expected: the diff lists it under its root-relative path, and its base content is readable.
     */
    public function testReportsChangesInNestedRepositories(): void
    {
        $git = new GitDiff($this->root);

        self::assertSame([self::SOURCE], $git->changedPhpFiles('HEAD'));
        self::assertSame("<?php\n// base", $git->fileAtRef('HEAD', self::SOURCE));
    }

    private function git(string $dir, string $args): void
    {
        exec(
            'git -C ' . escapeshellarg($dir) . ' -c user.name=t -c user.email=t@t -c commit.gpgsign=false ' . $args,
            $output,
            $code
        );
        self::assertSame(0, $code, "git $args failed in $dir");
    }

    private function write(string $file, string $contents): void
    {
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $contents);
    }
}

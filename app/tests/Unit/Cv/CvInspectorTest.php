<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cv;

use App\Cv\CvInspector;
use App\Cv\CvFileStatus;
use PHPUnit\Framework\TestCase;
use App\Profile\Model\CvVersion;

final class CvInspectorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/jobscan-cv-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            chmod($file, 0600);
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testAnExistingFileIsReportedWithItsSizeAndDate(): void
    {
        file_put_contents($this->directory . '/cv.pdf', str_repeat('x', 2048));

        $status = new CvInspector()->inspect(new CvVersion('a', 'A', $this->directory . '/cv.pdf'));

        self::assertTrue($status->isUsable());
        self::assertSame(2048, $status->size);
        self::assertNotNull($status->modifiedAt);
    }

    public function testProblemsAreDistinguished(): void
    {
        file_put_contents($this->directory . '/vide.pdf', '');
        $inspector = new CvInspector();

        self::assertSame(CvFileStatus::NOT_DECLARED, $inspector->inspect(new CvVersion('a', 'A', null))->state);
        self::assertSame(CvFileStatus::NOT_DECLARED, $inspector->inspect(new CvVersion('a', 'A', '  '))->state);
        self::assertSame(CvFileStatus::MISSING, $inspector->inspect(new CvVersion('a', 'A', $this->directory . '/absent.pdf'))->state);
        self::assertSame(CvFileStatus::EMPTY, $inspector->inspect(new CvVersion('a', 'A', $this->directory . '/vide.pdf'))->state);
        self::assertSame(CvFileStatus::MISSING, $inspector->inspect(new CvVersion('a', 'A', $this->directory))->state, 'Un dossier n\'est pas un CV.');
    }

    public function testAnUnreadableFileIsReported(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root lit tout.');
        }
        file_put_contents($this->directory . '/secret.pdf', 'x');
        chmod($this->directory . '/secret.pdf', 0000);

        self::assertSame(CvFileStatus::UNREADABLE, new CvInspector()->inspect(new CvVersion('a', 'A', $this->directory . '/secret.pdf'))->state);
    }

    public function testTheHomeDirectoryIsExpanded(): void
    {
        file_put_contents($this->directory . '/cv.pdf', 'x');

        $status = new CvInspector($this->directory)->inspect(new CvVersion('a', 'A', '~/cv.pdf'));

        self::assertTrue($status->isUsable());
        self::assertSame($this->directory . '/cv.pdf', $status->resolvedPath);
    }

    public function testAFileContentIsNeverRead(): void
    {
        // Un fichier dont la lecture échouerait (FIFO) n'est pas un fichier régulier : il est simplement signalé.
        $fifo = $this->directory . '/fifo.pdf';
        if (!function_exists('posix_mkfifo') || !posix_mkfifo($fifo, 0600)) {
            self::markTestSkipped('FIFO indisponible.');
        }

        self::assertSame(CvFileStatus::MISSING, new CvInspector()->inspect(new CvVersion('a', 'A', $fifo))->state);
    }
}

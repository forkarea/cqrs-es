<?php


namespace Gica\Cqrs\CodeGeneration\Traits;

use Gica\Cqrs\CodeGeneration\CodeGenerator;
use Gica\FileSystem\FileSystemInterface;
use Gica\FileSystem\OperatingSystemFileSystem;
use Psr\Log\LoggerInterface;

trait MapCodeGeneratorTrait
{
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var FileSystemInterface
     */
    private $fileSystem;

    public function __construct(
        LoggerInterface $logger,
        FileSystemInterface $fileSystem = null
    )
    {
        $this->logger = $logger;
        $this->fileSystem = $fileSystem ?? new OperatingSystemFileSystem();
    }

    public function generate(
        string $templateClassName,
        \Iterator $files,
        string $outputFilePath,
        string $outputShortClassName
    )
    {
        $this->getGenerator()
            ->discoverAndPutContents(
                $this->discover($files),
                $templateClassName,
                $outputFilePath,
                $outputShortClassName
            );

        $this->log($outputFilePath, $files);
    }

    abstract protected function log($outputFilePath);

    abstract protected function discover(string $searchDirectory): array;

    abstract protected function getGenerator(): CodeGenerator;
}
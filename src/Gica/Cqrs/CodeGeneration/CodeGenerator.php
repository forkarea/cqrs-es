<?php


namespace Gica\Cqrs\CodeGeneration;

use Gica\CodeAnalysis\MethodListenerDiscovery\MapCodeGenerator;
use Gica\CodeAnalysis\MethodListenerDiscovery\MethodListenerMapperWriter;
use Gica\FileSystem\FileSystemInterface;
use Gica\FileSystem\OperatingSystemFileSystem;

class CodeGenerator
{

    /**
     * @var MapCodeGenerator
     */
    private $codeGenerator;

    public function __construct(
        MapCodeGenerator $codeGenerator = null
    )
    {
        $this->codeGenerator = $codeGenerator ?? new MethodListenerMapperWriter;
    }

    public function discoverAndPutContents(
        Discoverer $discoverer,
        FileSystemInterface $fileSystem = null,
        string $commandSubscriberTemplateClassName,
        string $searchDirectory,
        string $outputFilePath,
        string $outputShortClassName = 'CommandHandlerSubscriber')
    {
        $fileSystem = $fileSystem ?? new OperatingSystemFileSystem();

        $classInfo = new \ReflectionClass($commandSubscriberTemplateClassName);

        $classInfo->getShortName();

        $this->deleteFileIfExists($fileSystem, $outputFilePath);

        $map = $discoverer->discover($searchDirectory);

        $template = file_get_contents($classInfo->getFileName());

        $template = str_replace($classInfo->getShortName() /*CommandSubscriberTemplate*/, $outputShortClassName /*CommandHandlerSubscriber*/, $template);

        $template = str_replace('--- This is just a template ---', '--- generated by ' . __FILE__ . ' at ' . date('c') . ' ---', $template);

        $code = $this->codeGenerator->generateAndGetFileContents($map, $template);

        $fileSystem->filePutContents($outputFilePath, $code);

        $fileSystem->fileSetPermissions($outputFilePath, 0777);
    }

    private function deleteFileIfExists(FileSystemInterface $fileSystem, string $outputFilePath)
    {
        try {
            if ($fileSystem->fileExists($outputFilePath)) {
                $fileSystem->fileDelete($outputFilePath);
            }
        } catch (\Exception $exception) {
            //it's ok
        }
    }

}
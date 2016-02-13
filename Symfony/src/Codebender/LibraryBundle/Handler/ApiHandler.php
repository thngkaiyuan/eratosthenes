<?php

namespace Codebender\LibraryBundle\Handler;

use Doctrine\ORM\EntityManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Finder\Finder;

class ApiHandler
{

    protected $entityManager;
    protected $container;

    function __construct(EntityManager $entityManager, ContainerInterface $containerInterface)
    {
        $this->entityManager = $entityManager;
        $this->container = $containerInterface;
    }

    public function getLibraryCode($library, $version = null, $renderView = false)
    {
        $builtinLibrariesPath = $this->container->getParameter('builtin_libraries');
        $externalLibrariesPath = $this->container->getParameter('external_libraries_new');

        $finder = new Finder();
        $exampleFinder = new Finder();

        $filename = $library;

        $last_slash = strrpos($library, "/");
        if ($last_slash !== false) {
            $filename = substr($library, $last_slash + 1);
        }

        //TODO handle the case of different .h filenames and folder names
        if ($filename == "ArduinoRobot") {
            $filename = "Robot_Control";
        }
        if ($filename == "ArduinoRobotMotorBoard") {
            $filename = "Robot_Motor";
        }
        if ($filename == 'BlynkSimpleSerial' || $filename == 'BlynkSimpleCC3000') {
            $filename = 'BlynkSimpleEthernet';
        }

        if ($this->hasBuiltIn($filename)) {
            $response = $this->fetchLibraryFiles($finder, $builtinLibrariesPath . "/libraries/" . $filename);

            if ($renderView) {
                $examples = $this->fetchLibraryExamples($exampleFinder, $builtinLibrariesPath . "/libraries/" . $filename);
                $meta = [];
            }
        } else {
            if (!$this->hasExternalLibrary($filename)) {
                return ["success" => false, "message" => "No Library named " . $library . " found."];
            } else {
                $response = $this->fetchLibraryFiles($finder, $externalLibrariesPath . "/" . $filename . "/" . $version);
                if (empty($response)) {
                    return ['success' => false, 'message' => 'No files for Library named `' . $library . '` with version `' . $version . '` found.'];
                }

                if ($renderView) {
                    $examples = $this->fetchLibraryExamples($exampleFinder, $externalLibrariesPath . "/" . $filename);

                    $externalLibrary = $this->entityManager->getRepository('CodebenderLibraryBundle:ExternalLibrary')
                        ->findOneBy(array('machineName' => $filename));
                    $filename = $externalLibrary->getMachineName();
                    $meta = $externalLibrary->getLiraryMeta();
                }
            }
        }
        if (!$renderView) {
            return ['success' => true, 'message' => 'Library found', 'files' => $response];
        }

        return [
            'success' => true,
            'library' => $filename,
            'files' => $response,
            'examples' => $examples,
            'meta' => $meta
        ];
    }

    public function hasBuiltIn($library)
    {
        $arduino_library_files = $this->container->getParameter('builtin_libraries') . "/";
        return is_dir($arduino_library_files . "/libraries/" . $library);
    }

    public function hasExternalLibrary($library, $getDisabled = false)
    {
        $lib = $this->entityManager
                    ->getRepository('CodebenderLibraryBundle:ExternalLibrary')
                    ->findBy(array('machineName' => $library));
        return !(empty($lib) || (!$getDisabled && !$lib[0]->getActive()));
    }

    public function fetchLibraryFiles($finder, $directory, $getContent = true)
    {
        if (!is_dir($directory)) {
            return array();
        }

        $finder->in($directory)->exclude('examples')->exclude('Examples');
        $finder->name('*.*');

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        $response = array();
        foreach ($finder as $file) {
            if ($getContent) {
                $mimeType = finfo_file($finfo, $file);
                if (strpos($mimeType, "text/") === false) {
                    $content = "/*\n *\n * We detected that this is not a text file.\n * Such files are currently not supported by our editor.\n * We're sorry for the inconvenience.\n * \n */";
                } else {
                    $content = (!mb_check_encoding($file->getContents(), 'UTF-8')) ? mb_convert_encoding($file->getContents(), "UTF-8") : $file->getContents();
                }
                $response[] = array("filename" => $file->getRelativePathname(), "content" => $content);
            } else {
                $response[] = array("filename" => $file->getRelativePathname());
            }
        }
        return $response;
    }

    public function fetchLibraryExamples($finder, $directory)
    {
        if (is_dir($directory)) {
            $finder->in($directory);
            $finder->name('*.pde')->name('*.ino');

            $response = array();
            foreach ($finder as $file) {
                $response[] = array("filename" => $file->getRelativePathname(), "content" => (!mb_check_encoding($file->getContents(), 'UTF-8')) ? mb_convert_encoding($file->getContents(), "UTF-8") : $file->getContents());
            }

            return $response;
        }

    }
}

<?php

namespace Codebender\LibraryBundle\Handler;

use Codebender\LibraryBundle\Entity\Library;
use Codebender\LibraryBundle\Entity\LibraryExample;
use Codebender\LibraryBundle\Entity\Version;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;
use Symfony\Component\Finder\Finder;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ApiHandler
{

    protected $entityManager;
    protected $container;

    function __construct(EntityManager $entityManager, ContainerInterface $containerInterface)
    {
        $this->entityManager = $entityManager;
        $this->container = $containerInterface;
    }

    /**
     * Returns a response which includes an array of version
     * strings belonging to an external library
     *
     * @param $defaultHeader
     * @return array
     */
    public function getVersionsResponse($defaultHeader)
    {
        if (!$this->isExternalLibrary($defaultHeader)) {
            return ['success' => false, 'message' => 'Invalid library name ' . $defaultHeader];
        }

        $versions = $this->getVersionStringsFromDefaultHeader($defaultHeader);
        return ['success' => true, 'versions' => $versions];
    }

    /**
     * Returns a response which include the example file
     * for all examples provided by the requested library
     *
     * @param $library
     * @param $version
     * @return array
     */
    public function getLibraryExamplesResponse($library, $version)
    {
        $type = $this->getLibraryType($library);
        if ($type === 'unknown') {
            return ['success' => false, 'message' => 'Requested library named ' . $library . ' not found.'];
        }

        // TODO: use a default version if version is not given in the request
        if (!$this->isExternalLibraryVersion($library, $version)) {
            return ['success' => false, 'message' => 'Requested version for library ' . $library . ' not found.'];
        }

        $examples = array();
        $path = "";
        /*
         * Assume the requested library is an example
         */
        $path = $this->getBuiltInLibraryExamplePath($library);
        if ($type === 'external') {
            $path = $this->getExternalLibraryPath($library, $version);
        }
        if ($type === 'builtin') {
            $path = $this->getBuiltInLibraryPath($library);
        }
        $inoFinder = new Finder();
        $inoFinder->in($path);
        $inoFinder->name('*.ino')->name('*.pde');

        foreach ($inoFinder as $example) {
            $files = array();

            $content = (!mb_check_encoding($example->getContents(), 'UTF-8')) ? mb_convert_encoding($example->getContents(), "UTF-8") : $example->getContents();
            $pathInfo = pathinfo($example->getBaseName());
            $files[] = array(
                "filename" => $pathInfo['filename'] . '.ino',
                "content" => (!mb_check_encoding($content, 'UTF-8')) ? mb_convert_encoding($content, "UTF-8") : $content
            );

            // TODO: Not only .h and .cpp files in Arduino examples
            $notInoFilesFinder = new Finder();
            $notInoFilesFinder->files()->name('*.h')->name('*.cpp');
            $notInoFilesFinder->in($path . "/" . $example->getRelativePath());

            foreach ($notInoFilesFinder as $nonInoFile) {
                $files[] = array(
                    "filename" => $nonInoFile->getBaseName(),
                    "content" => (!mb_check_encoding($nonInoFile->getContents(), 'UTF-8')) ? mb_convert_encoding($nonInoFile->getContents(), "UTF-8") : $nonInoFile->getContents()
                );
            }

            $dir = preg_replace('/[E|e]xamples\//', '', $example->getRelativePath());
            $dir = str_replace($pathInfo['filename'], '', $dir);
            $dir = str_replace('/', ':', $dir);
            if ($dir != '' && substr($dir, -1) != ':') {
                $dir .= ':';
            }


            $examples[$dir . $pathInfo['filename']] = $files;
        }
        return ['success' => true, 'examples' => $examples];
    }

    /**
     * Returns a response which include example files
     * for the requested example of the library
     *
     * @param $library
     * @param $example
     * @param $version
     * @return array
     */
    public function getExampleCodeResponse($library, $example, $version)
    {
        $type = $this->getLibraryType($library);
        if ($type === 'unknown') {
            return ['success' => false, 'message' => 'Requested library named' . $library . ' not found.'];
        }

        switch ($type) {
            case 'builtin':
                $dir = $this->container->getParameter('builtin_libraries') . "/libraries/";
                $example = $this->getExampleCodeFromDir($dir, $library, $example);
                break;
            case 'external':
                $example = $this->getExternalExampleCode($library, $version, $example);
                break;
            case 'example':
                $dir = $this->container->getParameter('builtin_libraries') . "/examples/";
                $example = $this->getExampleCodeFromDir($dir, $library, $example);
                break;
        }

        return $example;
    }

    /**
     * This method returns a response containing all the keywords of a library version.
     *
     * @param $defaultHeader
     * @param $version
     * @return array containing the keywords
     */
    public function getKeywordsResponse($defaultHeader, $version)
    {
        if ($defaultHeader === null) {
            return ['success' => false];
        }

        if (!$this->libraryVersionExists($defaultHeader, $version)) {
            return ['success' => false, 'message' => 'Version ' .$version. ' of library named ' .$defaultHeader. ' not found.'];
        }

        $libraryType = $this->getLibraryType($defaultHeader);
        if ($libraryType === 'external') {
            $keywords = $this->getExternalLibraryKeywords($defaultHeader, $version);
        } elseif ($libraryType === 'builtin') {
            $keywords = $this->getBuiltInLibraryKeywords($defaultHeader);
        } else {
            return ['success' => false];
        }

        return ['success' => true, 'keywords' => $keywords];
    }

    /**
     * This method sets the default values for an API request for values
     * which are not set.
     * TODO: Set version to the preferred version of a partner if unset
     *
     * @param $content
     */
    public function checkAndSetDefaultGetKeywordsParameters(&$content)
    {
        if (!isset($content['version'])) {
            $content['version'] = null;
        }
    }

    /**
     * This method returns the type of the library (e.g. external/builtin) as a string.
     *
     * @param $defaultHeader
     * @return string
     * @internal param $version
     */
    private function getLibraryType($defaultHeader)
    {
        if ($this->isExternalLibrary($defaultHeader)) {
            return 'external';
        } elseif ($this->isBuiltInLibrary($defaultHeader)) {
            return 'builtin';
        } elseif ($this->isBuiltInLibraryExample($defaultHeader)) {
            return 'example';
        }

        return 'unknown';
    }

    private function getExternalLibraryKeywords($defaultHeader, $version)
    {
        $path = $this->getExternalLibraryPath($defaultHeader, $version);
        $keywords = $this->getKeywordsFromFile($path);
        return $keywords;
    }

    private function getBuiltInLibraryKeywords($defaultHeader)
    {
        $path = $this->getBuiltInLibraryPath($defaultHeader);
        $keywords = $this->getKeywordsFromFile($path);
        return $keywords;
    }

    /**
     * This method returns an array of keywords found in $path.
     *
     * @param $path
     * @return array
     */
    private function getKeywordsFromFile($path)
    {
        $keywords = array();

        $finder = new Finder();
        $finder->in($path);
        $finder->name('/keywords\.txt/i');

        foreach ($finder as $file) {
            $content = (!mb_check_encoding($file->getContents(), 'UTF-8')) ? mb_convert_encoding($file->getContents(), "UTF-8") : $file->getContents();

            $lines = preg_split('/\r\n|\r|\n/', $content);

            foreach ($lines as $rawline) {

                $line = trim($rawline);
                $parts = preg_split('/\s+/', $line);

                $totalParts = count($parts);

                if (($totalParts == 2) || ($totalParts == 3)) {

                    if ((substr($parts[1], 0, 7) == "KEYWORD")) {
                        $keywords[$parts[1]][] = $parts[0];
                    }

                    if ((substr($parts[1], 0, 7) == "LITERAL")) {
                        $keywords["KEYWORD3"][] = $parts[0];
                    }

                }

            }

            break;
        }
        return $keywords;
    }

    /**
     * This method checks if a given library (version) exists
     *
     * @param $defaultHeader
     * @param $version
     * @return bool
     */
    private function libraryVersionExists($defaultHeader, $version)
    {
        if ($this->isExternalLibraryVersion($defaultHeader, $version)) {
            return true;
        } elseif ($this->isBuiltInLibrary($defaultHeader)) {
            return true;
        }

        return false;
    }

    /**
     * This method checks if the given version exists in the given library
     * specified by the $defaultHeader.
     *
     * @param $defaultHeader
     * @param $version
     * @return bool
     */
    private function isExternalLibraryVersion($defaultHeader, $version)
    {
        if (!$this->isExternalLibrary($defaultHeader)) {
            return false;
        }

        $versions = $this->getVersionStringsFromDefaultHeader($defaultHeader);
        return in_array($version, $versions);
    }

    /**
     * This method checks if the given built-in library exists (specified by
     * its $defaultHeader)
     *
     * @param $defaultHeader
     * @return bool
     */
    private function isBuiltInLibrary($defaultHeader)
    {
        if (!is_dir($this->getBuiltInLibraryPath($defaultHeader))) {
            return false;
        }

        return true;
    }

    private function getBuiltInLibraryPath($defaultHeader)
    {
        $builtInLibraryRoot = $this->container->getParameter('builtin_libraries') . "/";
        $path = $builtInLibraryRoot . '/libraries/' . $defaultHeader;
        return $path;
    }

    /**
     * This method checks if the given built-in library example exists (specified by
     * its $defaultHeader)
     *
     * @param $defaultHeader
     * @return bool
     */
    private function isBuiltInLibraryExample($defaultHeader)
    {
        if (!is_dir($this->getBuiltInLibraryExamplePath($defaultHeader))) {
            return false;
        }

        return true;
    }

    private function getBuiltInLibraryExamplePath($exmapleName)
    {
        $builtInLibraryRoot = $this->container->getParameter('builtin_libraries') . "/";
        $path = $builtInLibraryRoot . '/examples/' . $exmapleName;
        return $path;
    }

    /**
     * This method checks if a given external library exists in the database.
     *
     * @param $defaultHeader
     * @return bool
     */
    private function isExternalLibrary($defaultHeader)
    {
        $lib = $this->entityManager
            ->getRepository('CodebenderLibraryBundle:Library')
            ->findBy(array('default_header' => $defaultHeader));

        return !empty($lib);
    }

    /**
     * Constrct the path for the given library and version
     * @param $defaultHeader
     * @param $version
     * @return string
     */
    private function getExternalLibraryPath($defaultHeader, $version)
    {
        $externalLibraryRoot = $this->container->getParameter('external_libraries_new') . "/";

        $library = $this->getLibraryFromDefaultHeader($defaultHeader);
        $libraryFolderName = $library->getFolderName();

        $versions = $library->getVersions();
        $version = $versions->filter(
            function ($ver) use ($version) {
                return $ver->getVersion() === $version;
            },
            $versions
        )->first();
        $versionFolderName = $version->getFolderName();

        $path = $externalLibraryRoot . '/' . $libraryFolderName . '/' . $versionFolderName;
        return $path;
    }

    /**
     * This method returns an array of versions belonging to a library
     * with the given default header.
     *
     * @param $defaultHeader
     * @return array
     */
    private function getVersionStringsFromDefaultHeader($defaultHeader)
    {
        $versionObjects = $this->getAllVersionsFromDefaultHeader($defaultHeader);
        $versionsCollection = $versionObjects->map(function ($version) {
            return $version->getVersion();
        });
        $versions = $versionsCollection->toArray();
        return $versions;
    }

    /**
     * @param $defaultHeader
     * @return mixed
     */
    private function getAllVersionsFromDefaultHeader($defaultHeader)
    {
        $library = $this->getLibraryFromDefaultHeader($defaultHeader);
        $versionObjects = $library->getVersions();
        return $versionObjects;
    }

    /**
     * Get the requested version entity of the given library
     * @param $library
     * @param $version
     * @return Version
     */
    private function getVersionFromDefaultHeader($library, $version)
    {
        /* @var ArrayCollection $versionCollection */
        $versionCollection = $this->getAllVersionsFromDefaultHeader($library);

        // check if this library contains requested version
        $result = $versionCollection->filter(
            function ($versionObject) use ($version) {
                return $versionObject->getVersion() === $version;
            }
        );

        if ($result->isEmpty()) {
            return null;
        }

        return $result->first();
    }

    /**
     * Converts a given default header into its library object
     *
     * @param $defaultHeader
     * @return Library
     */
    private function getLibraryFromDefaultHeader($defaultHeader)
    {
        $lib = $this->entityManager
            ->getRepository('CodebenderLibraryBundle:Library')
            ->findOneBy(array('default_header' => $defaultHeader));

        return $lib;
    }

    /**
     * Get LibraryExample entity for the requested library example
     * @param $library
     * @param $version
     * @param $example
     * @return array
     */
    private function getExampleForExternalLibrary($library, $version, $example)
    {
        /* @var Version $versionMeta */
        $versionMeta = $this->getVersionFromDefaultHeader($library, $version);

        if ($versionMeta === null) {
            return [];
        }

        $examplenMeta = array_values(
            array_filter(
                $versionMeta->getLibraryExamples()->toArray(),
                function ($exampleObject) use ($example) {
                    return $exampleObject->getName() === $example;
                }
            )
        );

        return $examplenMeta;
    }

    /**
     * Fetch codes for the requested external library example
     * @param $library
     * @param $version
     * @param $example
     * @return array
     */
    private function getExternalExampleCode($library, $version, $example)
    {
        // TODO: use a default version if version is not given in the request
        $exampleMeta = $this->getExampleForExternalLibrary($library, $version, $example);

        if (count($exampleMeta) == 0) {
            $example = str_replace(":", "/", $example);
            $filename = pathinfo($example, PATHINFO_FILENAME);

            $exampleMeta = $this->getExampleForExternalLibrary($library, $version, $filename);

            if (count($exampleMeta) > 1) {
                $meta = null;
                foreach ($exampleMeta as $e) {
                    $path = $e->getPath();
                    if (!(strpos($path, $example) === false)) {
                        $meta = $e;
                        break;
                    }
                }
                if (!$meta) {
                    return ['success' => false];
                }
            } elseif (count($exampleMeta) == 0) {
                return ['success' => false];
            } else {
                $meta = $exampleMeta[0];
            }
        } else {
            $meta = $exampleMeta[0];
        }

        $externalLibraryPath = $this->container->getParameter('external_libraries_new');
        $libraryFolder = $meta->getVersion()->getLibrary()->getFolderName();
        $versionFolder = $meta->getVersion()->getFolderName();

        $fullPath = $externalLibraryPath . '/' . $libraryFolder . '/' . $versionFolder . '/' . $meta->getPath();

        $path = pathinfo($fullPath, PATHINFO_DIRNAME);
        $files = $this->getExampleFilesFromDir($path);
        return $files;
    }

    private function getExampleCodeFromDir($dir, $library, $example)
    {
        $finder = new Finder();
        $finder->in($dir . $library);
        $finder->name($example . ".ino", $example . ".pde");

        if (iterator_count($finder) == 0) {
            $example = str_replace(":", "/", $example);
            $filename = pathinfo($example, PATHINFO_FILENAME);
            $finder->name($filename . ".ino", $filename . ".pde");
            if (iterator_count($finder) > 1) {
                $filesPath = null;
                foreach ($finder as $e) {
                    $path = $e->getPath();
                    if (!(strpos($path, $example) === false)) {
                        $filesPath = $e;
                        break;
                    }
                }
                if (!$filesPath) {
                    return ['success' => false];
                }
            } elseif (iterator_count($finder) == 0) {
                return ['success' => false];
            } else {
                $filesPathIterator = iterator_to_array($finder, false);
                $filesPath = $filesPathIterator[0]->getPath();
            }
        } else {
            $filesPathIterator = iterator_to_array($finder, false);
            $filesPath = $filesPathIterator[0]->getPath();
        }
        $files = $this->getExampleFilesFromDir($filesPath);
        return $files;
    }

    private function getExampleFilesFromDir($dir)
    {
        $filesFinder = new Finder();
        $filesFinder->in($dir);
        $filesFinder->name('*.cpp')->name('*.h')->name('*.c')->name('*.S')->name('*.pde')->name('*.ino');

        $files = array();
        foreach ($filesFinder as $file) {
            if ($file->getExtension() == "pde") {
                $name = $file->getBasename("pde") . "ino";
            } else {
                $name = $file->getFilename();
            }

            $files[] = array(
                "filename" => $name,
                "code" => (!mb_check_encoding($file->getContents(), 'UTF-8')) ? mb_convert_encoding($file->getContents(), "UTF-8") : $file->getContents()
            );

        }

        return ['success' => true, "files" => $files];
    }
}

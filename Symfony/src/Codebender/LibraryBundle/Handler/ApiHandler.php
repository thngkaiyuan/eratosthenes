<?php

namespace Codebender\LibraryBundle\Handler;

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
        if (!$this->externalLibraryExists($defaultHeader)) {
            return ['success' => false, 'message' => 'Invalid library name ' . $defaultHeader];
        }

        $versions = $this->getVersionsFromDefaultHeader($defaultHeader);
        return ['success' => true, 'versions' => $versions];
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

        $libraryType = $this->getLibraryType($defaultHeader, $version);
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
     * @param $version
     * @return string
     */
    private function getLibraryType($defaultHeader, $version)
    {
        if ($this->isExternalLibraryVersion($defaultHeader, $version)) {
            return 'external';
        } elseif ($this->isBuiltInLibrary($defaultHeader)) {
            return 'builtin';
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
        if (!$this->externalLibraryExists($defaultHeader)) {
            return false;
        }

        $versions = $this->getVersionsFromDefaultHeader($defaultHeader);
        return in_array($version, $versions);
    }

    /**
     * This method checks if the given built-in library exists (specified by
     * its $defaultHeader.
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

    private function getExternalLibraryPath($defaultHeader, $version)
    {
        $externalLibraryRoot = $this->container->getParameter('external_libraries_new') . "/";

        $library = $this->getLibraryFromDefaultHeader($defaultHeader);
        $libraryFolderName = $library->getFolderName();

        $versions = $library->getVersions();
        $version = $versions->filter(function ($ver) use ($version) {
            return $ver->getVersion() === $version;
        }, $versions)->first();
        $versionFolderName = $version->getFolderName();

        $path = $externalLibraryRoot . '/' . $libraryFolderName . '/' . $versionFolderName;
        return $path;
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
     * This method checks if a given external library exists in the database.
     *
     * @param $defaultHeader
     * @return bool
     */
    private function externalLibraryExists($defaultHeader)
    {
        $lib = $this->entityManager
            ->getRepository('CodebenderLibraryBundle:Library')
            ->findBy(array('default_header' => $defaultHeader));

        return !empty($lib);
    }

    /**
     * This method returns an array of versions belonging to a library
     * with the given default header.
     *
     * @param $defaultHeader
     * @return array of versions
     */
    private function getVersionsFromDefaultHeader($defaultHeader)
    {
        $library = $this->getLibraryFromDefaultHeader($defaultHeader);
        $versionObjects = $library->getVersions();
        $versionsCollection = $versionObjects->map(function ($version) {
            return $version->getVersion();
        });
        $versions = $versionsCollection->toArray();
        return $versions;
    }

    /**
     * Converts a given default header into its library object
     *
     * @param $defaultHeader
     * @return the library object
     */
    private function getLibraryFromDefaultHeader($defaultHeader)
    {
        $lib = $this->entityManager
            ->getRepository('CodebenderLibraryBundle:Library')
            ->findBy(array('default_header' => $defaultHeader));

        return $lib[0];
    }
}

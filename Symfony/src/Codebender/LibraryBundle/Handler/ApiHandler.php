<?php

namespace Codebender\LibraryBundle\Handler;

use Codebender\LibraryBundle\Entity\Library;
use Doctrine\ORM\EntityManager;
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

    public function checkIfBuiltInExists($library)
    {
        $arduino_library_files = $this->container->getParameter('builtin_libraries') . "/";
        if (!is_dir($arduino_library_files . "/libraries/" . $library)) {
            return json_encode(array("success" => false, "message" => "No Library named " . $library . " found."));
        }

        return json_encode(array("success" => true, "message" => "Library found"));
    }

    public function checkIfExternalExists($library, $getDisabled = false)
    {
        $lib = $this->entityManager
            ->getRepository('CodebenderLibraryBundle:Library')
            ->findBy(array('default_header' => $library));

        if (empty($lib) || (!$getDisabled && !$lib[0]->getActive())) {
            return json_encode(array("success" => false, "message" => "No Library named " . $library . " found."));
        }

        return json_encode(array("success" => true, "message" => "Library found"));
    }

    public function checkIfBuiltInExampleFolderExists($library)
    {
        $arduinoLibraryFiles = $this->container->getParameter('builtin_libraries') . "/";
        if (is_dir($arduinoLibraryFiles . "/examples/" . $library)) {
            return json_encode(array("success" => true, "message" => "Library found"));
        }

        return json_encode(array("success" => false, "message" => "No Library named " . $library . " found."));
    }

    /**
     * Get all versions for a library
     *
     * @param $library
     * @return array
     */
    public function getLibraryVersions($library)
    {
        /* @var Library $lib */
        $lib = $this->entityManager
            ->getRepository('CodebenderLibraryBundle:Library')
            ->findOneBy(array('default_header' => $library));

        if ($lib === null || !$lib->getActive()) {
            return ["success" => false, "message" => "No Library named " . $library . " found."];
        }

        return ["success" => true, "data" => $lib->getVersions()->toArray()];
    }
}

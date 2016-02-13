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

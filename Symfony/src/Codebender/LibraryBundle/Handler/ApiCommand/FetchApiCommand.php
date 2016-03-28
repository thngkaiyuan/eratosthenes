<?php

namespace Codebender\LibraryBundle\Handler\ApiCommand;

use Codebender\LibraryBundle\Entity\Version;
use Symfony\Component\Finder\Finder;

class FetchApiCommand extends AbstractApiCommand
{
    protected $apiHandler;

    public function execute($content)
    {
        if (!array_key_exists('library', $content)) {
            return ["success" => false, "message" => "You need to specify which library to fetch."];
        }

        $content = $this->setDefault($content);

        $last_slash = strrpos($content['library'], "/");
        if ($last_slash !== false) {
            $content['library'] = substr($content['library'], $last_slash + 1);
        }

        $this->apiHandler = $this->container->get('codebender_library.apiHandler');

        //TODO handle the case of different .h filenames and folder names
        $RESERVED_NAMES = ["ArduinoRobot" => "Robot_Control", "ArduinoRobotMotorBoard" => "Robot_Motor",
            "BlynkSimpleSerial" => "BlynkSimpleEthernet", "BlynkSimpleCC3000" => "BlynkSimpleEthernet"];

        if (array_key_exists($content['library'], $reservedNames)) {
            $content['library'] = $reservedNames[$content['library']];
        }

        if ($this->apiHandler->isBuiltInLibrary($content['library'])) {
            return $this->fetchBuiltInLibrary($content);
        }

        if (!$this->apiHandler->isExternalLibrary($content['library'], $content['disabled'])) {
            return ["success" => false, "message" => "No Library named " . $content['library'] . " found."];
        }

        return $this->fetchExternalLibrary($content);
    }

    private function fetchExternalLibrary($content)
    {
        $externalLibrariesPath = $this->container->getParameter('external_libraries_new');
        $finder = new Finder();
        $exampleFinder = new Finder();
        $filename = $content['library'];

        // check if the provided version is valid
        if ($content['version'] !== null && !$this->apiHandler->libraryVersionExists($filename, $content['version'])) {
            return [
                'success' => false,
                'message' => 'No files for Library named `' . $filename . '` with version `' . $content['version'] . '` found.'
            ];
        }

        $versionObjects = $this->apiHandler->getAllVersionsFromDefaultHeader($filename);

        // fetch default version
        // if rendering view, fetch all versions
        // if specifically asked for a certain version, fetch that version
        // else if specifically asked for latest version, fetch latest version
        $versions = [$this->apiHandler->fetchPartnerDefaultVersion($this->getRequest()->get('authorizationKey'), $filename)];
        if ($content['renderView'] && $content['version'] === null) {
            $versions = $versionObjects->toArray();
        }
        if ($content['version'] !== null) {
            $versionsCollection = $versionObjects->filter(function ($version) use ($content) {
                return $version->getVersion() === $content['version'];
            });
            $versions = $versionsCollection->toArray();
        }
        if ($content['latest']) {
            $lib = $this->apiHandler->getLibraryFromDefaultHeader($filename);
            $versions = [$lib->getLatestVersion()];
        }

        // fetch library files for each version
        $response = [];
        $examples = [];
        foreach ($versions as $version) {
            /* @var Version $version */
            $libraryPath = $externalLibrariesPath . "/" . $filename . "/" . $version->getFolderName();

            // fetch library files for this version
            $fetchResponse = $this->apiHandler->fetchLibraryFiles($finder->create(), $libraryPath);
            if (!empty($fetchResponse)) {
                $response[$version->getVersion()] = $fetchResponse;
            }

            if ($content['renderView']) {
                // fetch example files for this version if it's rendering view
                $exampleResponse = $this->apiHandler->fetchLibraryExamples($exampleFinder->create(), $libraryPath);
                if (!empty($exampleResponse)) {
                    $examples[$version->getVersion()] = $exampleResponse;
                }
            }
        }

        if ($content['renderView']) {
            $externalLibrary = $this->entityManager->getRepository('CodebenderLibraryBundle:Library')
                ->findOneBy(array('default_header' => $filename));
            $filename = $externalLibrary->getDefaultHeader();
            $meta = $externalLibrary->getLibraryMeta();
            $versions = array_map(
                function ($version) {
                    return $version->getVersion();
                },
                $versions
            );

            return [
                'success' => true,
                'library' => $filename,
                'versions' => $versions,
                'files' => $response,
                'examples' => $examples,
                'meta' => $meta
            ];
        }

        return ['success' => true, 'message' => 'Library found', 'files' => $response];
    }

    private function fetchBuiltInLibrary($content)
    {
        $builtinLibrariesPath = $this->container->getParameter('builtin_libraries');
        $finder = new Finder();
        $exampleFinder = new Finder();
        $filename = $content['library'];

        $response = $this->apiHandler->fetchLibraryFiles($finder, $builtinLibrariesPath . "/libraries/" . $filename);

        if ($content['renderView']) {
            $examples = $this->apiHandler->fetchLibraryExamples($exampleFinder, $builtinLibrariesPath . "/libraries/" . $filename);
            $meta = [];
            $versions = [];

            return [
                'success' => true,
                'library' => $filename,
                'versions' => $versions,
                'files' => $response,
                'examples' => $examples,
                'meta' => $meta
            ];
        }

        return ['success' => true, 'message' => 'Library found', 'files' => $response];
    }

    private function setDefault($content)
    {
        $content['disabled'] = (array_key_exists('disabled', $content) ? $content['disabled'] : false);
        $content['version'] = (array_key_exists('version', $content) ? $content['version'] : null);
        $content['latest'] = (array_key_exists('latest', $content) ? $content['latest'] : false);
        $content['renderView'] = (array_key_exists('renderView', $content) ? $content['renderView'] : false);
        if (!array_key_exists('disabled', $content)) {
            $content['disabled'] = false;
        }
        if (!array_key_exists('version', $content)) {
            $content['version'] = null;
        }
        if (!array_key_exists('renderView', $content)) {
            $content['renderView'] = false;
        }
        return $content;
    }
}

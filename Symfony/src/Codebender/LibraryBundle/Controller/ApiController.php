<?php

namespace Codebender\LibraryBundle\Controller;

use Codebender\LibraryBundle\Entity\Library;
use Codebender\LibraryBundle\Entity\LibraryExample;
use Codebender\LibraryBundle\Entity\Version;
use Codebender\LibraryBundle\Handler\ApiHandler;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

class ApiController extends Controller
{
    /**
     * Dummy function, returns status
     *
     * @return Response
     */
    public function statusAction()
    {
        return new JsonResponse(['success' => true, 'status' => 'OK']);
    }

    /**
     * The main library manager API handler action.
     * Checks the autorization credentials and the validity of the request.
     * Can handle several types of requests, like code fetching, examples fetching, etc.
     *
     * TODO: need to refactor how this work, JsonResponse objects are returned from all over the place inconsistently
     * @return JsonResponse
     */
    public function apiHandlerAction()
    {
        $request = $this->getRequest();
        $content = $request->getContent();

        $content = json_decode($content, true);
        if ($content === null) {
            return new JsonResponse(['success' => false, 'message' => 'Wrong data']);
        }

        if ($this->isValid($content) === false) {
            return new JsonResponse(['success' => false, 'message' => 'Incorrect request fields']);
        }

        return new JsonResponse($this->selectAction($content));
    }

    /**
     * Decides which operation should be excuted based on the `type` parameter of
     * the request. Returns an array with the results.
     *
     * @param $content
     * @return array
     */
    private function selectAction($content)
    {
        switch ($content["type"]) {
            case "getVersions":
                $handler = $this->get('codebender_library.apiHandler');
                return $handler->getVersionsResponse($content["library"]);
            case "getExamples":
                return $this->getLibraryExamples($content["library"], $content["version"]);
            case "getExampleCode":
                return $this->getExampleCode($content["library"], $content["example"], $content["version"]);
            default:
                return ['success' => false, 'message' => 'No valid action requested'];
        }
    }

    private function isValid($requestContent)
    {
        if (!array_key_exists("type", $requestContent)) {
            return false;
        }

        if (in_array($requestContent["type"], array("getExampleCode", "getExamples", "fetch", "getKeywords", "getVersions")) &&
            !array_key_exists("library", $requestContent)
        ) {
            return false;
        }

        if ($requestContent["type"] == "getExampleCode" && !array_key_exists("example", $requestContent)) {
            return false;
        }

        return true;
    }

    private function getLibraryExamples($library, $version)
    {
        $exists = $this->getLibraryType($library);
        if ($exists['success'] !== true) {
            return $exists;
        }

        // TODO: use a default version if version is not given in the request
        $hasVersion = $this->getVerionForLibrary($library, $version);
        if ($hasVersion['success'] !== true) {
            return $hasVersion;
        }

        /* @var Version $versionObject */
        $versionObject = $hasVersion['data'];

        $examples = array();
        $path = "";
        /*
         * Assume the requested library is an example
         */
        $path = $this->container->getParameter('builtin_libraries') . "/examples/" . $library;
        if ($exists['type'] == 'external') {
            $path = $this->container->getParameter('external_libraries_new') . '/' . $library . '/' . $versionObject->getFolderName();
        }
        if ($exists['type'] == 'builtin') {
            $path = $this->container->getParameter('builtin_libraries') . "/libraries/" . $library;
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
        return ['success' => true, 'version' => $version,'examples' => $examples];
    }

    private function getLibraryType($library)
    {
        /* @var ApiHandler $handler */
        $handler = $this->get('codebender_library.apiHandler');

        /*
         * Each library's type can be either external () ..
         */
        $isExternal = json_decode($handler->checkIfExternalExists($library), true);
        if ($isExternal['success']) {
            return ['success' => true, 'type' => 'external'];
        }

        /*
         * .. or builtin (SD, Ethernet, etc) ...
         */
        $isBuiltIn = json_decode($handler->checkIfBuiltInExists($library), true);
        if ($isBuiltIn['success']) {
            return ['success' => true, 'type' => 'builtin'];
        }

        /*
         * .. or example (01.Basics, etc)
         */
        $isExample = json_decode($handler->checkIfBuiltInExampleFolderExists($library), true);
        if ($isExample['success']) {
            return ['success' => true, 'type' => 'example'];
        }

        // Library was not found, return proper message
        return ['success' => false, 'message' => 'Library named ' . $library . ' not found.'];
    }

    /**
     * Get the requested version entity of the given library
     * @param $library
     * @param $version
     * @return array
     */
    private function getVerionForLibrary($library, $version)
    {
        /* @var ApiHandler $handler */
        $handler = $this->get('codebender_library.apiHandler');

        $versions = $handler->getLibraryVersions($library);

        if (!$versions['success']) {
            return ['success' => false, 'message' => 'Requested version not found.'];
        }

        // check if this library contains requested version
        $result = array_values(
            array_filter(
                $versions['data'],
                function ($obj) use ($version) {
                    /* @var Version $obj */
                    return $obj->getVersion() === $version;
                }
            )
        );

        if (empty($result)) {
            return ['success' => false, 'message' => 'Requested version not found.'];
        }

        return ['success' => true, 'message' => 'Requested version found.', 'data' => $result[0]];
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
        $hasVersion = $this->getVerionForLibrary($library, $version);

        if (!$hasVersion['success']) {
            return $hasVersion;
        }

        /* @var Version $versionMeta */
        $versionMeta = $hasVersion['data'];

        $examplenMeta = array_values(
            array_filter(
                $versionMeta->getLibraryExamples()->toArray(),
                function ($exampleObject) use ($example) {
                    /* @var LibraryExample $exampleObject */
                    return $exampleObject->getName() === $example;
                }
            )
        );

        if (empty($examplenMeta)) {
            return ['success' => false, 'data' => []];
        }

        return ['success' => true, 'data' => $examplenMeta];
    }

    /**
     * @param $library
     * @param $example
     * @param $version
     * @return mixed|string
     */
    private function getExampleCode($library, $example, $version)
    {
        $type = $this->getLibraryType($library);
        if ($type['success'] !== true) {
            return $type;
        }

        switch ($type['type']) {
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

    private function getExternalExampleCode($library, $version, $example)
    {
        // TODO: use a default version if version is not given in the request
        $hasExample = $this->getExampleForExternalLibrary($library, $version, $example);

        $exampleMeta = $hasExample['data'];

        if (count($exampleMeta) == 0) {
            $example = str_replace(":", "/", $example);
            $filename = pathinfo($example, PATHINFO_FILENAME);

            $hasVersion = $this->getExampleForExternalLibrary($library, $version, $filename);
            if (!$hasVersion['success']) {
                return $hasVersion;
            }

            $exampleMeta = $hasVersion['data'];

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

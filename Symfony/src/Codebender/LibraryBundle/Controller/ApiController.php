<?php

namespace Codebender\LibraryBundle\Controller;

use Codebender\LibraryBundle\Entity\Example;
use Codebender\LibraryBundle\Entity\ExternalLibrary;
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
     * @param $version
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
            case "list":
                return $this->listAll();
            default:
                return ['success' => false, 'message' => 'No valid action requested'];
        }
    }

    private function isValid($requestContent)
    {
        if (!array_key_exists("type", $requestContent)) {
            return false;
        }

        if (in_array($requestContent["type"], array("getExampleCode", "getExamples", "fetch", "getKeywords")) &&
            !array_key_exists("library", $requestContent)
        ) {
            return false;
        }

        if ($requestContent["type"] == "getExampleCode" && !array_key_exists("example", $requestContent)) {
            return false;
        }

        return true;
    }

    private function listAll()
    {

        $arduinoLibraryFiles = $this->container->getParameter('builtin_libraries') . "/";

        $builtinExamples = $this->getLibariesListFromDir($arduinoLibraryFiles . "examples");
        $includedLibraries = $this->getLibariesListFromDir($arduinoLibraryFiles . "libraries");
        /*
         * External libraries list is fetched from the database, because we need to list
         * active libraries only
         */
        $externalLibraries = $this->getExternalLibrariesList();

        ksort($builtinExamples);
        ksort($includedLibraries);
        ksort($externalLibraries);

        return [
            'success' => true,
            'text' => 'Successful Request!',
            'categories' => [
                'Examples' => $builtinExamples,
                'Builtin Libraries' => $includedLibraries,
                'External Libraries' => $externalLibraries
            ]
        ];
    }

    private function getLibariesListFromDir($path)
    {

        $finder = new Finder();
        $finder->files()->name('*.ino')->name('*.pde');
        $finder->in($path);

        $libraries = array();

        foreach ($finder as $file) {
            $names = $this
                ->getExampleAndLibNameFromRelativePath(
                    $file->getRelativePath(),
                    $file->getBasename("." . $file->getExtension())
                );

            if (!isset($libraries[$names['library_name']])) {
                $libraries[$names['library_name']] = array("description" => "", "examples" => array());
            }
            $libraries[$names['library_name']]['examples'][] = array('name' => $names['example_name']);
        }
        return $libraries;
    }

    private function getExternalLibrariesList()
    {
        $entityManager = $this->getDoctrine()->getManager();
        $externalMeta = $entityManager
            ->getRepository('CodebenderLibraryBundle:ExternalLibrary')
            ->findBy(array('active' => true));

        $libraries = array();
        foreach ($externalMeta as $library) {
            $libraryMachineName = $library->getMachineName();
            if (!isset($libraries[$libraryMachineName])) {
                $libraries[$libraryMachineName] = array(
                    "description" => $library->getDescription(),
                    "humanName" => $library->getHumanName(),
                    "examples" => array()
                );

                if ($library->getOwner() !== null && $library->getRepo() !== null) {
                    $libraries[$libraryMachineName] = array(
                        "description" => $library->getDescription(),
                        "humanName" => $library->getHumanName(),
                        "url" => "http://github.com/" . $library->getOwner() . "/" . $library->getRepo(),
                        "examples" => array()
                    );
                }
            }

            $examples = $entityManager
                ->getRepository('CodebenderLibraryBundle:Example')
                ->findBy(array('library' => $library));

            foreach ($examples as $example) {
                $names = $this
                    ->getExampleAndLibNameFromRelativePath(
                        pathinfo($example->getPath(), PATHINFO_DIRNAME),
                        $example->getName()
                    );

                $libraries[$libraryMachineName]['examples'][] = array('name' => $names['example_name']);
            }


        }

        return $libraries;
    }

    private function getExampleAndLibNameFromRelativePath($path, $filename)
    {
        $type = "";
        $libraryName = strtok($path, "/");

        $tmp = strtok("/");

        while ($tmp != "" && !($tmp === false)) {
            if ($tmp != 'examples' && $tmp != 'Examples' && $tmp != $filename) {
                if ($type == "") {
                    $type = $tmp;
                } else {
                    $type = $type . ":" . $tmp;
                }
            }
            $tmp = strtok("/");


        }
        $exampleName = ($type == "" ? $filename : $type . ":" . $filename);
        return (array('library_name' => $libraryName, 'example_name' => $exampleName));
    }
}

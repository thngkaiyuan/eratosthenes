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
}

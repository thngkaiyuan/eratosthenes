<?php

namespace Codebender\LibraryBundle\Handler\ApiCommand;

use Doctrine\ORM\EntityManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Finder\Finder;

class InvalidApiCommand extends AbstractApiCommand
{
    protected $library;
    protected $version;
    protected $renderView;

    public function inject($content)
    {
        // do nothing
    }

    public function execute()
    {
        return ['success' => false, 'message' => 'No valid action requested'];
    }
}
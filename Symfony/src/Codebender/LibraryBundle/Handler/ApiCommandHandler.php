<?php

namespace Codebender\LibraryBundle\Handler;

use Doctrine\ORM\EntityManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Codebender\LibraryBundle\Handler\ApiCommand\ListApiCommand;

class ApiCommandHandler
{

    protected $entityManager;
    protected $container;

    function __construct(EntityManager $entityManager, ContainerInterface $containerInterface)
    {
        $this->entityManager = $entityManager;
        $this->container = $containerInterface;
    }

    public function parse($content)
    {
        $command = new ListApiCommand($this->entityManager, $this->container); // for now
        $command->inject($content);
        return $command;
    }
}

<?php

namespace Codebender\LibraryBundle\Handler;

use Doctrine\ORM\EntityManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Codebender\LibraryBundle\Handler\ApiCommand\InvalidApiCommand;

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
        $class = 'Codebender\\LibraryBundle\\Handler\\ApiCommand\\' . ucfirst($content['type']) . 'ApiCommand';
        if (!class_exists($class)) {
            $command = new InvalidApiCommand($this->entityManager, $this->container);
        } else {
            $command = new $class($this->entityManager, $this->container);
            $command->inject($content);
        }
        return $command;
    }
}

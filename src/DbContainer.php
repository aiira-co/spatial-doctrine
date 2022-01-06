<?php

declare(strict_types=1);

namespace Spatial\Entity;

class DbContainer
{
    private array $containerArray = [];

    public function get(string $name)
    {
        if (!isset($this->containerArray[$name])) {
            if (class_exists($name)) {
                $this->set($name);
            } else {
                return null;
            }
        }

        return $this->containerArray[$name];
    }

    public function set(string $name): void
    {
        $this->containerArray[$name] = new $name;
    }
}
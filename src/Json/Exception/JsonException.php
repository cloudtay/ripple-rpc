<?php declare(strict_types=1);

namespace Ripple\RPC\Json\Exception;

use Exception;

/**
 *
 */
class JsonException extends Exception
{
    /**
     * @param array $data
     */
    public function __construct(private array $data = [])
    {
        parent::__construct();
    }

    /**
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }
}

<?php

namespace XHGui;

use Slim\Http\Request as SlimRequest;

/**
 * Class making it convenient to access request parameters
 */
class Request extends SlimRequest
{
    //checks _GET [IS PSR-7 compliant]
    public function get(string $key)
    {
        return $this->getQueryParams()[$key];
    }
}

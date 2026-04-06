<?php

namespace newism\notfoundredirects\events;

use craft\base\Event;
use craft\web\Request;
use newism\notfoundredirects\models\Redirect;

class AfterRedirectEvent extends Event
{
    public Request $request;
    public string $uri;
    public Redirect $redirect;
    public string $destinationUrl;
    public int $statusCode;
}

<?php

namespace newism\notfoundredirects\events;

use craft\base\Event;
use craft\web\Request;

class DefineNotFoundUriEvent extends Event
{
    public Request $request;
    public string $uri;
    public int $siteId;
}

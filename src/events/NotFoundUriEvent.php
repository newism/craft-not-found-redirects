<?php

namespace newism\notfoundredirects\events;

use craft\events\CancelableEvent;
use craft\web\Request;

class NotFoundUriEvent extends CancelableEvent
{
    public Request $request;
    public int $siteId;
}

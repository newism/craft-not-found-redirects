<?php

namespace newism\notfoundredirects\events;

use craft\events\CancelableEvent;
use craft\web\Request;
use newism\notfoundredirects\models\Redirect;

class BeforeRedirectEvent extends CancelableEvent
{
    public Request $request;
    public string $uri;
    public Redirect $redirect;
    public string $destinationUrl;
}

<?php

namespace newism\notfoundredirects\db;

abstract class Table
{
    public const REDIRECTS = '{{%notfoundredirects_redirects}}';
    public const NOT_FOUND_URIS = '{{%notfoundredirects_404s}}';
    public const NOTES = '{{%notfoundredirects_notes}}';
    public const REFERRERS = '{{%notfoundredirects_referrers}}';
}

<?php

namespace Tests\Support;

/**
 * Thrown by FakeFetcher for any URL a test did not route. Deliberately an
 * \Error, not an \Exception, so the scraper's own catch (\Exception)
 * blocks cannot swallow it and turn it into a silent empty result.
 */
class UnexpectedFetch extends \Error
{
}

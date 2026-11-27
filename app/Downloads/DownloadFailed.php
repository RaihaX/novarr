<?php

namespace App\Downloads;

/**
 * Prowlarr or a download client could not do what was asked (not set up,
 * unreachable, refused): the message is plain words for the owner.
 */
class DownloadFailed extends \RuntimeException
{
}

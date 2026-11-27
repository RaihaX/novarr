<?php

namespace App\Comics;

/** A comic (or an inbox entry) that cannot be imported; the message says why in plain words. */
class ComicImportFailed extends \RuntimeException
{
}

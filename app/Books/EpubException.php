<?php

namespace App\Books;

/**
 * An ePub that cannot be read: not a zip, no package document, nothing to
 * read in it. The message is shown to the person who uploaded the file.
 */
class EpubException extends \RuntimeException
{
}

<?php

namespace App\Books;

/**
 * A pasted book link that could not be read (App\Books\BookLinkReader):
 * its message is for the reader, as it stands ("Amazon refused; paste the
 * Goodreads or Hardcover link instead.").
 */
class BookLinkFailed extends \RuntimeException
{
}

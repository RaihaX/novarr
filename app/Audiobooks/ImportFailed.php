<?php

namespace App\Audiobooks;

/**
 * An inbox entry that cannot become an audiobook (unreadable, a duplicate,
 * no audio in it): the message is the reason written next to it in
 * {inbox}/_failed. Never thrown for a problem with the tools themselves.
 */
class ImportFailed extends \RuntimeException
{
}

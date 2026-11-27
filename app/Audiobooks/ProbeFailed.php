<?php

namespace App\Audiobooks;

/**
 * An audio file the tools could not read. The message is the reason in
 * plain words, without a full stop ("there is no audio in it"): the
 * importer puts it in a sentence naming the file.
 */
class ProbeFailed extends \RuntimeException
{
}

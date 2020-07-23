<?php

function __getMetadata($data) {
    $metadata = array();

    $name = str_replace("!", "", str_replace("?", "", str_replace("'", "", str_replace(" ", "-", strtolower($data->name)))));
    $name = str_replace(",", "", $name);
    $name = str_replace("'", "", $name);

    $crawler = Goutte::request('GET', 'https://www.novelupdates.com/series/' . $name);
    $crawler->filter('#editdescription')->each(function ($node, $key) use (&$metadata) {
        $metadata["description"] = $node->html();
    });

    $crawler->filter('#authtag')->each(function ($node, $key) use (&$metadata) {
        if ( $key == 0 ) {
            $metadata["author"] = $node->text();
        }
    });

    $crawler->filter('#editstatus')->each(function ($node, $key) use (&$metadata) {
        $array = explode(" ", str_replace("Chapter ", "Chapters ", $node->text()));
        $key = array_search("Chapters", $array);

        $metadata["no_of_chapters"] = $key == 0 ? 0 : intval(trim($array[$key - 1]));
    });

    return $metadata;
}

function __convertWordToNumber($object) {
    // Replace all number words with an equivalent numeric value
    $data = strtr(
        $object,
        array(
            'zero'      => '0',
            'a'         => '1',
            'one'       => '1',
            'two'       => '2',
            'three'     => '3',
            'four'      => '4',
            'five'      => '5',
            'six'       => '6',
            'seven'     => '7',
            'eight'     => '8',
            'nine'      => '9',
            'ten'       => '10',
            'eleven'    => '11',
            'twelve'    => '12',
            'thirteen'  => '13',
            'fourteen'  => '14',
            'fifteen'   => '15',
            'sixteen'   => '16',
            'seventeen' => '17',
            'eighteen'  => '18',
            'nineteen'  => '19',
            'twenty'    => '20',
            'thirty'    => '30',
            'forty'     => '40',
            'fourty'    => '40', // common misspelling
            'fifty'     => '50',
            'sixty'     => '60',
            'seventy'   => '70',
            'eighty'    => '80',
            'ninety'    => '90',
            'hundred'   => '100',
            'thousand'  => '1000',
            'million'   => '1000000',
            'billion'   => '1000000000',
            'and'       => '',
        )
    );

// Coerce all tokens to numbers
    $parts = array_map(
        function ($val) {
            return floatval($val);
        },
        preg_split('/[\s-]+/', $data)
    );

    $stack = new SplStack; // Current work stack
    $sum   = 0; // Running total
    $last  = null;

    foreach ($parts as $part) {
        if (!$stack->isEmpty()) {
            // We're part way through a phrase
            if ($stack->top() > $part) {
                // Decreasing step, e.g. from hundreds to ones
                if ($last >= 1000) {
                    // If we drop from more than 1000 then we've finished the phrase
                    $sum += $stack->pop();
                    // This is the first element of a new phrase
                    $stack->push($part);
                } else {
                    // Drop down from less than 1000, just addition
                    // e.g. "seventy one" -> "70 1" -> "70 + 1"
                    $stack->push($stack->pop() + $part);
                }
            } else {
                // Increasing step, e.g ones to hundreds
                $stack->push($stack->pop() * $part);
            }
        } else {
            // This is the first element of a new phrase
            $stack->push($part);
        }

        // Store the last processed part
        $last = $part;
    }

    return $sum + $stack->pop();
}

function __generateHTMLContent($object) {
    $content = '<?xml version="1.0" encoding="utf-8"?>';
    $content .= '<package version="3.0" unique-identifier="BookId" xmlns="http://www.idpf.org/2007/opf">';
    $content .= '<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">';
    $content .= '<dc:language>en</dc:language>';
    $content .= '<dc:title>' . $object->name . '</dc:title>';
    $content .= '<dc:creator id="cre">' . $object->author . '</dc:creator>';
    $content .= '<meta refines="#cre" scheme="marc:relators" property="role">aut</meta>';
    $content .= '<dc:identifier id="BookId">urn:uuid:de5b9c8f-ce5a-4b5c-b418-e50dba48e2b9</dc:identifier>';
    $content .= '<meta content="0.9.9" name="Sigil version" />';
    $content .= '<meta property="dcterms:modified">2018-03-21T09:42:16Z</meta>';
    $content .= '</metadata>';
    $content .= '<manifest>';
    $content .= '<item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>';
    foreach ( $object->chapters as $item ) {
        $content .= '<item id="' . str_replace("/Novel/" . $item->novel_id . "/Text/", "", $item->html_file) . '" href="Text/' . str_replace("/Novel/" . $item->novel_id . "/Text/", "", $item->html_file) . '" media-type="application/xhtml+xml"/>';
    }
    $content .= '<item id="nav.xhtml" href="Text/nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>';
    $content .= '</manifest>';
    $content .= '<spine toc="ncx">';
    foreach ( $object->chapters as $item ) {
        $content .= '<itemref idref="' . str_replace("/Novel/" . $item->novel_id . "/Text/", "", $item->html_file) . '"/>';
    }
    $content .= '<itemref idref="nav.xhtml" linear="no"/>';
    $content .= '</spine>';
    $content .= '</package>';

    return $content;
}

function __generateHTMLToc($object) {
    $content = '<?xml version="1.0" encoding="utf-8" ?>';
    $content .= '<ncx version="2005-1" xmlns="http://www.daisy.org/z3986/2005/ncx/">';
    $content .= '<head>';
    $content .= '<meta content="urn:uuid:de5b9c8f-ce5a-4b5c-b418-e50dba48e2b9" name="dtb:uid"/>';
    $content .= '<meta content="0" name="dtb:depth"/>';
    $content .= '<meta content="0" name="dtb:totalPageCount"/>';
    $content .= '<meta content="0" name="dtb:maxPageNumber"/>';
    $content .= '</head>';
    $content .= '<docTitle>';
    $content .= '<text>Unknown</text>';
    $content .= '</docTitle>';
    $content .= '<navMap>';
    $content .= '<navPoint id="navPoint-1" playOrder="1">';
    $content .= '<navLabel>';
    $content .= '<text>Start</text>';
    $content .= '</navLabel>';
    $content .= '<content src="Text/' . str_replace("/Novel/" . $object->chapters[0]->novel_id . "/Text/", "", $object->chapters[0]->html_file) . '"/>';
    $content .= '</navPoint>';
    $content .= '</navMap>';
    $content .= '</ncx>';

    return $content;
}

function __generateHTMLNav($string) {
    $content = '<?xml version="1.0" encoding="utf-8"?>';
    $content .= '<!DOCTYPE html>';
    $content .= '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" lang="en" xml:lang="en">';
    $content .= '<head>';
    $content .= '<meta charset="utf-8"/>';
    $content .= '<title></title>';
    $content .= '</head>';
    $content .= '<body epub:type="frontmatter">';
    $content .= '<nav epub:type="toc" id="toc">';
    $content .= '<h1>Table of Contents</h1>';
    $content .= '<ol>';

    foreach ( $string as $s ) {
        $content .= '<li><a href="../Text/' . str_replace("/Novel/" . $s->novel_id . "/Text/", "", $s->html_file) . '">' . $s->label . '</a></li>';
    }

    $content .= '</ol>';
    $content .= '</nav>';
    $content .= '<nav epub:type="landmarks" id="landmarks" hidden="">';
    $content .= '<h2>Landmarks</h2>';
    $content .= '<ol>';
    $content .= '<li>';
    $content .= '<a epub:type="toc" href="#toc">Table of Contents</a>';
    $content .= '</li>';
    $content .= '</ol>';
    $content .= '</nav>';
    $content .= '</body>';
    $content .= '</html>';

    return $content;
}

function __generateHTMLChapter($string) {
    $content = '<?xml version="1.0" encoding="utf-8"?>';
    $content .= '<!DOCTYPE html>';
    $content .= '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops">';
    $content .= '<head>';
    $content .= '<title></title>';
    $content .= '</head>';
    $content .= '<body>';
    $content .= $string;
    $content .= '</body>';
    $content .= '</html>';

    return $content;
}

function __splitLargeString($string) {
    $result = array();

    foreach ( $string as $c ) {
        $string = preg_split('/(?<=[.?!])\s+(?=[a-z])/i', $c);

        foreach ( $string as $s ) {
            array_push($result, "<p>" . $s . "</p>");
        }
    }

    return __cleanseChapterArray($result);
}

function __cleanseChapterArray($string) {
    $cleanseArray = array();

    foreach ( $string as $i ) {
        if ( $i != "<p></p>" ) {
            if ( $i != "<p>&nbsp;</p>" ) {
                if ( $i != "<p>&nbsp; &nbsp;</p>" ) {
                    if ( $i != "<p><p>" ) {
                        if ( $i != "<p>  </p>" ) {
                            if ( $i != "<p>   </p>" ) {
                                if ( $i != "<p> </p>" ) {
                                    array_push($cleanseArray, __cleanseChapterContent($i));
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    return $cleanseArray;
}

function __cleanseChapterContent($string) {
    $string = str_replace("<Mystic Moon>", "Mystic Moon", $string);
    $string = preg_replace(array('/\s{2,}/', '/[\t\n]/'), ' ', $string);
    $string = preg_replace('/[\t\n\r\0\x0B]/', '', $string);
    $string = preg_replace('/([\s])\1+/', ' ', $string);
    $string = preg_replace("/[[:blank:]]+/"," ", $string);
    $string = preg_replace('/\s+/', ' ', $string);

    if ( stripos(strtolower($string), "table of contents") === false ) {
        if ( stripos(strtolower($string), "previous chapter") === false ) {
            if (stripos(strtolower($string), "translated by") === false) {
                if (stripos(strtolower($string), "edited by") === false) {
                    if (stripos(strtolower($string), "translator") === false) {
                        if (stripos(strtolower($string), "A+
						A-") === false) {
                            if (stripos(strtolower($string), "edited:") === false) {
                                if (stripos(strtolower($string), "tl: ") === false) {
                                    if (stripos(strtolower($string), "next chapter") === false) {
                                        if (stripos(strtolower($string), "previous chapter") === false) {
                                            if (stripos(strtolower($string), "proofreader:") === false) {
                                                if (stripos(strtolower($string), "(tl by ") === false) {
                                                    return trim($string);
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

function __tocChapterLabelGenerator($label, $url) {
    if (stripos($label, "teaser") === false) {
        $endloop = 0;
        $counter_chapter = 0;
        $chapter = 0;
        $alphabets = ['a', 'b', 'c', 'd', 'e', 'f'];

        $counter_book = 0;
        $book = 0;
        $bookCheck = 0;

        $label = str_replace("(", " (", $label);
        $label = str_replace("  ", " ", $label);

//            echo $label . "---";
        $label_arr = explode(" ", $label);

        foreach ($label_arr as $item) {
            $new_item = strtolower($item);

            if (
                is_numeric(substr($new_item, 0, 1)) &&
                substr($new_item, 1, 1) == "-" &&
                is_numeric(substr($new_item, 2, 1))
            ) {
                $new_item = str_replace("-", ".", $new_item);
            }

            if (
                is_numeric(substr($new_item, 0, 2)) &&
                substr($new_item, 2, 1) == "-" &&
                is_numeric(substr($new_item, 3, 1))
            ) {
                $new_item = str_replace("-", ".", $new_item);
            }

            if (
                is_numeric(substr($new_item, 0, 3)) &&
                substr($new_item, 3, 1) == "-" &&
                is_numeric(substr($new_item, 4, 1))
            ) {
                $new_item = str_replace("-", ".", $new_item);
            }

            $new_item = str_replace(":", "", $new_item);
            $new_item = str_replace(";", "", $new_item);
//                $new_item = str_replace("-", "", $new_item);
//                $new_item = str_replace("–", "", $new_item);
            $new_item = str_replace("!", "", $new_item);
            $new_item = str_replace(",", "", $new_item);
            $new_item = str_replace("!", "", $new_item);
            $new_item = preg_replace('/[^A-Za-z0-9 _\.\-\+\&]/', '', $new_item);

//                    echo $item . "---";
//                    echo bin2hex($new_item)  . "---";

            if ($counter_chapter == 1 && $endloop == 0) {
//                    echo " e --" . $new_item . "-- ";
                if ($new_item != "chapter") {
//                        echo " f";
                    if (is_numeric($new_item) || is_numeric(floatval($new_item))) {
                        $chapter = $new_item;

                        if ($label_arr[count($label_arr) - 1]) {
                            if (substr($label_arr[count($label_arr) - 1], 0, 1) == "(" && substr($label_arr[count($label_arr) - 1], -1, 1) == ")") {
                                $subchapter = substr($label_arr[count($label_arr) - 1], 1, 1);

                                if (!is_numeric($subchapter)) {
                                    $subchapter = array_search($subchapter, $alphabets) + 1;
                                }

                                $chapter = $chapter . "." . $subchapter;
                            }
                        }

                        $endloop = 1;

//                            echo " a" . $chapter;

                        $counter_chapter = 0;
                    } else {
                        $arr = preg_split('/(?<=[0-9])(?=[a-z]+)/i', $new_item);

                        if (count($arr) > 1) {
                            $chapter = $arr[0] . '.' . (array_search($arr[1], $alphabets) + 1);

                            $endloop = 1;

//                                echo " b" . $chapter;
                        } else {
                            /** check for Alphabets inside parentheses */
                            $check_alphabets_parantheses = explode("(", $new_item);

                            if (count($check_alphabets_parantheses) > 1) {
                                $subchapter = str_replace(")", "", $check_alphabets_parantheses[1]);

                                $chapter = $check_alphabets_parantheses[0] . "." . (array_search(strtolower($subchapter), $alphabets) + 1);

                                $endloop = 1;
                            }

//                                echo " c" . $chapter;
                        }

//                            echo " d";

                        $counter_chapter = 0;
                    }
                }
            }

            if ($counter_book == 1) {
                $book = intval($new_item);

                $counter_book = 0;
            }

            if ($new_item == "chapter" || $new_item == "ast" || $new_item == "episode") {
                if ($counter_chapter == 0) {
                    $counter_chapter++;
                }
            }

            if ($new_item == "book" || $new_item == "volume" || $new_item == "vol") {
                if ($bookCheck == 0) {
                    $counter_book++;
                    $bookCheck++;
                }
            }
        }

        $chapter = str_replace("..", ".", $chapter);
        $chapter = str_replace("(???)", "", $chapter);

        if (substr($chapter, -1) == ".") {
            $chapter = substr($chapter, 0, -1);
        }

//            echo $chapter . " -- ";

        $check_characters = preg_split('/(?<=[0-9])(?=[a-z]+)/i', $chapter);

        if (count($check_characters) > 1) {
            $chapter = $check_characters[0];

            if (strlen($check_characters[1]) == 1) {
                if (in_array(strtolower($check_characters[1]), $alphabets)) {
                    $chapter = $chapter . "." . (array_search(strtolower($check_characters[1]), $alphabets) + 1);
                }
            }
        }

        /** check for Alphabets inside parentheses */
        $check_alphabets_parantheses = explode("(", $chapter);

        if (count($check_alphabets_parantheses) > 1) {
            $subchapter = str_replace(")", "", $check_alphabets_parantheses[1]);

            $chapter = $check_alphabets_parantheses[0] . "." . (array_search(strtolower($subchapter), $alphabets) + 1);
        }

        if ($book == 0) { // check for book number in url
            $check_books_url = explode("-", $url);

            foreach ($check_books_url as $key => $item) {
                if ($item == "book" || $item == "volume") {
                    if (isset($check_books_url[$key + 1])) {
                        $book = $check_books_url[$key + 1];
                    }
                }
            }
        }

//            echo $chapter . "---";

        if (!is_float($chapter)) {
            $check_for_decimal = explode(".", $chapter);

            if (count($check_for_decimal) == 1) {
                if (stripos(strtolower($label), "[part 1]") == true) {
//                        echo "m1";
                    $chapter = $chapter . ".1";
                } else if (stripos(strtolower($label), "[part 2]") == true) {
//                        echo "m2";
                    $chapter = $chapter . ".2";
                } else if (stripos(strtolower($label), "(part 1)") == true) {
//                        echo "m3";
                    $chapter = $chapter . ".1";
                } else if (stripos(strtolower($label), "(part 2)") == true) {
//                        echo "m4";
                    $chapter = $chapter . ".2";
                } else if (stripos(strtolower($label), "part 1:") == true) {
//                        echo "m5";
                    $chapter = $chapter . ".1";
                } else if (stripos(strtolower($label), "part 2:") == true) {
//                        echo "m6";
                    $chapter = $chapter . ".2";
                } else if (stripos(strtolower($label), " part 1 ") == true) {
//                        echo "m7";
                    $chapter = $chapter . ".1";
                } else if (stripos(strtolower($label), " part 2 ") == true) {
//                        echo "m8";
                    $chapter = $chapter . ".2";
                } else if (stripos(strtolower($label), " - 1 - ") == true) {
//                        echo "m9";
                    $chapter = $chapter . ".1";
                } else if (stripos(strtolower($label), " - 2 - ") == true) {
//                        echo "m10";
                    $chapter = $chapter . ".2";
                } else if (stripos(strtolower($label), " - 1: ") == true) {
//                        echo "m11";
                    $chapter = $chapter . ".1";
                } else if (stripos(strtolower($label), " - 2: ") == true) {
//                        echo "m12";
                    $chapter = $chapter . ".2";
                } else if (stripos(strtolower($label), "(part 1/2)") == true) {
//                        echo "m13";
                    $chapter = $chapter . ".1";
                } else if (stripos(strtolower($label), "(part 2/2)") == true) {
//                        echo "m14";
                    $chapter = $chapter . ".2";
                } else if (stripos(strtolower($label), "(Part ½)") == true) {
//                        echo "m15";
                    $chapter = $chapter . ".1";
                } else if (stripos(strtolower($label), " (1/1)") == true) {
//                        echo "m16";
                    $chapter = $chapter . ".1";
                } else if (stripos(strtolower($label), " (2/2") == true) {
//                        echo "m17";
                    $chapter = $chapter . ".2";
                } else if (stripos(strtolower($label), " - 1>") == true) {
                    $chapter = $chapter . ".1";
                } else if (stripos(strtolower($label), " - 2>") == true) {
                    $chapter = $chapter . ".2";
                } else if (stripos(strtolower($label), " - 3>") == true) {
                    $chapter = $chapter . ".3";
                } else if (stripos(strtolower($label), " - 4>") == true) {
                    $chapter = $chapter . ".4";
                } else if (stripos(strtolower($label), " - 5>") == true) {
                    $chapter = $chapter . ".5";
                } else if (stripos(strtolower($label), " - 6>") == true) {
                    $chapter = $chapter . ".6";
                }
            }

        }

//        echo var_dump($label);

        $check_letters_length = preg_replace('/[^a-zA-Z]/', '', $chapter);

        if (strlen($check_letters_length) == 1) {
//                echo "m13";

            $chapter = preg_replace('/[^0-9]/', '', $chapter) . "." . (array_search(strtolower($check_letters_length), $alphabets) + 1);
        } else if (strlen($check_letters_length) > 1) {
//                echo "m14";
            $chapter = preg_replace('/[^0-9]/', '', $chapter);
        }

        if (substr($chapter, -1, 1) == "-") {
            $chapter = substr($chapter, 0, -1);
        }

        if (substr($chapter, -1, 1) == ".") {
            $chapter = substr($chapter, 0, -1);
        }

        if (substr($chapter, -2, 1) == "-") {
            $chapter = str_replace("-", ".", $chapter);
        }

        $chapter = str_replace("-.", ".", $chapter);

        if ( count(explode(".", $chapter)) > 1 ) {
            $tempChapter = explode(".", $chapter);

            $chapter = $tempChapter[0] . "." . $tempChapter[1];
        }

        if ( $chapter == 0 ) {
//            echo "a";
            if ( strpos($url, "https://lightnovelstranslations.com/road-to-kingdom/chapter-") !== false ) {
//                echo "b";
                $chapter_temp_string = str_replace("https://lightnovelstranslations.com/road-to-kingdom/chapter-", "", $url);
//                echo $chapter_temp_string;
                $chapter_temp_array = explode("-", $chapter_temp_string);

                $chapter = $chapter_temp_array[0];

//                echo var_dump($chapter_temp_array);
            }
        }

//            echo $chapter;

//            echo "<br>";

        return array(
            "label" => substr($label, 0, 250),
            "book" => $book,
            "url" => $url,
            "chapter" => $chapter
        );
    }
}